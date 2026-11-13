<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * ThemePreference (#2512) — resolves which visual theme the frontend wears.
 *
 * A theme is a token layer, not a second set of surfaces. `tokens.css`
 * stays the source of truth for the neutral design tokens; a theme sheet
 * loads after it and overrides those values plus the app shell's chrome.
 * No view knows which theme is on, so a theme can never change behaviour —
 * only appearance.
 *
 *   - `default` — the shipped green/gold look. The default, and the
 *     complete rollback: no theme sheet is enqueued and every surface
 *     renders exactly as it did before this existed.
 *   - `federation` — navy chrome, gold active marker, condensed display
 *     type, tighter radii. Designed against the app shell (#2456).
 *   - `leon-hutten` — labelled Kristalblauw (#5092): black chrome, a
 *     cyan-to-navy stripe motif, blue actions with a cyan accent, Open
 *     Sans throughout. The value is historical (see the constant).
 *   - `clubrood`, `oranje`, `tactiekbord`, `bordeaux`, `polder` (#5089)
 *     and nine more (#5092) — general-purpose palettes, each with its
 *     own bundled webfont under `assets/fonts/`.
 *
 * A theme owns the complete visual design, brand colours included:
 * `BrandStyles` stops emitting `--tt-primary` / `--tt-secondary` while
 * one is active (#2515), so each theme sheet declares its own. What the
 * club keeps is its identity in markup — the logo and the academy name,
 * which every theme renders untouched.
 *
 * Resolution order is user override -> club default -> `default`, matching
 * `ShellPreference`. The club default lives in `tt_config` (club-scoped per
 * CLAUDE.md §4 — never `wp_options`, which is global to the WP install and
 * would leak across tenants); the per-user override is a preference rather
 * than tenant config, so it lives in user meta, where `inherit` (the
 * default) means "follow the club".
 */
final class ThemePreference {

    /** Club-scoped config key holding the install default. */
    public const CONFIG_KEY = 'tt_frontend_theme';

    /** User-meta key holding the per-user override. */
    public const USER_META_KEY = 'tt_frontend_theme';

    /** Today's look. The default, and the rollback. */
    public const DEFAULT_THEME = 'default';

    /** Navy institutional theme (#2512). */
    public const FEDERATION = 'federation';

    /**
     * Kristalblauw: deep blues and cyan, Open Sans. The value is historical:
     * the theme was built as the Leon Hutten club design (#2939) and kept the
     * stored value when it was relabelled (#5092), so installs that chose it
     * keep their choice and its sheet with no migration.
     */
    public const LEON_HUTTEN = 'leon-hutten';

    /** Heritage red, black and muted gold; Barlow Condensed display (#5089). */
    public const CLUBROOD = 'clubrood';

    /** Navy and Dutch orange; Archivo (#5089). */
    public const ORANJE = 'oranje';

    /** Slate and chalk mint; IBM Plex Sans (#5089). */
    public const TACTIEKBORD = 'tactiekbord';

    /** Wine and sand; Source Serif 4 display, Source Sans 3 body (#5089). */
    public const BORDEAUX = 'bordeaux';

    /** Deep teal and coral; DM Sans (#5089). */
    public const POLDER = 'polder';

    /** Black and signal yellow; Oswald display (#5092). */
    public const GEEL_ZWART = 'geel-zwart';

    /** Tonal green and mint white; Manrope (#5092). */
    public const GROEN_WIT = 'groen-wit';

    /** Tonal violet and lilac; Outfit (#5092). */
    public const PAARS_WIT = 'paars-wit';

    /** Black header, green actions, yellow accent; Montserrat (#5092). */
    public const WAPENSCHILD = 'wapenschild';

    /** Black and bright green; Montserrat (#5092). */
    public const STREEPSHIRT = 'streepshirt';

    /** Deep green with yellow; Barlow Condensed display, Montserrat (#5092). */
    public const WEDSTRIJDDAG = 'wedstrijddag';

    /** Midnight navy and electric blue; Exo display, Poppins (#5092). */
    public const MIDDERNACHT = 'middernacht';

    /** Midnight navy and neon lime; Exo display, Poppins (#5092). */
    public const NEON = 'neon';

    /** Electric blue with cyan; Exo display, Poppins (#5092). */
    public const STADIONLICHT = 'stadionlicht';

    /** Per-user value meaning "follow the club default". */
    public const INHERIT = 'inherit';

    /**
     * Theme values a club default or user override may hold.
     *
     * @return list<string>
     */
    public static function themes(): array {
        return [
            self::DEFAULT_THEME,
            self::FEDERATION,
            self::LEON_HUTTEN,
            self::CLUBROOD,
            self::ORANJE,
            self::TACTIEKBORD,
            self::BORDEAUX,
            self::POLDER,
            self::GEEL_ZWART,
            self::GROEN_WIT,
            self::PAARS_WIT,
            self::WAPENSCHILD,
            self::STREEPSHIRT,
            self::WEDSTRIJDDAG,
            self::MIDDERNACHT,
            self::NEON,
            self::STADIONLICHT,
        ];
    }

    /**
     * Resolve the theme for a user: override -> club default -> default.
     *
     * Unknown stored values fall through rather than being echoed into a
     * class name — a hand-edited config or a value left behind by a future
     * version must never produce an unstyled page.
     */
    public static function resolve( int $user_id = 0 ): string {
        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        $override = self::userOverride( $user_id );
        if ( in_array( $override, self::themes(), true ) ) {
            return $override;
        }

        return self::clubDefault();
    }

    /**
     * The club-wide default. Falls back to `default` for an unset or
     * unrecognised value — existing installs see no change until an
     * operator opts in.
     */
    public static function clubDefault(): string {
        $stored = ( new ConfigService() )->get( self::CONFIG_KEY, self::DEFAULT_THEME );
        return in_array( $stored, self::themes(), true ) ? $stored : self::DEFAULT_THEME;
    }

    /** Set the club-wide default. Unrecognised values are ignored. */
    public static function setClubDefault( string $theme ): void {
        if ( ! in_array( $theme, self::themes(), true ) ) {
            return;
        }
        ( new ConfigService() )->set( self::CONFIG_KEY, $theme );
    }

    /**
     * The user's override, or `inherit` when they have not chosen one.
     * A stored value that is not a known theme reads as `inherit`.
     */
    public static function userOverride( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return self::INHERIT;
        }
        $stored = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
        return in_array( $stored, self::themes(), true ) ? $stored : self::INHERIT;
    }

    /**
     * Store a user override. `inherit` deletes the meta so the user follows
     * the club default again — including when the operator changes it later.
     */
    public static function setUserOverride( int $user_id, string $value ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        if ( $value === self::INHERIT ) {
            delete_user_meta( $user_id, self::USER_META_KEY );
            return;
        }
        if ( ! in_array( $value, self::themes(), true ) ) {
            return;
        }
        update_user_meta( $user_id, self::USER_META_KEY, $value );
    }

    /** True when a theme sheet has to be enqueued at all. */
    public static function hasThemeSheet( int $user_id = 0 ): bool {
        return self::resolve( $user_id ) !== self::DEFAULT_THEME;
    }

    /**
     * Root class stamped on the dashboard wrapper so per-view CSS can adapt
     * without JS and without reading the config a second time. Returns an
     * empty string for the default theme, which keeps that path's markup
     * byte-identical to before.
     */
    public static function rootClass( int $user_id = 0 ): string {
        $theme = self::resolve( $user_id );
        if ( $theme === self::DEFAULT_THEME ) {
            return '';
        }
        return 'tt-theme-' . $theme . ' ' . self::THEMED_CLASS . ( self::usesSharedSheet( $theme ) ? ' ' . self::SHARED_CLASS : '' );
    }

    /**
     * Root class every theme other than `default` carries (#5136), so a
     * component sheet can re-declare what a theme replaces without matching
     * on class names. `default` stamps none.
     */
    public const THEMED_CLASS = 'tt-themed';

    /**
     * The stylesheet filename for the resolved theme, relative to
     * `assets/css/`. Empty when the default theme is active.
     */
    public static function styleFile( int $user_id = 0 ): string {
        $theme = self::resolve( $user_id );
        return $theme === self::DEFAULT_THEME ? '' : 'theme-' . $theme . '.css';
    }

    /** The chrome and display rules the token-only themes share (#5089). */
    public const SHARED_SHEET = 'theme-shared.css';

    /** Root class those themes carry, which the shared sheet is scoped to. */
    public const SHARED_CLASS = 'tt-theme-shared';

    /**
     * Themes whose sheet is a token layer only, with every rule in the
     * shared sheet. Federation and Kristalblauw keep their own rules.
     *
     * @return list<string>
     */
    public static function sharedSheetThemes(): array {
        return [
            self::CLUBROOD,
            self::ORANJE,
            self::TACTIEKBORD,
            self::BORDEAUX,
            self::POLDER,
            self::GEEL_ZWART,
            self::GROEN_WIT,
            self::PAARS_WIT,
            self::WAPENSCHILD,
            self::STREEPSHIRT,
            self::WEDSTRIJDDAG,
            self::MIDDERNACHT,
            self::NEON,
            self::STADIONLICHT,
        ];
    }

    public static function usesSharedSheet( string $theme ): bool {
        return in_array( $theme, self::sharedSheetThemes(), true );
    }

    /**
     * The shared sheet's filename when the resolved theme uses it, relative
     * to `assets/css/`; '' otherwise.
     */
    public static function sharedStyleFile( int $user_id = 0 ): string {
        return self::usesSharedSheet( self::resolve( $user_id ) ) ? self::SHARED_SHEET : '';
    }

    /**
     * Human labels for the theme values, for the operator select and the
     * My settings override.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            self::DEFAULT_THEME => __( 'Default — green and gold', 'talenttrack' ),
            self::FEDERATION    => __( 'Federation — navy and gold', 'talenttrack' ),
            self::LEON_HUTTEN   => __( 'Kristalblauw — deep blues and cyan, Open Sans', 'talenttrack' ),
            self::CLUBROOD      => __( 'Clubrood — red, black and gold', 'talenttrack' ),
            self::ORANJE        => __( 'Oranje — navy and orange', 'talenttrack' ),
            self::TACTIEKBORD   => __( 'Tactiekbord — slate and mint', 'talenttrack' ),
            self::BORDEAUX      => __( 'Bordeaux — wine and sand', 'talenttrack' ),
            self::POLDER        => __( 'Polder — teal and coral', 'talenttrack' ),
            self::GEEL_ZWART    => __( 'Geel-Zwart — black and signal yellow', 'talenttrack' ),
            self::GROEN_WIT     => __( 'Groen-Wit — green and mint white', 'talenttrack' ),
            self::PAARS_WIT     => __( 'Paars-Wit — violet and lilac', 'talenttrack' ),
            self::WAPENSCHILD   => __( 'Wapenschild — black, green and yellow', 'talenttrack' ),
            self::STREEPSHIRT   => __( 'Streepshirt — black and bright green', 'talenttrack' ),
            self::WEDSTRIJDDAG  => __( 'Wedstrijddag — deep green and yellow', 'talenttrack' ),
            self::MIDDERNACHT   => __( 'Middernacht — midnight navy and electric blue', 'talenttrack' ),
            self::NEON          => __( 'Neon — midnight navy and neon lime', 'talenttrack' ),
            self::STADIONLICHT  => __( 'Stadionlicht — electric blue and cyan', 'talenttrack' ),
        ];
    }

    /**
     * The theme names without their colour description, for a summary line
     * such as the My settings overview (#4969).
     *
     * @return array<string, string>
     */
    public static function shortLabels(): array {
        return [
            self::DEFAULT_THEME => _x( 'Default', 'visual theme', 'talenttrack' ),
            self::FEDERATION    => _x( 'Federation', 'visual theme', 'talenttrack' ),
            self::LEON_HUTTEN   => _x( 'Kristalblauw', 'visual theme', 'talenttrack' ),
            self::CLUBROOD      => _x( 'Clubrood', 'visual theme', 'talenttrack' ),
            self::ORANJE        => _x( 'Oranje', 'visual theme', 'talenttrack' ),
            self::TACTIEKBORD   => _x( 'Tactiekbord', 'visual theme', 'talenttrack' ),
            self::BORDEAUX      => _x( 'Bordeaux', 'visual theme', 'talenttrack' ),
            self::POLDER        => _x( 'Polder', 'visual theme', 'talenttrack' ),
            self::GEEL_ZWART    => _x( 'Geel-Zwart', 'visual theme', 'talenttrack' ),
            self::GROEN_WIT     => _x( 'Groen-Wit', 'visual theme', 'talenttrack' ),
            self::PAARS_WIT     => _x( 'Paars-Wit', 'visual theme', 'talenttrack' ),
            self::WAPENSCHILD   => _x( 'Wapenschild', 'visual theme', 'talenttrack' ),
            self::STREEPSHIRT   => _x( 'Streepshirt', 'visual theme', 'talenttrack' ),
            self::WEDSTRIJDDAG  => _x( 'Wedstrijddag', 'visual theme', 'talenttrack' ),
            self::MIDDERNACHT   => _x( 'Middernacht', 'visual theme', 'talenttrack' ),
            self::NEON          => _x( 'Neon', 'visual theme', 'talenttrack' ),
            self::STADIONLICHT  => _x( 'Stadionlicht', 'visual theme', 'talenttrack' ),
        ];
    }
}
