<?php
/**
 * Migration: 0252_measurement_units_real
 *
 * #3273 — the unit of measure becomes a property of the datum instead of a
 * caption printed after it.
 *
 * WHAT THE UNIT ACTUALLY WAS
 *
 * A string on the definition. `tt_measurement_definitions.unit VARCHAR(50)`,
 * filled from a `measurement_unit` lookup or from a free-text box, and read
 * in exactly one kind of place: appending the symbol to a rendered value.
 * `tt_measurement_results.value_numeric` was a bare number whose meaning
 * depended on whatever that string said *at read time*.
 *
 * Three things followed, all of them silent:
 *
 * - The growth chart divided by 100. `BmiSeriesBuilder` found the height test
 *   by name and assumed centimetres; `m` is a seeded, selectable unit, so an
 *   academy recording height in metres got a BMI two orders of magnitude out
 *   and no error anywhere.
 * - Editing a definition rewrote history. The unit lived on the definition,
 *   so changing it redefined every result already recorded against it.
 * - A duration had no representation. `min` being a label, `5.30` meant 5.3
 *   minutes — 5:18 — and the entry field rejected the colon outright.
 *
 * WHAT REPLACES IT
 *
 * A registry (`tt_measurement_units`) where a unit carries its dimension and
 * its factor to that dimension's SI base. The definition declares the
 * dimension and the unit staff type in; each result stores the canonical base
 * value *and* the unit and value it was entered in, so a row is
 * self-describing and a later definition edit cannot reach back into it.
 *
 * WHY DECIMAL(14,5)
 *
 * Canonical storage is SI — seconds, metres, kilograms — so a height that used
 * to be `182.000` cm is now `1.82000` m. At the old DECIMAL(12,3) that would
 * have been 1mm precision for every length in the product. 14,5 keeps metres
 * at 0.01mm and seconds at 10µs, which costs three bytes a row and removes the
 * question permanently.
 *
 * WHY THE BACKFILL IS ALLOWED TO ASSUME
 *
 * It reads every result as having been entered in its definition's *current*
 * unit. There is no other information — but that is also precisely the
 * assumption every read path has been making implicitly since 0175. The
 * difference is that after this migration the assumption is recorded on the
 * row instead of re-derived, so it can never drift again.
 *
 * A definition whose unit string matches no registry symbol is left
 * `dimensionless`: its values are untouched, it converts to nothing, and the
 * tests view lists it for an operator to classify. Guessing would be worse
 * than saying "this one still needs a dimension".
 *
 * Idempotent throughout: every ALTER is column-guarded, the seed is
 * INSERT-if-absent, and the backfill only touches rows it has not stamped.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /**
     * The seed registry. `factor` converts a value in this unit to the
     * dimension's base unit; the base unit of each dimension has factor 1.
     *
     * `%`, `reps`, `level` and `bpm` are dimensions of their own rather than
     * forced into a physical one — a percentage is not a ratio of two lengths
     * here, it is a score out of a hundred, and treating it as SI-dimensionless
     * would invite conversions nobody wants.
     *
     * @var list<array{symbol:string,dimension:string,factor:string,precision:int,sort:int}>
     */
    private const UNITS = [
        [ 'symbol' => 's',     'dimension' => 'time',   'factor' => '1',      'precision' => 2, 'sort' => 10 ],
        [ 'symbol' => 'min',   'dimension' => 'time',   'factor' => '60',     'precision' => 2, 'sort' => 20 ],
        [ 'symbol' => 'ms',    'dimension' => 'time',   'factor' => '0.001',  'precision' => 0, 'sort' => 30 ],
        [ 'symbol' => 'm',     'dimension' => 'length', 'factor' => '1',      'precision' => 2, 'sort' => 40 ],
        [ 'symbol' => 'cm',    'dimension' => 'length', 'factor' => '0.01',   'precision' => 1, 'sort' => 50 ],
        [ 'symbol' => 'mm',    'dimension' => 'length', 'factor' => '0.001',  'precision' => 0, 'sort' => 60 ],
        [ 'symbol' => 'km',    'dimension' => 'length', 'factor' => '1000',   'precision' => 3, 'sort' => 70 ],
        [ 'symbol' => 'kg',    'dimension' => 'mass',   'factor' => '1',      'precision' => 1, 'sort' => 80 ],
        [ 'symbol' => 'g',     'dimension' => 'mass',   'factor' => '0.001',  'precision' => 0, 'sort' => 90 ],
        [ 'symbol' => 'reps',  'dimension' => 'count',  'factor' => '1',      'precision' => 0, 'sort' => 100 ],
        [ 'symbol' => 'bpm',   'dimension' => 'rate',   'factor' => '1',      'precision' => 0, 'sort' => 110 ],
        [ 'symbol' => '%',     'dimension' => 'ratio',  'factor' => '1',      'precision' => 1, 'sort' => 120 ],
        [ 'symbol' => 'level', 'dimension' => 'level',  'factor' => '1',      'precision' => 1, 'sort' => 130 ],
    ];

    public function getName(): string {
        return '0252_measurement_units_real';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $definitions = "{$p}tt_measurement_definitions";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $definitions ) ) !== $definitions ) return;

        $this->exec(
            "CREATE TABLE IF NOT EXISTS {$p}tt_measurement_units (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                uuid VARCHAR(36) DEFAULT NULL,
                symbol VARCHAR(50) NOT NULL,
                dimension VARCHAR(20) NOT NULL DEFAULT 'dimensionless',
                factor_to_base DECIMAL(20,10) NOT NULL DEFAULT 1,
                display_precision TINYINT UNSIGNED NOT NULL DEFAULT 2,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_uuid (uuid),
                UNIQUE KEY uniq_club_symbol (club_id, symbol),
                KEY idx_club (club_id),
                KEY idx_dimension (dimension)
            ) {$charset}"
        );

        $this->seedUnits();

        $this->addColumn( $definitions, 'dimension', "ADD COLUMN dimension VARCHAR(20) NOT NULL DEFAULT 'dimensionless' AFTER unit" );
        $this->addColumn( $definitions, 'entry_unit_id', 'ADD COLUMN entry_unit_id BIGINT UNSIGNED DEFAULT NULL AFTER dimension' );
        $this->addColumn( $definitions, 'numeric_format', "ADD COLUMN numeric_format VARCHAR(16) NOT NULL DEFAULT 'plain' AFTER entry_unit_id" );

        $results = "{$p}tt_measurement_results";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $results ) ) === $results ) {
            $this->addColumn( $results, 'entered_unit_id', 'ADD COLUMN entered_unit_id BIGINT UNSIGNED DEFAULT NULL AFTER value_text' );
            $this->addColumn( $results, 'entered_value', 'ADD COLUMN entered_value DECIMAL(14,5) DEFAULT NULL AFTER entered_unit_id' );
            $this->exec( "ALTER TABLE {$results} MODIFY COLUMN value_numeric DECIMAL(14,5) DEFAULT NULL" );
        }

        $targets = "{$p}tt_measurement_targets";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $targets ) ) === $targets ) {
            foreach ( [ 'green_min', 'green_max', 'amber_min', 'amber_max' ] as $col ) {
                $this->exec( "ALTER TABLE {$targets} MODIFY COLUMN {$col} DECIMAL(14,5) DEFAULT NULL" );
            }
        }

        $this->backfill();
    }

    /**
     * Seed the registry, once per club that already has definitions. A club
     * added later gets its rows from the same seed list via UnitRegistry's
     * ensure-seeded path, so this only has to cover what exists now.
     */
    private function seedUnits(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_measurement_units";
        $now   = current_time( 'mysql', true );

        $club_ids = $wpdb->get_col( "SELECT DISTINCT club_id FROM {$p}tt_measurement_definitions" );
        if ( ! is_array( $club_ids ) || empty( $club_ids ) ) $club_ids = [ 1 ];

        foreach ( $club_ids as $club_id ) {
            $club_id = (int) $club_id ?: 1;
            foreach ( self::UNITS as $u ) {
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE club_id = %d AND symbol = %s LIMIT 1",
                    $club_id, $u['symbol']
                ) );
                if ( $exists > 0 ) continue;

                $wpdb->insert( $table, [
                    'club_id'           => $club_id,
                    'uuid'              => wp_generate_uuid4(),
                    'symbol'            => $u['symbol'],
                    'dimension'         => $u['dimension'],
                    'factor_to_base'    => $u['factor'],
                    'display_precision' => $u['precision'],
                    'sort_order'        => $u['sort'],
                    'created_at'        => $now,
                ] );
            }
        }
    }

    /**
     * Stamp every definition with its dimension + entry unit, then convert its
     * results and target bands into the dimension's base unit.
     *
     * Guarded on `entry_unit_id IS NULL` so a re-run cannot convert twice: the
     * definition is stamped last, after its rows are converted, and a
     * definition already stamped is skipped whole.
     */
    private function backfill(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $defs = $wpdb->get_results(
            "SELECT id, club_id, unit, value_type
               FROM {$p}tt_measurement_definitions
              WHERE entry_unit_id IS NULL"
        );
        if ( ! is_array( $defs ) ) return;

        foreach ( $defs as $def ) {
            $def_id  = (int) $def->id;
            $club_id = (int) ( $def->club_id ?: 1 );
            $symbol  = trim( (string) ( $def->unit ?? '' ) );

            // Only `numeric` carries a physical quantity. scale / passfail /
            // status are ordinal or boolean and stay dimensionless whatever
            // string somebody typed in the unit box.
            $numeric = (string) ( $def->value_type ?? 'numeric' ) === 'numeric';

            $unit = $symbol === '' || ! $numeric ? null : $wpdb->get_row( $wpdb->prepare(
                "SELECT id, dimension, factor_to_base
                   FROM {$p}tt_measurement_units
                  WHERE club_id = %d AND symbol = %s LIMIT 1",
                $club_id, $symbol
            ) );

            if ( ! $unit ) {
                // Dimensionless: nothing to convert, but stamp the dimension so
                // the row is not revisited on a re-run.
                $wpdb->update(
                    "{$p}tt_measurement_definitions",
                    [ 'dimension' => 'dimensionless' ],
                    [ 'id' => $def_id ]
                );
                continue;
            }

            $factor = (float) $unit->factor_to_base;
            if ( $factor <= 0 ) $factor = 1.0;

            // Results: what is in value_numeric today was entered in this unit.
            // Record that, then convert the stored number to the base.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$p}tt_measurement_results
                    SET entered_value   = value_numeric,
                        entered_unit_id = %d,
                        value_numeric   = value_numeric * %f
                  WHERE definition_id = %d
                    AND value_numeric IS NOT NULL
                    AND entered_unit_id IS NULL",
                (int) $unit->id, $factor, $def_id
            ) );

            // Target bands were expressed in the same unit.
            if ( abs( $factor - 1.0 ) > 0.0000001 ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$p}tt_measurement_targets
                        SET green_min = green_min * %f,
                            green_max = green_max * %f,
                            amber_min = amber_min * %f,
                            amber_max = amber_max * %f
                      WHERE definition_id = %d",
                    $factor, $factor, $factor, $factor, $def_id
                ) );
            }

            $wpdb->update(
                "{$p}tt_measurement_definitions",
                [ 'dimension' => (string) $unit->dimension, 'entry_unit_id' => (int) $unit->id ],
                [ 'id' => $def_id ]
            );
        }
    }

    private function addColumn( string $table, string $column, string $clause ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
        if ( empty( $exists ) ) {
            $this->exec( "ALTER TABLE {$table} {$clause}" );
        }
    }
};
