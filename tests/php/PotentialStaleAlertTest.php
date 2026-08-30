<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Alerts\Definitions\PotentialStaleAlert;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * #3225 — `players.potential_stale`.
 *
 * Three things are under test, and they fail in different ways.
 *
 * **The clock.** The window runs from the later of the player's last
 * potential entry and their creation date, so a player who joined three
 * weeks ago is not overdue on day one, and a player who has never been
 * assessed is covered by the same condition rather than a second
 * definition.
 *
 * **The audience.** The base class adds each player's head coach, and on
 * the default seed that persona can *read* potential and not *change* it.
 * Telling somebody about work they cannot do is the noise this alert has to
 * avoid, so a recipient who cannot act is dropped.
 *
 * **Self-resolution.** Setting a potential clears the alert on the next
 * pass, with no dismissal — that is the whole reason this is an alert and
 * not a reminder.
 */
final class PotentialStaleAlertTest extends WP_UnitTestCase {

    /** @var string */
    private $p;

    /** @var int */
    private $club = 1;

    /** @var ConfigService */
    private $config;

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->config = new ConfigService();
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        parent::tear_down();
    }

    /**
     * Grant `tt_set_player_potential` to exactly these users.
     *
     * `add_cap()` cannot express this: the matrix bridge makes
     * `LegacyCapMapper` authoritative for every `tt_*` cap and recomputes a
     * raw grant away. Filtering at priority 999 runs after the bridge, and
     * withholding-then-granting is what lets the "head coach who cannot
     * change potential" case exist at all — which is the case this alert's
     * audience rule is about.
     *
     * @param list<int> $user_ids
     */
    private function onlyTheseCanSetPotential( array $user_ids ): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
        }

        $allowed = array_flip( array_map( 'intval', $user_ids ) );

        $this->cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $allowed ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( isset( $allowed[ $uid ] ) ) {
                $allcaps['tt_set_player_potential'] = true;
            } else {
                unset( $allcaps['tt_set_player_potential'] );
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );
    }

    /** @return list<\TT\Modules\Alerts\Domain\AlertOccurrence> */
    private function evaluate(): array {
        return ( new PotentialStaleAlert() )->evaluate( new AlertContext( $this->club ) );
    }

    // --- the clock ------------------------------------------------------

    public function test_a_player_never_assessed_and_long_joined_is_flagged(): void {
        $team   = $this->insertTeam();
        $hod    = $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );

        $out = $this->evaluate();

        $this->assertNotEmpty( $out );
        $this->assertSame( $player, $out[0]->subjectId );
        $this->assertSame( $hod, $out[0]->recipientUserId );
        $this->assertFalse( $out[0]->payload['has_potential'] );
        $this->assertStringContainsString( 'no potential recorded yet', $out[0]->payload['title'] );
    }

    /** A player who joined three weeks ago is not overdue on day one. */
    public function test_a_newly_joined_player_is_not_overdue(): void {
        $team = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $this->insertPlayer( $team, $this->daysAgo( 21 ) );

        $this->assertSame( [], $this->evaluate() );
    }

    public function test_a_recently_set_potential_is_not_stale(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->setPotential( $player, $this->daysAgo( 30 ) );

        $this->assertSame( [], $this->evaluate() );
    }

    public function test_an_old_potential_is_stale(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 800 ) );
        $this->setPotential( $player, $this->daysAgo( 300 ) );

        $out = $this->evaluate();

        $this->assertCount( 1, $out );
        $this->assertTrue( $out[0]->payload['has_potential'] );
        $this->assertStringContainsString( 'not been revisited', $out[0]->payload['title'] );
    }

    /**
     * The most recent entry is what counts. An old row must not keep a
     * player flagged after somebody has looked again.
     */
    public function test_the_most_recent_entry_decides(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 800 ) );
        $this->setPotential( $player, $this->daysAgo( 500 ) );
        $this->setPotential( $player, $this->daysAgo( 10 ) );

        $this->assertSame( [], $this->evaluate() );
    }

    /** Self-resolving: setting a potential clears it, with no dismissal. */
    public function test_setting_a_potential_clears_it_on_the_next_pass(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 400 ) );
        $this->assertCount( 1, $this->evaluate() );

        $this->setPotential( $player, $this->daysAgo( 0 ) );

        $this->assertSame( [], $this->evaluate() );
    }

    /** The window is a club-scoped config value, not a constant. */
    public function test_the_window_is_configurable(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 800 ) );
        $this->setPotential( $player, $this->daysAgo( 100 ) );

        $this->assertSame( [], $this->evaluate(), '100 days is inside the 180-day default' );

        $this->config->set( PotentialStaleAlert::CONFIG_KEY_STALE_DAYS, '90' );

        $this->assertCount( 1, $this->evaluate(), 'and outside a 90-day window' );
    }

    public function test_a_very_old_potential_is_urgent(): void {
        $team   = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $player = $this->insertPlayer( $team, $this->daysAgo( 900 ) );
        $this->setPotential( $player, $this->daysAgo( 400 ) );

        $out = $this->evaluate();

        $this->assertCount( 1, $out );
        $this->assertSame( Severity::URGENT, $out[0]->severity );
    }

    // --- the audience ---------------------------------------------------

    /**
     * The rule the class docblock argues for: a head coach who can read
     * potential but not change it is told nothing, because they cannot
     * clear it.
     */
    public function test_somebody_who_cannot_set_potential_is_not_told(): void {
        $team = $this->insertTeam();
        $head = self::factory()->user->create( [ 'role' => 'tt_head_coach' ] );
        $this->assignHeadCoach( $team, $head );
        $this->insertPlayer( $team, $this->daysAgo( 400 ) );

        // Nobody at all can set potential.
        $this->onlyTheseCanSetPotential( [] );

        $this->assertSame( [], $this->evaluate() );
    }

    /** A head coach who HAS been granted the change activity does get it. */
    public function test_a_head_coach_who_can_set_potential_is_told(): void {
        $team = $this->insertTeam();
        $head = self::factory()->user->create( [ 'role' => 'tt_head_coach' ] );
        $this->assignHeadCoach( $team, $head );
        $this->insertPlayer( $team, $this->daysAgo( 400 ) );

        $this->onlyTheseCanSetPotential( [ $head ] );

        $out = $this->evaluate();

        $this->assertNotEmpty( $out );
        $this->assertSame( $head, $out[0]->recipientUserId );
    }

    // --- exclusions -----------------------------------------------------

    public function test_a_trial_player_is_excluded(): void {
        $team = $this->insertTeam();
        $this->insertUserWhoCanSetPotential();
        $this->insertPlayer( $team, $this->daysAgo( 400 ), 'trial' );

        $this->assertSame( [], $this->evaluate() );
    }

    public function test_a_player_with_no_team_is_excluded(): void {
        $this->insertUserWhoCanSetPotential();
        $this->insertPlayer( 0, $this->daysAgo( 400 ) );

        $this->assertSame( [], $this->evaluate() );
    }

    // --- fixtures -------------------------------------------------------

    private function daysAgo( int $n ): string {
        return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $n * DAY_IN_SECONDS );
    }

    private function insertUserWhoCanSetPotential(): int {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->onlyTheseCanSetPotential( [ $uid ] );
        return $uid;
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 potential' ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $created_at, string $status = 'active' ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Stale',
            'last_name'  => 'Potential',
            'status'     => $status,
            'created_at' => $created_at,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function setPotential( int $player_id, string $set_at ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_player_potential", [
            'club_id'        => $this->club,
            'player_id'      => $player_id,
            'set_at'         => $set_at,
            'set_by'         => 1,
            'potential_band' => PotentialBand::SEMI_PRO,
        ] );
    }

    /** Head-coach assignment through `tt_team_people` (#1315). */
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
}
