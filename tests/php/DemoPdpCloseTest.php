<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\PdpGenerator;

/**
 * #3650 — a demo dossier is closed by its last conversation, not by a dice
 * roll.
 *
 * `closeFiles()` gave every current-season file a 30% chance of a signed-off
 * verdict and `status = completed`, without ever asking whether a single
 * conversation had been held. On the `small` preset — eight weeks, one
 * season, the season that has just started — that produced dossiers stamped
 * completed seven seconds after they were created, with all four
 * conversations still in the diary. A head of development reading the demo
 * saw "Actieve cyclus / Voltooid" on the same card and PDP coverage
 * reporting 64 of 64, while almost no conversation had taken place.
 *
 * The invariant pinned here holds whatever the clock says, which is the
 * point: no file carries a completed status or a verdict while one of its
 * conversations has `conducted_at IS NULL`. A test that instead asserted
 * "nothing closes in a one-season window" would be true today and wrong the
 * following June, when that season's cycle really has run its course.
 *
 * `$now` is pinned, per `DemoSeasonCadenceTest` — the generator's own
 * conducted/scheduled split still reads the wall clock, which is exactly the
 * disagreement being tested.
 */
final class DemoPdpCloseTest extends WP_UnitTestCase {

    /** Mid-September, early in the 2026/2027 season. */
    private const NOW = 1789430400; // 2026-09-15 00:00:00 UTC

    /** @var list<object> */
    private array $teams = [];

    /** @var list<object> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        // Deterministic draws: `closeFiles()` decides on `mt_rand()`, and a
        // test whose coverage depends on the seed is one that passes by luck.
        mt_srand( 36503650 );

        foreach ( [ 'U11', 'U12', 'U13', 'U14' ] as $age ) {
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [
                'club_id'   => 1,
                'name'      => 'PDP Close ' . $age,
                'age_group' => $age,
            ] );
            $team = (object) [
                'id'                 => (int) $wpdb->insert_id,
                'age_group'          => $age,
                'head_coach_user_id' => 0,
            ];
            $this->teams[] = $team;

            foreach ( [ 'rising_star', 'steady_solid', 'new_arrival', DemoRoster::ARCHETYPE_DEPARTED ] as $archetype ) {
                foreach ( [ 1, 2 ] as $n ) {
                    $wpdb->insert( "{$wpdb->prefix}tt_players", [
                        'club_id'     => 1,
                        'first_name'  => 'Dossier',
                        'last_name'   => sprintf( '%s %s %d', $age, $archetype, $n ),
                        'team_id'     => $team->id,
                        'date_joined' => '2021-01-01',
                        'wp_user_id'  => null,
                    ] );
                    $this->players[] = (object) [
                        'id'          => (int) $wpdb->insert_id,
                        'team_id'     => $team->id,
                        'archetype'   => $archetype,
                        'date_joined' => '2021-01-01',
                    ];
                }
            }
        }
    }

    public function tear_down(): void {
        mt_srand();
        parent::tear_down();
    }

    private function generate( int $weeks ): void {
        $calendar = new DemoCalendar( $weeks, self::NOW );

        ( new PdpGenerator(
            new DemoBatchRegistry( 'test-3650-' . $weeks ),
            $this->players,
            $this->teams,
            [ 'hjo' => 1, 'admin' => 1 ],
            $weeks,
            'en_US',
            $calendar,
            new DemoRoster( $calendar, $this->teams, $this->players )
        ) )->generate();
    }

    /**
     * Files that carry a conversation nobody has held yet.
     *
     * @return list<int>
     */
    private function filesWithAnOpenConversation(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT pdp_file_id FROM {$wpdb->prefix}tt_pdp_conversations
              WHERE conducted_at IS NULL"
        );
        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /** @return list<int> */
    private function fileIdsWithStatus( string $status ): array {
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_pdp_files WHERE status = %s",
            $status
        ) );
        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /** @return list<int> */
    private function fileIdsWithAVerdict(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT pdp_file_id FROM {$wpdb->prefix}tt_pdp_verdicts"
        );
        return array_values( array_map( 'intval', (array) $ids ) );
    }

    // ----- One season: the reported bug -----

    public function test_a_one_season_window_closes_no_dossier_with_an_unheld_conversation(): void {
        $this->generate( 8 );

        $open = $this->filesWithAnOpenConversation();
        $this->assertNotSame( [], $open, 'a season that has just started has conversations still to come' );

        $this->assertSame(
            [],
            array_values( array_intersect( $this->fileIdsWithStatus( 'completed' ), $open ) ),
            'a dossier cannot be completed while a conversation is still in the diary'
        );
        $this->assertSame(
            [],
            array_values( array_intersect( $this->fileIdsWithAVerdict(), $open ) ),
            'and it cannot carry a signed-off end-of-season verdict either'
        );
    }

    public function test_a_one_season_window_still_builds_the_open_cycle(): void {
        $this->generate( 8 );
        global $wpdb;

        $files = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_pdp_files" );
        $talks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_pdp_conversations" );

        $this->assertGreaterThan( 0, $files, 'the dossiers themselves are unaffected by #3650' );
        $this->assertSame( $files * DemoCalendar::ROUNDS_PER_SEASON, $talks, 'four conversations a dossier' );
    }

    // ----- Several seasons: #3402 unchanged -----

    public function test_every_prior_season_dossier_still_closes_with_a_verdict(): void {
        $this->generate( 156 );
        global $wpdb;

        $current = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_seasons WHERE is_current = 1"
        );
        $this->assertGreaterThan( 0, $current );

        $prior = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}tt_pdp_files WHERE season_id <> %d",
            $current
        ) );
        $this->assertNotSame( [], (array) $prior, 'a three-year window has finished seasons in it' );

        $verdicts = $this->fileIdsWithAVerdict();
        foreach ( (array) $prior as $row ) {
            $file_id = (int) $row->id;
            $this->assertSame( 'completed', (string) $row->status, 'a finished season carries no open dossier (#3402)' );
            $this->assertContains( $file_id, $verdicts, 'and every one of them is signed off' );
        }
    }

    public function test_the_invariant_holds_across_several_seasons_too(): void {
        $this->generate( 156 );

        $open = $this->filesWithAnOpenConversation();

        $this->assertSame(
            [],
            array_values( array_intersect( $this->fileIdsWithStatus( 'completed' ), $open ) )
        );
        $this->assertSame(
            [],
            array_values( array_intersect( $this->fileIdsWithAVerdict(), $open ) )
        );
    }
}
