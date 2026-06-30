<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;
use TT\Modules\Measurements\Services\PlayerMeasurementProfile;

/**
 * #2138 — status value type with operator-defined coloured levels.
 *
 * Smoke coverage for the new domain layer: the curated palette guards an
 * unknown token; the levels repository replaces a definition's ordered set
 * (archiving removed rows, ordinal = position); and a status result resolves
 * to the matched level's token on the player profile read model — the same
 * shape the REST API and the rendered HTML consume.
 */
final class MeasurementStatusLevelsTest extends WP_UnitTestCase {

    private function makeStatusDefinition(): int {
        return ( new MeasurementDefinitionsRepository() )->create( [
            'category_id' => 1,
            'name'        => 'Match readiness',
            'value_type'  => 'status',
            'direction'   => 'neutral',
            'frequency'   => 'adhoc',
        ] );
    }

    public function test_palette_guards_unknown_token(): void {
        $this->assertTrue( MeasurementLevelPalette::isValid( 'green' ) );
        $this->assertFalse( MeasurementLevelPalette::isValid( 'chartreuse' ) );
        $this->assertSame( MeasurementLevelPalette::DEFAULT_TOKEN, MeasurementLevelPalette::safe( 'chartreuse' ) );
        $this->assertSame( 'tt-mlvl-swatch--red', MeasurementLevelPalette::cssClass( 'red' ) );
    }

    public function test_status_value_type_persists(): void {
        $id  = $this->makeStatusDefinition();
        $this->assertGreaterThan( 0, $id );
        $def = ( new MeasurementDefinitionsRepository() )->find( $id );
        $this->assertSame( 'status', (string) $def->value_type );
    }

    public function test_replace_levels_sets_ordinal_and_archives_removed(): void {
        $id   = $this->makeStatusDefinition();
        $repo = new MeasurementLevelsRepository();

        $repo->replaceForDefinition( $id, [
            [ 'label' => 'At risk',  'color_token' => 'red' ],
            [ 'label' => 'Watch',    'color_token' => 'amber' ],
            [ 'label' => 'On track', 'color_token' => 'green' ],
        ] );

        $levels = $repo->listForDefinition( $id );
        $this->assertCount( 3, $levels );
        $this->assertSame( 'At risk', (string) $levels[0]->label );
        $this->assertSame( 1, (int) $levels[0]->ordinal );
        $this->assertSame( 'On track', (string) $levels[2]->label );
        $this->assertSame( 3, (int) $levels[2]->ordinal );

        // Re-save without "Watch" — it should be archived out of the live set,
        // keeping the others (matched by id) and re-numbering ordinals.
        $keep_first = (int) $levels[0]->id;
        $keep_last  = (int) $levels[2]->id;
        $repo->replaceForDefinition( $id, [
            [ 'id' => $keep_first, 'label' => 'At risk',  'color_token' => 'red' ],
            [ 'id' => $keep_last,  'label' => 'On track', 'color_token' => 'green' ],
        ] );

        $after = $repo->listForDefinition( $id );
        $this->assertCount( 2, $after );
        $labels = array_map( static fn ( $l ) => (string) $l->label, $after );
        $this->assertSame( [ 'At risk', 'On track' ], $labels );
    }

    public function test_unknown_color_token_is_coerced_on_save(): void {
        $id   = $this->makeStatusDefinition();
        $repo = new MeasurementLevelsRepository();
        $repo->replaceForDefinition( $id, [
            [ 'label' => 'Mystery', 'color_token' => 'chartreuse' ],
        ] );
        $levels = $repo->listForDefinition( $id );
        $this->assertCount( 1, $levels );
        $this->assertSame( MeasurementLevelPalette::DEFAULT_TOKEN, (string) $levels[0]->color_token );
    }

    public function test_profile_resolves_status_level_token(): void {
        $id   = $this->makeStatusDefinition();
        ( new MeasurementLevelsRepository() )->replaceForDefinition( $id, [
            [ 'label' => 'At risk',  'color_token' => 'red' ],
            [ 'label' => 'On track', 'color_token' => 'green' ],
        ] );

        // Insert a real player row so the profile read model has a subject
        // (the age-group lookup tolerates an empty result).
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Status',
            'last_name'  => 'Player',
            'age_group'  => '',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $player_id );

        ( new MeasurementResultsRepository() )->create( [
            'player_id'     => $player_id,
            'definition_id' => $id,
            'recorded_date' => current_time( 'Y-m-d' ),
            'value_text'    => 'On track',
            'value_numeric' => 2,
        ] );

        $profile = ( new PlayerMeasurementProfile() )->forPlayer( $player_id );
        $found   = null;
        foreach ( $profile as $cat ) {
            foreach ( (array) $cat['tests'] as $t ) {
                if ( (int) $t['definition_id'] === $id ) { $found = $t; break 2; }
            }
        }
        $this->assertNotNull( $found, 'status test appears in the profile' );
        $this->assertSame( 'status', (string) $found['value_type'] );
        $this->assertSame( 'green', (string) $found['level_token'] );
        $this->assertSame( '', (string) $found['flag'], 'status tests carry no green/amber target flag' );
    }

    public function test_repository_resolves_label_to_token(): void {
        $id   = $this->makeStatusDefinition();
        $repo = new MeasurementLevelsRepository();
        $repo->replaceForDefinition( $id, [
            [ 'label' => 'At risk',  'color_token' => 'red' ],
            [ 'label' => 'On track', 'color_token' => 'green' ],
        ] );

        $match = $repo->findByLabel( $id, 'On track' );
        $this->assertNotNull( $match );
        $this->assertSame( 'green', (string) $match->color_token );
        $this->assertNull( $repo->findByLabel( $id, 'Nope' ) );
    }
}
