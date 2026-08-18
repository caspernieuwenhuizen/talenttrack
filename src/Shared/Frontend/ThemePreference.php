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
 *
 * Club brand colours are deliberately NOT a theme's business:
 * `--tt-primary` / `--tt-secondary` are emitted by `BrandStyles` and
 * re-themed by the operator's colour editor. A theme owns the neutrals,
 * the chrome, depth, status colours and type — never the club's identity.
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

    /** Per-user value meaning "follow the club default". */
    public const INHERIT = 'inherit';

    /**
     * Theme values a club default or user override may hold.
     *
     * @return list<string>
     */
    public static function themes(): array {
        return [ self::DEFAULT_THEME, self::FEDERATION ];
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
        return $theme === self::DEFAULT_THEME ? '' : 'tt-theme-' . $theme;
    }

    /**
     * The stylesheet filename for the resolved theme, relative to
     * `assets/css/`. Empty when the default theme is active.
     */
    public static function styleFile( int $user_id = 0 ): string {
        $theme = self::resolve( $user_id );
        return $theme === self::DEFAULT_THEME ? '' : 'theme-' . $theme . '.css';
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
        ];
    }
}
