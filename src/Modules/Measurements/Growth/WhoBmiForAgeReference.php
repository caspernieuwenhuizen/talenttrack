<?php
namespace TT\Modules\Measurements\Growth;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PlayerSex;

/**
 * WhoBmiForAgeReference (#2895) — WHO 2007 BMI-for-age, 5–19 years.
 *
 * The LMS coefficients live in `config/growth/who-2007-bmi-for-age.php`,
 * generated from WHO's own expanded-table workbooks rather than
 * transcribed. `WhoBmiForAgeTest` recomputes every published cut-off from
 * those coefficients and checks the result against what WHO printed, so
 * the data and the arithmetic below are verified together against the
 * source.
 *
 * WHY AN AGE-ADJUSTED FIGURE AT ALL
 *
 * A raw BMI is not interpretable for a minor. The same 19.5 is unremarkable
 * at seventeen and high at nine, so a bare number invites a coach to read a
 * judgement about a child's body that the number does not support. The
 * percentile is the interpretable form, and it is only meaningful because
 * it is read against the player's own age and sex.
 *
 * THE RANGE IS THE REFERENCE'S OWN
 *
 * 61 to 228 months — five years and one month, to nineteen years exactly.
 * Below that the 0–5 reference applies and this one does not; above it
 * there is no curve. `covers()` says so and `sds()` returns null rather
 * than extrapolating, because an extrapolated percentile on a child is a
 * confident answer to a question the reference cannot answer.
 */
final class WhoBmiForAgeReference implements GrowthReference {

    public const KEY = 'who-2007';

    private const MIN_MONTHS = 61;
    private const MAX_MONTHS = 228;

    /** @var array<string, array<int, array{0:float,1:float,2:float}>>|null */
    private static ?array $lms = null;

    public function key(): string {
        return self::KEY;
    }

    public function label(): string {
        return __( 'WHO 2007 (5–19 years)', 'talenttrack' );
    }

    public function covers( int $age_months, string $sex ): bool {
        if ( $age_months < self::MIN_MONTHS || $age_months > self::MAX_MONTHS ) return false;
        $key = self::sexKey( $sex );
        if ( $key === '' ) return false;

        return isset( self::table()[ $key ][ $age_months ] );
    }

    public function sds( float $value, int $age_months, string $sex ): ?float {
        if ( $value <= 0 ) return null;

        $lms = $this->lmsFor( $age_months, $sex );
        if ( $lms === null ) return null;

        [ $l, $m, $s ] = $lms;
        if ( $m <= 0 || $s <= 0 ) return null;

        // The L == 0 branch is the log-normal limit of the Box-Cox
        // transform. WHO's BMI tables never publish L == 0, but the formula
        // divides by L, so the guard is what stops a future reference — or a
        // revised table — turning into a division by zero.
        if ( abs( $l ) < 1e-9 ) {
            return log( $value / $m ) / $s;
        }

        return ( ( $value / $m ) ** $l - 1 ) / ( $l * $s );
    }

    public function valueAtSds( float $z, int $age_months, string $sex ): ?float {
        $lms = $this->lmsFor( $age_months, $sex );
        if ( $lms === null ) return null;

        [ $l, $m, $s ] = $lms;
        if ( $m <= 0 || $s <= 0 ) return null;

        if ( abs( $l ) < 1e-9 ) {
            return $m * exp( $s * $z );
        }

        $base = 1 + $l * $s * $z;
        // Far out in the tail the Box-Cox base can go non-positive, at which
        // point the curve has no value to give. Returning null beats
        // returning NAN to a chart.
        if ( $base <= 0 ) return null;

        return $m * $base ** ( 1 / $l );
    }

    /**
     * Percentile (0–100) for a value, or null when uncovered.
     *
     * Presentation-facing: a coach reads "on the 62nd percentile", not
     * "z = 0.31". Kept beside the SDS rather than in a view because the
     * normal CDF is arithmetic, not composition.
     */
    public function percentile( float $value, int $age_months, string $sex ): ?float {
        $z = $this->sds( $value, $age_months, $sex );
        if ( $z === null ) return null;

        return self::normalCdf( $z ) * 100;
    }

    /** @return array{0:float,1:float,2:float}|null */
    private function lmsFor( int $age_months, string $sex ): ?array {
        if ( ! $this->covers( $age_months, $sex ) ) return null;
        return self::table()[ self::sexKey( $sex ) ][ $age_months ];
    }

    /**
     * `male` / `female` to the table's own key. A blank sex — the default
     * on every player record — has no curve, which is the documented cost
     * of leaving the field unrecorded rather than an error.
     */
    private static function sexKey( string $sex ): string {
        switch ( PlayerSex::sanitize( $sex ) ) {
            case PlayerSex::MALE:   return 'boys';
            case PlayerSex::FEMALE: return 'girls';
            default:                return '';
        }
    }

    /** @return array<string, array<int, array{0:float,1:float,2:float}>> */
    private static function table(): array {
        if ( self::$lms === null ) {
            $data = require TT_PLUGIN_DIR . 'config/growth/who-2007-bmi-for-age.php';
            self::$lms = is_array( $data['lms'] ?? null ) ? $data['lms'] : [];
        }
        return self::$lms;
    }

    /**
     * Standard normal CDF via the error function.
     *
     * PHP has no `erf`, so this is Abramowitz & Stegun 7.1.26 — accurate to
     * about 1.5e-7, which is four orders of magnitude finer than the
     * nearest whole percentile anybody reads off this.
     */
    private static function normalCdf( float $z ): float {
        $sign = $z < 0 ? -1 : 1;
        $x    = abs( $z ) / M_SQRT2;

        $t = 1 / ( 1 + 0.3275911 * $x );
        $y = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( - $x * $x );

        return 0.5 * ( 1 + $sign * $y );
    }
}
