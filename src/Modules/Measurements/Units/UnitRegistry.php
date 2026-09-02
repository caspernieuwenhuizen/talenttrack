<?php
namespace TT\Modules\Measurements\Units;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * UnitRegistry (#3273) — the units an academy can measure in, with the one
 * thing a caption never had: a dimension and a factor to that dimension's base.
 *
 * Club-scoped like every other repository here, and memoised per request
 * because the entry grid resolves the same unit once per player row.
 *
 * Reads defensively: an install whose migration has not run yet gets an empty
 * registry rather than a fatal, and every caller treats an empty registry as
 * "dimensionless", which is the pre-#3273 behaviour. That is what lets the
 * units layer be introduced without a hard ordering dependency on the schema.
 */
class UnitRegistry {

    /** @var array<int, array<int, UnitRow>> club_id => id => unit */
    private static array $by_id = [];

    /** @var array<int, array<string, UnitRow>> club_id => symbol => unit */
    private static array $by_symbol = [];

    /** @var array<int, bool> */
    private static array $loaded = [];

    /**
     * Every active unit for the current club, ordered for a picker.
     *
     * @return list<UnitRow>
     */
    public function all(): array {
        $club = (int) CurrentClub::id();
        $this->load( $club );
        return array_values( self::$by_id[ $club ] ?? [] );
    }

    /**
     * Units of one dimension, for the entry-unit picker once a dimension is
     * chosen.
     *
     * @return list<UnitRow>
     */
    public function forDimension( string $dimension ): array {
        return array_values( array_filter(
            $this->all(),
            static fn( UnitRow $u ): bool => $u->dimension === $dimension
        ) );
    }

    public function byId( ?int $id ): ?UnitRow {
        if ( ! $id ) return null;
        $club = (int) CurrentClub::id();
        $this->load( $club );
        return self::$by_id[ $club ][ $id ] ?? null;
    }

    public function bySymbol( string $symbol ): ?UnitRow {
        $symbol = trim( $symbol );
        if ( $symbol === '' ) return null;
        $club = (int) CurrentClub::id();
        $this->load( $club );
        return self::$by_symbol[ $club ][ $symbol ] ?? null;
    }

    /**
     * The base unit of a dimension — the one `value_numeric` is stored in.
     */
    public function baseFor( string $dimension ): ?UnitRow {
        return $this->bySymbol( Dimensions::baseSymbol( $dimension ) );
    }

    /**
     * Drop the memo. Tests that write units mid-request need this; production
     * code does not, because a request never edits the registry and then reads
     * it back.
     */
    public static function flush(): void {
        self::$by_id    = [];
        self::$by_symbol = [];
        self::$loaded    = [];
    }

    private function load( int $club_id ): void {
        if ( ! empty( self::$loaded[ $club_id ] ) ) return;
        self::$loaded[ $club_id ]    = true;
        self::$by_id[ $club_id ]     = [];
        self::$by_symbol[ $club_id ] = [];

        global $wpdb;
        $table = $wpdb->prefix . 'tt_measurement_units';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, club_id, symbol, dimension, factor_to_base, display_precision, sort_order
               FROM {$table}
              WHERE club_id = %d AND is_active = 1
              ORDER BY sort_order ASC, symbol ASC",
            $club_id
        ) );
        if ( ! is_array( $rows ) ) return;

        foreach ( $rows as $row ) {
            $unit = UnitRow::fromRow( $row );
            self::$by_id[ $club_id ][ $unit->id ]         = $unit;
            self::$by_symbol[ $club_id ][ $unit->symbol ] = $unit;
        }
    }
}
