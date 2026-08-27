<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Recipients\TeamHeadCoachLookup;
use TT\Modules\Workflow\Resolvers\TeamHeadCoachResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * #2719 — the shared head-coach lookup.
 *
 * Three places used to answer "who is this team's head coach" with their
 * own copy of the same four-table join: the workflow engine's assignee
 * resolver and `headCoachesByTeam()` on two Alerts base classes. This
 * covers the one implementation they now share.
 *
 * The point of the refactor is that behaviour does not change, so the
 * weight here sits on two things rather than on happy-path resolution:
 *
 *   - the batched and single-team entry points agree, on every fixture,
 *     including the ones with no answer. They are the two shapes the two
 *     engines call, and a refactor that made them disagree would misroute
 *     silently — a task to nobody, or an alert to the wrong coach.
 *   - the tie-break is still the lowest `tt_team_people.id`. Both previous
 *     implementations picked it, one via `ORDER BY tp.id ASC LIMIT 1` and
 *     the other via `MIN(tp2.id)`, and they had to agree: an alert
 *     occurrence that flips recipient between sweeps duplicates itself
 *     under two dedupe keys.
 *
 * A wrong answer here is a `CLAUDE.md` §1 privacy failure — a named
 * minor's data routed to somebody who should not see it — which is why
 * the negative cases are here at all.
 */
final class TeamHeadCoachLookupTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
    }

    // ── resolution ─────────────────────────────────────────────────────

    public function test_resolves_the_head_coach_of_a_team(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $this->insertTeam( 'U14' );
        $this->assignHeadCoach( $team, $coach );

        $this->assertSame( $coach, TeamHeadCoachLookup::forTeam( $team ) );
        $this->assertSame( [ $team => $coach ], TeamHeadCoachLookup::forTeams( [ $team ] ) );
    }

    public function test_a_team_with_no_head_coach_resolves_to_nothing(): void {
        $team = $this->insertTeam( 'U16 unstaffed' );

        // Absent from the batch result rather than present with a zero, so
        // a caller can tell "nobody to tell" from "user 0".
        $this->assertNull( TeamHeadCoachLookup::forTeam( $team ) );
        $this->assertSame( [], TeamHeadCoachLookup::forTeams( [ $team ] ) );
    }

    public function test_a_head_coach_with_no_wp_account_resolves_to_nothing(): void {
        $team = $this->insertTeam( 'U13 volunteer' );
        $this->assignHeadCoach( $team, 0 );

        $this->assertNull( TeamHeadCoachLookup::forTeam( $team ) );
        $this->assertSame( [], TeamHeadCoachLookup::forTeams( [ $team ] ) );
    }

    public function test_an_assistant_coach_is_not_a_head_coach(): void {
        $assistant = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team      = $this->insertTeam( 'U15 assistant only' );
        $this->assignRole( $team, $assistant, 'assistant_coach' );

        $this->assertNull( TeamHeadCoachLookup::forTeam( $team ) );
    }

    public function test_a_coach_of_another_team_is_not_returned(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $mine  = $this->insertTeam( 'U14 mine' );
        $other = $this->insertTeam( 'U14 other' );
        $this->assignHeadCoach( $other, $coach );

        $this->assertNull( TeamHeadCoachLookup::forTeam( $mine ) );
        $this->assertSame( [ $other => $coach ], TeamHeadCoachLookup::forTeams( [ $mine, $other ] ) );
    }

    // ── batching ───────────────────────────────────────────────────────

    public function test_batched_and_single_lookups_agree_across_a_mixed_academy(): void {
        $a_coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $b_coach = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $with_a  = $this->insertTeam( 'U12' );
        $with_b  = $this->insertTeam( 'U13' );
        $without = $this->insertTeam( 'U14 unstaffed' );

        $this->assignHeadCoach( $with_a, $a_coach );
        $this->assignHeadCoach( $with_b, $b_coach );

        $teams   = [ $with_a, $with_b, $without ];
        $batched = TeamHeadCoachLookup::forTeams( $teams );

        // The assertion that actually guards the refactor: the two entry
        // points the two engines call must not drift apart.
        foreach ( $teams as $team ) {
            $this->assertSame(
                $batched[ $team ] ?? null,
                TeamHeadCoachLookup::forTeam( $team ),
                "batched and single lookup disagree for team {$team}"
            );
        }

        $this->assertSame( [ $with_a => $a_coach, $with_b => $b_coach ], $batched );
    }

    public function test_an_empty_or_junk_team_list_resolves_to_nothing(): void {
        $this->assertSame( [], TeamHeadCoachLookup::forTeams( [] ) );
        $this->assertSame( [], TeamHeadCoachLookup::forTeams( [ 0, -1 ] ) );
        $this->assertNull( TeamHeadCoachLookup::forTeam( 0 ) );
        $this->assertNull( TeamHeadCoachLookup::forTeam( -5 ) );
    }

    public function test_a_repeated_team_id_does_not_duplicate_the_answer(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $this->insertTeam( 'U14' );
        $this->assignHeadCoach( $team, $coach );

        $this->assertSame( [ $team => $coach ], TeamHeadCoachLookup::forTeams( [ $team, $team, $team ] ) );
    }

    // ── tie-break ──────────────────────────────────────────────────────

    public function test_two_head_coaches_resolve_to_the_earliest_assignment(): void {
        $first  = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam( 'U17 double-staffed' );

        $this->assignHeadCoach( $team, $first );
        $this->assignHeadCoach( $team, $second );

        // Stable, and stable the same way through both entry points. If
        // these disagreed, an alert occurrence would flip recipient from
        // sweep to sweep and duplicate under two dedupe keys.
        $this->assertSame( $first, TeamHeadCoachLookup::forTeam( $team ) );
        $this->assertSame( [ $team => $first ], TeamHeadCoachLookup::forTeams( [ $team ] ) );
    }

    // ── the workflow adapter still behaves as it did ───────────────────

    public function test_workflow_resolver_returns_the_same_answer_as_the_lookup(): void {
        $coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team  = $this->insertTeam( 'U14' );
        $this->assignHeadCoach( $team, $coach );

        $resolved = ( new TeamHeadCoachResolver() )->resolve( new TaskContext( null, $team ) );

        $this->assertSame( [ $coach ], $resolved );
        $this->assertSame( [ TeamHeadCoachLookup::forTeam( $team ) ], $resolved );
    }

    public function test_workflow_resolver_returns_an_empty_list_when_nobody_resolves(): void {
        $team = $this->insertTeam( 'U16 unstaffed' );

        // Empty array, not [0] and not [null] — the engine reads this as
        // "no assignee found" and skips creating an orphan task.
        $this->assertSame( [], ( new TeamHeadCoachResolver() )->resolve( new TaskContext( null, $team ) ) );
    }

    public function test_workflow_resolver_with_no_team_on_the_context_resolves_to_nothing(): void {
        $this->assertSame( [], ( new TeamHeadCoachResolver() )->resolve( new TaskContext() ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function assignHeadCoach( int $team_id, int $user_id ): void {
        $this->assignRole( $team_id, $user_id, 'head_coach' );
    }

    private function assignRole( int $team_id, int $user_id, string $role_key ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            $role_key
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => $role_key,
                'label'    => ucwords( str_replace( '_', ' ', $role_key ) ),
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Test',
            'last_name'  => ucfirst( $role_key ),
            'wp_user_id' => $user_id,
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
        ] );
    }
}
