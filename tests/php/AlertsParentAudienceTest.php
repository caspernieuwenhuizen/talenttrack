<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\AlertEvaluator;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Definitions\EvaluationSharedWithFamilyAlert;
use TT\Modules\Alerts\Definitions\GoalUpdatedForMyChildAlert;
use TT\Modules\Alerts\Definitions\PlayerNotEvaluatedAlert;
use TT\Modules\Alerts\Domain\AlertAudience;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;

/**
 * #3795 / #3803 — the parent audience.
 *
 * Two failures are pinned here because both were live before this shipped
 * and both look like the feature working.
 *
 * A parent's alert preferences listed twenty-one conditions, every one of
 * them written for staff, while `GET /alerts` returned `{"data":[]}` — a
 * complete-looking screen attached to nothing. And the first draft of the
 * fix filtered that screen by capability, which would have removed the four
 * definitions the family actually asked for, because staff are who acts on
 * them.
 *
 * The third group is the one that matters most: these are minors' records,
 * so "a guardian of player A sees nothing about player B" is tested from
 * both ends — the definition's recipient resolution AND the evaluator's
 * independent re-check, because either alone would be a single point of
 * failure for a cross-family leak.
 */
final class AlertsParentAudienceTest extends WP_UnitTestCase {

    /** Every definition a family may switch on (#3795's locked type list). */
    private const FAMILY_KEYS = [
        'evaluations.player_not_evaluated',
        'evaluations.saved_not_shared',
        'evaluations.shared_with_family',
        'goals.past_target_date',
        'goals.updated_for_my_child',
        'pdp.no_conversation_this_cycle',
    ];

