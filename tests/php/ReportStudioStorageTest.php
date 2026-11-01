<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Players\PlayerDeletionCascade;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * #4569 — the Report Studio tables: the migration is idempotent and seeds the
 * two matrix entities; a version number is unique per report; erasing a
 * player or purging a team takes the reports about them, and everything that
 * hangs off those reports, with it.
 */
final class ReportStudioStorageTest extends WP_UnitTestCase {

    // The cascades commit, so the purge tests clean up what they wrote.
    use CommitsAfterCascade;

    private const MIGRATION = '/database/migrations/0307_report_studio_storage.php';

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
    }

    public function test_the_migration_runs_twice_and_writes_the_matrix_once(): void {
        global $wpdb;
        $matrix = $wpdb->prefix . 'tt_authorization_matrix';
        $count  = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$matrix} WHERE entity IN ( 'report_studio', 'report_publishing' )" );

        ( require dirname( __DIR__, 2 ) . self::MIGRATION )->up();
        $first = $count();
        ( require dirname( __DIR__, 2 ) . self::MIGRATION )->up();

        $this->assertSame( $first, $count(), 'a second run writes no matrix rows' );
        foreach ( [ 'tt_reports', 'tt_report_drafts', 'tt_report_versions', 'tt_report_publications' ] as $table ) {
            $this->assertSame( $wpdb->prefix . $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) );
        }

        $row = static fn( string $persona, string $entity, string $scope ): int => (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$matrix} WHERE persona = %s AND entity = %s AND activity = 'create_delete' AND scope_kind = %s",
            $persona, $entity, $scope
        ) );
        $this->assertSame( 1, $row( 'head_coach', 'report_studio', 'team' ) );
        $this->assertSame( 1, $row( 'assistant_coach', 'report_studio', 'team' ) );
        $this->assertSame( 1, $row( 'head_of_development', 'report_studio', 'global' ) );
        $this->assertSame( 1, $row( 'academy_admin', 'report_publishing', 'global' ) );
        $this->assertSame( 0, $row( 'head_coach', 'report_publishing', 'team' ) + $row( 'head_coach', 'report_publishing', 'global' ), 'a coach does not publish' );
        $this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$matrix} WHERE entity IN ( 'report_studio', 'report_publishing' ) AND persona IN ( 'scout', 'parent', 'player', 'team_manager', 'readonly_observer' )" ) );
    }

    public function test_a_version_number_is_unique_per_report(): void {
        global $wpdb;
        $report = $this->report( [ 'scope' => 'me' ] );
        $repo   = new ReportsRepository();

        // The fixture published version 1.
        $two   = $repo->publish( $report, [ 'blocks' => [] ], '', [], 1 );
        $three = $repo->publish( $report, [ 'blocks' => [] ], '', [], 1 );
        $this->assertSame( [ 2, 3 ], [ $two['version_no'] ?? 0, $three['version_no'] ?? 0 ] );

        $wpdb->suppress_errors( true );
        $dupe = $wpdb->insert( $wpdb->prefix . 'tt_report_versions', [
            'uuid' => wp_generate_uuid4(), 'club_id' => CurrentClub::id(), 'report_id' => $report, 'version_no' => 3,
        ] );
        $wpdb->suppress_errors( false );
        $this->assertFalse( $dupe, 'the database refuses a second version 3' );
    }

    public function test_erasing_a_player_removes_their_reports_and_everything_on_them(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [ 'club_id' => CurrentClub::id(), 'first_name' => 'Erase', 'last_name' => 'Me', 'status' => 'active' ] );
        $player = (int) $wpdb->insert_id;
        $report = $this->report( [ 'scope' => 'player', 'player_id' => $player ] );
        $other  = $this->report( [ 'scope' => 'me' ] );

        ( new PlayerDeletionCascade() )->cascade( [ $player ] );

        $this->assertSame( 0, $this->rows( 'tt_reports', 'id', $report ) );
        foreach ( [ 'tt_report_drafts', 'tt_report_versions', 'tt_report_publications' ] as $table ) {
            $this->assertSame( 0, $this->rows( $table, 'report_id', $report ), $table );
        }
        $this->assertSame( 1, $this->rows( 'tt_reports', 'id', $other ), 'a report about nobody in particular stays' );

        $this->cleanUpAndCommit();
    }

    public function test_purging_a_team_removes_its_reports_and_everything_on_them(): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => CurrentClub::id(), 'name' => 'Purge U13' ] );
        $team   = (int) $wpdb->insert_id;
        $report = $this->report( [ 'scope' => 'team', 'team_id' => $team ] );

        ( new ArchiveRepository() )->deletePermanently( 'team', [ $team ] );

        $this->assertSame( 0, $this->rows( 'tt_teams', 'id', $team ), 'Precondition: the team is gone.' );
        $this->assertSame( 0, $this->rows( 'tt_reports', 'id', $report ) );
        foreach ( [ 'tt_report_drafts', 'tt_report_versions', 'tt_report_publications' ] as $table ) {
            $this->assertSame( 0, $this->rows( $table, 'report_id', $report ), $table );
        }

        $this->cleanUpAndCommit();
    }

    public function test_a_report_is_a_recycle_bin_entity(): void {
        $report = $this->report( [ 'scope' => 'me' ] );
        $repo   = new ArchiveRepository();

        $this->assertSame( 1, $repo->archive( 'report', [ $report ], 1 ) );
        $this->assertNotNull( ( new ReportsRepository() )->find( $report )['archived_at'] ?? null );
        $this->assertSame( 1, $repo->restore( 'report', [ $report ] ) );
        $this->assertNull( ( new ReportsRepository() )->find( $report )['archived_at'] ?? 'not restored' );
    }

    /**
     * A stored report with an owner draft, one published version and one
     * publication.
     *
     * @param array<string,mixed> $subject
     */
    private function report( array $subject ): int {
        $repo = new ReportsRepository();
        $id   = $repo->create( [
            'kind' => 'report', 'scope' => (string) $subject['scope'], 'binding' => 'fixed', 'audience' => 'internal',
            'period_mode' => 'on_open', 'period_value' => '', 'layout' => 'rows', 'sharing' => 'me', 'name' => 'Storage',
            'player_id' => $subject['player_id'] ?? null, 'team_id' => $subject['team_id'] ?? null, 'cohort' => null,
        ], 1 );
        $this->assertGreaterThan( 0, $id );
        $repo->publish( $id, [ 'blocks' => [] ], 'first', [ 'head_coach' ], 1 );
        return $id;
    }

    private function rows( string $table, string $column, int $id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", $id ) );
    }
}
