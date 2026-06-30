<?php
namespace TT\Modules\Measurements\Levels;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MeasurementLevelPalette (#2138).
 *
 * The curated, fixed colour vocabulary for status-type measurement levels.
 * The operator picks a *token key* (never a raw hex), so colour lives in the
 * design system (`assets/css/frontend-measurement-levels.css`, token-backed
 * via `tokens.css`) and a future SaaS front end re-themes by swapping the
 * stylesheet, not by re-importing stored hex.
 *
 * The editor (FrontendMeasurementTestsView), the entry grid and the profile
 * chip all resolve a level's colour through `cssClass()` so they agree on the
 * exact swatch. `labels()` drives the picker; `isValid()` guards persistence.
 */
final class MeasurementLevelPalette {

    /** The fixed swatch set. Order is the picker order. */
    private const TOKENS = [ 'green', 'lime', 'amber', 'orange', 'red', 'grey', 'blue' ];

    public const DEFAULT_TOKEN = 'grey';

    /**
     * Token key -> translated label for the colour picker.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            'green'  => __( 'Green', 'talenttrack' ),
            'lime'   => __( 'Lime', 'talenttrack' ),
            'amber'  => __( 'Amber', 'talenttrack' ),
            'orange' => __( 'Orange', 'talenttrack' ),
            'red'    => __( 'Red', 'talenttrack' ),
            'grey'   => __( 'Grey', 'talenttrack' ),
            'blue'   => __( 'Blue', 'talenttrack' ),
        ];
    }

    /** @return string[] */
    public static function tokens(): array {
        return self::TOKENS;
    }

    public static function isValid( string $token ): bool {
        return in_array( $token, self::TOKENS, true );
    }

    /** Coerce any input to a known token (falls back to the neutral grey). */
    public static function safe( string $token ): string {
        return self::isValid( $token ) ? $token : self::DEFAULT_TOKEN;
    }

    /**
     * The CSS class that paints a level's swatch. No raw hex ever leaves the
     * stylesheet -- the renderer only emits this class.
     */
    public static function cssClass( string $token ): string {
        return 'tt-mlvl-swatch--' . self::safe( $token );
    }
}
