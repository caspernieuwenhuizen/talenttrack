<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FilterBar;

/**
 * #2448 — FilterBar::paramNames() derives the query params a bar emits from
 * its own group config.
 *
 * This is what lets saved views work on any surface. #2385 hardcoded six
 * report params in three places; on a list view, whose params are
 * `filter[<key>]` / `search` / `orderby`, that whitelist matched nothing and
 * saved an empty preset without erroring. Deriving the list means a surface
 * cannot be wired up wrong — the bar already knows what it emits.
 */
final class FilterBarParamNamesTest extends WP_UnitTestCase {

    public function test_form_backed_groups_contribute_their_input_name(): void {
        $names = FilterBar::paramNames( [
            [ 'type' => 'select', 'key' => 'team', 'name' => 'team_id' ],
            [ 'type' => 'text',   'key' => 'howmany', 'name' => 'n' ],
            [ 'type' => 'toggle', 'key' => 'cancelled', 'name' => 'show_cancelled' ],
        ] );

        $this->assertSame( [ 'team_id', 'n', 'show_cancelled' ], $names );
    }

    public function test_date_range_contributes_both_ends(): void {
        $names = FilterBar::paramNames( [
            [
                'type' => 'date_range',
                'key'  => 'range',
                'from' => [ 'name' => 'from', 'value' => '' ],
                'to'   => [ 'name' => 'to',   'value' => '' ],
            ],
        ] );

        $this->assertSame( [ 'from', 'to' ], $names );
    }

    public function test_link_based_groups_use_explicit_param_when_given(): void {
        // period/status carry their param inside each option's URL, so it
        // cannot be read off the group the way a form input's name can.
        $names = FilterBar::paramNames( [
            [ 'type' => 'period', 'key' => 'when',   'param' => 'period', 'options' => [] ],
            [ 'type' => 'status', 'key' => 'state',  'param' => 'archived', 'options' => [] ],
        ] );

        $this->assertSame( [ 'period', 'archived' ], $names );
    }

    public function test_link_based_groups_fall_back_to_their_key(): void {
        // The reports already name the group after the param, so they keep
        // working without declaring `param`.
        $names = FilterBar::paramNames( [
            [ 'type' => 'period', 'key' => 'period', 'options' => [] ],
        ] );

        $this->assertSame( [ 'period' ], $names );
    }

    /**
     * The regression guard that matters: this exact group set is the
     * attendance leaderboard's, and it must derive to the six params #2385
     * had hardcoded — no more, no fewer.
     */
    public function test_leaderboard_groups_reproduce_the_retired_hardcoded_whitelist(): void {
        $names = FilterBar::paramNames( [
            [ 'type' => 'select',     'key' => 'team',    'name' => 'team_id' ],
            [ 'type' => 'period',     'key' => 'period',  'options' => [] ],
            [ 'type' => 'select',     'key' => 'type',    'name' => 'activity_type_key' ],
            [
                'type' => 'date_range', 'key' => 'range',
                'from' => [ 'name' => 'from' ], 'to' => [ 'name' => 'to' ],
            ],
            [ 'type' => 'text',       'key' => 'howmany', 'name' => 'n' ],
        ] );

        sort( $names );
        $expected = [ 'activity_type_key', 'from', 'n', 'period', 'team_id', 'to' ];
        $this->assertSame( $expected, $names );
    }

    public function test_list_table_param_shape_is_derived(): void {
        $names = FilterBar::paramNames( [
            [ 'type' => 'select', 'key' => 'team',     'name' => 'filter[team_id]' ],
            [ 'type' => 'text',   'key' => 'search',   'name' => 'search' ],
            [ 'type' => 'status', 'key' => 'archived', 'param' => 'filter[archived]', 'options' => [] ],
        ] );

        $this->assertSame( [ 'filter[team_id]', 'search', 'filter[archived]' ], $names );
    }

    public function test_duplicates_and_empty_names_are_skipped(): void {
        $names = FilterBar::paramNames( [
            [ 'type' => 'select', 'key' => 'a', 'name' => 'team_id' ],
            [ 'type' => 'select', 'key' => 'b', 'name' => 'team_id' ],
            [ 'type' => 'select', 'key' => 'c', 'name' => '' ],
            [ 'type' => 'select', 'key' => 'd' ],
        ] );

        $this->assertSame( [ 'team_id' ], $names );
    }

    public function test_unknown_and_malformed_groups_are_ignored(): void {
        $names = FilterBar::paramNames( [
            [ 'type' => 'mystery', 'key' => 'x', 'name' => 'ignored' ],
            [ 'name' => 'no_type' ],
            'not an array',
            [],
        ] );

        $this->assertSame( [], $names );
    }

    public function test_no_groups_yields_no_params(): void {
        $this->assertSame( [], FilterBar::paramNames( [] ) );
    }
}
