<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamMonthlyEvaluations (#4134, epic #4094) — the Evaluations section of the
 * team monthly report: where each player stands this month, per area of the
 * game, and who moved.
 *
 * The headline tiles have long said "Evaluated 11/14" and "Squad rating 6,9".
 * This section is the explanation behind them.
 *
 * ## One source, so the section and the tile agree
 *
 * Every figure is read from `tt_eval_ratings` joined to `tt_evaluations`, over
 * the evaluations of players on the team, live evaluations only, club-scoped —
 * the rows `TeamKpisRepository::avgSquadRatingBetween()` reads. The squad
 * average **is** that method's answer for the same types, so the section and
 * the "Squad rating" tile cannot disagree.
 *
 * - A main category's figure per evaluation is its *effective* rating
 *   (`EvalRatingsRepository`): the direct rating when the coach gave one, the
 *   mean of its subcategories otherwise.
 * - A player's month average for a category is the mean of those over the
 *   player's evaluations in the window; the category's squad average the mean
 *   over every evaluation in it.
 * - A player's overall average is the mean of all their rating rows in the
 *   window, the squad average's own definition applied to one player.
 * - The previous month is the report's previous window. A mover is measured
 *   against the player's own previous evaluation, whenever it was.
 *
 * ## Load, then derive
 *
 * `compose()` runs a fixed number of queries whatever the squad size, and
 * hands plain arrays to `derive()`, which does every calculation and touches
 * no database, so the arithmetic is tested on a hand-computed fixture.
 *
 * The rating scale (min, max, step) comes from configuration and travels in
 * the payload, so a snapshot keeps the scale it was taken on.
 */
final class TeamMonthlyEvaluations {

    /** Tones a cell is coloured with, low to high across the scale. */
    public const TONES = 5;

    /** Movers listed each way. */
    public const MOVERS = 3;

    /**
     * @param array<int,object>                 $squad    the report's squad, in shirt order, keyed by player id.
     * @param array{from:string,to:string}      $previous the report's previous window.
     * @param array<string,mixed>               $options  the section's option bag.
     * @return array<string,mixed>
     */
    public static function compose( int $team_id, string $from, string $to, array $previous, array $squad, array $options ): array {
        $types = EvaluationsBlockOptions::typeIds( $options );
        $kpis  = new TeamKpisRepository();

        $window = self::evaluations( $team_id, $from, $to, $types );
        $before = self::evaluations( $team_id, $previous['from'], $previous['to'], $types );
        $last   = self::lastBefore( $team_id, $from, $types );

        $ids     = array_merge( array_keys( $window ), array_keys( $before ), array_keys( $last ) );
        $ratings = self::ratings( $ids );
        $mains   = self::mains( $ids );

        $attach = static function ( array $rows ) use ( $ratings, $mains ): array {
            $out = [];
            foreach ( $rows as $id => $row ) {
                $row['ratings'] = $ratings[ $id ]['all'] ?? [];
                $row['subs']    = $ratings[ $id ]['subs'] ?? [];
                $row['mains']   = $mains[ $id ] ?? [];
                $out[]          = $row;
            }
            return $out;
        };

        $last_ratings = [];
        foreach ( $last as $id => $row ) {
            foreach ( $ratings[ $id ]['all'] ?? [] as $value ) {
                $last_ratings[ (int) $row['player_id'] ][] = $value;
            }
        }

        $squad_rows = [];
        foreach ( $squad as $pid => $player ) {
            $squad_rows[] = [
                'player_id'     => (int) $pid,
                'name'          => trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ),
                'jersey_number' => isset( $player->jersey_number ) && is_numeric( $player->jersey_number ) ? (int) $player->jersey_number : null,
            ];
        }

        [ $main_list, $sub_list ] = self::categoryTree();

        return self::derive( [
            'level'              => EvaluationsBlockOptions::level( $options ),
            'sub'                => EvaluationsBlockOptions::withSubcategories( $options ),
            'scale'              => self::scale(),
            'squad'              => $squad_rows,
            'mains'              => $main_list,
            'subs'               => $sub_list,
            'window'             => $attach( $window ),
            'previous'           => $attach( $before ),
            'before'             => $last_ratings,
            'squad_avg'          => $kpis->avgSquadRatingBetween( $team_id, $from, $to, $types ),
            'squad_avg_previous' => $kpis->avgSquadRatingBetween( $team_id, $previous['from'], $previous['to'], $types ),
            'type_labels'        => self::typeLabels(),
            'types'              => $types,
        ] );
    }

    /**
     * Every figure the section prints, from plain arrays. No database.
     *
     * @param array<string,mixed> $in see `compose()` for the shape.
     * @return array<string,mixed>
     */
    public static function derive( array $in ): array {
        $scale  = self::scaleOf( $in['scale'] ?? [] );
        $squad  = self::rows( $in['squad'] ?? [] );
        $window = self::rows( $in['window'] ?? [] );
        $prev   = self::rows( $in['previous'] ?? [] );
        $mains  = self::rows( $in['mains'] ?? [] );
        $subs   = is_array( $in['subs'] ?? null ) ? $in['subs'] : [];
        $level  = SectionLevel::parse( $in['level'] ?? null ) ?? EvaluationsBlockOptions::DEFAULT_LEVEL;
        $before = is_array( $in['before'] ?? null ) ? $in['before'] : [];

        $in_squad = [];
        foreach ( $squad as $p ) $in_squad[ (int) $p['player_id'] ] = $p;

        // Per player: the window's rows, and the previous window's.
        $now_by  = self::byPlayer( $window );
        $prev_by = self::byPlayer( $prev );

        // Coverage.
        $missing   = [];
        $evaluated = 0;
        foreach ( $squad as $p ) {
            if ( isset( $now_by[ (int) $p['player_id'] ] ) ) {
                $evaluated++;
            } else {
                $missing[] = [ 'player_id' => (int) $p['player_id'], 'name' => (string) $p['name'] ];
            }
        }
        $coaches = [];
        $counts  = [];
        foreach ( $window as $e ) {
            $coaches[ (int) ( $e['coach_id'] ?? 0 ) ] = true;
            $type = (int) ( $e['type_id'] ?? 0 );
            $counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
        }
        $labels  = is_array( $in['type_labels'] ?? null ) ? $in['type_labels'] : [];
        $by_type = [];
        foreach ( $counts as $type => $n ) {
            $by_type[] = [ 'type_id' => $type, 'label' => (string) ( $labels[ $type ] ?? _x( 'Other', 'evaluation without a type', 'talenttrack' ) ), 'count' => $n ];
        }
        usort( $by_type, static fn( array $a, array $b ): int => $b['count'] <=> $a['count'] ?: strcmp( $a['label'], $b['label'] ) );

        // Categories: the squad average, its change, and the spread of the
        // players' month averages.
        $categories = [];
        $has_subs   = false;
        foreach ( $window as $e ) {
            if ( ! empty( $e['subs'] ) ) $has_subs = true;
        }
        foreach ( $mains as $main ) {
            $id    = (int) $main['id'];
            $row   = self::categoryRow( $id, (string) $main['label'], $window, $prev, $now_by, $scale, 'mains' );
            if ( $row === null ) continue;
            if ( ! empty( $in['sub'] ) && $level === SectionLevel::DETAILS && $has_subs ) {
                $row['subcategories'] = [];
                foreach ( self::rows( $subs[ $id ] ?? [] ) as $sub ) {
                    $sub_row = self::categoryRow( (int) $sub['id'], (string) $sub['label'], $window, $prev, $now_by, $scale, 'subs' );
                    if ( $sub_row !== null ) $row['subcategories'][] = $sub_row;
                }
            }
            $categories[] = $row;
        }

        // Movers: each player's window average against their own previous
        // evaluation's.
        $moves = [];
        foreach ( $now_by as $pid => $rows ) {
            if ( ! isset( $in_squad[ $pid ] ) ) continue;
            $now  = self::mean( self::flatten( $rows, 'ratings' ) );
            $then = self::mean( array_values( array_map( 'floatval', is_array( $before[ $pid ] ?? null ) ? $before[ $pid ] : [] ) ) );
            if ( $now === null || $then === null ) continue;
            $delta = round( round( $now, 1 ) - round( $then, 1 ), 1 );
            if ( abs( $delta ) < 0.05 ) continue;
            $moves[] = [
                'player_id' => $pid,
                'name'      => (string) $in_squad[ $pid ]['name'],
                'from'      => round( $then, 1 ),
                'to'        => round( $now, 1 ),
                'delta'     => $delta,
            ];
        }
        $rising  = array_values( array_filter( $moves, static fn( array $m ): bool => $m['delta'] > 0 ) );
        $falling = array_values( array_filter( $moves, static fn( array $m ): bool => $m['delta'] < 0 ) );
        usort( $rising, static fn( array $a, array $b ): int => $b['delta'] <=> $a['delta'] ?: strcmp( $a['name'], $b['name'] ) );
        usort( $falling, static fn( array $a, array $b ): int => $a['delta'] <=> $b['delta'] ?: strcmp( $a['name'], $b['name'] ) );

        $squad_avg  = self::num( $in['squad_avg'] ?? null );
        $squad_prev = self::num( $in['squad_avg_previous'] ?? null );

        $out = [
            'level'             => $level,
            'scale'             => $scale,
            'types'             => array_values( array_map( 'intval', is_array( $in['types'] ?? null ) ? $in['types'] : [] ) ),
            'types_counted'     => self::typesCounted( is_array( $in['types'] ?? null ) ? $in['types'] : [], $labels ),
            'squad_avg'         => $squad_avg !== null ? round( $squad_avg, 1 ) : null,
            'squad_avg_delta'   => $squad_avg !== null && $squad_prev !== null ? round( round( $squad_avg, 1 ) - round( $squad_prev, 1 ), 1 ) : null,
            'evaluated'         => $evaluated,
            'squad'             => count( $squad ),
            'missing'           => $missing,
            'evaluations'       => count( $window ),
            'coaches'           => count( array_filter( array_keys( $coaches ) ) ),
            'by_type'           => $by_type,
            'rising_count'      => count( $rising ),
            'falling_count'     => count( $falling ),
            'rising'            => array_slice( $rising, 0, self::MOVERS ),
            'falling'           => array_slice( $falling, 0, self::MOVERS ),
            'categories'        => $categories,
            'has_subcategories' => $has_subs,
            'sub'               => ! empty( $in['sub'] ) && $level === SectionLevel::DETAILS && $has_subs,
        ];

        if ( $level === SectionLevel::DETAILS ) {
            $out['grid'] = self::grid( $squad, $categories, $now_by, $prev_by, $scale );
        }

        return $out;
    }

    /**
     * One category's line: squad average, change, and the spread of the
     * players' month averages on the scale. Null when nobody was rated on it
     * in either window, so a category nobody uses prints no empty line.
     *
     * @param list<array<string,mixed>>        $window
     * @param list<array<string,mixed>>        $prev
     * @param array<int,list<array<string,mixed>>> $now_by
     * @param array{min:float,max:float,step:float} $scale
     * @return array<string,mixed>|null
     */
    private static function categoryRow( int $id, string $label, array $window, array $prev, array $now_by, array $scale, string $key ): ?array {
        $now  = self::mean( self::valuesFor( $window, $key, $id ) );
        $then = self::mean( self::valuesFor( $prev, $key, $id ) );
        if ( $now === null && $then === null ) return null;

        $per_player = [];
        foreach ( $now_by as $rows ) {
            $v = self::mean( self::valuesFor( $rows, $key, $id ) );
            if ( $v !== null ) $per_player[] = $v;
        }
        $min  = null;
        $max  = null;
        $wide = false;
        if ( $per_player !== [] ) {
            $min = round( min( $per_player ), 1 );
            $max = round( max( $per_player ), 1 );
            // "Wide" when the players span the whole scale.
            $wide = $min <= $scale['min'] && $max >= $scale['max'];
        }

        return [
            'category_id'   => $id,
            'label'         => $label,
            'avg'           => $now !== null ? round( $now, 1 ) : null,
            'delta'         => $now !== null && $then !== null ? round( round( $now, 1 ) - round( $then, 1 ), 1 ) : null,
            'min'           => $min,
            'max'           => $max,
            'band_from_pct' => $min !== null ? self::pctOf( $min, $scale ) : null,
            'band_to_pct'   => $max !== null ? self::pctOf( $max, $scale ) : null,
            'avg_pct'       => $now !== null ? self::pctOf( $now, $scale ) : null,
            'wide'          => $wide,
        ];
    }

    /**
     * The player × category grid, in shirt order: each cell the player's month
     * average with its tone and its direction against the previous month; a
     * player not evaluated gets a row saying so; the squad row last.
     *
     * @param list<array<string,mixed>>             $squad
     * @param list<array<string,mixed>>             $categories
     * @param array<int,list<array<string,mixed>>>  $now_by
     * @param array<int,list<array<string,mixed>>>  $prev_by
     * @param array{min:float,max:float,step:float} $scale
     * @return array<string,mixed>
     */
    private static function grid( array $squad, array $categories, array $now_by, array $prev_by, array $scale ): array {
        $rows = [];
        foreach ( $squad as $p ) {
            $pid  = (int) $p['player_id'];
            $mine = $now_by[ $pid ] ?? [];
            $then = $prev_by[ $pid ] ?? [];

            $cells = [];
            foreach ( $categories as $cat ) {
                $id    = (int) $cat['category_id'];
                $value = self::mean( self::valuesFor( $mine, 'mains', $id ) );
                $prior = self::mean( self::valuesFor( $then, 'mains', $id ) );
                $trend = '';
                if ( $value !== null && $prior !== null ) {
                    $d     = round( round( $value, 1 ) - round( $prior, 1 ), 1 );
                    $trend = $d > 0 ? 'up' : ( $d < 0 ? 'down' : 'flat' );
                }
                $cells[] = [
                    'category_id' => $id,
                    'value'       => $value !== null ? round( $value, 1 ) : null,
                    'tone'        => $value !== null ? self::tone( $value, $scale ) : 0,
                    'trend'       => $trend,
                ];
            }

            $dates = array_map( static fn( array $e ): string => (string) ( $e['date'] ?? '' ), $mine );
            rsort( $dates );
            $avg    = self::mean( self::flatten( $mine, 'ratings' ) );
            $rows[] = [
                'player_id'     => $pid,
                'name'          => (string) $p['name'],
                'jersey_number' => $p['jersey_number'] ?? null,
                'evaluated'     => $mine !== [],
                'cells'         => $cells,
                'avg'           => $avg !== null ? round( $avg, 1 ) : null,
                'count'         => count( $mine ),
                'last'          => $dates[0] ?? null,
            ];
        }

        return [ 'rows' => $rows ];
    }

    /**
     * Which of 1..TONES a value falls in across the configured scale: the
     * colour ramp, so a cell reads the same whatever scale the academy uses.
     *
     * @param array{min:float,max:float,step:float} $scale
     */
    public static function tone( float $value, array $scale ): int {
        $span = $scale['max'] - $scale['min'];
        if ( $span <= 0 ) return 3;
        $pos = ( $value - $scale['min'] ) / $span;
        return (int) max( 1, min( self::TONES, (int) floor( $pos * self::TONES ) + 1 ) );
    }

    /**
     * Where a value sits on the scale, 0–100.
     *
     * @param array{min:float,max:float,step:float} $scale
     */
    private static function pctOf( float $value, array $scale ): float {
        $span = $scale['max'] - $scale['min'];
        if ( $span <= 0 ) return 50.0;
        return round( max( 0.0, min( 100.0, ( $value - $scale['min'] ) / $span * 100 ) ), 1 );
    }

    /**
     * The types the section counted, as the meta line names them; empty when
     * every type counts.
     *
     * @param array<int|string,mixed> $types
     * @param array<int|string,mixed> $labels
     * @return list<string>
     */
    private static function typesCounted( array $types, array $labels ): array {
        $out = [];
        foreach ( $types as $type ) {
            $label = (string) ( $labels[ (int) $type ] ?? '' );
            if ( $label !== '' ) $out[] = $label;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<int,list<array<string,mixed>>>
     */
    private static function byPlayer( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) ( $row['player_id'] ?? 0 ) ][] = $row;
        }
        return $out;
    }

    /**
     * The values a set of evaluations holds for one category.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<float>
     */
    private static function valuesFor( array $rows, string $key, int $id ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $bag = is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [];
            $v   = $bag[ $id ] ?? null;
            if ( is_int( $v ) || is_float( $v ) || ( is_string( $v ) && is_numeric( $v ) ) ) $out[] = (float) $v;
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<float>
     */
    private static function flatten( array $rows, string $key ): array {
        $out = [];
        foreach ( $rows as $row ) {
            foreach ( is_array( $row[ $key ] ?? null ) ? $row[ $key ] : [] as $v ) {
                if ( is_numeric( $v ) ) $out[] = (float) $v;
            }
        }
        return $out;
    }

    /** @param list<float> $values */
    private static function mean( array $values ): ?float {
        return $values === [] ? null : array_sum( $values ) / count( $values );
    }

    /** @param mixed $v */
    private static function num( $v ): ?float {
        return is_int( $v ) || is_float( $v ) ? (float) $v : null;
    }

    /**
     * @param mixed $rows
     * @return list<array<string,mixed>>
     */
    private static function rows( $rows ): array {
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            if ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }

    /**
     * @param mixed $raw
     * @return array{min:float,max:float,step:float}
     */
    private static function scaleOf( $raw ): array {
        $raw  = is_array( $raw ) ? $raw : [];
        $min  = is_numeric( $raw['min'] ?? null ) ? (float) $raw['min'] : 5.0;
        $max  = is_numeric( $raw['max'] ?? null ) ? (float) $raw['max'] : 10.0;
        $step = is_numeric( $raw['step'] ?? null ) ? (float) $raw['step'] : 0.5;
        if ( $max <= $min ) $max = $min + 1.0;
        return [ 'min' => $min, 'max' => $max, 'step' => $step > 0 ? $step : 0.5 ];
    }

    /**
     * The academy's rating scale, from configuration.
     *
     * @return array{min:float,max:float,step:float}
     */
    public static function scale(): array {
        return self::scaleOf( [
            'min'  => QueryHelpers::get_config( 'rating_min', '5' ),
            'max'  => QueryHelpers::get_config( 'rating_max', '10' ),
            'step' => QueryHelpers::get_config( 'rating_step', '0.5' ),
        ] );
    }

    /* ---------------------------------------------------------------
     * Queries
     * ------------------------------------------------------------- */

    /**
     * The live evaluations of the team's players in a window, keyed by id —
     * the rows `TeamKpisRepository::avgSquadRatingBetween()` averages.
     *
     * @param list<int> $types empty means every type.
     * @return array<int,array{id:int, player_id:int, coach_id:int, type_id:int, date:string}>
     */
    private static function evaluations( int $team_id, string $from, string $to, array $types ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $p    = $wpdb->prefix;
        $args = [ $team_id, CurrentClub::id(), $from, $to ];
        $type = '';
        if ( $types !== [] ) {
            $type = ' AND e.eval_type_id IN (' . implode( ',', array_fill( 0, count( $types ), '%d' ) ) . ')';
            $args = array_merge( $args, $types );
        }
        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.player_id, e.coach_id, e.eval_type_id, e.eval_date
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE pl.team_id = %d
                AND pl.club_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'pl' ) . "
                AND " . ArchiveRepository::filterClause( 'active', 'e' ) . "
                AND e.eval_date BETWEEN %s AND %s{$type}
              ORDER BY e.eval_date ASC, e.id ASC",
            ...$args
        ) );

        $out = [];
        foreach ( $rows ?? [] as $row ) {
            $id         = (int) ( $row->id ?? 0 );
            $out[ $id ] = [
                'id'        => $id,
                'player_id' => (int) ( $row->player_id ?? 0 ),
                'coach_id'  => (int) ( $row->coach_id ?? 0 ),
                'type_id'   => (int) ( $row->eval_type_id ?? 0 ),
                'date'      => (string) ( $row->eval_date ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Each player's last evaluation day before the window, for the movers:
     * every evaluation on that day, so two coaches rating the same match
     * count together.
     *
     * @param list<int> $types
     * @return array<int,array{id:int, player_id:int, coach_id:int, type_id:int, date:string}>
     */
    private static function lastBefore( int $team_id, string $from, array $types ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $p     = $wpdb->prefix;
        $type  = '';
        $type2 = '';
        $targs = [];
        if ( $types !== [] ) {
            $marks = implode( ',', array_fill( 0, count( $types ), '%d' ) );
            $type  = " AND e.eval_type_id IN ({$marks})";
            $type2 = " AND e2.eval_type_id IN ({$marks})";
            $targs = $types;
        }
        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.id, e.player_id, e.coach_id, e.eval_type_id, e.eval_date
               FROM {$p}tt_evaluations e
               JOIN {$p}tt_players pl ON pl.id = e.player_id
              WHERE pl.team_id = %d
                AND pl.club_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'pl' ) . "
                AND " . ArchiveRepository::filterClause( 'active', 'e' ) . "
                AND e.eval_date < %s{$type}
                AND e.eval_date = (
                    SELECT MAX(e2.eval_date) FROM {$p}tt_evaluations e2
                     WHERE e2.player_id = e.player_id
                       AND " . ArchiveRepository::filterClause( 'active', 'e2' ) . "
                       AND e2.eval_date < %s{$type2}
                )",
            ...array_merge( [ $team_id, CurrentClub::id(), $from ], $targs, [ $from ], $targs )
        ) );

        $out = [];
        foreach ( $rows ?? [] as $row ) {
            $id         = (int) ( $row->id ?? 0 );
            $out[ $id ] = [
                'id'        => $id,
                'player_id' => (int) ( $row->player_id ?? 0 ),
                'coach_id'  => (int) ( $row->coach_id ?? 0 ),
                'type_id'   => (int) ( $row->eval_type_id ?? 0 ),
                'date'      => (string) ( $row->eval_date ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Every rating row of these evaluations: all of them (for the overall
     * averages) and the subcategory ones by category.
     *
     * @param list<int> $ids
     * @return array<int,array{all:list<float>, subs:array<int,float>}>
     */
    private static function ratings( array $ids ): array {
        $out = [];
        foreach ( ( new EvalRatingsRepository() )->ratingsForEvaluations( $ids ) as $eval_id => $rows ) {
            $all  = [];
            $subs = [];
            foreach ( $rows as $r ) {
                $value = (float) ( $r->rating ?? 0 );
                $all[] = $value;
                if ( ! empty( $r->category_parent_id ) ) $subs[ (int) ( $r->category_id ?? 0 ) ] = $value;
            }
            $out[ (int) $eval_id ] = [ 'all' => $all, 'subs' => $subs ];
        }
        return $out;
    }

    /**
     * Each evaluation's effective rating per main category.
     *
     * @param list<int> $ids
     * @return array<int,array<int,float>>
     */
    private static function mains( array $ids ): array {
        $out = [];
        foreach ( ( new EvalRatingsRepository() )->effectiveMainRatingsForEvaluations( $ids ) as $eval_id => $by_main ) {
            foreach ( $by_main as $main_id => $row ) {
                if ( $row['value'] !== null ) $out[ (int) $eval_id ][ (int) $main_id ] = (float) $row['value'];
            }
        }
        return $out;
    }

    /**
     * The active main categories in display order, and each one's
     * subcategories.
     *
     * @return array{0:list<array{id:int,label:string}>, 1:array<int,list<array{id:int,label:string}>>}
     */
    private static function categoryTree(): array {
        $mains = [];
        $subs  = [];
        foreach ( ( new EvalCategoriesRepository() )->getAll( false ) as $row ) {
            if ( ! is_object( $row ) ) continue;
            $id    = (int) ( $row->id ?? 0 );
            $entry = [ 'id' => $id, 'label' => EvalCategoriesRepository::displayLabel( (string) ( $row->label ?? '' ), $id ) ];
            if ( empty( $row->parent_id ) ) {
                if ( ! empty( $row->is_active ) ) $mains[] = $entry;
            } else {
                $subs[ (int) $row->parent_id ][] = $entry;
            }
        }
        return [ $mains, $subs ];
    }

    /**
     * The academy's evaluation types, by id, in its own words.
     *
     * @return array<int,string>
     */
    public static function typeLabels(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookups( 'eval_type' ) as $row ) {
            $out[ (int) ( $row->id ?? 0 ) ] = LookupTranslator::name( $row );
        }
        return $out;
    }
}
