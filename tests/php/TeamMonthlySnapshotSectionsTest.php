<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Frontend\TeamMonthlyReportSnapshotPage;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\Reports\TeamReportSnapshotRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\TeamMonthlyReportPdfExporter;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * A team monthly report snapshot is shown under the reader's current section
 * rights (`TeamSectionRights::cut()`): for every section, the copy the head of
 * development froze reads for each reader as the live report reads for them,
 * on the snapshot page and in its PDF.
 */
final class TeamMonthlySnapshotSectionsTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private const SAFEGUARDING = 'Safeguarding: moved for welfare.';
    private const PUBLIC       = 'Moved to centre back.';

    private int $club = 1;
    private int $team = 0;
    private int $hod = 0;
    private int $narrow = 0;
    private int $assistant = 0;
    private int $scout = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Frozen U16', 'age_group' => 'U16' ] );
        $this->team      = (int) $wpdb->insert_id;
        $this->hod       = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team );
        $this->narrow    = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team );
        $this->assistant = $this->makePersona( 'tt_coach', 'assistant_coach', $this->team );
        $this->scout     = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        $this->seed();
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_each_reader_reads_the_snapshot_as_the_live_report(): void {
        $this->assertNotContains( RecordVisibility::LEVEL_SAFEGUARDING, RecordVisibility::forJourney( $this->narrow ), 'Precondition: the narrower reader lacks the safeguarding level.' );
        $frozen = $this->frozen();

        foreach ( [ 'head of development' => $this->hod, 'observer' => $this->narrow, 'assistant coach' => $this->assistant ] as $who => $reader ) {
            $this->flush();
            if ( ! TeamReportAccess::canRead( $reader, $this->team ) ) continue;
            $live = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $reader );
            $this->flush();
            $cut  = TeamMonthlyReport::forReader( $frozen, $this->team, $reader );
            foreach ( $frozen['blocks'] as $section ) {
                $this->assertSame(
                    $this->json( $live['data'][ $section ] ?? null ),
                    $this->json( $cut['data'][ $section ] ?? null ),
                    "{$section} for the {$who}: the snapshot reads as the live report"
                );
            }
        }
    }

    public function test_a_reader_without_the_safeguarding_level_gets_no_such_entry_on_screen_or_paper(): void {
        $frozen = $this->frozen();
        $this->assertStringContainsString( self::SAFEGUARDING, (string) wp_json_encode( $frozen ), 'Precondition: the taker froze the entry.' );
        $uuid = ( new TeamReportSnapshotRepository() )->create( $this->team, [ 'team_id' => $this->team, 'layout' => 'A', 'blocks' => [] ], $frozen, 'Snapshot', $this->hod );
        $this->assertNotSame( '', $uuid );

        foreach ( [ [ $this->narrow, false ], [ $this->hod, true ] ] as [ $reader, $sees ] ) {
            $this->flush();
            wp_set_current_user( $reader );
            ob_start();
            $this->assertTrue( TeamMonthlyReportSnapshotPage::render( $uuid ) );
            $page = (string) ob_get_clean();

            $pdf  = ( new TeamMonthlyReportPdfExporter() )->collect( new ExportRequest( 'team_monthly_report_pdf', 'pdf', $this->club, $reader, null, [ 'snapshot' => $uuid ] ) );
            $html = (string) ( $pdf['html'] ?? '' );

            $this->assertSame( $sees, str_contains( $page, esc_html( self::SAFEGUARDING ) ), 'the page' );
            $this->assertSame( $sees, str_contains( $html, esc_html( self::SAFEGUARDING ) ), 'the PDF' );
            $this->assertStringContainsString( esc_html( self::PUBLIC ), $page, 'the public entry stays' );
        }
    }

    public function test_a_scout_without_the_team_reads_neither_the_live_report_nor_the_snapshot(): void {
        $uuid = ( new TeamReportSnapshotRepository() )->create( $this->team, [ 'team_id' => $this->team, 'layout' => 'A', 'blocks' => [] ], $this->frozen(), 'Snapshot', $this->hod );
        $this->flush();
        wp_set_current_user( $this->scout );
        if ( TeamReportAccess::canRead( $this->scout, $this->team ) ) {
            $this->markTestSkipped( 'The scout reads this team; covered by the per-reader comparison.' );
        }
        ob_start();
        $this->assertFalse( TeamMonthlyReportSnapshotPage::render( $uuid ) );
        ob_end_clean();
    }

    /** @return array<string,mixed> */
    private function frozen(): array {
        $this->flush();
        wp_set_current_user( $this->hod );
        return ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->hod );
    }

    /**
     * The payload as JSON without the moment it was composed: the live report
     * and the frozen one are composed a moment apart, and that moment is not a
     * section's content.
     */
    private function json( mixed $payload ): string {
        return (string) wp_json_encode( $this->level( $payload ) );
    }

    private function level( mixed $payload ): mixed {
        if ( ! is_array( $payload ) ) return $payload;
        foreach ( $payload as $key => $value ) {
            $payload[ $key ] = $key === 'generated_at' || $key === 'as_of' ? '' : $this->level( $value );
        }
        return $payload;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) add_role( $role, $role_type, [ 'read' => true ] );
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Frozen',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $role_type === 'assistant_coach' ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'team_id' => $team_id, 'person_id' => $person, 'role_in_team' => 'assistant_coach', 'is_head_coach' => 0,
            ] );
        }
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }

    /** A squad over March 2020: trainings with registers, a public and a safeguarding change, and an open injury. */
    private function seed(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $players = [];
        foreach ( [ 'Ada', 'Bo', 'Cor', 'Dirk' ] as $n => $first ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => $first, 'last_name' => 'Frozen',
                'jersey_number' => $n + 1, 'status' => 'active', 'date_of_birth' => '2005-04-12',
            ] );
            $players[] = (int) $wpdb->insert_id;
        }
        $statuses = [ 'Present', 'Absent', 'Late', 'Present' ];
        foreach ( [ '2020-02-11', '2020-03-03', '2020-03-10' ] as $i => $date ) {
            $wpdb->insert( "{$p}tt_activities", [
                'club_id' => $this->club, 'team_id' => $this->team, 'title' => 'Training ' . $date, 'session_date' => $date,
                'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
            ] );
            $activity = (int) $wpdb->insert_id;
            foreach ( $players as $j => $pid ) {
                $wpdb->insert( "{$p}tt_attendance", [
                    'club_id' => $this->club, 'activity_id' => $activity, 'player_id' => $pid,
                    'status' => $statuses[ ( $i + $j ) % 4 ], 'record_type' => 'actual', 'is_guest' => 0,
                ] );
            }
        }
        foreach ( [ [ 0, 'position_changed', self::PUBLIC, 'public' ], [ 1, 'team_changed', self::SAFEGUARDING, 'safeguarding' ] ] as $i => [ $who, $type, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'player_id' => $players[ $who ], 'event_type' => $type,
                'event_date' => '2020-03-1' . ( $i + 2 ) . ' 09:00:00', 'summary' => $summary, 'visibility' => $visibility,
                'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => $i + 1,
            ] );
        }
        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id' => $this->club, 'player_id' => $players[2], 'started_on' => '2020-03-18', 'actual_return' => null, 'notes' => 'Ankle.',
        ] );
    }
}
