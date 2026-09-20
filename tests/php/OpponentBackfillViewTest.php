<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Frontend\FrontendOpponentBackfillView;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3860 — the review screen that fills in the opponents nothing ever
 * wrote, and the report line that keeps asking for the ones it could not.
 *
 * The screen proposes; it never writes on its own. What is asserted here
 * is which matches it offers, what it proposes for them, and that a
 * fixture whose title says nothing is still listed — with an empty
 * proposal — rather than hidden, because filling it in from memory is the
 * whole point.
 */
final class OpponentBackfillViewTest extends WP_UnitTestCase {

    private string $p = '';
    private int $club = 0;
    private int $team = 0;
    private int $readable = 0;
    private int $unreadable = 0;
    private int $already_set = 0;
    private int $training = 0;

    public function set_up(): void {
        parent::set_up();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Hedel JO12-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->readable    = $this->insertMatch( 'Hedel JO12-1 - Ajax JO12-1', '2026-09-05', null );
        $this->unreadable  = $this->insertMatch( 'Zaterdag 14:00', '2026-09-12', null );
        $this->already_set = $this->insertMatch( 'Hedel JO12-1 - DVVC', '2026-09-19', 'DVVC' );
        $this->training    = $this->insertActivity( 'training', 'Training', '2026-09-06', null );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_it_lists_only_matches_with_no_opponent_stored(): void {
        $ids = $this->pendingIds();

        $this->assertContains( $this->readable, $ids );
        $this->assertContains( $this->unreadable, $ids, 'a match nobody can parse is exactly the one to fill in by hand' );
        $this->assertNotContains( $this->already_set, $ids, 'a match with an opponent is not missing one' );
        $this->assertNotContains( $this->training, $ids, 'a training has no opponent to miss' );
    }

    public function test_a_readable_title_arrives_with_a_proposal(): void {
        $row = $this->rowFor( $this->readable );

        $this->assertSame( 'Ajax JO12-1', $row['proposed_opponent'] );
        $this->assertSame( 'home', $row['proposed_home_away'] );
        $this->assertSame( 'high', $row['confidence'] );
        $this->assertSame( 'Hedel JO12-1 - Ajax JO12-1', $row['title'], 'the title is shown as it stands, for checking against' );
    }

    public function test_an_unreadable_title_arrives_with_an_empty_proposal(): void {
        $row = $this->rowFor( $this->unreadable );

        $this->assertSame( '', $row['proposed_opponent'], 'a guess was proposed for a title with no signal' );
        $this->assertSame( '', $row['confidence'] );
    }

    /** A stored home/away is a fact somebody entered; the parser does not overrule it. */
    public function test_a_stored_venue_is_not_overwritten_by_the_proposal(): void {
        global $wpdb;
        $wpdb->update( "{$this->p}tt_activities", [ 'home_away' => 'away' ], [ 'id' => $this->readable ] );

        $this->assertSame( 'away', $this->rowFor( $this->readable )['proposed_home_away'] );
    }

    /** A coach with no team grant reviews nothing rather than the club. */
    public function test_a_coach_without_team_scope_sees_nothing(): void {
        $user_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $user_id );

        $this->assertSame( [], FrontendOpponentBackfillView::pending( $user_id, false ) );
    }

    /**
     * The safety net: whatever the backfill leaves behind keeps surfacing
     * in the report the coach reads every month, by date and title.
     */
    public function test_the_monthly_report_names_matches_still_missing_an_opponent(): void {
        $report  = ( new TeamMonthlyReport() )->forTeam( $this->team, '2026-09-01', '2026-09-30', [ 'quality' ] );
        $quality = (array) ( $report['data']['quality'] ?? [] );
        $missing = (array) ( $quality['matches_without_opponent'] ?? [] );

        $ids = array_map( static fn( $m ): int => (int) ( (array) $m )['activity_id'], $missing );
        $this->assertContains( $this->readable, $ids );
        $this->assertContains( $this->unreadable, $ids );
        $this->assertNotContains( $this->already_set, $ids );

        foreach ( $missing as $match ) {
            $this->assertNotSame( '', (string) ( (array) $match )['title'], 'the fixture is named by its title, which is where the opponent usually is' );
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    /** @return list<int> */
    private function pendingIds(): array {
        return array_map(
            static fn( array $row ): int => $row['id'],
            FrontendOpponentBackfillView::pending( get_current_user_id(), true )
        );
    }

    /** @return array<string,mixed> */
    private function rowFor( int $activity_id ): array {
        foreach ( FrontendOpponentBackfillView::pending( get_current_user_id(), true ) as $row ) {
            if ( $row['id'] === $activity_id ) return $row;
        }
        $this->fail( "activity {$activity_id} is not on the backfill screen" );
    }

    private function insertMatch( string $title, string $date, ?string $opponent ): int {
        return $this->insertActivity( 'match', $title, $date, $opponent );
    }

    private function insertActivity( string $type, string $title, string $date, ?string $opponent ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => $opponent,
        ] );
        return (int) $wpdb->insert_id;
    }
}
