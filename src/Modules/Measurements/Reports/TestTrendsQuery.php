<?php
namespace TT\Modules\Measurements\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementResultsRepository;

/**
 * TestTrendsQuery (#2537) — one test, every player in scope, over a window.
 *
 * The read model behind the Test trends report and its REST endpoint, so the
 * rendered page and an API consumer can never disagree (CLAUDE.md §4). It
 * returns the shared date axis, one entry per player with their value on each
 * of those dates, the squad average per date, and — only where the test has a
 * direction — the change over the window with a verdict.
 *
 * The direction is the whole point. On a `lower` test (a sprint time) a
 * negative delta is an improvement, and the verdict, the percentage and the
 * ranking order all have to follow the test rather than the sign of the
 * number. A test with `direction = neutral` (height, weight) gets no verdict
 * at all: there is no better or worse to report.
 */
final class TestTrendsQuery {

    /**
     * Below this much change, a result counts as unchanged rather than as a
     * direction. A 1% move on a sprint time is inside the noise of a hand
     * timing and calling it "improved" would overstate what was measured.
     */
    private const FLAT_PCT = 2.0;

    private MeasurementDefinitionsRepository $definitions;
    private MeasurementResultsRepository $results;

    public function __construct(
        ?MeasurementDefinitionsRepository $definitions = null,
        ?MeasurementResultsRepository $results = null
    ) {
        $this->definitions = $definitions ?? new MeasurementDefinitionsRepository();
        $this->results     = $results ?? new MeasurementResultsRepository();
    }

    /**
     * @param array{team_id?: int, age_group?: string, date_from?: string, date_to?: string} $filters
     * @param array<int, int>|null $allowed_team_ids null = unrestricted;
     *        a list narrows to those teams; an empty list returns nothing.
     * @return array{
     *   definition: array<string, mixed>|null,
     *   dates: array<int, string>,
     *   players: array<int, array<string, mixed>>,
     *   average: array<string, float>,
     *   is_numeric: bool,
     *   has_direction: bool
     * }
     */
    public function forDefinition( int $definition_id, array $filters = [], ?array $allowed_team_ids = null ): array {
        $empty = [
            'definition' => null, 'dates' => [], 'players' => [],
            'average' => [], 'is_numeric' => false, 'has_direction' => false,
        ];
        if ( $definition_id <= 0 ) return $empty;
        if ( $allowed_team_ids !== null && $allowed_team_ids === [] ) return $empty;

        $def = $this->definitions->find( $definition_id );
        if ( ! $def ) return $empty;

        $value_type    = (string) ( $def->value_type ?? 'numeric' );
        $direction     = (string) ( $def->direction ?? 'higher' );
        $is_numeric    = in_array( $value_type, [ 'numeric', 'scale' ], true );
        $has_direction = $is_numeric && in_array( $direction, [ 'higher', 'lower' ], true );

        $query = $filters;
        if ( $allowed_team_ids !== null ) {
            $query['team_ids'] = $allowed_team_ids;
        }
        $rows = $this->results->listForDefinitionExport( $definition_id, $query );

        $dates   = [];
        $players = [];
        foreach ( $rows as $r ) {
            $date = (string) ( $r->recorded_date ?? '' );
            $pid  = (int) ( $r->player_id ?? 0 );
            if ( $date === '' || $pid <= 0 ) continue;
            $dates[ $date ] = true;

            if ( ! isset( $players[ $pid ] ) ) {
                $players[ $pid ] = [
                    'player_id' => $pid,
                    'name'      => trim( (string) ( $r->first_name ?? '' ) . ' ' . (string) ( $r->last_name ?? '' ) ),
                    'team_name' => (string) ( $r->team_name ?? '' ),
                    'values'    => [],
                    'texts'     => [],
                ];
            }
            if ( $r->value_numeric !== null ) {
                $players[ $pid ]['values'][ $date ] = (float) $r->value_numeric;
            }
            if ( $r->value_text !== null && (string) $r->value_text !== '' ) {
                $players[ $pid ]['texts'][ $date ] = (string) $r->value_text;
            }
        }
        if ( $players === [] ) return $empty;

        $dates = array_keys( $dates );
        sort( $dates );

        foreach ( $players as $pid => $p ) {
            $players[ $pid ] = $this->withChange( $p, $dates, $direction, $has_direction );
        }

        usort( $players, static fn ( $a, $b ) => strcasecmp( (string) $a['name'], (string) $b['name'] ) );

        return [
            'definition'    => [
                'id'         => (int) $def->id,
                'name'       => (string) $def->name,
                'unit'       => (string) ( $def->unit ?? '' ),
                'value_type' => $value_type,
                'direction'  => $direction,
                'category'   => (string) ( $def->category_label ?? $def->category_name ?? '' ),
            ],
            'dates'         => $dates,
            'players'       => array_values( $players ),
            'average'       => $is_numeric ? $this->averagePerDate( $players, $dates ) : [],
            'is_numeric'    => $is_numeric,
            'has_direction' => $has_direction,
        ];
    }

