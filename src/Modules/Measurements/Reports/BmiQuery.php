<?php
namespace TT\Modules\Measurements\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Growth\BmiSeriesBuilder;
use TT\Modules\Measurements\Growth\GrowthReference;
use TT\Modules\Measurements\Growth\WhoBmiForAgeReference;

/**
 * BmiQuery (#2895) — the read model behind every BMI-for-age surface.
 *
 * Three surfaces share it: the roster table, the per-player trend, and the
 * block on a player's Measurements tab. They render differently and they must
 * never disagree, so the numbers are computed once, here, and the REST
 * endpoint returns this same shape (CLAUDE.md §4).
 *
 * BMI on its own says nothing about a 13-year-old. The same 19.4 is unremarkable
 * at 16 and high at 11, which is why every figure this class returns carries its
 * age-and-sex context: a z-score (SDS) and a percentile against a named growth
 * reference. A screen that shows the raw number alone is the screen that gets
 * misread.
 *
 * What this class deliberately does NOT do is judge. There is no "overweight"
 * flag, no threshold, no colour. It reports where a player sits on a published
 * curve and how that has moved. The clinical reading belongs to someone
 * qualified to make it, and these are minors.
 */
final class BmiQuery {

    private \wpdb $wpdb;
    private BmiSeriesBuilder $series;
    private GrowthReference $reference;

    public function __construct( ?GrowthReference $reference = null ) {
        global $wpdb;
        $this->wpdb      = $wpdb;
        $this->series    = new BmiSeriesBuilder();
        $this->reference = $reference ?? new WhoBmiForAgeReference();
    }

    /** The reference in use, so a view can name it on screen. */
    public function reference(): GrowthReference {
        return $this->reference;
    }

    /** The pairing tolerance, so a view can state it rather than imply it. */
    public function pairWindowDays(): int {
        return BmiSeriesBuilder::PAIR_WINDOW_DAYS;
    }

