<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Admin\AdminMenuRegistry;
use TT\Shared\Admin\BulkActionsHelper;
use TT\Shared\Admin\DragReorder;
use TT\Shared\Admin\SchemaStatus;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * Menu — registers the top-level TalentTrack admin menu plus subpages.
 *
 * v2.11.0: added Custom Fields submenu (TalentTrack → Custom Fields).
 * v2.6.0: enqueues admin-sortable.js on TT admin pages. (Originally for
 * the old CustomFieldsTab drag-sort UI; still used by OptionSetEditor.)
 */
class Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        // #2979 — the retired wp-admin dashboard, sent to the real one.
        add_action( 'admin_init', [ __CLASS__, 'redirectRetiredDashboard' ] );
        // v2.17.0: wire bulk-action post handler + admin JS.
        BulkActionsHelper::init();
        // v2.19.0: wire drag-to-reorder AJAX handler.
        DragReorder::init();
        // v3.0.0: migration UX — admin notice + Plugins-page action link +
        // admin-post handler for the "Run now" button.
        SchemaStatus::init();
        // Result notice for the redirect from the Run handler.
        add_action( 'admin_notices', [ SchemaStatus::class, 'renderResultNotice' ] );
    }

    public static function register(): void {
        // #0033 finalisation — every submenu page + non-clickable
        // separator row registered with `AdminMenuRegistry` (seeded
        // from `CoreSurfaceRegistration`) is emitted here, with
        // disabled-module entries skipped automatically. The top-level
        // `add_menu_page` is the only literal that stays — it owns
        // the menu icon position + slug bootstrap that WordPress
        // requires before any submenu can attach.
        //
        // Top-level click lands on the Account page (tabs: Account /
        // Plan & restrictions). #2979 — the legacy stats-and-tiles
        // dashboard that used to sit beside it is retired; the old URL
        // redirects via `redirectRetiredDashboard()`.
        add_menu_page( __( 'TalentTrack', 'talenttrack' ), __( 'TalentTrack', 'talenttrack' ), 'read', 'talenttrack', [ \TT\Modules\License\Admin\AccountPage::class, 'render' ], 'dashicons-groups', 26 );
        AdminMenuRegistry::applyAll();
        // v3.71.1 — remove the auto-cloned first submenu (WP copies the
        // parent label as the first submenu row) entirely. The previous
        // approach renamed it to "Dashboard", which duplicated the
        // top-level "TalentTrack" entry: clicking either landed on the
        // same dashboard and the user kept asking "why is the top item
        // there". Removing the clone leaves a single clear entry point.
        // Runs at admin_menu priority 999 so every submenu (including
        // the auto-mirror) is already registered when we touch
        // $submenu.
        add_action( 'admin_menu', [ __CLASS__, 'removeDashboardMirror' ], 999 );
        add_action( 'admin_head', [ __CLASS__, 'injectMenuCss' ] );
    }

    /**
     * v3.71.1 — drop the auto-cloned `talenttrack` submenu entry.
     * `remove_submenu_page` works on the slug pair (parent, slug) so
     * we target the row whose slug equals the parent slug — that's
     * the WP-injected mirror, not any of the registered children.
     * Idempotent: calling again is a no-op since the row is already
     * gone.
     *
     * v3.90.0 — the top-level entry now lands on the Account page
     * directly (callback in `register()`). Dropping the auto-mirror
     * still matters: WP would otherwise emit a duplicate "TalentTrack"
     * submenu pointing at the same place as the parent, doubling up
     * the entry under the icon.
     *
     * v3.91.2 — also promote `tt-account` to `$submenu['talenttrack'][0]`.
     * WP's menu-header rendering builds the parent menu's `<a>` href
     * from `$submenu[parent][0][2]` (the first child's slug) when
     * children exist. Without this promotion, clicking "TalentTrack"
     * landed on whichever submenu happened to be registered first
     * (`tt-dashboard-layouts` in practice — `PersonaDashboardModule::boot()`
     * runs before `CoreSurfaceRegistration::register()`). Promoting
     * Account to position 0 makes the click land on the Account page
     * regardless of registration order.
     */
    public static function removeDashboardMirror(): void {
        remove_submenu_page( 'talenttrack', 'talenttrack' );

        global $submenu;
        if ( empty( $submenu['talenttrack'] ) || ! is_array( $submenu['talenttrack'] ) ) {
            return;
        }
        $account_slug = \TT\Modules\License\Admin\AccountPage::SLUG; // 'tt-account'
        foreach ( $submenu['talenttrack'] as $i => $row ) {
            if ( ! is_array( $row ) ) continue;
            if ( ( $row[2] ?? '' ) === $account_slug ) {
                if ( $i === 0 ) return; // already first
                $entry = $submenu['talenttrack'][ $i ];
                unset( $submenu['talenttrack'][ $i ] );
                array_unshift( $submenu['talenttrack'], $entry );
                $submenu['talenttrack'] = array_values( $submenu['talenttrack'] );
                return;
            }
        }
    }

    /**
     * CSS that styles tt-sep-* entries as non-clickable heading rows.
     */
    public static function injectMenuCss(): void {
        ?>
        <style>
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] {
            pointer-events: none;
            cursor: default !important;
            padding: 14px 12px 4px !important;
            color: #8a9099 !important;
            font-size: 10px !important;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin-top: 4px;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:hover,
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:focus {
            color: #8a9099 !important;
            background: transparent !important;
        }
        /* The first separator in a menu doesn't need the top border */
        #adminmenu .wp-submenu li:first-child a[href*="page=tt-sep-"] {
            border-top: none;
            margin-top: 0;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] .tt-menu-separator-label {
            pointer-events: none;
        }
        </style>
        <?php
    }

    /**
     * Whether the legacy wp-admin menu entries should be shown.
     * Default false (Sprint 6's aggressive-push policy). Direct URLs
     * to legacy pages keep working regardless of this option.
     *
     * Stored in `tt_config` (the plugin's central key-value table)
     * under the `show_legacy_menus` key. Both the frontend
     * Configuration view (REST POST /config) and the wp-admin
     * Configuration page write here.
     */
    public static function shouldShowLegacyMenus(): bool {
        $value = QueryHelpers::get_config( 'show_legacy_menus', '0' );
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * #0024 — show the Welcome submenu while the setup wizard hasn't
     * been completed/dismissed. Force-on via `?force_welcome=1` so the
     * Reset link can re-enter the wizard from a completed install.
     */
    public static function shouldShowWelcome(): bool {
        if ( isset( $_GET['force_welcome'] ) && $_GET['force_welcome'] === '1' ) return true;
        if ( ! class_exists( '\TT\Modules\Onboarding\OnboardingState' ) ) return false;
        return \TT\Modules\Onboarding\OnboardingState::shouldShowWelcome();
    }

    /**
     * #2979 — `?page=tt-dashboard` is retired; send it to the real one.
     *
     * The wp-admin dashboard was a second dashboard: a tile grid mirroring
     * the menu, plus five stat cards with a weekly delta. The tile half was
     * superseded by the frontend root (`PersonaLandingRenderer`), and the
     * decision on this issue is that the deltas are not worth porting — two
     * dashboards that can disagree cost more than an at-a-glance count, and
     * the same numbers are derivable from the analytics engine.
     *
     * Retired by **redirect**, not by deleting the registration. It is a
     * plausible bookmark, and an admin whose bookmark dies files a bug while
     * one who lands on the working equivalent does not notice.
     */
    public static function redirectRetiredDashboard(): void {
        $requested = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
        if ( $requested !== 'tt-dashboard' ) return;

        // Only when the frontend dashboard is actually somewhere. With no
        // page hosting the shortcode, `dashboardUrl()` falls back to the
        // current request — which here is this admin page, and a redirect
        // loop is a worse outcome than a dead bookmark.
        $target = RecordLink::dashboardPageId() > 0
            ? RecordLink::dashboardUrl()
            : admin_url();

        wp_safe_redirect( $target, 301 );
        exit;
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'talenttrack' ) === false && strpos( $hook, 'tt-' ) === false ) return;
        wp_enqueue_style( 'tt-admin', TT_PLUGIN_URL . 'assets/css/admin.css', [], TT_VERSION );
        // Pull frontend-admin.css so the .tt-confirm-overlay / .tt-flash-near
        // styles are available on wp-admin pages that use admin-confirm.js
        // (they're scoped to .tt-confirm-* / .tt-flash-near-* selectors and
        // don't bleed into core admin styles).
        wp_enqueue_style( 'tt-frontend-admin', TT_PLUGIN_URL . 'assets/css/frontend-admin.css', [ 'tt-admin' ], TT_VERSION );
        wp_enqueue_script( 'tt-admin', TT_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], TT_VERSION, true );

        // Confirm + flash bridge: shipped on every TT-prefixed admin page so
        // any button can declare data-tt-confirm-* attributes and get the
        // in-app modal instead of browser window.confirm(). (#3)
        wp_enqueue_script( 'tt-confirm',       TT_PLUGIN_URL . 'assets/js/components/confirm.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-flash',         TT_PLUGIN_URL . 'assets/js/components/flash.js',   [], TT_VERSION, true );
        wp_enqueue_script( 'tt-admin-confirm', TT_PLUGIN_URL . 'assets/js/admin-confirm.js', [ 'tt-confirm', 'tt-flash' ], TT_VERSION, true );

        // F4 — client-side search + sort + filter on TT admin tables.
        // Tables opt in by adding the `tt-table-sortable` class to the
        // <table> element. The script auto-adds a search input above
        // and makes every <th> sortable on click.
        wp_enqueue_script(
            'tt-table-tools',
            TT_PLUGIN_URL . 'assets/js/tt-table-tools.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-table-tools',
            'ttTableToolsStrings',
            [
                'search'            => __( 'Search:', 'talenttrack' ),
                'searchPlaceholder' => __( 'Filter rows…', 'talenttrack' ),
                'rowsTotal'         => __( '{n} row(s)', 'talenttrack' ),
                'rowsFiltered'      => __( '{v} of {n}', 'talenttrack' ),
            ]
        );

        // Register (not auto-enqueue) the sortable script. The CustomFieldsTab
        // and OptionSetEditor call wp_enqueue_script('tt-admin-sortable') on
        // demand — this registration makes that call effective.
        wp_register_script(
            'tt-admin-sortable',
            TT_PLUGIN_URL . 'assets/js/admin-sortable.js',
            [],
            TT_VERSION,
            true
        );
    }
}
