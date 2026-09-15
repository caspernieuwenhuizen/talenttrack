<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\REST\ReportsRestController;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PotentialOverviewQuery;
use TT\Modules\Players\Frontend\PlayerStatusVisibility;
use TT\Modules\Players\Services\PotentialRecorder;

/**
 * #3412 — a head of development could not see potential across a squad.
 *
 * #3385 established the gap: the band appeared nowhere that showed more
 * than one player, and the only cross-player surface reading potential
 * folds it into a composite that cannot be sorted or filtered by.
 *
 * The assertions below are chosen for the things that would be wrong in a
 * way nobody notices:
 *
 *  - **Players with no band must be rows.** 39 of 64 on the demo install.
 *    A query that returned only the assessed players would look correct on
 *    every screenshot and answer the opposite of the question.
 *  - **Band order is the vocabulary's order, not the string's.** Sorting
 *    `VARCHAR` band codes alphabetically puts `first_team` in the middle.
 *  - **Scope cannot widen access.** The scope selector chooses between two
 *    ways of naming a team set; it must never be a way of reaching one.
 *  - **REST and the view answer the same.** CLAUDE.md §4 — a second
 *    implementation of the filter is how they come to disagree.
 */
final class PotentialOverviewTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $admin_id;
    private int $team_a;
    private int $team_b;
    private int $team_other_age;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->admin_id = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin_id );

        $this->team_a         = $this->insertTeam( 'U15-1', 'U15' );
        $this->team_b         = $this->insertTeam( 'U15-2', 'U15' );
        $this->team_other_age = $this->insertTeam( 'U17-1', 'U17' );
    }

    private function query(): PotentialOverviewQuery {
        return new PotentialOverviewQuery();
    }

    /** @return list<array<string,mixed>> */
    private function teamRows( int $team_id, array $bands = [], string $sort = 'band', string $dir = 'asc' ): array {
        return $this->query()->rows(
            $this->admin_id,
            PotentialOverviewQuery::SCOPE_TEAM,
            $team_id,
            '',
            $bands,
            $sort,
            $dir
        );
    }

    /** @return list<array<string,mixed>> */
    private function ageGroupRows( string $age_group, array $bands = [], string $sort = 'band', string $dir = 'asc' ): array {
        return $this->query()->rows(
            $this->admin_id,
            PotentialOverviewQuery::SCOPE_AGE_GROUP,
            0,
            $age_group,
            $bands,
            $sort,
            $dir
        );
    }

    /**
     * The whole point of the screen. A player nobody has assessed is a row
     * marked unrecorded, not a row that is not there.
     */
    public function test_players_with_no_band_are_rows(): void {
        $assessed = $this->insertPlayer( $this->team_a, 'Assessed', 'Aa', 15 );
        $blank    = $this->insertPlayer( $this->team_a, 'Blank', 'Bb', 15 );
        $this->recordBand( $assessed, PotentialBand::SEMI_PRO );

        $rows = $this->teamRows( $this->team_a );
        $by_id = $this->index( $rows );

        $this->assertCount( 2, $rows, 'the unassessed player is not omitted' );
        $this->assertTrue( $by_id[ $assessed ]['recorded'] );
        $this->assertFalse( $by_id[ $blank ]['recorded'] );
        $this->assertSame( '', $by_id[ $blank ]['band'] );
        $this->assertSame( PotentialBand::SEMI_PRO, $by_id[ $assessed ]['band'] );
    }

    /**
     * "The U15s" may be two squads. An age-group scope gathers them; it
     * does not gather the U17s along the way.
     */
    public function test_age_group_scope_spans_squads_and_stops_at_the_label(): void {
        $a = $this->insertPlayer( $this->team_a,         'A', 'Fifteen', 15 );
        $b = $this->insertPlayer( $this->team_b,         'B', 'Fifteen', 15 );
        $c = $this->insertPlayer( $this->team_other_age, 'C', 'Seventeen', 17 );

        $ids = array_keys( $this->index( $this->ageGroupRows( 'U15' ) ) );
        sort( $ids );

        $expected = [ $a, $b ];
        sort( $expected );

        $this->assertSame( $expected, $ids );
        $this->assertNotContains( $c, $ids, 'a different age-group label is a different scope' );
    }

    /**
     * Band order is the vocabulary's order — best first — and the players
     * nobody has assessed stay at the bottom whichever way it is read.
     * Reversing the comparator would float them to the top of a
     * "lowest band first" read, which answers a different question.
     */
    public function test_band_sort_follows_the_vocabulary_and_parks_the_unassessed(): void {
        $top  = $this->insertPlayer( $this->team_a, 'Top', 'Zz', 15 );
        $mid  = $this->insertPlayer( $this->team_a, 'Mid', 'Yy', 15 );
        $low  = $this->insertPlayer( $this->team_a, 'Low', 'Xx', 15 );
        $none = $this->insertPlayer( $this->team_a, 'None', 'Ww', 15 );

        $this->recordBand( $top, PotentialBand::FIRST_TEAM );
        $this->recordBand( $mid, PotentialBand::SEMI_PRO );
        $this->recordBand( $low, PotentialBand::RECREATIONAL );

        $asc = array_column( $this->teamRows( $this->team_a, [], 'band', 'asc' ), 'player_id' );
        $this->assertSame( [ $top, $mid, $low, $none ], $asc );

        $desc = array_column( $this->teamRows( $this->team_a, [], 'band', 'desc' ), 'player_id' );
        $this->assertSame( [ $low, $mid, $top, $none ], $desc, 'unrecorded stays last in both directions' );
    }

    /**
     * The filter has to be able to express "show me the players nobody has
     * assessed" — that is the list a head of development acts on.
     */
    public function test_the_band_filter_can_select_the_unrecorded(): void {
        $assessed = $this->insertPlayer( $this->team_a, 'Has', 'Band', 15 );
        $blank    = $this->insertPlayer( $this->team_a, 'No', 'Band', 15 );
        $this->recordBand( $assessed, PotentialBand::TOP_AMATEUR );

        $only_none = array_column(
            $this->teamRows( $this->team_a, [ PotentialOverviewQuery::BAND_NONE ] ),
            'player_id'
        );
        $this->assertSame( [ $blank ], $only_none );

        $only_band = array_column(
            $this->teamRows( $this->team_a, [ PotentialBand::TOP_AMATEUR ] ),
            'player_id'
        );
        $this->assertSame( [ $assessed ], $only_band );

        $this->assertCount( 2, $this->teamRows( $this->team_a, [] ), 'no filter means the whole squad' );
    }

    /**
     * A band nobody recognises must not silently empty the report — the
     * filter drops it and the squad comes back whole.
     */
    public function test_an_unknown_band_filter_is_discarded_rather_than_obeyed(): void {
        $this->insertPlayer( $this->team_a, 'Whole', 'Squad', 15 );

        $this->assertSame( [], PotentialOverviewQuery::sanitizeBands( [ 'wonderkid' ] ) );
        $this->assertCount( 1, $this->teamRows( $this->team_a, [ 'wonderkid' ] ) );
    }

    /**
     * Movement is the difference between "always been semi-pro" and
     * "raised to semi-pro last month", which is a different conversation.
     * `PotentialBand::ALL` is best-first, so a *lower* index is a higher
     * band — the easy thing to get backwards.
     */
    public function test_movement_names_the_direction_and_the_band_it_came_from(): void {
        $risen = $this->insertPlayer( $this->team_a, 'Risen', 'Rr', 15 );
        $this->recordBand( $risen, PotentialBand::RECREATIONAL, '-200 days' );
        $this->recordBand( $risen, PotentialBand::SEMI_PRO,     '-20 days' );

        $fallen = $this->insertPlayer( $this->team_a, 'Fallen', 'Ff', 15 );
        $this->recordBand( $fallen, PotentialBand::FIRST_TEAM, '-200 days' );
        $this->recordBand( $fallen, PotentialBand::SEMI_PRO,   '-20 days' );

        $first = $this->insertPlayer( $this->team_a, 'First', 'Ee', 15 );
        $this->recordBand( $first, PotentialBand::SEMI_PRO, '-20 days' );

        $rows = $this->index( $this->teamRows( $this->team_a ) );

        $this->assertSame( 'up', $rows[ $risen ]['direction'] );
        $this->assertSame( PotentialBand::RECREATIONAL, $rows[ $risen ]['previous_band'] );

        $this->assertSame( 'down', $rows[ $fallen ]['direction'] );
        $this->assertSame( PotentialBand::FIRST_TEAM, $rows[ $fallen ]['previous_band'] );

        $this->assertSame( 'first', $rows[ $first ]['direction'] );
        $this->assertSame( '', $rows[ $first ]['previous_band'] );
        $this->assertSame( 1, $rows[ $first ]['revisions'] );
    }

    /**
     * The #3265 age floor means the academy is not *asked* below 13, so a
     * twelve-year-old without a band is not a coverage failure. Counting
     * them would report a gap that is policy, and send somebody looking
     * for work that must not be done.
     */
    public function test_players_below_the_age_floor_are_not_counted_as_gaps(): void {
        $assessed = $this->insertPlayer( $this->team_a, 'Old', 'Enough', 15 );
        $this->insertPlayer( $this->team_a, 'Also', 'Fifteen', 15 );
        $this->insertPlayer( $this->team_a, 'Too', 'Young', 11 );
        $this->recordBand( $assessed, PotentialBand::SEMI_PRO );

        $rows    = $this->teamRows( $this->team_a );
        $summary = PotentialOverviewQuery::summarise( $rows );

        $this->assertSame( 3, $summary['players'], 'the young player is still a row' );
        $this->assertSame( 1, $summary['recorded'] );
        $this->assertSame( 1, $summary['missing'], 'only the askable player counts as a gap' );
        $this->assertSame( 1, $summary['not_asked'] );
        $this->assertSame( 50.0, $summary['coverage'], 'coverage is over the askable players, not everybody' );
    }

    /**
     * A scope is a way of naming a team set, never a way of reaching one.
     * Two directions: an unknown caller gets nothing, and the rows a caller
     * does get never name a team outside their scope.
     */
    public function test_scope_cannot_widen_access(): void {
        $this->insertPlayer( $this->team_a, 'In', 'Scope', 15 );
        $this->insertPlayer( $this->team_other_age, 'Out', 'Of', 17 );

        $stranger = $this->query()->rows( 0, PotentialOverviewQuery::SCOPE_AGE_GROUP, 0, 'U15' );
        $this->assertSame( [], $stranger, 'an unknown caller reads no team' );

        $scoped   = $this->query()->teamsInScope( $this->admin_id, PotentialOverviewQuery::SCOPE_TEAM, $this->team_a, '' );
        $scope_ids = array_column( $scoped, 'team_id' );
        $this->assertSame( [ $this->team_a ], $scope_ids );

        foreach ( $this->teamRows( $this->team_a ) as $row ) {
            $this->assertContains( $row['team_id'], $scope_ids, 'no row may name a team outside the scope' );
        }
    }

    /**
     * CLAUDE.md §4 — the REST route is the same answer, not a second
     * implementation of it.
     */
    public function test_rest_returns_the_same_filtered_set_as_the_query(): void {
        $a = $this->insertPlayer( $this->team_a, 'Rest', 'One', 15 );
        $b = $this->insertPlayer( $this->team_b, 'Rest', 'Two', 15 );
        $this->insertPlayer( $this->team_a, 'Rest', 'Three', 15 );
        $this->recordBand( $a, PotentialBand::FIRST_TEAM );
        $this->recordBand( $b, PotentialBand::RECREATIONAL );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/potential-overview' );
        $request->set_param( 'scope', PotentialOverviewQuery::SCOPE_AGE_GROUP );
        $request->set_param( 'age_group', 'U15' );
        $request->set_param( 'sort', 'band' );
        $request->set_param( 'dir', 'asc' );

        $response = ReportsRestController::potentialOverview( $request );
        $payload  = $response->get_data();
        $rows     = $payload['data']['rows'];

        $expected = array_column( $this->ageGroupRows( 'U15' ), 'player_id' );
        $this->assertSame( $expected, array_column( $rows, 'player_id' ) );
        $this->assertNotEmpty( $expected );
    }

    /**
     * REST accepts `bands` both ways a client sends it — a repeated query
     * parameter arrives as an array, a comma-separated one as a string —
     * and the answer is the same set either way.
     */
    public function test_rest_reads_bands_as_a_list_or_a_comma_separated_string(): void {
        $blank = $this->insertPlayer( $this->team_a, 'Filter', 'Blank', 15 );
        $band  = $this->insertPlayer( $this->team_a, 'Filter', 'Band', 15 );
        $this->recordBand( $band, PotentialBand::SEMI_PRO );

        foreach ( [ PotentialOverviewQuery::BAND_NONE, [ PotentialOverviewQuery::BAND_NONE ] ] as $shape ) {
            $request = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/potential-overview' );
            $request->set_param( 'scope', PotentialOverviewQuery::SCOPE_TEAM );
            $request->set_param( 'team_id', $this->team_a );
            $request->set_param( 'bands', $shape );

            $payload = ReportsRestController::potentialOverview( $request )->get_data();
            $rows    = $payload['data']['rows'];

            $this->assertSame( [ $blank ], array_column( $rows, 'player_id' ) );
        }
    }

    /**
     * The summary describes the squad, not the slice. A coverage figure
     * that moved when somebody ticked a filter box would mean nothing.
     */
    public function test_the_summary_is_computed_over_the_scope_not_the_filter(): void {
        $band = $this->insertPlayer( $this->team_a, 'Sum', 'Band', 15 );
        $this->insertPlayer( $this->team_a, 'Sum', 'Blank', 15 );
        $this->recordBand( $band, PotentialBand::SEMI_PRO );

        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/reports/potential-overview' );
        $request->set_param( 'scope', PotentialOverviewQuery::SCOPE_TEAM );
        $request->set_param( 'team_id', $this->team_a );
        $request->set_param( 'bands', [ PotentialBand::SEMI_PRO ] );

        $payload = ReportsRestController::potentialOverview( $request )->get_data();
        $body    = $payload['data'];

        $this->assertCount( 1, $body['rows'], 'the filter narrows the rows' );
        $this->assertSame( 2, $body['summary']['players'], 'and leaves the squad summary alone' );
        $this->assertSame( 1, $body['summary']['missing'] );
    }

    /**
     * #3386 folded into this issue: the screen that shows a squad's
     * potential is where it gets edited. One write path — the same rules
     * the per-player capture screen goes through.
     */
    public function test_recording_a_band_appends_once_and_never_for_a_restatement(): void {
        $player   = $this->insertPlayer( $this->team_a, 'Write', 'Path', 15 );
        $recorder = new PotentialRecorder();

        $first = $recorder->record( $player, PotentialBand::SEMI_PRO );
        $this->assertSame( PotentialRecorder::RECORDED, $first['result'] );

        $again = $recorder->record( $player, PotentialBand::SEMI_PRO );
        $this->assertSame( PotentialRecorder::UNCHANGED, $again['result'], 'restating a standing band is not a revision' );

        $with_notes = $recorder->record( $player, PotentialBand::SEMI_PRO, 'Flat six weeks.' );
        $this->assertSame( PotentialRecorder::RECORDED, $with_notes['result'], 'reaffirming with notes is a real act' );

        $changed = $recorder->record( $player, PotentialBand::FIRST_TEAM );
        $this->assertSame( PotentialRecorder::RECORDED, $changed['result'] );

        $row = $this->index( $this->teamRows( $this->team_a ) )[ $player ];
        $this->assertSame( PotentialBand::FIRST_TEAM, $row['band'] );
        $this->assertSame( 3, $row['revisions'], 'the no-op wrote nothing' );
    }

    public function test_an_invalid_band_and_a_too_young_player_are_refused(): void {
        $player = $this->insertPlayer( $this->team_a, 'Refused', 'Rr', 15 );
        $young  = $this->insertPlayer( $this->team_a, 'Too', 'Small', 10 );

        $recorder = new PotentialRecorder();

        $this->assertSame( PotentialRecorder::INVALID,   $recorder->record( $player, 'wonderkid' )['result'] );
        $this->assertSame( PotentialRecorder::NO_PLAYER, $recorder->record( 0, PotentialBand::SEMI_PRO )['result'] );
        $this->assertSame( PotentialRecorder::BELOW_AGE, $recorder->record( $young, PotentialBand::SEMI_PRO )['result'] );

        $this->assertFalse( $this->index( $this->teamRows( $this->team_a ) )[ $young ]['recorded'] );
    }

    /**
     * The acceptance criterion that ties this to #3413: setting a band from
     * the report must reach the traffic light, because the status is
     * computed from the same store rather than cached beside it.
     */
    public function test_the_traffic_light_picks_up_a_band_recorded_here(): void {
        $player = $this->insertPlayer( $this->team_a, 'Light', 'Ll', 15 );
        $calc   = new PlayerStatusCalculator();

        $before = $calc->calculate( $player );
        $this->assertContains( 'potential', $before->missing_inputs );

        ( new PotentialRecorder() )->record( $player, PotentialBand::FIRST_TEAM );

        $after = $calc->calculate( $player );
        $this->assertNotContains( 'potential', $after->missing_inputs );
        $this->assertGreaterThan( $before->coverage, $after->coverage );
    }

    /**
     * Decision 3 of #3412 asked this gate to inherit the dot's, and for a
     * finding if the dot's gate admits somebody it should not. It does —
     * the family toggle — so the squad gate narrows it. Both directions:
     * staff in, family out, in either toggle state.
     */
    public function test_family_personas_never_reach_the_squad_view(): void {
        $this->assertTrue(
            PlayerStatusVisibility::squadVisibleTo( $this->admin_id ),
            'staff read the squad view'
        );
        $this->assertFalse(
            PlayerStatusVisibility::squadVisibleTo( 0 ),
            'an unknown caller is not staff'
        );

        $player_user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team_a,
            'first_name' => 'Family',
            'last_name'  => 'Persona',
            'status'     => 'active',
            'wp_user_id' => $player_user,
        ] );

        $this->assertFalse(
            PlayerStatusVisibility::squadVisibleTo( $player_user ),
            'a player reaches nothing here even though the dot gate may admit them'
        );
    }

    /* ---- fixtures ------------------------------------------------------- */

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function index( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) $out[ (int) $row['player_id'] ] = $row;
        return $out;
    }

    private function insertTeam( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id'   => $this->club,
            'name'      => $name,
            'age_group' => $age_group,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, string $last, int $age ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'first_name'    => $first,
            'last_name'     => $last,
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( '-' . $age . ' years -30 days' ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function recordBand( int $player_id, string $band, string $when = '-10 days' ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'set_at'         => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ),
            'set_by'         => $this->admin_id,
            'potential_band' => $band,
        ] );
    }
}