    /**
     * One row per player in the given teams: their latest BMI point, where it
     * sits on the curve, and how far it has moved since the previous point.
     *
     * Players with no usable height/weight pair are returned too, with nulls.
     * Dropping them would make the report look complete when it is not — an
     * academy needs to see who it has no data for, because that is the first
     * thing to fix.
     *
     * @param list<int> $team_ids
     * @return list<array{
     *   player_id:int, first_name:string, last_name:string, team_id:int,
     *   team_name:string, sex:string, age_months:int|null, date:string|null,
     *   bmi:float|null, sds:float|null, percentile:float|null,
     *   delta_bmi:float|null, delta_sds:float|null, previous_date:string|null,
     *   gap_days:int|null, points:int, covered:bool
     * }>
     */
    public function rosterRows( array $team_ids ): array {
        $team_ids = array_values( array_filter( array_map( 'intval', $team_ids ), static fn( $i ) => $i > 0 ) );
        if ( $team_ids === [] ) return [];

        $club = (int) CurrentClub::id();
        $ph   = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $p    = $this->wpdb->prefix;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $players = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, pl.team_id, pl.sex,
                    pl.date_of_birth, t.name AS team_name
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.club_id = %d
                AND pl.team_id IN ({$ph})
                AND " . ArchiveRepository::filterClause( 'active', 'pl' ) . "
              ORDER BY t.name, pl.last_name, pl.first_name",
            array_merge( [ $club ], $team_ids )
        ) );

        $rows = [];
        foreach ( (array) $players as $pl ) {
            $player_id = (int) ( $pl->id ?? 0 );
            $sex       = (string) ( $pl->sex ?? '' );
            $dob       = (string) ( $pl->date_of_birth ?? '' );

            $points = $this->series->forPlayer( $player_id, $club );
            $latest = $points === [] ? null : $points[ count( $points ) - 1 ];
            $prev   = count( $points ) >= 2 ? $points[ count( $points ) - 2 ] : null;

            $age_months = $latest === null ? null : self::ageInMonths( $dob, (string) $latest['date'] );

            $sds = ( $latest !== null && $age_months !== null )
                ? $this->reference->sds( (float) $latest['bmi'], $age_months, $sex )
                : null;

            $prev_sds = null;
            if ( $prev !== null ) {
                $prev_age = self::ageInMonths( $dob, (string) $prev['date'] );
                if ( $prev_age !== null ) {
                    $prev_sds = $this->reference->sds( (float) $prev['bmi'], $prev_age, $sex );
                }
            }

            $rows[] = [
                'player_id'     => $player_id,
                'first_name'    => (string) ( $pl->first_name ?? '' ),
                'last_name'     => (string) ( $pl->last_name ?? '' ),
                'team_id'       => (int) ( $pl->team_id ?? 0 ),
                'team_name'     => (string) ( $pl->team_name ?? '' ),
                'sex'           => $sex,
                'age_months'    => $age_months,
                'date'          => $latest === null ? null : (string) $latest['date'],
                'bmi'           => $latest === null ? null : (float) $latest['bmi'],
                'sds'           => $sds,
                'percentile'    => $sds === null ? null : self::percentileFromZ( $sds ),
                'delta_bmi'     => ( $latest !== null && $prev !== null )
                    ? round( (float) $latest['bmi'] - (float) $prev['bmi'], 2 )
                    : null,
                'delta_sds'     => ( $sds !== null && $prev_sds !== null )
                    ? round( $sds - $prev_sds, 2 )
                    : null,
                'previous_date' => $prev === null ? null : (string) $prev['date'],
                'gap_days'      => $latest === null ? null : (int) $latest['gap_days'],
                'points'        => count( $points ),
                // `covered` separates "we have no measurements" from "the
                // reference does not describe this player". Both render as an
                // empty cell; only one of them is the academy's to fix.
                'covered'       => $age_months !== null && $this->reference->covers( $age_months, $sex ),
            ];
        }

        return $rows;
    }

    /**
     * One player's full series, each point carrying its z and percentile.
     *
     * @return list<array{
     *   date:string, bmi:float, height_cm:float, weight_kg:float,
     *   height_date:string, gap_days:int, age_months:int|null,
     *   sds:float|null, percentile:float|null
     * }>
     */
    public function playerSeries( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        $club = (int) CurrentClub::id();
        $p    = $this->wpdb->prefix;

        $player = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT sex, date_of_birth FROM {$p}tt_players WHERE id = %d AND club_id = %d",
            $player_id, $club
        ) );
        if ( $player === null ) return [];

        $sex = (string) ( $player->sex ?? '' );
        $dob = (string) ( $player->date_of_birth ?? '' );

        $out = [];
        foreach ( $this->series->forPlayer( $player_id, $club ) as $point ) {
            $age_months = self::ageInMonths( $dob, (string) $point['date'] );
            $sds        = $age_months === null
                ? null
                : $this->reference->sds( (float) $point['bmi'], $age_months, $sex );

            $out[] = $point + [
                'age_months' => $age_months,
                'sds'        => $sds,
                'percentile' => $sds === null ? null : self::percentileFromZ( $sds ),
            ];
        }

        return $out;
    }

    /**
     * The reference band values at a given age and sex — the curve a player's
     * points are drawn against.
     *
     * Returns a LIST rather than a z-keyed map on purpose: PHP casts numeric
     * string keys to integers, so `'-2'` and `'0'` would silently become ints
     * while `'+1'` stayed a string, giving a caller an array whose key types
     * depend on the sign. A list of pairs has one shape.
     *
     * @return list<array{z:float, value:float}>|null
     */
    public function referenceBands( int $age_months, string $sex ): ?array {
        if ( ! $this->reference->covers( $age_months, $sex ) ) return null;

        $bands = [];
        foreach ( [ -2.0, -1.0, 0.0, 1.0, 2.0 ] as $z ) {
            $value = $this->reference->valueAtSds( $z, $age_months, $sex );
            if ( $value === null ) return null;
            $bands[] = [ 'z' => $z, 'value' => round( $value, 2 ) ];
        }
        return $bands;
    }

    /**
     * Whole months between a birth date and a measurement date.
     *
     * Returns null when the player has no birth date: BMI-for-age without an
     * age is not a number anyone can act on, and guessing one would be worse
     * than showing nothing.
     */
    public static function ageInMonths( string $date_of_birth, string $on_date ): ?int {
        if ( $date_of_birth === '' || $on_date === '' ) return null;

        $dob = \DateTimeImmutable::createFromFormat( 'Y-m-d', $date_of_birth );
        $at  = \DateTimeImmutable::createFromFormat( 'Y-m-d', $on_date );
        if ( $dob === false || $at === false ) return null;
        if ( $at < $dob ) return null;

        $diff = $dob->diff( $at );
        return ( $diff->y * 12 ) + $diff->m;
    }

    /**
     * Percentile for a z-score, via the standard normal CDF.
     *
     * Abramowitz & Stegun 7.1.26 — the same approximation the reference
     * implementation uses, kept identical so a percentile shown next to an SDS
     * cannot contradict it.
     */
    public static function percentileFromZ( float $z ): float {
        $sign = $z < 0 ? -1.0 : 1.0;
        $x    = abs( $z ) / sqrt( 2.0 );

        $t = 1.0 / ( 1.0 + 0.3275911 * $x );
        $y = 1.0 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$x * $x );

        return round( 0.5 * ( 1.0 + $sign * $y ) * 100, 1 );
    }
}
