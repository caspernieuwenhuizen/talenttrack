<?php
namespace TT\Modules\MatchExecution;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;

/**
 * MatchExecutionLayout (#2934) — which container the live-match surface
 * renders in.
 *
 * `?tt_view=match-execution` ships two layouts:
 *
 *   - `classic`  — the single scroll the surface has always had: up to
 *     fourteen sections stacked in one scroller.
 *   - `sections` — a pinned match bar, a state-derived section switcher
 *     in the thumb zone, one scrolling panel and a pinned state action.
 *
 * `classic` is the default and stays a complete rollback: the sectioned
 * layout adds no data and changes no state machine, so flipping the value
 * back restores the previous rendering exactly.
 *
 * Shaped deliberately like {@see \TT\Shared\Frontend\ShellPreference}.
 * Resolution order is user override -> club default -> `classic`. The club
 * default lives in `tt_config` (club-scoped per CLAUDE.md §4 — never
 * `wp_options`, which is global to the WP install and would leak across
 * tenants). The per-user override is a preference rather than tenant
 * config, so it lives in user meta; `inherit` (the default) means "follow
 * the club".
 *
 * **The per-user override is the point.** A coach who has run twenty
 * matches on the current layout should not be moved onto a new one because
 * the academy flipped a default mid-season, and an academy should be able
 * to trial the new layout on one coach's phone for one Saturday before
 * flipping everyone. That is the same argument #2456 makes for the
 * navigation shell, and it is why this is not simply a club-wide switch.
 *
 * **Not a module or feature toggle.** Those answer "does this academy have
 * this capability at all" — switching a module off removes the surface,
 * hides its tile and gates its entities. This decides how a surface that
 * is definitely present is laid out, which is the same kind of decision as
 * `tt_frontend_shell` and belongs beside it.
 *
 * Every consumer reads through {@see resolve()}. Nothing else reads the
 * config key, which is what makes retiring the old layout a single-file
 * change rather than a search across the view.
 */
final class MatchExecutionLayout {

    /** Club-scoped config key holding the academy default. */
    public const CONFIG_KEY = 'tt_match_execution_layout';

    /** User-meta key holding the per-user override. */
    public const USER_META_KEY = 'tt_match_execution_layout';

    /** Today's single scroll. The default, and the rollback. */
    public const CLASSIC = 'classic';

    /** Pinned bar + thumb-zone section switcher + one panel. */
    public const SECTIONS = 'sections';

    /** Per-user value meaning "follow the academy default". */
    public const INHERIT = 'inherit';

    /**
     * Layouts a club default or user override may hold.
     *
     * @return list<string>
     */
    public static function layouts(): array {
        return [ self::CLASSIC, self::SECTIONS ];
    }

    /**
     * Resolve the layout for a user: override -> club default -> classic.
     *
     * Unknown stored values fall through to the next step rather than
     * rendering nothing, so a hand-edited config or a value left behind by
     * a future version can never produce a broken surface.
     */
    public static function resolve( int $user_id = 0 ): string {
        if ( $user_id <= 0 ) {
            $user_id = get_current_user_id();
        }

        $override = self::userOverride( $user_id );
        if ( in_array( $override, self::layouts(), true ) ) {
            return $override;
        }

        return self::clubDefault();
    }

    /**
     * The academy-wide default. Falls back to `classic` for an unset or
     * unrecognised value — existing installs see no change until an
     * operator opts in.
     */
    public static function clubDefault(): string {
        $stored = ( new ConfigService() )->get( self::CONFIG_KEY, self::CLASSIC );
        return in_array( $stored, self::layouts(), true ) ? $stored : self::CLASSIC;
    }

    /** Set the academy-wide default. Unrecognised values are ignored. */
    public static function setClubDefault( string $layout ): void {
        if ( ! in_array( $layout, self::layouts(), true ) ) {
            return;
        }
        ( new ConfigService() )->set( self::CONFIG_KEY, $layout );
    }

    /**
     * The user's override, or `inherit` when they have not chosen one.
     * A stored value that is not a known layout reads as `inherit`.
     */
    public static function userOverride( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return self::INHERIT;
        }
        $stored = (string) get_user_meta( $user_id, self::USER_META_KEY, true );
        return in_array( $stored, self::layouts(), true ) ? $stored : self::INHERIT;
    }

    /**
     * Store a user override. `inherit` deletes the meta so the user
     * follows the academy default again — including when the operator
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
        if ( ! in_array( $value, self::layouts(), true ) ) {
            return;
        }
        update_user_meta( $user_id, self::USER_META_KEY, $value );
    }

    /** True when the resolved layout is the sectioned one. */
    public static function isSections( int $user_id = 0 ): bool {
        return self::resolve( $user_id ) === self::SECTIONS;
    }

    /**
     * Human labels for the layout values, for the operator select and the
     * My settings override.
     *
     * @return array<string, string>
     */
    public static function labels(): array {
        return [
            self::CLASSIC  => __( 'Classic — one long page', 'talenttrack' ),
            self::SECTIONS => __( 'Sections — score pinned, tabs at the bottom', 'talenttrack' ),
        ];
    }
}
