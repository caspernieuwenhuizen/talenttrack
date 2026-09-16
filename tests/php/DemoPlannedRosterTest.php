<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\Generators\ActivityGenerator;

/**
 * #3484 — demo data has to contain both kinds of attendance row.
 *
 * `tt_attendance` models two things: the squad a coach *planned*
 * (`record_type = 'expected'`) and the register they *took*
 * (`record_type = 'actual'`). A generated academy contained only the second
 * — thirteen thousand actual rows and no planned ones.
 *
 * That is why the ten defects of one week (#3390, #3443 ×3, #3444, #3445,
 * #3446, #3451, #3456 and the three write defects inside #3451) were none of
 * them reproducible on the demo install: every one is a planned roster read
 * as a recorded register, and there were no planned rosters to misread. They
 * were found by reading source or reported against real academy data, which
 * is how several survived for years behind a green suite.
 *
 * What is pinned here is the *shape* of the generated data, not a count: the
 * three states the completeness and attendance surfaces have to handle must
 * each occur at least once.
 */
final class DemoPlannedRosterTest extends WP_UnitTestCase {

    /** Mid-September, early in the 2026/2027 season — pinned, per DemoSeasonCadenceTest. */
    private const NOW = 1789430400;

    private int $team_id = 0;

    /** @var list<object> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [
            'club_id'   => 1,
            'name'      => 'Planned Roster U13',
            'age_group' => 'U13',
        ] );
        $this->team_id = (int) $wpdb->insert_id;

        for ( $i = 1; $i <= 6; $i++ ) {
            $wpdb->insert( "{$wpdb->prefix}tt_players", [
                'club_id'     => 1,
                'first_name'  => 'Squad',
                'last_name'   => 'Member ' . $i,
                'team_id'     => $this->team_id,
                'date_joined' => '2024-08-01',
                'wp_user_id'  => null,
            ] );
        }

        $this->players = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE team_id = %d",
            $this->team_id
        ) );
    }

    private function generate(): void {
        global $wpdb;

        $teams    = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $this->team_id
        ) );
        $calendar = new DemoCalendar( 8, self::NOW );

        ( new ActivityGenerator(
            new DemoBatchRegistry( 'test-3484' ),
            $teams,
            $this->players,
            8,
            'en_US',
            $calendar,
            new DemoRoster( $calendar, $teams, $this->players )
        ) )->generate();
    }

    /** @return array<string,int> record_type => row count */
    private function countsByKind(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT record_type, COUNT(*) AS n FROM {$wpdb->prefix}tt_attendance GROUP BY record_type"
        );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row->record_type ] = (int) $row->n;
        }
        return $out;
    }

    public function test_a_generated_academy_contains_planned_rows(): void {
        $this->generate();
        $counts = $this->countsByKind();

        $this->assertGreaterThan(
            0,
            $counts['expected'] ?? 0,
            'Demo data must contain planned rosters, or the expected/actual bugs stay unreproducible.'
        );
        $this->assertGreaterThan( 0, $counts['actual'] ?? 0, 'And still contain registers.' );
    }

    /** The state every one of the ten bugs mishandles: both kinds, same player, same activity. */
    public function test_a_completed_activity_carries_both_kinds_for_the_same_player(): void {
        global $wpdb;
        $this->generate();

        $both = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT activity_id, player_id
                  FROM {$wpdb->prefix}tt_attendance
                 GROUP BY activity_id, player_id
                HAVING SUM( record_type = 'expected' ) > 0
                   AND SUM( record_type = 'actual' )   > 0
             ) x"
        );

        $this->assertGreaterThan( 0, $both );
    }

    /** A planned-but-not-yet-played squad — what #3390 read as "Present". */
    public function test_a_future_activity_is_planned_and_not_registered(): void {
        global $wpdb;
        $this->generate();

        $future_planned_only = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a
              WHERE a.session_date > %s
                AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance p
                              WHERE p.activity_id = a.id AND p.record_type = 'expected' )
                AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance r
                                  WHERE r.activity_id = a.id AND r.record_type = 'actual' )",
            gmdate( 'Y-m-d', self::NOW )
        ) );

        $this->assertGreaterThan( 0, $future_planned_only );
    }

    /** A past activity nobody registered — the case epic #3442's surfaces exist for. */
    public function test_some_past_activity_has_a_plan_and_no_register(): void {
        global $wpdb;
        $this->generate();

        $unregistered = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_activities a
              WHERE a.session_date <= %s
                AND EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance p
                              WHERE p.activity_id = a.id AND p.record_type = 'expected' )
                AND NOT EXISTS ( SELECT 1 FROM {$wpdb->prefix}tt_attendance r
                                  WHERE r.activity_id = a.id AND r.record_type = 'actual' )",
            gmdate( 'Y-m-d', self::NOW )
        ) );

        $this->assertGreaterThan( 0, $unregistered );
    }

    /**
     * Plan statuses use the real vocabulary. "A Maybe pre-selecting as a
     * recorded Excused" was one of #3443's three bugs, and an all-Present
     * plan cannot show it.
     */
    public function test_planned_rows_use_more_than_one_status(): void {
        global $wpdb;
        $this->generate();

        $statuses = $wpdb->get_col(
            "SELECT DISTINCT status FROM {$wpdb->prefix}tt_attendance WHERE record_type = 'expected'"
        );

        $this->assertGreaterThan(
            1,
            count( (array) $statuses ),
            'A plan of nothing but Present cannot demonstrate the plan vocabulary.'
        );
        foreach ( (array) $statuses as $status ) {
            $this->assertContains(
                (string) $status,
                [ AttendanceStatus::PRESENT, AttendanceStatus::ABSENT, AttendanceStatus::EXCUSED ],
                'Planned rows must use the plannedStatusMap() vocabulary.'
            );
        }
    }
}
