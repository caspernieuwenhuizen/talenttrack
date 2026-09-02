<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3282 — the backfill migration catches up the profile columns for readings
 * that predate the sync.
 *
 * `ProfileMeasurementSync` subscribes to a save hook, so it only ever fixes a
 * player somebody re-saves. On every install that existed before #3219 and
 * #3281 the majority of readings predate the hook and the profile columns
 * still hold whatever was typed at signup — which is what the pilot reported:
 * a recorded height and an empty profile field.
 *
 * The rows here are written straight to the tables rather than through
 * `MeasurementResultsRepository`, precisely because going through the
 * repository would fire the hook and sync them. Inserting behind it is what
 * reproduces a pre-#3219 install.
 */
final class ProfilePhysiqueBackfillTest extends WP_UnitTestCase {

    private int $club;
    private int $height_def;
    private int $weight_def;

    public function set_up(): void {
        parent::set_up();
        $this->club       = (int) CurrentClub::id();
        $this->height_def = $this->definition( 'Lengte' );
        $this->weight_def = $this->definition( 'Gewicht' );
    }

    private function definition( string $name ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_definitions', [
            'club_id'     => $this->club,
            'category_id' => 1,
            'name'        => $name,
            'value_type'  => 'numeric',
            'direction'   => 'neutral',
            'is_active'   => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A player row with whatever the profile columns should start at. */
    private function player( ?int $height, ?int $weight ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => $this->club,
            'first_name' => 'Backfill',
            'last_name'  => 'Case',
            'status'     => 'active',
            'height_cm'  => $height,
            'weight_kg'  => $weight,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A reading inserted BEHIND the save hook, as a pre-#3219 row would be.
     *
     * @param array<string,mixed> $extra lifecycle columns, for the archived case.
     */
    private function reading( int $player_id, int $definition_id, string $date, float $base_value, array $extra = [] ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_measurement_results', array_merge( [
            'club_id'       => $this->club,
            'player_id'     => $player_id,
            'definition_id' => $definition_id,
            'recorded_date' => $date,
            'value_numeric' => $base_value,
        ], $extra ) );
    }

    private function profile( int $player_id ): object {
        global $wpdb;
        return (object) $wpdb->get_row( $wpdb->prepare(
            "SELECT height_cm, weight_kg FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ), ARRAY_A );
    }

    private function runMigration(): void {
        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0253_backfill_profile_physique.php';
        $migration->up();
    }

    public function test_it_backfills_a_reading_that_predates_the_sync(): void {
        $player = $this->player( 160, 50 );
        $this->reading( $player, $this->height_def, '2026-03-01', 172.0 );
        $this->reading( $player, $this->weight_def, '2026-03-01', 64.0 );

        // Nothing has touched the profile yet — this is the reported state.
        $this->assertSame( 160, (int) $this->profile( $player )->height_cm );

        $this->runMigration();

        $after = $this->profile( $player );
        $this->assertSame( 172, (int) $after->height_cm );
        $this->assertSame( 64, (int) $after->weight_kg );
    }

    /** The most recent reading wins, not the last one inserted. */
    public function test_it_writes_the_most_recent_reading(): void {
        $player = $this->player( 160, null );
        $this->reading( $player, $this->height_def, '2026-06-01', 175.0 );
        $this->reading( $player, $this->height_def, '2026-01-01', 168.0 );

        $this->runMigration();

        $this->assertSame( 175, (int) $this->profile( $player )->height_cm );
    }

    /**
     * A player whose only readings are archived keeps their existing value.
     *
     * The column may predate the series entirely, so there is nothing better
     * to replace it with — and blanking it would lose a number for nothing.
     */
    public function test_a_player_with_only_archived_readings_is_left_alone(): void {
        $player = $this->player( 158, 48 );
        $this->reading( $player, $this->height_def, '2026-03-01', 172.0, [
            'archived_at' => '2026-04-01 00:00:00',
        ] );

        $this->runMigration();

        $after = $this->profile( $player );
        $this->assertSame( 158, (int) $after->height_cm );
        $this->assertSame( 48, (int) $after->weight_kg );
    }

    /** A player with no readings at all is not touched. */
    public function test_a_player_with_no_readings_is_left_alone(): void {
        $player = $this->player( 155, 45 );

        $this->runMigration();

        $after = $this->profile( $player );
        $this->assertSame( 155, (int) $after->height_cm );
        $this->assertSame( 45, (int) $after->weight_kg );
    }

    /**
     * #3273 — readings are stored in the dimension's base unit.
     *
     * A height recorded in centimetres is stored as metres, so a backfill that
     * copied `value_numeric` straight across would write 2 instead of 172 and
     * then have it refused by the range guard. The conversion has to happen,
     * and it happens because the migration calls the service rather than
     * writing its own SQL.
     */
    public function test_a_reading_in_base_units_is_converted(): void {
        global $wpdb;

        $unit_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_measurement_units WHERE symbol = %s LIMIT 1",
            'cm'
        ) );
        if ( $unit_id <= 0 ) {
            $this->markTestSkipped( 'the unit registry (migration 0252) is not present in this fixture' );
        }

        $wpdb->update(
            $wpdb->prefix . 'tt_measurement_definitions',
            [ 'dimension' => 'length', 'entry_unit_id' => $unit_id, 'unit' => 'cm' ],
            [ 'id' => $this->height_def ]
        );

        $player = $this->player( 160, null );
        // 1.72 m in canonical base units — what 172 cm is stored as.
        $this->reading( $player, $this->height_def, '2026-03-01', 1.72 );

        $this->runMigration();

        $this->assertSame( 172, (int) $this->profile( $player )->height_cm );
    }

    /** A mistyped reading must not reach a profile, backfill or not. */
    public function test_an_out_of_range_reading_is_refused(): void {
        $player = $this->player( 160, null );
        $this->reading( $player, $this->height_def, '2026-03-01', 1720.0 );

        $this->runMigration();

        $this->assertSame( 160, (int) $this->profile( $player )->height_cm );
    }

    /** Running it twice writes nothing the second time. */
    public function test_it_is_idempotent(): void {
        $player = $this->player( 160, null );
        $this->reading( $player, $this->height_def, '2026-03-01', 172.0 );

        $this->runMigration();
        $this->assertSame( 172, (int) $this->profile( $player )->height_cm );

        $this->runMigration();
        $this->assertSame( 172, (int) $this->profile( $player )->height_cm );
    }
}
