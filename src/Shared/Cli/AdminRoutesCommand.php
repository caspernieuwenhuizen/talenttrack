<?php
namespace TT\Shared\Cli;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Admin\AdminMenuRegistry;
use TT\Shared\Frontend\DashboardShortcode;

/**
 * `wp tt admin-routes` (#2981, epic #2874) — which wp-admin pages have a
 * frontend equivalent, asked of a booted install.
 *
 * WHY A COMMAND AND NOT A GREP
 *
 * #2874 asked for a generated inventory. A static generator found 32 slugs
 * against the 48 a live install registers, and the gap is structural rather
 * than a bad regex: `AdminMenuRegistry::register()` is called from module
 * `boot()`, guarded by `ModuleRegistry::isEnabled()`, so the methodology
 * editors, `tt-spond` and `tt-football-actions` exist only at runtime.
 *
 * Then the hand-checked list turned out to be stale the other way — seven
 * pages listed as needing a port had been routable since #1451 / #1481 /
 * #1936 / #2654. Two audits, two different wrong answers, both because
 * nobody could ask the running install.
 *
 * A grep cannot answer this question. A booted plugin can.
 *
 * Deliberately NOT a CI gate yet. Get the inventory trustworthy first;
 * decide afterwards whether an unrouted page should fail a build. Turning it
 * into a gate before anyone has read its output twice is how you get a gate
 * people disable.
 */
final class AdminRoutesCommand {

    /**
     * List every wp-admin page this install registers, and whether it has a
     * frontend route.
     *
     * ## OPTIONS
     *
     * [--unrouted]
     * : Only show pages with no frontend route that are not deliberately
     *   admin-only. This is the list that represents actual work.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp tt admin-routes
     *     wp tt admin-routes --unrouted
     *     wp tt admin-routes --format=json
     *
     * @param list<string>          $args
     * @param array<string, string> $assoc
     */
    public function __invoke( array $args, array $assoc ): void {
        $rows = self::rows();

        // #3132 — say what could not be followed statically, before showing a
        // table that implies completeness. A dispatcher arm built from
        // something the deriver cannot resolve is not absent, it is unknown,
        // and the difference is the whole reason this command exists.
        foreach ( self::unresolvedRouteSites() as $where ) {
            \WP_CLI::warning( "Route at {$where} is built from something that cannot be resolved statically. Classify it by hand." );
        }

        if ( isset( $assoc['unrouted'] ) ) {
            $rows = array_values( array_filter(
                $rows,
                static fn( array $r ): bool => $r['status'] === 'unrouted'
            ) );
        }

        $format = $assoc['format'] ?? 'table';

        if ( $rows === [] ) {
            \WP_CLI::success( 'Nothing to report: every admin page this install registers is either routed or deliberately admin-only.' );
            return;
        }

        \WP_CLI\Utils\format_items(
            $format,
            $rows,
            [ 'admin_slug', 'title', 'cap', 'module', 'enabled', 'frontend_slug', 'status' ]
        );
    }

