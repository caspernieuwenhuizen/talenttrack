<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerOrder;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Measurements\Reports\TestTrendsQuery;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementSessionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Modules\Measurements\Services\TestVerdict;
use TT\Modules\Measurements\Units\DurationFormat;
use TT\Modules\Measurements\Units\UnitContext;

/**
 * Test rounds the team held in the window, who was tested, and who moved in
 * each direction since their previous reading (#4590).
 *
 * #3515 — the section can be told which tests to show and how much of each.
 * With no options it reports every test held in the window as a summary,
 * which is what it did before the options existed.
 */
final class TestsComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $options = $ctx->optionsFor( TeamMonthlyReportBlock::TESTS );
        $wanted  = TestsBlockOptions::definitionIds( $options );
        $show    = TestsBlockOptions::show( $options );

        $held = self::testRoundsInWindow( $ctx, $wanted, $show );

        if ( $wanted === [] ) {
            return [ 'rounds' => array_values( $held ), 'show' => $show, 'level' => TestsBlockOptions::level( $options ) ];
        }

        // A named test the squad did not take this window still gets a section,
        // saying so. The whole point of a saved composition is that next month
        // is one click, and a section that silently vanished would read as an
        // oversight rather than as "no readings".
        $out = [];
        foreach ( $wanted as $def_id ) {
            if ( isset( $held[ $def_id ] ) ) {
                $out[] = $held[ $def_id ];
                continue;
            }
            $empty = self::emptyTestRound( $ctx, $def_id );
            // A definition deleted since the composition was saved resolves to
            // nothing. Drop it: a report saved in September must open in March.
            if ( $empty !== null ) $out[] = $empty;
        }

        return [ 'rounds' => $out, 'show' => $show, 'level' => TestsBlockOptions::level( $options ) ];
    }

    /**
     * Test rounds actually held in the window, keyed by definition id.
     *
     * @param list<int> $wanted Empty means every test held; otherwise only
     *        these are queried, so picking one test does not cost a trend
     *        query per test the squad happened to take.
     * @return array<int,array<string,mixed>>
     */
    private static function testRoundsInWindow( TeamReportContext $ctx, array $wanted, string $show ): array {
        $trends    = new TestTrendsQuery();
        $targets   = new MeasurementTargetsRepository();
        $squad     = count( $ctx->players() );
        $jerseys   = PlayerOrder::jerseys( $ctx->players() );
        $team      = $ctx->team();
        $age_group = $team !== null ? (string) ( $team->age_group ?? '' ) : '';

        $out = [];
        foreach ( ( new MeasurementSessionsRepository() )->listForTeam( $ctx->team_id ) as $s ) {
            $def_id  = (int) ( $s->definition_id ?? 0 );
            $planned = (string) ( $s->planned_date ?? '' );
            if ( $def_id <= 0 || isset( $out[ $def_id ] ) ) continue;
            if ( $planned === '' || $planned < $ctx->from || $planned > $ctx->to ) continue;
            if ( $wanted !== [] && ! in_array( $def_id, $wanted, true ) ) continue;

            $trend = $trends->forDefinition( $def_id, [ 'team_id' => $ctx->team_id, 'date_to' => $ctx->to ] );

            // #4063 — the trend values are in the test's entry unit; the unit
            // context says how a person reads them (mm:ss for a duration).
            $units         = UnitContext::forDefinition( ( new MeasurementDefinitionsRepository() )->find( $def_id ) );
            $definition    = $trend['definition'];
            $raw_direction = is_array( $definition ) ? (string) ( $definition['direction'] ?? '' ) : '';
            $direction     = $trend['has_direction'] ? $raw_direction : '';

            $in_window = [];
            foreach ( $trend['dates'] as $d ) {
                if ( $d >= $ctx->from && $d <= $ctx->to ) $in_window[] = $d;
            }
            if ( $in_window === [] ) continue;
            $date = $in_window[ count( $in_window ) - 1 ];

            // The round before this one, the squad's and each player's
            // comparison point (#4069). The same pair the step is taken over.
            $dates     = array_values( $trend['dates'] );
            $at        = array_search( $date, $dates, true );
            $prev_date = is_int( $at ) && $at > 0 ? $dates[ $at - 1 ] : null;
            $target    = $age_group !== '' ? $targets->forDefinitionAndAge( $def_id, $age_group ) : null;

            // #4093 — the target and each reading's standing, worded by the
            // helper the player profile's register uses, so a reading reads
            // the same on the report as on the player's own page.
            $value_type  = is_array( $definition ) ? (string) ( $definition['value_type'] ?? '' ) : '';
            $target_cell = TestVerdict::target( $value_type, $raw_direction, $target, $units );

            $tested   = 0;
            $improved = [];
            $declined = [];
            $all      = [];
            foreach ( $trend['players'] as $p ) {
                $values = $p['values'] ?? null;
                if ( ! is_array( $values ) || ! array_key_exists( $date, $values ) ) continue;
                $tested++;

                $steps = $p['steps'] ?? null;
                $step  = is_array( $steps ) ? ( $steps[ $date ] ?? null ) : null;

                $entry = [
                    'player_id' => (int) ( $p['player_id'] ?? 0 ),
                    'name'      => (string) ( $p['name'] ?? '' ),
                    'delta'     => is_array( $step ) ? (float) ( $step['delta'] ?? 0 ) : 0.0,
                ];

                // A player tested for the first time has a reading but no
                // step: "nothing to compare with", which is not the same as
                // no change.
                $value    = (float) $values[ $date ];
                $previous = $prev_date !== null && isset( $values[ $prev_date ] ) ? (float) $values[ $prev_date ] : null;
                $flag     = $targets->flagFor( $units->toBase( $value ), $target, $raw_direction !== '' ? $raw_direction : 'neutral' );
                $verdict  = TestVerdict::verdict( $value_type, $raw_direction, $flag );
                $all[]    = $entry + [
                    'value'            => $value,
                    'value_display'    => self::testValueDisplay( $units, $value ),
                    'previous'         => $previous,
                    'previous_display' => $previous !== null ? self::testValueDisplay( $units, $previous ) : '—',
                    'delta_display'    => is_array( $step ) ? self::testDeltaDisplay( $units, $entry['delta'] ) : '—',
                    'trend'            => is_array( $step ) ? (string) ( $step['trend'] ?? '' ) : '',
                    'first'            => ! is_array( $step ),
                    // #4069 — the player's best reading ever, and where it
                    // sits against the age group's target band. Bands hold
                    // canonical values, the trend the entry unit.
                    'pb'               => self::isPersonalBest( $values, $date, $direction ),
                    'flag'             => $flag,
                    'verdict_label'    => $verdict['label'],
                    'verdict_tone'     => $verdict['tone'],
                ];

                if ( ! is_array( $step ) ) continue;
                $trend_key = (string) ( $step['trend'] ?? '' );
                if ( $trend_key === 'up' )   $improved[] = $entry;
                if ( $trend_key === 'down' ) $declined[] = $entry;
            }

            $average = isset( $trend['average'][ $date ] ) ? (float) $trend['average'][ $date ] : null;
            // #4063 — a result table reads best to worst on a test with a
            // direction, shirt order otherwise.
            $ranked = self::withSquadComparison(
                self::rankReadings( PlayerOrder::sort( $all, $jerseys ), $direction ),
                $units,
                $direction,
                $average
            );

            $out[ $def_id ] = [
                'definition_id' => $def_id,
                'name'          => is_array( $definition ) ? (string) ( $definition['name'] ?? '' ) : (string) ( $s->definition_name ?? '' ),
                'unit'          => is_array( $definition ) ? (string) ( $definition['unit'] ?? '' ) : '',
                'unit_label'    => self::testUnitLabel( $units ),
                'is_duration'   => $units->isDuration(),
                'direction'     => $direction,
                'date'          => $date,
                'previous_date' => $prev_date,
                'tested'        => $tested,
                'squad'         => $squad,
                // #3518 — these are player lists, so they read in shirt order
                // like every other player list in the report.
                'improved'      => PlayerOrder::sort( $improved, $jerseys ),
                'declined'      => PlayerOrder::sort( $declined, $jerseys ),
                // #4069 — the round at a glance, for the stat strip.
                'average'       => self::testAverage( $units, $trend['average'], $date, $prev_date, $raw_direction, $trend['has_direction'] ),
                'best'          => self::testExtreme( $ranked, $direction, true ),
                'worst'         => self::testExtreme( $ranked, $direction, false ),
                'moves'         => self::testMoves( $ranked ),
                'bands'         => self::testBands( $ranked, $target !== null ? $age_group : '', $tested ),
                // #4093 — the target as printed, `≤ 12:30`, or null when the
                // age group has no band; `target_absent` when the test has no
                // better or worse and so cannot have one.
                'target'           => $target_cell['text'] !== '' && ! $target_cell['absent'] ? $target_cell['text'] : null,
                'target_age_group' => $age_group,
                'target_absent'    => $target_cell['absent'],
                'history'       => self::testHistory( $units, $trend['average'], $dates, is_int( $at ) ? $at : count( $dates ) - 1 ),
                'readings'      => TestsBlockOptions::showsPlayers( $show ) ? $ranked : [],
            ];
        }

        return $out;
    }

    /**
     * Is this the player's best reading ever? Only on a test with a direction,
     * and only once there is an earlier reading to beat — a first reading is a
     * first reading, not a record.
     *
     * @param array<array-key,mixed> $values date => reading, entry unit
     */
    private static function isPersonalBest( array $values, string $date, string $direction ): bool {
        if ( $direction !== 'lower' && $direction !== 'higher' ) return false;

        $now     = (float) $values[ $date ];
        $earlier = [];
        foreach ( $values as $d => $v ) {
            if ( (string) $d < $date && ( is_int( $v ) || is_float( $v ) ) ) $earlier[] = (float) $v;
        }
        if ( $earlier === [] ) return false;

        return $direction === 'lower' ? $now < min( $earlier ) : $now > max( $earlier );
    }

    /**
     * Rank, bar length and distance to the squad average per reading (#4069).
     *
     * The rank is shared on a tie, and only exists on a test with a direction:
     * without better or worse there is nothing to rank. `worse_than_avg` marks
     * where the squad-average line falls in a best-to-worst table.
     *
     * @param list<array<string,mixed>> $rows ranked
     * @return list<array<string,mixed>>
     */
    private static function withSquadComparison( array $rows, UnitContext $units, string $direction, ?float $average ): array {
        $ranked = $direction === 'lower' || $direction === 'higher';
        $max    = 0.0;
        foreach ( $rows as $r ) $max = max( $max, (float) $r['value'] );

        $out  = [];
        $rank = 0;
        $last = null;
        foreach ( $rows as $i => $r ) {
            $value = (float) $r['value'];
            if ( $last === null || $value !== $last ) $rank = $i + 1;
            $last = $value;

            $vs = $average !== null ? $value - $average : null;

            $r['rank']           = $ranked ? $rank : null;
            $r['bar_pct']        = $max > 0 ? round( $value / $max * 100, 1 ) : 0.0;
            $r['vs_avg']         = $vs;
            $r['vs_avg_display'] = $vs !== null ? self::testGapDisplay( $units, $vs ) : '—';
            $r['worse_than_avg'] = $vs !== null && ( ( $direction === 'lower' && $vs > 0 ) || ( $direction === 'higher' && $vs < 0 ) );
            $out[] = $r;
        }
        return $out;
    }

    /**
     * The squad average this round and the round before, with the move
     * between them judged the way a single reading is (#4069).
     *
     * @param array<string,float> $averages date => squad average, entry unit
     * @return array{value:?float, display:string, previous:?float, previous_display:string, delta_display:string, trend:string}
     */
    private static function testAverage( UnitContext $units, array $averages, string $date, ?string $prev_date, string $direction, bool $has_direction ): array {
        $now  = isset( $averages[ $date ] ) ? (float) $averages[ $date ] : null;
        $then = $prev_date !== null && isset( $averages[ $prev_date ] ) ? (float) $averages[ $prev_date ] : null;

        $delta_display = '—';
        $trend         = '';
        if ( $now !== null && $then !== null ) {
            $delta_display = self::testDeltaDisplay( $units, $now - $then );
            $trend         = TestTrendsQuery::stateFor( $now - $then, $then, $direction, $has_direction )['trend'];
        }

        return [
            'value'            => $now,
            'display'          => $now !== null ? self::testAverageDisplay( $units, $now ) : '—',
            'previous'         => $then,
            'previous_display' => $then !== null ? self::testAverageDisplay( $units, $then ) : '—',
            'delta_display'    => $delta_display,
            'trend'            => $trend,
        ];
    }

    /**
     * The best or the worst reading of the round: the ends of the ranking on
     * a test with a direction, the highest and lowest value without one.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{player_id:int, name:string, value_display:string}|null
     */
    private static function testExtreme( array $rows, string $direction, bool $best ): ?array {
        if ( $rows === [] ) return null;

        if ( $direction === 'lower' || $direction === 'higher' ) {
            $row = $best ? $rows[0] : $rows[ count( $rows ) - 1 ];
        } else {
            $row = $rows[0];
            foreach ( $rows as $r ) {
                $higher = (float) $r['value'] > (float) $row['value'];
                $lower  = (float) $r['value'] < (float) $row['value'];
                if ( ( $best && $higher ) || ( ! $best && $lower ) ) $row = $r;
            }
        }

        return [
            'player_id'     => (int) ( $row['player_id'] ?? 0 ),
            'name'          => (string) ( $row['name'] ?? '' ),
            'value_display' => (string) ( $row['value_display'] ?? '' ),
        ];
    }

    /**
     * How many moved each way since their previous reading, and how many had
     * none. A neutral test's rose / fell count as up / down.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{up:int, down:int, flat:int, first:int}
     */
    private static function testMoves( array $rows ): array {
        $moves = [ 'up' => 0, 'down' => 0, 'flat' => 0, 'first' => 0 ];
        foreach ( $rows as $r ) {
            if ( ! empty( $r['first'] ) ) {
                $moves['first']++;
                continue;
            }
            $t = (string) ( $r['trend'] ?? '' );
            if ( $t === 'up' || $t === 'rose' ) {
                $moves['up']++;
            } elseif ( $t === 'down' || $t === 'fell' ) {
                $moves['down']++;
            } else {
                $moves['flat']++;
            }
        }
        return $moves;
    }

    /**
     * Readings per target band — green, amber, red — against the team's age
     * group. Null when the test has no band for it, so the strip leaves the
     * cell out rather than printing "0 of 15 on target".
     *
     * @param list<array<string,mixed>> $rows
     * @return array{age_group:string, ok:int, warn:int, bad:int, of:int}|null
     */
    private static function testBands( array $rows, string $age_group, int $tested ): ?array {
        if ( $age_group === '' ) return null;

        $counts = [ 'ok' => 0, 'warn' => 0, 'bad' => 0 ];
        foreach ( $rows as $r ) {
            $flag = (string) ( $r['flag'] ?? '' );
            if ( isset( $counts[ $flag ] ) ) $counts[ $flag ]++;
        }
        if ( array_sum( $counts ) === 0 ) return null;

        return [ 'age_group' => $age_group, 'ok' => $counts['ok'], 'warn' => $counts['warn'], 'bad' => $counts['bad'], 'of' => $tested ];
    }

    /**
     * The squad average over the last four rounds up to this one, oldest
     * first, each as a share of the highest so a strip can draw it.
     *
     * @param array<string,float> $averages
     * @param list<string>        $dates
     * @return list<array{date:string, value:float, display:string, pct:float}>
     */
    private static function testHistory( UnitContext $units, array $averages, array $dates, int $at ): array {
        $points = [];
        foreach ( array_slice( $dates, 0, $at + 1 ) as $d ) {
            if ( isset( $averages[ $d ] ) ) $points[] = [ 'date' => $d, 'value' => (float) $averages[ $d ] ];
        }
        $points = array_slice( $points, -4 );

        $max = 0.0;
        foreach ( $points as $pt ) $max = max( $max, $pt['value'] );

        $out = [];
        foreach ( $points as $pt ) {
            $out[] = [
                'date'    => $pt['date'],
                'value'   => $pt['value'],
                'display' => self::testAverageDisplay( $units, $pt['value'] ),
                'pct'     => $max > 0 ? round( $pt['value'] / $max * 100, 1 ) : 0.0,
            ];
        }
        return $out;
    }

    /**
     * A squad average as a person reads it. An average is not a reading, so
     * it does not get a reading's precision: whole seconds on a timed test of
     * a minute or more, two decimals otherwise.
     */
    private static function testAverageDisplay( UnitContext $units, float $value ): string {
        if ( $units->isDuration() ) {
            $seconds = $units->toBase( $value );
            return DurationFormat::format( round( $seconds, abs( $seconds ) >= 60 ? 0 : 2 ) );
        }
        return UnitContext::localeNumber( round( $value, 2 ) );
    }

    /**
     * A reading's distance to the squad average, signed: "−1:36" on a timed
     * test, "+0,45" otherwise.
     */
    private static function testGapDisplay( UnitContext $units, float $gap ): string {
        if ( $units->isDuration() ) {
            $seconds = $units->toBase( $gap );
            $seconds = round( $seconds, abs( $seconds ) >= 60 ? 0 : 1 );
            if ( abs( $seconds ) < 0.05 ) return '0:00';
            return ( $seconds > 0 ? '+' : '−' ) . DurationFormat::format( abs( $seconds ) );
        }
        if ( abs( $gap ) < 0.0001 ) return '0';
        return ( $gap > 0 ? '+' : '−' ) . number_format_i18n( abs( $gap ), abs( $gap ) < 10 ? 2 : 1 );
    }

    /**
     * The placeholder for a test that was asked for but not taken this window.
     * Null when the definition no longer exists.
     *
     * @return array<string,mixed>|null
     */
    private static function emptyTestRound( TeamReportContext $ctx, int $definition_id ): ?array {
        $definition = ( new MeasurementDefinitionsRepository() )->find( $definition_id );
        if ( ! $definition ) return null;

        $units = UnitContext::forDefinition( $definition );

        return [
            'definition_id' => $definition_id,
            'name'          => (string) ( $definition->name ?? '' ),
            'unit'          => (string) ( $definition->unit ?? '' ),
            'unit_label'    => self::testUnitLabel( $units ),
            'is_duration'   => $units->isDuration(),
            'direction'     => '',
            'date'          => '',
            'previous_date' => null,
            'average'       => null,
            'best'          => null,
            'worst'         => null,
            'moves'         => null,
            'bands'         => null,
            'target'           => null,
            'target_age_group' => '',
            'target_absent'    => false,
            'history'       => [],
            'tested'        => 0,
            'squad'         => count( $ctx->players() ),
            'improved'      => [],
            'declined'      => [],
            'readings'      => [],
            'empty'         => true,
        ];
    }

    /**
     * What a test's result column is headed with: `mm:ss` for a duration,
     * the unit's symbol otherwise.
     */
    private static function testUnitLabel( UnitContext $units ): string {
        return $units->isDuration() ? 'mm:ss' : $units->symbol();
    }

    /**
     * One reading as a person reads it (#4063). Trend values arrive in the
     * entry unit; a duration goes back to seconds and prints as m:ss, rounded
     * to the hundredth first so float noise from the unit conversion cannot
     * turn 16:04 into 16:03.99.
     */
    private static function testValueDisplay( UnitContext $units, float $value ): string {
        if ( $units->isDuration() ) {
            return DurationFormat::format( round( $units->toBase( $value ), 2 ) );
        }
        return UnitContext::localeNumber( $value );
    }

    /**
     * A change between two readings (#4063). A duration's change is spoken in
     * seconds ("−7 s"), whatever unit the test is entered in; anything else
     * keeps the entry unit, which the column header names.
     */
    private static function testDeltaDisplay( UnitContext $units, float $delta ): string {
        if ( $units->isDuration() ) {
            $seconds = round( $units->deltaFromBase( $units->toBase( $delta ) ), 1 );
            if ( abs( $seconds ) < 0.05 ) return '0 ' . $units->deltaSymbol();
            return ( $seconds > 0 ? '+' : '−' ) . UnitContext::localeNumber( abs( $seconds ) ) . ' ' . $units->deltaSymbol();
        }
        if ( abs( $delta ) < 0.0001 ) return '0';
        return ( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ), abs( $delta ) < 10 ? 2 : 1 );
    }

    /**
     * Readings best to worst on a test with a direction (#4063): ascending on
     * `lower`, descending on `higher`. Without a direction there is no better
     * or worse, so the shirt order they arrive in stands. Ties keep that shirt
     * order too.
     *
     * @param list<array<string,mixed>> $readings in shirt order
     * @return list<array<string,mixed>>
     */
    private static function rankReadings( array $readings, string $direction ): array {
        if ( $direction !== 'lower' && $direction !== 'higher' ) return $readings;

        $sign = $direction === 'lower' ? 1 : -1;
        return TeamReportFigures::stableSort(
            $readings,
            static fn( array $a, array $b ): int => $sign * ( (float) ( $a['value'] ?? 0 ) <=> (float) ( $b['value'] ?? 0 ) )
        );
    }
}
