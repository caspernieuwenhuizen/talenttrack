<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;

/**
 * TeamReportFigures (#4590) — the arithmetic the team block composers share:
 * the squad attendance average, the median minutes share, the bands, deltas
 * and status counts. One place, so two blocks cannot round the same figure
 * two ways.
 *
 * @phpstan-import-type AttendanceRow from TeamMonthlyReport
 * @phpstan-import-type MinutesRow from TeamMonthlyReport
 */
final class TeamReportFigures {

    /**
     * The squad's attendance: the mean of the per-player percentages, one
     * decimal (#4068). The attendance section prints it above its table and
     * the headline tile prints the same number, so the two cannot disagree —
     * the tile used to pool every register row, count only "present" and
     * round to a whole percent, and read 100% above a player at 93.8%.
     *
     * Late counts as attended, as it does in every row (#4013). A value below
     * 100 is capped at 99.9, so 100% means everybody attended everything.
     * Null when nobody has a register row, so a quiet month reads "—".
     *
     * @param list<AttendanceRow> $rows
     */
    public static function squadAttendancePct( array $rows ): ?float {
        $sum = 0.0;
        $n   = 0;
        foreach ( $rows as $r ) {
            if ( $r['present_pct'] === null ) continue;
            $sum += $r['present_pct'];
            $n++;
        }
        if ( $n === 0 ) return null;

        $mean = $sum / $n;
        return $mean < 100.0 ? min( round( $mean, 1 ), 99.9 ) : 100.0;
    }

    /**
     * @param int|float|null $value
     * @param int|float|null $previous
     * @return array{value:int|float|null, previous:int|float|null, delta:int|float|null}
     */
    public static function measure( $value, $previous ): array {
        return [ 'value' => $value, 'previous' => $previous, 'delta' => self::delta( $value, $previous ) ];
    }

    /**
     * @param int|float|null $now
     * @param int|float|null $before
     * @return int|float|null
     */
    public static function delta( $now, $before ) {
        if ( $now === null || $before === null ) return null;
        $d = $now - $before;
        return is_float( $d ) ? round( $d, 1 ) : $d;
    }

    public static function pct( int $part, int $whole ): ?float {
        return $whole > 0 ? round( $part / $whole * 100, 1 ) : null;
    }

    public static function share( int $minutes, int $available ): ?float {
        return $available > 0 ? round( $minutes / $available * 100, 1 ) : null;
    }

    /** @param list<MinutesRow> $rows */
    public static function medianShare( array $rows ): ?float {
        $shares = [];
        foreach ( $rows as $r ) {
            $s = self::share( $r['total_minutes'], $r['available_minutes'] );
            if ( $s !== null ) $shares[] = $s;
        }
        if ( $shares === [] ) return null;
        sort( $shares );
        $n   = count( $shares );
        $mid = intdiv( $n, 2 );
        return $n % 2 === 1 ? $shares[ $mid ] : round( ( $shares[ $mid - 1 ] + $shares[ $mid ] ) / 2, 1 );
    }

    /**
     * @param array<int,StatusVerdict> $verdicts
     * @return array{green:int, amber:int, red:int, unknown:int}
     */
    public static function statusCounts( array $verdicts ): array {
        $counts = [ 'green' => 0, 'amber' => 0, 'red' => 0, 'unknown' => 0 ];
        foreach ( $verdicts as $v ) {
            switch ( $v->color ) {
                case StatusVerdict::COLOR_GREEN: $counts['green']++; break;
                case StatusVerdict::COLOR_AMBER: $counts['amber']++; break;
                case StatusVerdict::COLOR_RED:   $counts['red']++;   break;
                default:                         $counts['unknown']++;
            }
        }
        return $counts;
    }

    /** @param array<int,StatusVerdict> $verdicts */
    public static function attentionCount( array $verdicts ): int {
        $c = self::statusCounts( $verdicts );
        return $c['amber'] + $c['red'];
    }

    public static function attendanceBand( ?float $pct ): ?string {
        if ( $pct === null ) return null;
        if ( $pct < TeamMonthlyReport::ATTENDANCE_RED_BELOW ) return 'red';
        if ( $pct < TeamMonthlyReport::ATTENDANCE_AMBER_BELOW ) return 'amber';
        return 'green';
    }

    public static function name( object $row ): string {
        return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
    }

    /**
     * One activity as the coverage and quality blocks name it.
     *
     * @return array{activity_id:int,title:string,date:string}
     */
    public static function activityRef( object $a ): array {
        return [
            'activity_id' => (int) ( $a->id ?? 0 ),
            'title'       => (string) ( $a->title ?? '' ),
            'date'        => (string) ( $a->session_date ?? '' ),
        ];
    }

    /**
     * `usort` with ties left in the order the rows arrived — PHP 7.4's sort
     * is not stable, and every ranking here falls back to shirt order.
     *
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>, array<string,mixed>): int $cmp
     * @return list<array<string,mixed>>
     */
    public static function stableSort( array $rows, callable $cmp ): array {
        $indexed = [];
        foreach ( $rows as $i => $row ) {
            $indexed[] = [ $i, $row ];
        }
        usort(
            $indexed,
            /**
             * @param array{int, array<string,mixed>} $a
             * @param array{int, array<string,mixed>} $b
             */
            static function ( array $a, array $b ) use ( $cmp ): int {
                $c = $cmp( $a[1], $b[1] );
                return $c !== 0 ? $c : $a[0] <=> $b[0];
            }
        );

        $out = [];
        foreach ( $indexed as $pair ) {
            $out[] = $pair[1];
        }
        return $out;
    }
}
