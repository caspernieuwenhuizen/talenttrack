<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * ShellPreference (#2456) — resolves which frontend shell to render.
 *
 * TalentTrack ships two application shells:
 *
 *   - `classic` — the chrome the plugin has always had: brand header,
 *     breadcrumb, content. No global navigation; the user returns to the
 *     persona tile hub to change module.
 *   - `app` — a persistent shell: grouped sidebar at desktop widths, an
 *     off-canvas drawer below, both rendered once from `TileRegistry`.
 *
 * `classic` is the default and stays a complete rollback: no view may
 * depend on the app shell's DOM, so flipping the value back restores the
 * previous rendering exactly.
 *
 * Resolution order is user override -> club default -> `classic`. The club
 * default lives in `tt_config` (club-scoped per CLAUDE.md §4 — never
 * `wp_options`, which is global to the WP install and would leak across
 * tenants). The per-user override is a user preference rather than tenant
 * config, so it lives in user meta; `inherit` (the default) means "follow
 * the club".
 *
 * Every consumer reads through `resolve()`. Nothing else reads the config
 * key directly — one resolver is what makes the SaaS migration a single
 * replacement rather than a search across views.
 */
final class ShellPreference {

    /** Club-scoped config key holding the install default. */
    public const CONFIG_KEY = 'tt_frontend_shell';

    /** User-meta key holding the per-user override. */
    public const USER_META_KEY = 'tt_frontend_shell';

    /** Today's chrome. The default, and the rollback. */
    public const CLASSIC = 'classic';

    /** The persistent application shell. */
    public const APP = 'app';

    /** Per-user value meaning "follow the club default". */
    public const INHERIT = 'inherit';

    /**
     * Shell values a club default or user override may hold.
     *
     * @return list<string>
     */
    public static function shells(): array {
        return [ self::CLASSIC, self::APP ];
    }

    /**
     * Resolve the shell for a user: override -> club default -> classic.
     *
     * Unknown stored values fall through to the next step rather than
     * rendering nothing, so a hand-edited config or a value left behind by
     * a future version can never produce a chrome-less page.
     */
    public static function resolve( int $user_id = 0 ): string {
        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        $override = self::userOverride( $user_id );
        if ( in_array( $override, self::shells(), true ) ) {
            return $override;
        }

        return self::clubDefault();
    }

    /**
     * The club-wide default. Falls back to `classic` for an unset or
     * unrecognised value — existing installs see no change until an
     * operator opts in.
     */
    public static function clubDefault(): string {
        $stored = ( new ConfigService() )->get( self::CONFIG_KEY, self::CLASSIC );
        return in_array( $stored, self::shells(), true ) ? $stored : self::CLASSIC;
    }

    /** Set the club-wide default. Unrecognised values are ignored. */
    public static function setClubDefault( string $shell ): void {
        if ( ! in_array( $shell, self::shells(), true ) ) {
            return;
        }
        ( new ConfigService() )->set( self::CONFIG_KEY, $shell );
    }

    /**
     * The user's override, or `inherit` when they have not chosen one.
     * A stored value that is not a known shell reads as `inherit`.
     */
    public static function userOverride( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return self::INHERIT;
        }
        $stored = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
        return in_array( $stored, self::shells(), true ) ? $stored : self::INHERIT;
    }

    /**
     * Store a user override. `inherit` deletes the meta so the user
     * follows the club default again — including when the operator
     * changes it later.
     */
    public static function setUserOverride( int $user_id, string $value ): void {
        if ( $user_id <= 0 ) {
            return;
        }
        if ( $value === self::INHERIT ) {
            delete_user_meta( $user_id, self::USER_META_KEY );
            return;
        }
        if ( ! in_array( $value, self::shells(), true ) ) {
            return;
        }
        update_user_meta( $user_id, self::USER_META_KEY, $value );
    }

    /** True when the resolved shell is the persistent app shell. */
    public static function isApp( int $user_id = 0 ): bool {
        return self::resolve( $user_id ) === self::APP;
    }

    /**
     * Root class stamped on the dashboard wrapper so per-view CSS can
     * adapt without JS and without reading the config a second time.
     */
    public static function rootClass( int $user_id = 0 ): string {
        return 'tt-shell-' . self::resolve( $user_id );
    }

    /**
     * Human labels for the shell values, for the operator select and the
     * My settings override.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            self::CLASSIC => __( 'Classic — tile hub, no sidebar', 'talenttrack' ),
            self::APP     => __( 'App shell — sidebar navigation', 'talenttrack' ),
        ];
    }
}
