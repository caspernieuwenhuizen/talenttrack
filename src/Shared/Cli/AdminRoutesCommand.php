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
        $rows        = [];

        foreach ( AdminMenuRegistry::allEntries() as $entry ) {
            $slug = (string) ( $entry['slug'] ?? '' );
            if ( $slug === '' ) continue;
            // Separators are menu furniture, not pages.
            if ( ! empty( $entry['is_separator'] ) ) continue;

            $module = (string) ( $entry['module_class'] ?? '' );

            // The frontend slug is the admin slug minus the `tt-` prefix, by
            // the convention every port so far has followed. Where a port
            // chose a different name the pair still shows as unrouted, which
            // is the honest answer: nothing in the codebase records the link.
            $candidate = str_starts_with( $slug, 'tt-' ) ? substr( $slug, 3 ) : $slug;
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
     * Parsed out of `DashboardShortcode`'s `switch` because that file is the
     * authority and its arms are not enumerable at runtime. The issue proposed
     * lifting the routable set into a shared array both this and the #2885
     * tile-route gate could read, which is the better end state — but that is a
     * refactor of the busiest file in the plugin and belongs in its own PR, not
     * riding along inside a reporting tool.
     *
     * @return list<string>
     */
    public static function routableSlugs(): array {
        $path = TT_PLUGIN_DIR . 'src/Shared/Frontend/DashboardShortcode.php';
        if ( ! is_readable( $path ) ) return [];

        $source = (string) file_get_contents( $path );
        if ( $source === '' ) return [];

        $matches = [];
        preg_match_all( "/case\s+'([a-z0-9-]+)'\s*:/", $source, $matches );

        /** @var list<string> $slugs */
        $slugs = array_values( array_unique( $matches[1] ?? [] ) );
        sort( $slugs );

        return $slugs;
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
