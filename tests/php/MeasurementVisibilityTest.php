<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * #3392 — a test can be kept from a player and their family while staying
 * visible to staff.
 *
 * `show_on_profile` looked like this lever and was not: it is
 * viewer-agnostic, so switching it off hid the test from the coach too.
 * The operator's real question — staff yes, family no — had no expression,
 * so the only way to keep a maturation or body-composition figure off a
 * minor's screen was not to record it at all.
 *
 * The filter lives in the shared read model rather than the views, which
 * is what these tests actually pin: the rendered profile and the REST
 * response must not be able to disagree about what one reader may see.
 */
final class MeasurementVisibilityTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $player;
    private int $open_def;
    private int $staff_def;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();

        $this->player    = $this->seedPlayer();
        $this->open_def  = $this->seedDefinition( 'Sprint 10m', RecordVisibility::LEVEL_PUBLIC );
        $this->staff_def = $this->seedDefinition( 'Maturation offset', RecordVisibility::LEVEL_COACHING_STAFF );

        $this->seedResult( $this->open_def, 1.9 );
        $this->seedResult( $this->staff_def, -0.4 );
    }

    /* ---- the ladder --------------------------------------------------- */

    public function test_a_player_gets_the_public_level_only(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );

        $this->assertSame( [ RecordVisibility::LEVEL_PUBLIC ], RecordVisibility::forMeasurements( $user ) );
    }

    public function test_a_parent_gets_the_public_level_only(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        $this->assertSame( [ RecordVisibility::LEVEL_PUBLIC ], RecordVisibility::forMeasurements( $user ) );
    }

    public function test_an_administrator_reaches_the_staff_level(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertContains( RecordVisibility::LEVEL_COACHING_STAFF, RecordVisibility::forMeasurements( $user ) );
    }

    public function test_the_medical_cap_alone_does_not_reach_the_medical_level(): void {
        // The trap this feature is most likely to be broken by, and the one
        // the first draft fell into.
        //
        // `tt_view_player_medical` does not mean "is medical staff": it
        // bridges from `player_injuries:read`, which the seed grants a
        // player at self scope and a parent at player scope so they can see
        // their own injuries. A cap-only check therefore hands the player
        // the medical-level test the level exists to withhold.
        $player = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        foreach ( [ $player, $parent ] as $user ) {
            $this->assertTrue(
                user_can( $user, 'tt_view_player_medical' ),
                'the premise: they do hold the cap, over their own record'
            );
            $this->assertNotContains(
                RecordVisibility::LEVEL_MEDICAL,
                RecordVisibility::forMeasurements( $user ),
                'holding the cap over your own record is not clearance to read a medical-level test'
            );
        }
    }

    public function test_the_journey_still_shows_a_player_their_own_medical_entries(): void {
        // The other side of the same distinction: for the journey the cap
        // alone IS the right test, because the timeline is already scoped
        // to one authorised player. Moving measurements must not have
        // narrowed that.
        $player = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );

        $this->assertContains(
            RecordVisibility::LEVEL_MEDICAL,
            RecordVisibility::forJourney( $player ),
            'a player reads their own injuries on their own timeline — unchanged by #3392'
        );
    }

    /* ---- the profile read model --------------------------------------- */

    public function test_a_player_does_not_see_a_staff_only_test(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $names = $this->testNamesFor( $user );

        $this->assertContains( 'Sprint 10m', $names );
        $this->assertNotContains( 'Maturation offset', $names );
    }

    public function test_a_parent_sees_exactly_what_their_child_sees(): void {
        $player_names = $this->testNamesFor( (int) self::factory()->user->create( [ 'role' => 'tt_player' ] ) );
        $parent_names = $this->testNamesFor( (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] ) );

        $this->assertSame( $player_names, $parent_names );
    }

    public function test_staff_still_see_the_staff_only_test(): void {
        // The direction that makes the feature worth having: the test stays
        // recorded, flagged and trended for the coach.
        $names = $this->testNamesFor( (int) self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $this->assertContains( 'Sprint 10m', $names );
        $this->assertContains( 'Maturation offset', $names );
    }

    public function test_the_summary_counts_only_what_the_reader_may_see(): void {
        // The At-a-glance tile reads through forPlayer(), so a count that
        // disagreed with the list underneath it would be its own small bug.
        $profile = new PlayerMeasurementProfile();

        $player = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $staff  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertSame( 1, $profile->summaryForPlayer( $this->player, $player )['tracked'] );
        $this->assertSame( 2, $profile->summaryForPlayer( $this->player, $staff )['tracked'] );
    }

    public function test_the_view_and_rest_agree_for_one_caller(): void {
        // §4 — the filter lives in the shared read model precisely so these
        // cannot diverge. Same caller, same answer, by construction.
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        wp_set_current_user( $user );

        $explicit = $this->flatten( ( new PlayerMeasurementProfile() )->forPlayer( $this->player, $user ) );
        $implicit = $this->flatten( ( new PlayerMeasurementProfile() )->forPlayer( $this->player ) );

        $this->assertSame( $explicit, $implicit );
        $this->assertNotContains( 'Maturation offset', $implicit );
    }

    /* ---- upgrade safety ------------------------------------------------ */

    public function test_a_definition_saved_without_a_level_is_public(): void {
        // Migration 0259 defaults the column to `public`, so nothing
        // disappears from a player's screen on upgrade. This asserts the
        // write path agrees with the column default rather than leaving a
        // fresh definition at some other level.
        $id = ( new \TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository() )->create( [
            'category_id' => 0,
            'name'        => 'No level given',
            'value_type'  => 'numeric',
        ] );

        global $wpdb;
        $this->assertSame(
            RecordVisibility::LEVEL_PUBLIC,
            (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT visibility FROM {$this->p}tt_measurement_definitions WHERE id = %d",
                $id
            ) )
        );
    }

    public function test_an_unknown_level_falls_back_to_public(): void {
        $repo = new \TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository();
        $repo->update( $this->staff_def, [ 'visibility' => 'not_a_level' ] );

        global $wpdb;
        $this->assertSame(
            RecordVisibility::LEVEL_PUBLIC,
            (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT visibility FROM {$this->p}tt_measurement_definitions WHERE id = %d",
                $this->staff_def
            ) )
        );
    }

    /* ---- helpers ------------------------------------------------------- */

    /** @return list<string> */
    private function testNamesFor( int $user_id ): array {
        return $this->flatten( ( new PlayerMeasurementProfile() )->forPlayer( $this->player, $user_id ) );
    }

    /**
     * @param array<mixed> $profile
     * @return list<string>
     */
    private function flatten( array $profile ): array {
        $out = [];
        foreach ( $profile as $cat ) {
            foreach ( (array) ( $cat['tests'] ?? [] ) as $test ) {
                $out[] = (string) ( $test['name'] ?? '' );
            }
        }
        sort( $out );
        return $out;
    }

    private function seedPlayer(): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U15 Measure' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'team_id'       => $team,
            'first_name'    => 'Measured',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedDefinition( string $name, string $visibility ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_measurement_definitions", [
            'club_id'         => $this->club,
            // NOT NULL with no default on the foundation table.
            'category_id'     => 0,
            'name'            => $name,
            'value_type'      => 'numeric',
            'frequency'       => 'adhoc',
            'direction'       => 'higher',
            'is_active'       => 1,
            'show_on_profile' => 1,
            'visibility'      => $visibility,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedResult( int $definition_id, float $value ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_measurement_results", [
            'club_id'       => $this->club,
            'player_id'     => $this->player,
            'definition_id' => $definition_id,
            'value_numeric' => $value,
            'recorded_date' => current_time( 'Y-m-d' ),
        ] );
    }
}