    /**
     * First value, latest value and the movement between them — computed over
     * the dates the player actually has, so a missed round narrows the window
     * rather than distorting the change.
     *
     * `improved` is derived from the test's direction, never from the sign:
     * on a `lower` test a negative delta is progress. A neutral test gets a
     * null verdict — the change is still reported as a fact.
     *
     * @param array<string, mixed> $p
     * @param array<int, string>   $dates
     * @return array<string, mixed>
     */
    private function withChange( array $p, array $dates, string $direction, bool $has_direction ): array {
        $present = [];
        foreach ( $dates as $d ) {
            if ( isset( $p['values'][ $d ] ) ) $present[] = (float) $p['values'][ $d ];
        }

        $p['count']   = count( $present );
        $p['first']   = $present !== [] ? $present[0] : null;
        $p['last']    = $present !== [] ? $present[ count( $present ) - 1 ] : null;
        $p['delta']   = null;
        $p['pct']     = null;
        $p['verdict'] = null;
        // #2628 — the display state, in the six-value vocabulary the reports
        // and the REST payload share. Derived here rather than in a view so
        // both consumers get the same answer (CLAUDE.md §4), and so a
        // non-WordPress front end can pick its own glyph for each state.
        $p['trend']   = null;

        // #2837 — the movement between each pair of adjacent measuring
        // moments, keyed by the later date of the pair. One overall number
        // flattens the shape a coach opens this report to see: a player who
        // gained 2 kg and lost 1,5 kg reads as +0,5 exactly like a player who
        // gained 0,5 steadily. Computed here rather than in the view so the
        // REST payload carries it too (CLAUDE.md §4).
        $p['steps'] = self::stepsBetween( $p['values'] ?? [], $dates, $direction, $has_direction );

        if ( count( $present ) < 2 ) return $p;

        $delta      = $p['last'] - $p['first'];
        $p['delta'] = $delta;
        $p['pct']   = $p['first'] != 0.0 ? ( $delta / abs( $p['first'] ) ) * 100 : null;

        $state        = self::stateFor( $delta, $p['first'], $direction, $has_direction );
        $p['trend']   = $state['trend'];
        $p['verdict'] = $state['verdict'];
        return $p;
    }

