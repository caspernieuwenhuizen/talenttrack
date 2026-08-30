<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CrossViewLinkRegistry (#2304) — the single source of truth for "can the
 * current user reach view `<slug>`?" as used by in-body cross-view links,
 * tiles and buttons.
 *
 * Historically each in-body cross-view link hand-checked the target view's
 * capability inline, and those checks drifted from the target view's actual
 * early-return guard. This registry maps a `tt_view` slug → the gate that
 * mirrors that view's REAL guard, so the affordance is hidden exactly when
 * the destination would refuse the user.
 *
 * IMPORTANT: the gate registered for a slug mirrors the TARGET VIEW's own
 * early-return guard — NOT the dashboard-tile visibility entity declared in
 * `TileRegistry`. Those two frequently differ (e.g. the `team-planner` tile
 * declares the `activities_panel` entity for dashboard visibility, but the
 * team-planner view enforces `tt_view_plan`). Gates live in
 * `CoreSurfaceRegistration::registerCrossViewLinkGates()`.
 *
 * A gate is one of three forms:
 *   - a cap string (e.g. `'tt_view_plan'`)          → AuthorizationService::userCanOrMatrix
 *   - an `[entity, activity]` pair                   → MatrixGate::canAnyScope
 *   - a closure `fn(int $uid, array $ctx): bool`     → called directly
 *
 * The render helper is `CrossViewLink`; the CI gate `xview-link-lint.yml`
 * stops NEW in-body cross-view links from skipping the helper.
 */
final class CrossViewLinkRegistry {

    /**
     * @var array<string, string|array{0:string,1:string}|callable>
     */
    private static array $gates = [];

    /**
     * Register (or overwrite) the gate for a view slug.
     *
     * @param string                                         $slug
     * @param string|array{0:string,1:string}|callable       $gate
     */
    public static function register( string $slug, $gate ): void {
        if ( $slug === '' ) return;
        self::$gates[ $slug ] = $gate;
    }

    /**
     * Whether a gate is registered for the slug.
     */
    public static function isRegistered( string $slug ): bool {
        return isset( self::$gates[ $slug ] );
    }

    /**
     * Clear the registry — for tests.
     */
    public static function clear(): void {
        self::$gates = [];
    }

    /**
     * Decide whether `$userId` may reach view `$slug`.
     *
     * - When a gate IS registered: evaluate it (a user id <= 0 is denied).
     * - When NOT registered: fall back to a permissive read check — if the
     *   slug's tile declares a matrix entity AND the matrix is active, allow
     *   only matrix readers; otherwise allow. This keeps unregistered
     *   internal links working while the CI gate stops NEW ungated links.
     *
     * @param array<string,mixed> $ctx
     */
    public static function allows( string $slug, int $userId, array $ctx = [] ): bool {
        if ( self::surfaceSwitchedOff( $slug ) ) return false;

        if ( isset( self::$gates[ $slug ] ) ) {
            if ( $userId <= 0 ) return false;
            return self::evaluate( self::$gates[ $slug ], $userId, $ctx );
        }
        return self::fallbackAllows( $slug, $userId );
    }

    /**
     * Does this surface exist on this install at all? (#3254)
     *
     * A different question from the gate below it, and asked first. The
     * gate answers "may this user do it?"; this answers "is there anything
     * here to do?" — and an academy that has switched a module off should
     * not be offered its surfaces no matter who is looking.
     *
     * That distinction is why this cannot live in the cap layer.
     * `LegacyCapMapper` lets a WP `administrator` pass every `tt_*` cap
     * unconditionally, which is the deliberate emergency override for the
     * human running the install — so a cap-based check hid the affordance
     * from a coach and left it in place for the operator, who is exactly
     * the person most likely to have just switched the module off.
     *
     * The same pair the dispatcher consults before routing
     * (`DashboardShortcode::dispatch()`), so nav and dispatch answer
     * identically — the drift #2570 closed for capabilities, closed for
     * module and feature state. It sits alongside `allows()` rather than
     * inside `evaluate()`, and `CrossViewLink::allows()` asks it before
     * taking the `$opts['gate']` override branch, so a caller passing its
     * own gate cannot skip it either.
     */
    public static function surfaceSwitchedOff( string $slug ): bool {
        if ( $slug === '' ) return false;

        if ( class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' )
            && \TT\Shared\Tiles\TileRegistry::isViewSlugDisabled( $slug ) ) {
            return true;
        }

        if ( class_exists( '\\TT\\Core\\FeatureRegistry' )
            && \TT\Core\FeatureRegistry::viewSlugDisabled( $slug ) ) {
            return true;
        }

        return false;
    }

    /**
     * Evaluate a gate of any of the three supported forms. Shared by
     * `CrossViewLink` so an explicit `$opts['gate']` override normalizes
     * identically to a registered gate.
     *
     * Guards for `class_exists` / `method_exists` on the authorization
     * classes and returns `true` when they're absent (graceful degradation,
     * mirroring how the dashboard dispatcher's `matrixDispatchAllows` guards).
     *
     * @param string|array{0:string,1:string}|callable $gate
     * @param array<string,mixed>                       $ctx
     */
    public static function evaluate( $gate, int $userId, array $ctx = [] ): bool {
        // Closure / callable form.
        if ( is_callable( $gate ) ) {
            return (bool) $gate( $userId, $ctx );
        }

        // [entity, activity] pair → MatrixGate::canAnyScope.
        if ( is_array( $gate ) ) {
            $entity   = isset( $gate[0] ) ? (string) $gate[0] : '';
            $activity = isset( $gate[1] ) ? (string) $gate[1] : '';
            if ( $entity === '' || $activity === '' ) return false;
            if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' )
                || ! method_exists( '\\TT\\Modules\\Authorization\\MatrixGate', 'canAnyScope' ) ) {
                return true;
            }
            return \TT\Modules\Authorization\MatrixGate::canAnyScope( $userId, $entity, $activity );
        }

        // Cap-string form → AuthorizationService::userCanOrMatrix.
        $cap = (string) $gate;
        if ( $cap === '' ) return false;
        if ( ! class_exists( '\\TT\\Infrastructure\\Security\\AuthorizationService' )
            || ! method_exists( '\\TT\\Infrastructure\\Security\\AuthorizationService', 'userCanOrMatrix' ) ) {
            return true;
        }
        return \TT\Infrastructure\Security\AuthorizationService::userCanOrMatrix( $userId, $cap );
    }

    /**
     * Permissive fallback for unregistered slugs: gate on the tile's
     * declared matrix entity at `read` when the matrix is active, else
     * allow. Mirrors the dashboard dispatcher's `matrixDispatchAllows`
     * (entity from TileRegistry, admin bypass, class-absent → allow).
     */
    private static function fallbackAllows( string $slug, int $userId ): bool {
        if ( $slug === '' ) return true;
        if ( ! class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' )
            || ! method_exists( '\\TT\\Shared\\Tiles\\TileRegistry', 'entityForViewSlug' ) ) {
            return true;
        }
        $entity = \TT\Shared\Tiles\TileRegistry::entityForViewSlug( $slug );
        if ( $entity === null ) return true;
        if ( ! self::matrixActive() ) return true;
        if ( $userId <= 0 ) return false;
        $user = get_user_by( 'id', $userId );
        if ( $user instanceof \WP_User && in_array( 'administrator', (array) $user->roles, true ) ) {
            return true;
        }
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' )
            || ! method_exists( '\\TT\\Modules\\Authorization\\MatrixGate', 'canAnyScope' ) ) {
            return true;
        }
        return \TT\Modules\Authorization\MatrixGate::canAnyScope( $userId, $entity, 'read' );
    }

    /**
     * Whether the authorization matrix is active (`tt_authorization_active`).
     * Not cached — the fallback path is off the hot render loop, and tests
     * flip the option between assertions.
     */
    private static function matrixActive(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            return false;
        }
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        return (bool) $cfg->getBool( 'tt_authorization_active', false );
    }
}
