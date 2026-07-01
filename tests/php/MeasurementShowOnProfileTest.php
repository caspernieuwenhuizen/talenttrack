<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * #2204 — per-test "show on player profile" flag.
 *
 * Migration 0195 adds tt_measurement_definitions.show_on_profile (default 1).
 * A test toggled off (`show_on_profile = 0`) stops rendering on the player
 * profile but keeps recording results and appears in reports / exports.
 *
 * These tests lock:
 *   (a) the flag round-trips through the repository (default visible; toggled
 *       off persists);
 *   (b) the player-profile read model excludes a hidden test but keeps a
 *       visible one — the same read the frontend view + REST profile use.
 */
final class MeasurementShowOnProfileTest extends WP_UnitTestCase {

    public function test_show_on_profile_defaults_visible_and_round_trips(): void {
        $repo = new MeasurementDefinitionsRepository();

        $id = $repo->create( [
            'category_id' => 1,
            'name'        => 'Height',
            'value_type'  => 'numeric',
            'unit'        => 'cm',
            'frequency'   => 'annual',
        ] );
        $def = $repo->find( $id );
        $this->assertSame( 1, (int) $def->show_on_profile, 'a new test defaults to visible on the profile' );

        $repo->update( $id, [ 'show_on_profile' => 0 ] );
        $def = $repo->find( $id );
        $this->assertSame( 0, (int) $def->show_on_profile, 'toggling off persists' );

        $repo->update( $id, [ 'show_on_profile' => 1 ] );
        $def = $repo->find( $id );
        $this->assertSame( 1, (int) $def->show_on_profile, 'toggling back on persists' );
    }

    public function test_hidden_test_is_excluded_from_the_player_profile(): void {
        global $wpdb;
        $defs    = new MeasurementDefinitionsRepository();
        $results = new MeasurementResultsRepository();

        $visible = $defs->create( [
            'category_id' => 1, 'name' => 'Sprint 30m', 'value_type' => 'numeric',
            'unit' => 's', 'frequency' => 'quarterly', 'show_on_profile' => 1,
        ] );
        $hidden = $defs->create( [
            'category_id' => 1, 'name' => 'Secret metric', 'value_type' => 'numeric',
            'unit' => 'x', 'frequency' => 'quarterly', 'show_on_profile' => 0,
        ] );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'first_name' => 'Tess', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;

        foreach ( [ $visible, $hidden ] as $def_id ) {
            $results->create( [
                'player_id' => $player_id, 'definition_id' => $def_id,
                'recorded_date' => '2026-06-20', 'value_numeric' => 4.5,
            ] );
        }

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $player_id );

        $names = [];
        foreach ( $profile as $cat ) {
            foreach ( (array) $cat['tests'] as $test ) {
                $names[] = (string) $test['name'];
            }
        }

        $this->assertContains( 'Sprint 30m', $names, 'a visible test shows on the profile' );
        $this->assertNotContains( 'Secret metric', $names, 'a hidden test is excluded from the profile' );
    }
}
