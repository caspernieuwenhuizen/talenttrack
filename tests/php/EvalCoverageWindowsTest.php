<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Analytics\EvalWindowsRepository;

/**
 * #3802 — green must be earned.
 *
 * `eval-coverage` reported `gap_count: 0` for all 64 players while the
 * last evaluation anywhere was seven weeks old. Not a miscount: with no
 * windows configured, the loop over windows never runs, so the counts are
 * **structurally** zero. The report gave a clean bill of health because it
 * had been asked nothing.
 *
 * Three defects, all pinned here: the silent zero, a `team_id` that was
 * accepted and dropped, and a feature that nothing ever seeded.
 */
final class EvalCoverageWindowsTest extends WP_UnitTestCase {

    private function clearWindows(): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_config', [ 'config_key' => EvalWindowsRepository::CONFIG_KEY ] );
    }

    public function set_up(): void {
        parent::set_up();
        $this->clearWindows();
    }

    private function makeTeamWithPlayer( string $team, string $last ): array {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => $team ] );
        $team_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team_id,
            'first_name' => 'Cov',
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return [ $team_id, (int) $wpdb->insert_id ];
    }

    // -----------------------------------------------------------------
    // the seed
    // -----------------------------------------------------------------

    public function test_windows_are_seeded_on_first_read(): void {
        $repo = new EvalWindowsRepository();
        $this->assertSame( [], $repo->all(), 'nothing configured to begin with' );

        $seeded = $repo->seedDefaultsIfAbsent();

        $this->assertCount( 4, $seeded, 'a season seeds four rounds' );
        foreach ( $seeded as $w ) {
            $this->assertNotSame( '', $w['name'] );
            $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $w['start'] );
            $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $w['end'] );
            $this->assertLessThan( $w['end'], $w['start'], 'each window runs forwards' );
        }
    }

    public function test_seeding_is_idempotent(): void {
        $repo  = new EvalWindowsRepository();
        $first = $repo->seedDefaultsIfAbsent();

        $this->assertSame( $first, $repo->seedDefaultsIfAbsent(), 'a second read seeds nothing new' );
    }

    /**
     * The case that would be a bug rather than a feature: an administrator
     * who deliberately cleared the windows must not find four of them back.
     */
    public function test_a_deliberately_empty_list_is_left_alone(): void {
        ( new ConfigService() )->setJson( EvalWindowsRepository::CONFIG_KEY, [] );

        $this->assertSame(
            [],
            ( new EvalWindowsRepository() )->seedDefaultsIfAbsent(),
            'an empty list is a decision, not an absence'
        );
    }

    // -----------------------------------------------------------------
    // the silent zero
    // -----------------------------------------------------------------

    public function test_coverage_reports_whether_it_was_configured(): void {
        $this->makeTeamWithPlayer( 'Cov Team', 'One' );

        $coverage = ( new EvalCoverageService() )->coverage();

        $this->assertArrayHasKey(
            'configured',
            $coverage,
            'a caller must be able to tell a measured zero from an unasked question'
        );
        $this->assertTrue( $coverage['configured'], 'the seed ran, so the report is now answerable' );
    }

    // -----------------------------------------------------------------
    // the ignored filter
    // -----------------------------------------------------------------

    public function test_the_team_filter_narrows_the_matrix(): void {
        [ $mine ]  = $this->makeTeamWithPlayer( 'Cov Mine', 'Mine' );
        $this->makeTeamWithPlayer( 'Cov Theirs', 'Theirs' );

        $service = new EvalCoverageService();
        $all     = $service->coverage();
        $one     = $service->coverage( 0, $mine );

        $this->assertGreaterThan(
            $one['total_players'],
            $all['total_players'],
            'the unfiltered matrix must be wider than the filtered one'
        );
        $this->assertSame( 1, $one['total_players'], 'team_id narrows to that team' );

        foreach ( $one['teams'] as $team ) {
            $this->assertSame( $mine, (int) $team['team_id'], 'no other team may appear' );
        }
    }

    public function test_an_unknown_team_returns_nothing_rather_than_everything(): void {
        $this->makeTeamWithPlayer( 'Cov Team', 'One' );

        $coverage = ( new EvalCoverageService() )->coverage( 0, 99999 );

        $this->assertSame(
            0,
            $coverage['total_players'],
            'a team that does not exist must not fall back to every team — that was the bug'
        );
    }
}
