<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerOrder (#3518, epic #3513) — shirt order, one definition.
 *
 * A coach reading the monthly report looks for a player by number, so a list of
 * players reads in shirt order. One comparator rather than an `ORDER BY` per
 * query, because the tables come from four different places — the squad query,
 * the attendance ranking, the test trends, and (from #3516) the match squads —
 * and a rule spelled out four times drifts.
 *
 * **Players with no number sort last**, among themselves by name. A null is not
 * a zero: sorting unnumbered players to the top would put a trialist above the
 * captain.
 *
 * Not every table: where the order *is* the information — minutes by share
 * played, the attention list by severity — it stays as it is. Sorting those by
 * number would throw away what the table is for.
 */
final class PlayerOrder {

    /**
     * Jersey numbers keyed by player id, from rows carrying `jersey_number`.
     *
     * @param array<int,object>|list<array<string,mixed>> $players
     * @return array<int,int|null>
     */
    public static function jerseys( iterable $players ): array {
        $out = [];
        foreach ( $players as $key => $player ) {
            if ( is_object( $player ) ) {
                $id     = isset( $player->id ) ? (int) $player->id : (int) $key;
                $jersey = $player->jersey_number ?? null;
            } else {
                $id     = isset( $player['player_id'] ) ? (int) $player['player_id'] : (int) $key;
                $jersey = $player['jersey_number'] ?? null;
            }
            $out[ $id ] = is_numeric( $jersey ) ? (int) $jersey : null;
        }
        return $out;
    }

    /**
     * Rows in shirt order. Rows whose player has no number, or is not in the
     * squad map at all, come last by name.
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,int|null>       $jerseys
     * @return list<array<string,mixed>>
     */
    public static function sort( array $rows, array $jerseys, string $id_key = 'player_id', string $name_key = 'name' ): array {
        usort(
            $rows,
            /**
             * @param array<string,mixed> $a
             * @param array<string,mixed> $b
             */
            static function ( array $a, array $b ) use ( $jerseys, $id_key, $name_key ): int {
                $ja = $jerseys[ (int) ( $a[ $id_key ] ?? 0 ) ] ?? null;
                $jb = $jerseys[ (int) ( $b[ $id_key ] ?? 0 ) ] ?? null;

                if ( $ja !== $jb ) {
                    if ( $ja === null ) return 1;
                    if ( $jb === null ) return -1;
                    return $ja <=> $jb;
                }

                // Same number — a mid-season reissue — or both unnumbered.
                // Name keeps the order deterministic either way.
                return strcasecmp( (string) ( $a[ $name_key ] ?? '' ), (string) ( $b[ $name_key ] ?? '' ) );
            }
        );

        return $rows;
    }

    /**
     * The `ORDER BY` for a squad query, with unnumbered players last.
     *
     * Literal SQL, no user input. Kept beside the comparator so the two cannot
     * disagree about where a null belongs.
     */
    public static function sqlOrderBy( string $alias = 'p' ): string {
        $alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
        return "{$alias}.jersey_number IS NULL ASC, {$alias}.jersey_number ASC, {$alias}.last_name ASC, {$alias}.first_name ASC";
    }
}
