<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Frontend\FrontendAlertsInboxView;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Services\AlertOversight;
use TT\Shared\Frontend\Components\AlertChip;

/**
 * #2633 — the inline surfaces.
 *
 * Four properties carry the whole wave, and each is the kind of thing that
 * degrades silently rather than breaking loudly:
 *
 *  1. **Batching.** A chip on a fifty-row list must cost ONE query. A
 *     regression here does not fail; it just makes the activities list
 *     slower every release until somebody profiles it.
 *  2. **Self-resolution reaching the chip.** Fixing the record must remove
 *     the chip. If a resolved occurrence keeps its chip, the inline surface
 *     is lying about the record's current state, which is the one thing it
 *     exists to tell the truth about.
 *  3. **Epic decision 12 on the player record.** Open occurrences surface;
 *     resolved ones do not; nothing is written to the journey.
 *  4. **The oversight roll-up.** Cap-scoped to the teams the viewer
 *     oversees, and it writes nothing — decision 7 deliberately sends no
 *     occurrence to a Head of Development, and the moment this aggregate
 *     starts fanning rows of its own that decision has been reversed by
 *     accident.
 */
final class AlertsInlineSurfacesTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var AlertOccurrencesRepository */
    private $repo;

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->repo = new AlertOccurrencesRepository();

        // DELETE, not TRUNCATE: TRUNCATE forces an implicit commit, which
        // breaks the transaction WP_UnitTestCase rolls back between tests.
        $wpdb->query( "DELETE FROM {$this->p}tt_alert_occurrences" );

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );
        AlertChip::flush();
    }

    public function tear_down(): void {
        AlertChip::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // Batching

    public function test_a_fifty_row_list_costs_one_extra_query(): void {
        global $wpdb;

        $ids = range( 1, 50 );
        foreach ( $ids as $id ) {
            $this->seed( 'activity', $id );
        }

        // Warm the two per-request lookups a prime performs before its read:
        // the table-existence check and the module off-switch. Both are
        // cached for the life of the request in production, so counting them
        // here would measure a cost the real page never pays per list.
        $this->repo->tableExists();
        AlertChip::moduleEnabled();
        AlertChip::flush();

        $before = $wpdb->num_queries;
        AlertChip::prime( 'activity', $ids );
        $primed = $wpdb->num_queries - $before;

        $this->assertSame( 1, $primed, 'priming a whole list must be a single batched read' );

        // Render one chip outside the measurement. The first chip on a page
        // resolves the dashboard URL and the cross-view gate, both of which
        // are per-request lookups the real page also pays exactly once. What
        // is under test is the PER-ROW cost, so the shared setup is warmed
        // first and the remaining 49 rows are what gets counted.
        $this->assertNotSame( '', AlertChip::html( 'activity', $ids[0] ) );

        $before = $wpdb->num_queries;
        $chips  = 0;
        foreach ( array_slice( $ids, 1 ) as $id ) {
            if ( AlertChip::html( 'activity', $id ) !== '' ) $chips++;
        }

        $this->assertSame( 49, $chips, 'every seeded row carries a chip' );
        $this->assertSame(
            0,
            $wpdb->num_queries - $before,
            'a primed list must render its chips without touching the database again'
        );
    }

    public function test_a_subject_with_no_alert_is_cached_as_a_real_answer(): void {
        global $wpdb;

        $this->repo->tableExists();
        AlertChip::moduleEnabled();
        // Warm the per-request lookups a chip performs (dashboard URL,
        // cross-view gate) on a subject that HAS one, so what the counter
        // sees below is only the cost of the clean-record answer.
        $this->seed( 'activity', 6 );
        AlertChip::prime( 'activity', [ 6, 7 ] );
        AlertChip::html( 'activity', 6 );

        $before = $wpdb->num_queries;
        $this->assertSame( '', AlertChip::html( 'activity', 7 ) );
        $this->assertSame(
            0,
            $wpdb->num_queries - $before,
            '"this record is clean" is an answer worth caching; re-querying it is how a clean list still costs N queries'
        );
    }

    // Self-resolution

    public function test_fixing_the_record_removes_its_chip(): void {
        $this->seed( 'activity', 41 );
        AlertChip::prime( 'activity', [ 41 ] );
        $this->assertNotSame( '', AlertChip::html( 'activity', 41 ), 'precondition: the chip is there' );

        // The coach marked the activity completed; the next sweep stopped
        // seeing the condition and stamped resolved_at.
        $this->repo->resolveMissing( 'test.inline', [], current_time( 'mysql' ) );
        AlertChip::flush();

        $this->assertSame( '', AlertChip::html( 'activity', 41 ) );
    }

    public function test_another_recipients_alert_is_not_shown_on_my_chip(): void {
        $other = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->seed( 'activity', 55, $other );

        AlertChip::prime( 'activity', [ 55 ] );

        $this->assertSame(
            '',
            AlertChip::html( 'activity', 55 ),
            'the chip reads the viewer\'s own occurrences; reading by subject alone would hand a player-bearing alert to anyone who can open the record'
        );
    }

    // The player record (epic decision 12)

    public function test_player_record_shows_open_alerts(): void {
        $this->seed( 'activity', 60, $this->coach, Severity::URGENT, 900 );

        $html = AlertChip::playerHtml( 900 );

        $this->assertNotSame( '', $html );
        $this->assertStringContainsString( 'tt-alert-chip--urgent', $html );
        $this->assertStringContainsString( 'player_id=900', $html );
    }

    public function test_player_record_never_shows_a_resolved_alert(): void {
        $this->seed( 'activity', 61, $this->coach, Severity::ATTENTION, 901 );
        $this->repo->resolveMissing( 'test.inline', [], current_time( 'mysql' ) );
        AlertChip::flush();

        $this->assertSame(
            '',
            AlertChip::playerHtml( 901 ),
            'epic decision 12: a resolved occurrence is operational exhaust, not part of the record'
        );
    }

    /**
     * The negative that the whole of decision 12 rests on. An alert about a
     * player must leave no trace in their journey: the journey records what
     * happened to the player, not what staff failed to record, and at
     * 90-day retention a journey entry would vanish retroactively anyway.
     */
    public function test_the_player_surface_writes_nothing_to_the_journey(): void {
        global $wpdb;

        // `tt_player_events` is the journey's storage (migration 0037).
        $table = "{$this->p}tt_player_events";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->markTestSkipped( 'no player journey table on this install' );
        }

        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $this->seed( 'activity', 62, $this->coach, Severity::URGENT, 902 );
        AlertChip::playerHtml( 902 );

        $this->assertSame(
            $before,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
            'rendering a player\'s open alerts must never write to their journey'
        );
    }

    // The oversight roll-up (epic decision 7's counterpart)

    public function test_rollup_groups_open_conditions_by_team(): void {
        $team_a = $this->insertTeam( 'U14 rollup' );
        $team_b = $this->insertTeam( 'U16 rollup' );

        $a1 = $this->insertActivity( $team_a );
        $a2 = $this->insertActivity( $team_a );
        $b1 = $this->insertActivity( $team_b );

        $this->seed( 'activity', $a1 );
        $this->seed( 'activity', $a2 );
        $this->seed( 'activity', $b1 );

        $rows = AlertOversight::forUser( $this->coach );
        $by   = [];
        foreach ( $rows as $row ) $by[ $row['team_id'] ] = $row['count'];

        $this->assertSame( 2, $by[ $team_a ] ?? 0 );
        $this->assertSame( 1, $by[ $team_b ] ?? 0 );
    }

    /**
     * An occurrence is written once per recipient (epic decision 5). The
     * roll-up counts DISTINCT subjects, so two coaches on one unmarked
     * activity is still one unmarked activity — otherwise a Head of
     * Development would be told a team is twice as far behind as it is.
     */
    public function test_rollup_counts_subjects_not_recipient_rows(): void {
        $second = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $team   = $this->insertTeam( 'U14 dedupe' );
        $act    = $this->insertActivity( $team );

        $this->seed( 'activity', $act, $this->coach );
        $this->seed( 'activity', $act, $second );

        $rows = AlertOversight::forUser( $this->coach );

        $this->assertCount( 1, $rows );
        $this->assertSame( 1, $rows[0]['count'] );
    }

    public function test_rollup_is_scoped_to_the_teams_the_viewer_oversees(): void {
        $team = $this->insertTeam( 'U14 scoped' );
        $act  = $this->insertActivity( $team );
        $this->seed( 'activity', $act );

        // A user with neither the settings capability nor any team-role
        // scope oversees nothing, so the aggregate must be empty even
        // though the underlying rows exist.
        $outsider = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $this->assertSame( [], AlertOversight::teamIdsFor( $outsider ) );
        $this->assertSame( [], AlertOversight::forUser( $outsider ) );
    }

    public function test_rollup_writes_no_occurrence_rows(): void {
        global $wpdb;

        $team = $this->insertTeam( 'U14 readonly' );
        $act  = $this->insertActivity( $team );
        $this->seed( 'activity', $act );

        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_alert_occurrences" );
        AlertOversight::forUser( $this->coach );
        $after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_alert_occurrences" );

        $this->assertSame(
            $before,
            $after,
            'the roll-up reads the existing table sideways; fanning rows at oversight users is exactly what epic decision 7 refuses'
        );
    }

    /**
     * A coach with one team already sees everything about it in their own
     * inbox, so an aggregate of one row is a second way of saying the same
     * thing. Two teams is where "which of mine is behind" stops being
     * answerable by scrolling.
     */
    public function test_rollup_is_offered_only_to_someone_overseeing_more_than_one_team(): void {
        $single = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( AlertOversight::isAvailableTo( $single ) );

        $this->insertTeam( 'U14 one' );
        $this->insertTeam( 'U16 two' );

        $this->assertTrue( AlertOversight::isAvailableTo( $this->coach ) );
    }

    // The inbox view's navigation contract (CLAUDE.md §5)

    public function test_inbox_renders_the_breadcrumb_chain(): void {
        $this->seed( 'activity', 70 );

        $html = $this->capture( static function (): void {
            FrontendAlertsInboxView::render( get_current_user_id() );
        } );

        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    /**
     * The path §5 is really about. A refusal that renders a bare notice
     * with no chain is a dead end, and it is exactly the path nobody
     * remembers to check.
     */
    public function test_inbox_renders_the_breadcrumb_chain_on_the_signed_out_path(): void {
        wp_set_current_user( 0 );

        $html = $this->capture( static function (): void {
            FrontendAlertsInboxView::render( 0 );
        } );

        $this->assertStringContainsString( 'tt-breadcrumbs', $html );
    }

    public function test_inbox_emits_no_second_back_affordance(): void {
        $this->seed( 'activity', 71 );

        $html = $this->capture( static function (): void {
            FrontendAlertsInboxView::render( get_current_user_id() );
        } );

        // The chain plus the tt_back pill are the only two affordances a
        // view may emit. A hand-rolled "Back to dashboard" is the third,
        // and the one this rule was written to stop.
        $this->assertStringNotContainsString( 'Back to dashboard', $html );
        $this->assertStringNotContainsString( 'tt-player-tabs', $html, 'no hand-rolled tab strip (§5c)' );
    }

    // The repository's filter surface

    public function test_subject_filter_narrows_the_inbox_read(): void {
        $this->seed( 'activity', 80 );
        $this->seed( 'activity', 81 );

        $rows = $this->repo->listForUser( $this->coach, [
            'subject_type' => 'activity',
            'subject_id'   => 80,
        ] );

        $this->assertCount( 1, $rows );
        $this->assertSame( 80, (int) $rows[0]->subject_id );
    }

    public function test_resolved_state_returns_only_resolved_rows(): void {
        $this->seed( 'activity', 82 );
        $this->seed( 'activity', 83 );

        $this->repo->resolveMissing(
            'test.inline',
            [ $this->occurrence( 'activity', 83, $this->coach )->dedupeKey() ],
            current_time( 'mysql' )
        );

        $open     = $this->repo->listForUser( $this->coach, [ 'state' => 'open' ] );
        $resolved = $this->repo->listForUser( $this->coach, [ 'state' => 'resolved' ] );

        $this->assertCount( 1, $open );
        $this->assertSame( 83, (int) $open[0]->subject_id );
        $this->assertCount( 1, $resolved );
        $this->assertSame( 82, (int) $resolved[0]->subject_id );
    }

    /**
     * `Severity::normalise()` coerces junk to `attention`, which is right
     * for a stored value and wrong for a filter: a mistyped parameter would
     * silently answer a different question. An unknown severity means "no
     * severity filter".
     */
    public function test_an_unknown_severity_filter_does_not_silently_narrow(): void {
        $this->seed( 'activity', 84, $this->coach, Severity::URGENT );

        $rows = $this->repo->listForUser( $this->coach, [ 'severity' => 'catastrophic' ] );

        $this->assertCount( 1, $rows );
    }

    // Helpers

    private function occurrence(
        string $subjectType,
        int $subjectId,
        int $userId,
        string $severity = Severity::ATTENTION,
        ?int $playerId = null
    ): AlertOccurrence {
        return new AlertOccurrence(
            'test.inline',
            $userId,
            $subjectType,
            $subjectId,
            $severity,
            [ 'title' => 'Something needs doing', 'url' => 'https://example.test/' ],
            $playerId
        );
    }

    private function seed(
        string $subjectType,
        int $subjectId,
        int $userId = 0,
        string $severity = Severity::ATTENTION,
        ?int $playerId = null
    ): void {
        if ( $userId <= 0 ) $userId = $this->coach;
        $this->repo->upsert(
            $this->occurrence( $subjectType, $subjectId, $userId, $severity, $playerId ),
            current_time( 'mysql' )
        );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $team_id,
            'title'             => 'Rollup fixture',
            'session_date'      => current_time( 'Y-m-d' ),
            'activity_type_key' => 'training',
            'plan_state'        => 'planned',
            'coach_id'          => $this->coach,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function capture( callable $render ): string {
        ob_start();
        $render();
        return (string) ob_get_clean();
    }
}