    /**
     * One entry per adjacent pair of dates that the player has BOTH readings
     * for, keyed by the later date.
     *
     * A gap is a gap: if either side is missing the step is absent, and the
     * comparison is never quietly stretched across the hole to the reading
     * before it. The overall change still spans whatever moments exist, which
     * is a different question and deliberately answered differently.
     *
     * @param array<string, mixed> $values date => reading
     * @param array<int, string>   $dates  every measuring moment, in order
     * @return array<string, array{delta: float, trend: string}>
     */
    private static function stepsBetween( array $values, array $dates, string $direction, bool $has_direction ): array {
        $steps = [];

        for ( $i = 1; $i < count( $dates ); $i++ ) {
            $prev_date = $dates[ $i - 1 ];
            $this_date = $dates[ $i ];
            if ( ! isset( $values[ $prev_date ], $values[ $this_date ] ) ) continue;

            $prev  = (float) $values[ $prev_date ];
            $now   = (float) $values[ $this_date ];
            $delta = $now - $prev;

            $steps[ $this_date ] = [
                'delta' => $delta,
                'trend' => self::stateFor( $delta, $prev, $direction, $has_direction )['trend'],
            ];
        }

        return $steps;
    }

    /**
     * Verdict and display state for one movement, against the reading it is
     * measured from.
     *
     * Shared by the overall change and by every step so the two can never
     * read a move differently. A neutral test has no better or worse: report
     * which way the value went and stop there. A move under the flat
     * threshold is `flat` either way — "unchanged" is a fact, not a judgement.
     *
     * @return array{trend: string, verdict: ?string}
     */
    private static function stateFor( float $delta, ?float $base, string $direction, bool $has_direction ): array {
        $pct       = ( $base !== null && $base != 0.0 ) ? ( $delta / abs( $base ) ) * 100 : null;
        $magnitude = $pct !== null ? abs( $pct ) : 0.0;

        if ( ! $has_direction ) {
            if ( $magnitude < self::FLAT_PCT ) {
                return [ 'trend' => 'flat', 'verdict' => null ];
            }
            return [
                'trend'   => $delta > 0 ? 'rose' : ( $delta < 0 ? 'fell' : 'flat' ),
                'verdict' => null,
            ];
        }

        if ( $magnitude < self::FLAT_PCT ) {
            return [ 'trend' => 'flat', 'verdict' => 'flat' ];
        }

        $better = $direction === 'lower' ? $delta < 0 : $delta > 0;
        return [
            'trend'   => $better ? 'up' : 'down',
            'verdict' => $better ? 'improved' : 'declined',
        ];
    }

    /**
     * Squad average per date, over the players who have a value that date.
     * Averaging across dates a player missed would move the line for reasons
     * that have nothing to do with performance.
     *
     * @param array<int, array<string, mixed>> $players
     * @param array<int, string> $dates
     * @return array<string, float>
     */
    private function averagePerDate( array $players, array $dates ): array {
        $out = [];
        foreach ( $dates as $d ) {
            $sum = 0.0;
            $n   = 0;
            foreach ( $players as $p ) {
                if ( ! isset( $p['values'][ $d ] ) ) continue;
                $sum += (float) $p['values'][ $d ];
                $n++;
            }
            if ( $n > 0 ) $out[ $d ] = $sum / $n;
        }
        return $out;
    }

    /**
     * The ranking strip: the players who moved most in each direction.
     * Ordered by the size of the move, and only over players whose verdict
     * is a direction — a "flat" player belongs in neither column, which is
     * the mistake the mockup made before it was caught.
     *
     * @param array<int, array<string, mixed>> $players
     * @return array{improved: array<int, array<string, mixed>>, declined: array<int, array<string, mixed>>}
     */
    public function rankings( array $players, int $limit = 3 ): array {
        $improved = array_values( array_filter( $players, static fn ( $p ) => ( $p['verdict'] ?? '' ) === 'improved' ) );
        $declined = array_values( array_filter( $players, static fn ( $p ) => ( $p['verdict'] ?? '' ) === 'declined' ) );

        $by_size = static fn ( $a, $b ) => abs( (float) $b['pct'] ) <=> abs( (float) $a['pct'] );
        usort( $improved, $by_size );
        usort( $declined, $by_size );

        return [
            'improved' => array_slice( $improved, 0, max( 1, $limit ) ),
            'declined' => array_slice( $declined, 0, max( 1, $limit ) ),
        ];
    }
}
