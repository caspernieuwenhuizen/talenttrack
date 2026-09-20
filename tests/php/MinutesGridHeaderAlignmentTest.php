<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Frontend\FrontendMinutesGridView;

/**
 * #3845 — the minutes grid's `Min | G | A` labels name the column they sit
 * above.
 *
 * The sub-header row was the only row in the table that did not emit its
 * own leading cell: it relied on the Player header's `rowspan`, which #3531
 * removed so the two score rows could carry their own labels in the frozen
 * column. Every label shifted one column left — "Min" into the player
 * column, `G` above the minutes box — while the stored numbers stayed
 * right. On a grid whose premise is that a spreadsheet user needs no
 * explanation, that is a coach typing a goal into the assists box.
 *
 * Column counts are what this asserts, rather than a string of markup:
 * every row of a table is the same width, and the bug was a row that was
 * not.
 */
final class MinutesGridHeaderAlignmentTest extends WP_UnitTestCase {

    /** Three columns per match plus three for the totals, after the player column. */
    private const PER_GROUP = 3;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    /** @param list<array<string,mixed>> $activities */
    private function renderGrid( array $activities, array $players, array $cells ): string {
        $method = new ReflectionMethod( FrontendMinutesGridView::class, 'renderGrid' );
        $method->setAccessible( true );

        ob_start();
        $method->invoke( null, [
            'players'    => $players,
            'activities' => $activities,
            'cells'      => $cells,
        ] );
        return (string) ob_get_clean();
    }

    private function twoMatchGrid(): string {
        $activities = [
            [
                'activity_id' => 11,
                'title'       => 'Ajax U17',
                'session_date'=> '2026-09-05',
                'is_home'     => true,
                'home_score'  => 2,
                'away_score'  => 1,
            ],
            [
                'activity_id' => 12,
                'title'       => 'PSV U17',
                'session_date'=> '2026-09-12',
                'is_home'     => false,
                'home_score'  => null,
                'away_score'  => null,
            ],
        ];
        $players = [
            [ 'player_id' => 1, 'first_name' => 'Sem', 'last_name' => 'Bakker', 'jersey_number' => 7 ],
            [ 'player_id' => 2, 'first_name' => 'Noah', 'last_name' => 'Jansen', 'jersey_number' => null ],
        ];
        $cells = [
            1 => [
                11 => [ 'minutes' => 70, 'squad' => true, 'goals' => 1, 'assists' => 0 ],
                12 => [ 'minutes' => 35, 'squad' => true, 'goals' => 0, 'assists' => 1 ],
            ],
            // Player 2 misses the second match entirely — the "not in squad"
            // cell spans three columns, which is part of what is counted.
            2 => [
                11 => [ 'minutes' => 0, 'squad' => true, 'goals' => 0, 'assists' => 0 ],
            ],
        ];

        return $this->renderGrid( $activities, $players, $cells );
    }

    /** @return list<int> the column count of each row, colspans honoured */
    private function rowWidths( string $html ): array {
        preg_match_all( '#<tr\b[^>]*>(.*?)</tr>#s', $html, $rows );

        $widths = [];
        foreach ( $rows[1] as $row ) {
            preg_match_all( '#<(?:th|td)\b([^>]*)>#', $row, $cells );
            $width = 0;
            foreach ( $cells[1] as $attrs ) {
                $width += preg_match( '#colspan="(\d+)"#', $attrs, $m ) ? (int) $m[1] : 1;
            }
            $widths[] = $width;
        }
        return $widths;
    }

    public function test_every_row_is_the_same_width(): void {
        $widths = $this->rowWidths( $this->twoMatchGrid() );

        $this->assertNotEmpty( $widths );
        $expected = 1 + ( 2 * self::PER_GROUP ) + self::PER_GROUP; // player + 2 matches + total
        foreach ( $widths as $i => $width ) {
            $this->assertSame( $expected, $width, 'row ' . $i . ' is not the table\'s width' );
        }
    }

    /** The row the bug was in, named rather than merely counted. */
    public function test_the_sub_header_opens_with_an_empty_frozen_corner(): void {
        $html = $this->twoMatchGrid();

        $this->assertSame(
            1,
            preg_match( '#<tr class="tt-agrid__subhead">(.*?)</tr>#s', $html, $m ),
            'the sub-header row is rendered'
        );
        $row = $m[1];

        $this->assertSame(
            1,
            preg_match( '#^<th class="tt-agrid__player" scope="col"></th>#', $row ),
            'the sub-header opens with the frozen, empty corner cell'
        );

        // Min | G | A per match, then once more for the totals.
        preg_match_all( '#<th class="tt-agrid__sub#', $row, $subs );
        $this->assertCount( 3 * self::PER_GROUP, $subs[0] );
    }

    /**
     * What the displacement actually did: the minutes label ended up over
     * the player column and `G` over the minutes box.
     */
    public function test_the_first_label_of_a_match_group_is_the_minutes_one(): void {
        $html = $this->twoMatchGrid();
        preg_match( '#<tr class="tt-agrid__subhead">(.*?)</tr>#s', $html, $m );

        preg_match_all( '#<th class="tt-agrid__sub[^>]*>([^<]*)</th>#', $m[1], $labels );
        $this->assertCount( 3 * self::PER_GROUP, $labels[1] );

        // The first cell of each group of three is the minutes column, and it
        // is the one carrying the separator rule that groups a match.
        preg_match_all( '#<th class="tt-agrid__sub([^"]*)"#', $m[1], $classes );
        foreach ( $classes[1] as $i => $extra ) {
            if ( $i % self::PER_GROUP === 0 ) {
                $this->assertStringContainsString( 'tt-agrid-cell--sep', $extra, 'the rule falls before the minutes box' );
            } else {
                $this->assertStringNotContainsString( 'tt-agrid-cell--sep', $extra );
            }
        }
    }

    /** Both stat columns stay addressable by the show/hide toggles. */
    public function test_the_stat_headers_keep_their_toggle_hooks(): void {
        $html = $this->twoMatchGrid();
        preg_match( '#<tr class="tt-agrid__subhead">(.*?)</tr>#s', $html, $m );

        preg_match_all( '#data-stat="goals"#', $m[1], $goals );
        preg_match_all( '#data-stat="assists"#', $m[1], $assists );

        $this->assertCount( 3, $goals[0], 'one per match, plus the totals' );
        $this->assertCount( 3, $assists[0] );
    }
}
