<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Query\PlayerFileCounts;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Tournaments\Frontend\PlayerTournamentsTab;

/**
 * #3562 (epic #3558) — the Tournaments tab on the player file.
 *
 * The tab composes and does not compute, so what there is to test is what
 * it *says*, and who it says it to. Four things:
 *
 *   - a player never in a squad gets the finding, not a blank panel, and
 *     carries no badge;
 *   - the guard is inside the panel, because a tab hidden from the strip is
 *     not a tab that is protected — the panel is reachable by `?tab=`;
 *   - a parent whose child closed the section gets the notice, and the same
 *     parent gets the record when the child has not;
 *   - a fixture with no result says so in words rather than rendering 0-0.
 *
 * The fixtures are `PlayerTournamentHistoryTest`'s, cut down: a
 * `tt_club_admin` caller, because a WordPress `administrator` does not
 * resolve to the `academy_admin` persona and the `user_has_cap` bridge
 * overwrites `$allcaps` with the matrix's answer.
 */
final class PlayerTournamentsTabTest extends WP_UnitTestCase {

    private const TEAM = 35620;

    /** @var int */
    private $admin = 0;

    /** @var int */
    private $tournament = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $this->admin );

        $this->seedTeam();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ── never selected is a finding ────────────────────────────────────

    public function test_a_player_never_in_a_squad_is_told_so_rather_than_shown_nothing(): void {
        $player = $this->seedPlayer();

        $html = $this->render( $player, $this->admin );

        $this->assertStringContainsString( 'has not been in a tournament squad yet', $html );
        $this->assertStringNotContainsString( 'tt-ptour__list', $html );
    }

    public function test_the_badge_counts_the_squads_and_is_zero_for_a_player_never_picked(): void {
        $picked = $this->seedPlayerWithATournament();
        $never  = $this->seedPlayer();

        $this->assertSame( 1, (int) PlayerFileCounts::for( $picked )['tournaments'] );
        $this->assertSame( 0, (int) PlayerFileCounts::for( $never )['tournaments'] );
    }

    public function test_an_archived_tournament_stops_counting_toward_the_badge(): void {
        global $wpdb;
        $player = $this->seedPlayerWithATournament();

        $this->assertSame( 1, (int) PlayerFileCounts::for( $player )['tournaments'] );

        $wpdb->update(
            $wpdb->prefix . 'tt_tournaments',
            [ 'archived_at' => '2026-01-01 00:00:00' ],
            [ 'id' => $this->tournament ]
        );

        $this->assertSame(
            0,
            (int) PlayerFileCounts::for( $player )['tournaments'],
            'the badge and the tab read the same archive filter'
        );
    }

    // ── the panel guards itself ────────────────────────────────────────

    /**
     * The acceptance criterion the mockup's state D is about: the panel is
     * reachable by URL, so it repeats the gate rather than trusting the
     * strip that did not offer it.
     */
    public function test_opening_the_panel_by_url_without_entitlement_shows_the_refusal(): void {
        $player   = $this->seedPlayerWithATournament();
        $stranger = self::factory()->user->create( [ 'role' => 'subscriber' ] );

        $html = $this->render( $player, $stranger );

        $this->assertStringContainsString( 'do not have permission', $html );
        $this->assertStringNotContainsString( 'tt-ptour__list', $html );

        // Paired with the grant, so a fixture that refused everybody would
        // fail here rather than pass above.
        $this->assertStringContainsString( 'tt-ptour__list', $this->render( $player, $this->admin ) );
    }

    public function test_a_parent_sees_the_record_until_the_child_closes_the_section(): void {
        global $wpdb;
        $player = $this->seedPlayerWithATournament();

        $parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => $player,
            'parent_user_id' => $parent,
        ] );

        $this->assertStringContainsString(
            'tt-ptour__list',
            $this->render( $player, $parent ),
            'the section is shared by default, so no family loses access when this ships'
        );

        ( new PlayerParentVisibilityRepository() )->setVisibility( $player, 'tournaments', false );

        $html = $this->render( $player, $parent );
        $this->assertStringContainsString( 'chosen not to share their tournament history', $html );
        $this->assertStringNotContainsString( 'tt-ptour__list', $html );
    }

    // ── what the panel says ────────────────────────────────────────────

    public function test_a_fixture_with_no_result_says_so_in_words(): void {
        $player = $this->seedPlayerWithATournament();

        $html = $this->render( $player, $this->admin );

        $this->assertStringContainsString( 'no result', $html );
        $this->assertStringNotContainsString( '0–0', $html );
    }

    public function test_the_page_names_where_the_minutes_come_from(): void {
        $player = $this->seedPlayerWithATournament();

        $html = $this->render( $player, $this->admin );

        $this->assertStringContainsString( 'rotation plan of completed fixtures', $html );
        $this->assertStringContainsString( 'Activities tab', $html );
    }

    /** Only a shortfall is coloured, and the figures are spelled out beside it. */
    public function test_a_shortfall_against_the_players_own_target_is_marked_and_written_out(): void {
        $player = $this->seedPlayerWithATournament();

        $html = $this->render( $player, $this->admin );

        $this->assertStringContainsString( 'tt-ptour__target is-short', $html );
        $this->assertStringContainsString( 'min target', $html, 'the state is in words as well as in colour' );
    }

    public function test_every_tournament_opens_with_a_native_details_element(): void {
        $player = $this->seedPlayerWithATournament();

        $html = $this->render( $player, $this->admin );

        $this->assertStringContainsString( '<details class="tt-ptour__tournament"', $html );
        $this->assertStringContainsString( '<summary class="tt-ptour__summary"', $html );
    }

    // ── helpers ────────────────────────────────────────────────────────

    private function render( int $player_id, int $user_id ): string {
        ob_start();
        PlayerTournamentsTab::render( $player_id, $user_id );
        return (string) ob_get_clean();
    }

    private function seedTeam(): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'id'      => self::TEAM,
            'club_id' => 1,
            'name'    => 'Toernooi FC',
        ] );
    }

    private function seedPlayer(): int {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'first_name' => 'Daan',
            'last_name'  => 'de Vries',
            'team_id'    => self::TEAM,
            'status'     => 'active',
        ] );

        $this->assertNotFalse( $ok, 'the player fixture must write' );

        return (int) $wpdb->insert_id;
    }

    /**
     * One player, one tournament, two fixtures of two ten-minute periods:
     * one completed with a result and played whole, one not completed and
     * with no result. The player's target is 60 minutes and they played 20,
     * so the target bar is short.
     */
    private function seedPlayerWithATournament(): int {
        global $wpdb;

        $player = $this->seedPlayer();

        $wpdb->insert( $wpdb->prefix . 'tt_tournaments', [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'name'       => 'Paastoernooi',
            'start_date' => '2026-04-06',
            'end_date'   => '2026-04-06',
            'team_id'    => self::TEAM,
            'created_by' => $this->admin,
        ] );
        $this->tournament = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->tournament, 'the tournament fixture must write' );

        $wpdb->insert( $wpdb->prefix . 'tt_tournament_squad', [
            'club_id'            => 1,
            'tournament_id'      => $this->tournament,
            'player_id'          => $player,
            'eligible_positions' => '["MID"]',
            'target_minutes'     => 60,
        ] );

        $played   = $this->seedMatch( 1, true, 2, 1 );
        $upcoming = $this->seedMatch( 2, false, null, null );

        $this->assign( $played, 0, $player );
        $this->assign( $played, 1, $player );
        $this->assign( $upcoming, 0, $player );

        return $player;
    }

    private function seedMatch( int $sequence, bool $completed, ?int $ours, ?int $theirs ): int {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_tournament_matches', [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament,
            'sequence'             => $sequence,
            'label'                => 'Wedstrijd ' . $sequence,
            'opponent_name'        => 'Ajax ' . $sequence,
            'opponent_level'       => 'equal',
            'duration_min'         => 20,
            'substitution_windows' => '[10]',
            'completed_at'         => $completed ? '2026-04-06 12:00:00' : null,
            'our_score'            => $ours,
            'their_score'          => $theirs,
        ] );

        $this->assertNotFalse( $ok, 'the fixture row must write' );

        return (int) $wpdb->insert_id;
    }

    private function assign( int $match_id, int $period, int $player_id ): void {
        global $wpdb;

        $ok = $wpdb->insert( $wpdb->prefix . 'tt_tournament_assignments', [
            'club_id'       => 1,
            'match_id'      => $match_id,
            'period_index'  => $period,
            'player_id'     => $player_id,
            'position_code' => 'MID',
        ] );

        $this->assertNotFalse( $ok, 'the assignment fixture must write' );
    }
}