    /** Definitions that exist only for a family — never on a staff matrix. */
    private const FAMILY_ONLY_KEYS = [
        'evaluations.shared_with_family',
        'goals.updated_for_my_child',
    ];

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var int */
    private $staff;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->p = $wpdb->prefix;
        AlertPreferencesRepository::flushTableCache();
        AlertOccurrencesRepository::flushTableCache();
        AlertRegistry::flush();
        // Static, and the guardian link changes within a test.
        AlertAudience::flush();

        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );
        $wpdb->query( "DELETE FROM {$this->p}tt_player_parents" );

        $this->staff = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        AlertAudience::flush();
        AlertRegistry::flush();
        parent::tear_down();
    }

    // ── the matrix a parent is shown ───────────────────────────────────

    public function test_a_parent_matrix_lists_the_family_types_and_nothing_else(): void {
        $player = $this->insertPlayer( $this->insertTeam( 'U13' ), $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );

        $keys = array_keys( ( new AlertPolicyResolver() )->matrixFor( $parent ) );
        sort( $keys );

        $this->assertSame(
            self::FAMILY_KEYS,
            $keys,
            'a parent sees the four reused definitions plus the two new ones, and no staff-only condition'
        );
    }

    /**
     * The regression the capability filter would have caused. All four of
     * these declare a staff capability; a parent holds none of them.
     */
    public function test_the_reused_definitions_survive_for_a_parent_despite_their_staff_capability(): void {
        $player = $this->insertPlayer( $this->insertTeam( 'U13' ), $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );

        $matrix = ( new AlertPolicyResolver() )->matrixFor( $parent );

        foreach ( [ 'evaluations.player_not_evaluated', 'evaluations.saved_not_shared', 'goals.past_target_date', 'pdp.no_conversation_this_cycle' ] as $key ) {
            $this->assertArrayHasKey( $key, $matrix, $key . ' is a family type even though staff hold the capability to fix it' );
            $this->assertNotSame( '', $matrix[ $key ]['definition']->capRequired(), 'fixture sanity: this definition still declares a staff capability' );
        }
    }

    public function test_a_staff_matrix_keeps_every_staff_definition(): void {
        $matrix = ( new AlertPolicyResolver() )->matrixFor( $this->staff );

        $staff_keys = [];
        foreach ( AlertRegistry::all() as $key => $definition ) {
            if ( in_array( $key, self::FAMILY_ONLY_KEYS, true ) ) continue;
            $staff_keys[] = $key;
        }
        $this->assertNotEmpty( $staff_keys, 'fixture sanity: the catalogue is not empty' );

        foreach ( $staff_keys as $key ) {
            $this->assertArrayHasKey( $key, $matrix, $key . ' disappeared from a staff matrix' );
        }
    }

    public function test_the_family_only_definitions_never_reach_a_staff_matrix(): void {
        $matrix = ( new AlertPolicyResolver() )->matrixFor( $this->staff );

        foreach ( self::FAMILY_ONLY_KEYS as $key ) {
            $this->assertArrayNotHasKey( $key, $matrix );
        }
    }

    // ── who the occurrences go to ──────────────────────────────────────

    public function test_a_linked_parent_receives_an_occurrence_about_their_own_child(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );

        $out = ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) );

        $family = $this->forRecipient( $out, $parent );
        $this->assertCount( 1, $family, 'the reporter\'s {"data":[]} is what this asserts against' );
        $this->assertSame( AlertAudience::PARENT, $family[0]->audience );
        $this->assertSame( $player, $family[0]->playerId );
        $this->assertNotSame( '', $family[0]->title() );
    }

    /**
     * Two parents, two children. The cross pair must be empty in both
     * directions — a leak here is a named minor's record reaching another
     * family, which is the failure this whole audience notion exists to
     * make structurally impossible.
     */
    public function test_a_parent_sees_nothing_about_a_child_they_are_not_linked_to(): void {
        $team    = $this->insertTeam( 'U13' );
        $childA  = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $childB  = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $parentA = $this->linkParent( $childA );
        $parentB = $this->linkParent( $childB );

        $out = ( new PlayerNotEvaluatedAlert() )->evaluate( new AlertContext( $this->club ) );

        foreach ( $this->forRecipient( $out, $parentA ) as $occ ) {
            $this->assertSame( $childA, $occ->playerId, 'parent A was told about a child that is not theirs' );
        }
        foreach ( $this->forRecipient( $out, $parentB ) as $occ ) {
            $this->assertSame( $childB, $occ->playerId, 'parent B was told about a child that is not theirs' );
        }
        $this->assertCount( 1, $this->forRecipient( $out, $parentA ) );
        $this->assertCount( 1, $this->forRecipient( $out, $parentB ) );
    }

    /**
     * The evaluator's independent re-check. A definition that resolved the
     * wrong guardian — or a link removed between two sweeps — must not get
     * a row written, so the gate is tested without the definition's own
     * resolution in the way.
     */
    public function test_the_evaluator_refuses_a_parent_occurrence_for_an_unlinked_child(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );

        $alert = new PlayerNotEvaluatedAlert();
        $repo  = new AlertOccurrencesRepository();

        ( new AlertEvaluator() )->run( $alert, new AlertContext( $this->club ) );
        $this->assertSame( 1, $this->openCountFor( $repo, $parent, $alert->key() ) );

        // The link goes away; the next sweep must stop delivering.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$this->p}tt_player_parents" );
        AlertAudience::flush();

        ( new AlertEvaluator() )->run( $alert, new AlertContext( $this->club ) );
        $this->assertSame( 0, $this->openCountFor( $repo, $parent, $alert->key() ) );
    }

    // ── the share gate ─────────────────────────────────────────────────

    /**
     * The privacy requirement, stated as a test: a family must not learn
     * that an assessment of their child exists before staff released it.
     */
    public function test_shared_with_family_does_not_fire_on_a_save_that_was_never_shared(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->linkParent( $player );
        $this->insertEvaluation( $player, $this->daysAgo( 2 ), '' );

        $this->assertSame(
            [],
            ( new EvaluationSharedWithFamilyAlert() )->evaluate( new AlertContext( $this->club ) ),
            'an evaluation with no player feedback has not been shared and must stay invisible to the family'
        );
    }

    public function test_shared_with_family_fires_once_feedback_has_been_released(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );
        $this->insertEvaluation( $player, $this->daysAgo( 2 ), 'Strong week, keep the scanning habit going.' );

        $out = ( new EvaluationSharedWithFamilyAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $parent, $out[0]->recipientUserId );
        $this->assertSame( AlertAudience::PARENT, $out[0]->audience );
        $this->assertSame( 'evaluation', $out[0]->subjectType );
    }

    /** Whitespace is not feedback. */
    public function test_shared_with_family_ignores_whitespace_only_feedback(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->linkParent( $player );
        $this->insertEvaluation( $player, $this->daysAgo( 2 ), '   ' );

        $this->assertSame( [], ( new EvaluationSharedWithFamilyAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_shared_with_family_tells_the_head_coach_nothing(): void {
        $team   = $this->insertTeam( 'U13' );
        $this->assignHeadCoach( $team, $this->staff );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->linkParent( $player );
        $this->insertEvaluation( $player, $this->daysAgo( 2 ), 'Shared.' );

        $out = ( new EvaluationSharedWithFamilyAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertSame( [], $this->forRecipient( $out, $this->staff ) );
    }

    // ── the goal update ────────────────────────────────────────────────

    public function test_a_goal_nobody_has_touched_since_writing_it_is_not_an_update(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->linkParent( $player );
        $this->insertGoal( $player, 'Scan before receiving', false );

        $this->assertSame( [], ( new GoalUpdatedForMyChildAlert() )->evaluate( new AlertContext( $this->club ) ) );
    }

    public function test_a_goal_changed_after_it_was_written_reaches_the_family(): void {
        $team   = $this->insertTeam( 'U13' );
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $parent = $this->linkParent( $player );
        $this->insertGoal( $player, 'Scan before receiving', true );

        $out = ( new GoalUpdatedForMyChildAlert() )->evaluate( new AlertContext( $this->club ) );

        $this->assertCount( 1, $out );
        $this->assertSame( $parent, $out[0]->recipientUserId );
        $this->assertSame( 'goal', $out[0]->subjectType );
        $this->assertSame( AlertAudience::PARENT, $out[0]->audience );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /**
     * @param list<\TT\Modules\Alerts\Domain\AlertOccurrence> $out
     * @return list<\TT\Modules\Alerts\Domain\AlertOccurrence>
     */
    private function forRecipient( array $out, int $userId ): array {
        $hits = [];
        foreach ( $out as $occ ) {
            if ( $occ->recipientUserId === $userId ) $hits[] = $occ;
        }
        return $hits;
    }

    private function openCountFor( AlertOccurrencesRepository $repo, int $userId, string $key ): int {
        $n = 0;
        foreach ( $repo->openForUser( $userId, 100 ) as $row ) {
            if ( (string) ( $row->alert_key ?? '' ) === $key ) $n++;
        }
        return $n;
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $date_joined ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'     => $this->club,
            'team_id'     => $team_id,
            'first_name'  => 'Family',
            'last_name'   => 'Fixture',
            'status'      => 'active',
            'date_joined' => $date_joined,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A WP user linked to the player through `tt_player_parents`, which is
     * the one live source of the parent → child link. Deliberately not via
     * `tt_players.guardian_email`: that column is an invite hint and never
     * a runtime linkage, and a test that seeded it would pass against an
     * implementation that reads the wrong thing.
     */
    private function linkParent( int $player_id ): int {
        global $wpdb;

        $user = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$this->p}tt_player_parents", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'parent_user_id' => $user,
            'is_primary'     => 1,
        ] );
        AlertAudience::flush();
        return (int) $user;
    }

    private function insertEvaluation( int $player_id, string $date, string $feedback ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_evaluations", [
            'club_id'         => $this->club,
            'player_id'       => $player_id,
            'coach_id'        => $this->staff,
            'eval_date'       => $date,
            'player_feedback' => $feedback,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * `$updated` forces `updated_at` past `created_at`, which is what the
     * definition reads as "somebody changed this after writing it".
     */
    private function insertGoal( int $player_id, string $title, bool $updated ): int {
        global $wpdb;

        $created = gmdate( 'Y-m-d H:i:s', strtotime( '-3 days', (int) current_time( 'timestamp' ) ) );
        $wpdb->insert( "{$this->p}tt_goals", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'title'      => $title,
            'status'     => 'in_progress',
            'created_by' => $this->staff,
            'created_at' => $created,
            'updated_at' => $updated
                ? gmdate( 'Y-m-d H:i:s', strtotime( '-1 day', (int) current_time( 'timestamp' ) ) )
                : $created,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function assignHeadCoach( int $team_id, int $user_id ): void {
        global $wpdb;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->p}tt_functional_roles WHERE role_key = %s LIMIT 1",
            'head_coach'
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$this->p}tt_functional_roles", [
                'club_id'  => $this->club,
                'role_key' => 'head_coach',
                'label'    => 'Head Coach',
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
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

    private function daysAgo( int $days ): string {
        return gmdate( 'Y-m-d', strtotime( "-{$days} days", (int) current_time( 'timestamp' ) ) );
    }
}
