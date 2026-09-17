<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Analytics\Reports\PlayerOrder;

/**
 * #3518 (epic #3513) — shirt order, and where a player without a number goes.
 *
 * The rule is small and the failure is loud: a coach scanning for number 7
 * finds it in a different place on every page. What is pinned here is the one
 * judgement in it — **a null jersey is not a zero**. Sorting unnumbered
 * players to the top would open every table with the trialists.
 */
final class PlayerOrderTest extends WP_UnitTestCase {

    /** @return list<array<string,mixed>> */
    private function rows(): array {
        return [
            [ 'player_id' => 1, 'name' => 'Anna Bakker' ],
            [ 'player_id' => 2, 'name' => 'Bram de Vries' ],
            [ 'player_id' => 3, 'name' => 'Cees Jansen' ],
            [ 'player_id' => 4, 'name' => 'Dirk Smit' ],
        ];
    }

    private function names( array $rows ): array {
        return array_map( static fn( array $r ): string => (string) $r['name'], $rows );
    }

    public function test_rows_sort_by_jersey_number(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [ 1 => 9, 2 => 2, 3 => 11, 4 => 1 ] );

        $this->assertSame( [ 'Dirk Smit', 'Bram de Vries', 'Anna Bakker', 'Cees Jansen' ], $this->names( $sorted ) );
    }

    /** 2 before 11 — string ordering would put 11 first. */
    public function test_numbers_sort_numerically_not_as_text(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [ 1 => 2, 2 => 11, 3 => 3, 4 => 20 ] );

        $this->assertSame( [ 'Anna Bakker', 'Cees Jansen', 'Bram de Vries', 'Dirk Smit' ], $this->names( $sorted ) );
    }

    public function test_players_without_a_number_come_last_by_name(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [ 1 => null, 2 => 7, 3 => null, 4 => 3 ] );

        $this->assertSame( [ 'Dirk Smit', 'Bram de Vries', 'Anna Bakker', 'Cees Jansen' ], $this->names( $sorted ) );
    }

    /** A row for somebody not in the squad map is unnumbered, not first. */
    public function test_an_unknown_player_sorts_last(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [ 2 => 4 ] );

        $this->assertSame( 'Bram de Vries', $this->names( $sorted )[0] );
    }

    /** A mid-season reissue must not make the order wobble between renders. */
    public function test_a_shared_number_is_ordered_deterministically_by_name(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [ 1 => 7, 2 => 7, 3 => 1, 4 => 2 ] );

        $this->assertSame( [ 'Cees Jansen', 'Dirk Smit', 'Anna Bakker', 'Bram de Vries' ], $this->names( $sorted ) );
    }

    public function test_everyone_unnumbered_reads_alphabetically(): void {
        $sorted = PlayerOrder::sort( $this->rows(), [] );

        $this->assertSame( [ 'Anna Bakker', 'Bram de Vries', 'Cees Jansen', 'Dirk Smit' ], $this->names( $sorted ) );
    }

    // ── reading jerseys off a squad ────────────────────────────────────

    public function test_jerseys_are_read_from_objects_keyed_by_id(): void {
        $squad = [
            5 => (object) [ 'id' => 5, 'jersey_number' => '10' ],
            6 => (object) [ 'id' => 6, 'jersey_number' => null ],
        ];

        $this->assertSame( [ 5 => 10, 6 => null ], PlayerOrder::jerseys( $squad ) );
    }

    public function test_jerseys_are_read_from_rows(): void {
        $rows = [
            [ 'player_id' => 5, 'jersey_number' => 10 ],
            [ 'player_id' => 6 ],
        ];

        $this->assertSame( [ 5 => 10, 6 => null ], PlayerOrder::jerseys( $rows ) );
    }

    // ── the SQL half ───────────────────────────────────────────────────

    /**
     * The comparator and the query have to agree about nulls, or the roster
     * and the attendance table disagree about where the same player belongs.
     */
    public function test_the_sql_order_also_puts_nulls_last(): void {
        $sql = PlayerOrder::sqlOrderBy( 'p' );

        $this->assertStringContainsString( 'p.jersey_number IS NULL ASC', $sql );
        $this->assertStringContainsString( 'p.jersey_number ASC', $sql );
    }

    public function test_the_sql_alias_is_not_injectable(): void {
        $this->assertStringNotContainsString( ';', PlayerOrder::sqlOrderBy( 'p; DROP TABLE x' ) );
    }
}