    /**
     * The inventory itself, separated from the presentation so a test can
     * assert it without going through WP_CLI's output layer.
     *
     * @return list<array<string, string>>
     */
    public static function rows(): array {
        $routable    = self::routableSlugs();
        $admin_only  = self::adminOnly();
        $renamed     = self::renamedPairings();
        $rows        = [];

        foreach ( AdminMenuRegistry::allEntries() as $entry ) {
            $slug = (string) ( $entry['slug'] ?? '' );
            if ( $slug === '' ) continue;
            // Separators are menu furniture, not pages.
            if ( ! empty( $entry['is_separator'] ) ) continue;

            $module = (string) ( $entry['module_class'] ?? '' );

            // #3132 — a recorded pairing first, the prefix convention second.
            //
            // Stripping `tt-` holds for most of the plugin and for none of the
            // ports #2874 commissioned: every one of them renamed the slug, so
            // the tool reported the three pages it exists to track as unrouted.
            // The methodology row is the case no prefix rule reaches at all —
            // eight admin pages collapse into one frontend surface.
            //
            // The recorded slug is still checked against the real routable set,
            // so a stale or mistyped map entry reads as unrouted rather than as
            // a false green.
            $candidate = isset( $renamed[ $slug ]['frontend_slug'] )
                ? (string) $renamed[ $slug ]['frontend_slug']
                : ( str_starts_with( $slug, 'tt-' ) ? substr( $slug, 3 ) : $slug );
            $routed    = in_array( $candidate, $routable, true );

            if ( isset( $admin_only[ $slug ] ) ) {
                $status = 'diagnostic';
            } elseif ( $routed ) {
                $status = 'routed';
            } else {
                $status = 'unrouted';
            }

            $rows[] = [
                'admin_slug'    => $slug,
                'title'         => (string) ( $entry['title'] ?? '' ),
                'cap'           => (string) ( $entry['cap'] ?? '' ),
                'module'        => $module === '' ? '(core)' : self::shortModule( $module ),
                'enabled'       => self::moduleEnabled( $module ) ? 'yes' : 'no',
                'frontend_slug' => $routed ? $candidate : '',
                'status'        => $status,
            ];
        }

        usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['admin_slug'], $b['admin_slug'] ) );

        return $rows;
    }

    /**
     * Every `?tt_view=` slug the dispatcher answers.
     *
     * Derived from `DashboardShortcode` because that file is the authority and
     * its arms are not enumerable at runtime — through the shared deriver, not
     * a regex of this command's own. See `derive()` for why that matters.
     *
     * @return list<string>
     */
    public static function routableSlugs(): array {
        return array_keys( self::derive()[0] );
    }

    /**
     * Dispatcher arms this command could not follow statically.
     *
     * Reported rather than dropped, so a route nobody can resolve reads as
     * "classify this by hand" instead of as absent — which is how a reporting
     * tool ends up confidently wrong. `check-docs` and the mobile-class gate
     * print the same list for the same reason.
     *
     * @return list<string>
     */
    public static function unresolvedRouteSites(): array {
        return self::derive()[1];
    }

    /**
     * #3132 — read the canonical deriver rather than a second regex.
     *
     * This command shipped with `preg_match_all( "/case '([a-z0-9-]+)':/" )`,
     * which is exactly the trap `tools/lib/routable-slugs.php` was written for
     * a few weeks earlier: it cannot see a **constant arm**
     * (`case FrontendCategoryWeightsView::SLUG:`, where the literal lives in
     * the view class) or a **pre-auth route** (handled by
     * `$tt_view_param === …` above the dispatch chain). On this tree that is
     * ten live routes invisible to the regex, including two of the three
     * pages #2874 commissioned ports for.
     *
     * `tools/` ships inside the plugin zip — the release rsync excludes
     * `.git`, `.github`, `tests`, `phpstan*`, parts of `vendor/` and
     * `branding-plugin`, not `tools/` — so the require resolves on a real
     * install, not only in a checkout.
     *
     * Four consumers, one deriver: the docs gate, the mobile-class gate, the
     * tile-route gate and this command.
     *
     * @return array{0: array<string, string>, 1: list<string>} [ slug => where, unresolvable call sites ]
     */
    private static function derive(): array {
        $root       = rtrim( TT_PLUGIN_DIR, '/\\' );
        $lib        = $root . '/tools/lib/routable-slugs.php';
        $dispatcher = $root . '/src/Shared/Frontend/DashboardShortcode.php';

        if ( ! is_readable( $lib ) || ! is_readable( $dispatcher ) ) return [ [], [] ];

        require_once $lib;
        if ( ! function_exists( 'tt_routable_slugs' ) ) return [ [], [] ];

        /** @var array{0: array<string, string>, 1: list<string>} $derived */
        $derived = tt_routable_slugs( $root, $dispatcher );

        return $derived;
    }

    /**
     * #3132 — admin pages whose frontend port renamed or merged the slug.
     *
     * @return array<string, array{frontend_slug: string, renamed_by: string}>
     */
    public static function renamedPairings(): array {
        $path = TT_PLUGIN_DIR . 'config/admin_frontend_slug_map.php';
        if ( ! is_readable( $path ) ) return [];

        $map = require $path;

        return is_array( $map ) ? $map : [];
    }

    /** @return array<string, string> */
    public static function adminOnly(): array {
        $path = TT_PLUGIN_DIR . 'config/admin_only_surfaces.php';
        if ( ! is_readable( $path ) ) return [];

        $list = require $path;

        return is_array( $list ) ? $list : [];
    }

    private static function moduleEnabled( string $module_class ): bool {
        if ( $module_class === '' ) return true;
        if ( ! class_exists( '\\TT\\Core\\ModuleRegistry' ) ) return true;

        return \TT\Core\ModuleRegistry::isEnabled( $module_class );
    }

    /** `TT\Modules\Spond\SpondModule` reads as `Spond` in a terminal column. */
    private static function shortModule( string $module_class ): string {
        $parts = explode( '\\', $module_class );
        $last  = (string) end( $parts );

        return str_ends_with( $last, 'Module' ) ? substr( $last, 0, -6 ) : $last;
    }
}
