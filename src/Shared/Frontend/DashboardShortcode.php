<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Auth\LoginForm;
use TT\Modules\Auth\LogoutHandler;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Tiles\TileRegistry;

/**
 * DashboardShortcode — owns [talenttrack_dashboard].
 *
 * v2.6.1: adds a "Go to Admin" link to the user-menu dropdown for
 * administrators only. Non-admins see only Edit profile + Log out.
 */
class DashboardShortcode {

    public static function register(): void {
        add_shortcode( 'talenttrack_dashboard', [ __CLASS__, 'render' ] );
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function render( $atts = [] ): string {
        // #1379 — design tokens (the single source for the 2026 green/gold
        // palette, type/space scale, radius, shadow). Enqueued first, with
        // no deps, so every other TalentTrack stylesheet inherits it.
        wp_enqueue_style( 'tt-tokens', TT_PLUGIN_URL . 'assets/css/tokens.css', [], TT_VERSION );
        wp_enqueue_style( 'tt-public', TT_PLUGIN_URL . 'assets/css/public.css', [ 'tt-tokens' ], TT_VERSION );
        // #0019 Sprint 1 session 3 — shared component + token stylesheet
        // loaded alongside the legacy one. Every new component reads from
        // tokens defined here; public.css keeps the legacy dashboard/login
        // layout untouched.
        wp_enqueue_style( 'tt-frontend-admin', TT_PLUGIN_URL . 'assets/css/frontend-admin.css', [ 'tt-public' ], TT_VERSION );
        // #1690 — shared frontend "app chrome": restyles the global header
        // into the top bar + persona chip, and ships the reusable KPI tile.
        wp_enqueue_style( 'tt-frontend-app-chrome', TT_PLUGIN_URL . 'assets/css/frontend-app-chrome.css', [ 'tt-public', 'tt-frontend-admin' ], TT_VERSION );
        // #2631 — the alerts banner. Loaded on every view rather than only
        // the landing page: the banner renders above the body of whatever
        // the coach opened, so its styles must be present there too.
        wp_enqueue_style( 'tt-frontend-alerts', TT_PLUGIN_URL . 'assets/css/frontend-alerts.css', [ 'tt-public' ], TT_VERSION );
        // #2632 — the preference + policy matrices. Small enough not to be
        // worth a per-view conditional enqueue, and the settings screens are
        // reachable from more than one entry point.
        wp_enqueue_style( 'tt-frontend-alert-settings', TT_PLUGIN_URL . 'assets/css/frontend-alert-settings.css', [ 'tt-public' ], TT_VERSION );
        // #2633 — the inline alert chip. Enqueued here rather than lazily
        // from AlertChip itself: a chip is frequently built into a markup
        // STRING (the activity card is assembled and returned, not echoed),
        // which can happen long after wp_enqueue_scripts has run. A
        // stylesheet that arrives after the head is written is a stylesheet
        // that never applies, so the sheet loads with the rest of the
        // dashboard chrome and the component stays a pure renderer.
        \TT\Shared\Frontend\Components\AlertChip::enqueue();
        // #2035 — branded 404 layout, used by the in-app "?tt_view=<unknown>"
        // fallback (and shared with the standalone WP-404 takeover).
        wp_enqueue_style( 'tt-frontend-404', TT_PLUGIN_URL . 'assets/css/frontend-404.css', [ 'tt-public' ], TT_VERSION );
        // #2545 — links inside a rendered help topic. Loaded on every view,
        // not just the docs page: the help drawer renders topic bodies over
        // whatever the reader happens to be looking at.
        wp_enqueue_style( 'tt-frontend-docs', TT_PLUGIN_URL . 'assets/css/frontend-docs.css', [ 'tt-public' ], TT_VERSION );
        // #2456 — the `app` shell's sidebar / rail / drawer. Enqueued only
        // when the resolved shell asks for it, so a `classic` install
        // ships neither the stylesheet nor the behaviour script.
        if ( ShellPreference::isApp() ) {
            wp_enqueue_style(
                'tt-frontend-app-shell',
                TT_PLUGIN_URL . 'assets/css/frontend-app-shell.css',
                [ 'tt-public', 'tt-frontend-app-chrome' ],
                TT_VERSION
            );
            wp_enqueue_script(
                'tt-app-shell',
                TT_PLUGIN_URL . 'assets/js/components/app-shell.js',
                [],
                TT_VERSION,
                true
            );
            // #2459 — thumb-zone bar below 768px. Its own sheet rather
            // than an addition to the shell's, so the two can be worked
            // on independently.
            wp_enqueue_style(
                'tt-frontend-app-bottom-bar',
                TT_PLUGIN_URL . 'assets/css/frontend-app-bottom-bar.css',
                [ 'tt-frontend-app-shell' ],
                TT_VERSION
            );
            // #2479 — pinned record identity (RecordSpine). Own sheet for
            // the same reason.
            wp_enqueue_style(
                'tt-frontend-record-spine',
                TT_PLUGIN_URL . 'assets/css/frontend-record-spine.css',
                [ 'tt-frontend-app-shell' ],
                TT_VERSION
            );
            // #2458 — command palette + peek panel. The sidebar solves the
            // ~30 grouped destinations; the palette is what reaches a named
            // record and the long setup / report tail.
            wp_enqueue_style(
                'tt-frontend-spotlight',
                TT_PLUGIN_URL . 'assets/css/frontend-spotlight.css',
                [ 'tt-frontend-app-shell' ],
                TT_VERSION
            );
            wp_enqueue_script(
                'tt-spotlight',
                TT_PLUGIN_URL . 'assets/js/components/spotlight.js',
                [ 'tt-public' ],
                TT_VERSION,
                true
            );
        }

        // #2512 — the resolved theme's token layer. Enqueued last of the
        // stylesheets so it overrides both tokens.css and the shell's own
        // sheet; skipped entirely under the `default` theme, which is what
        // keeps that value a complete rollback. The dependency list picks
        // up the shell sheet only when the shell actually enqueued it, so
        // a `classic` install never registers a handle it does not load.
        $theme_style = ThemePreference::styleFile();
        if ( $theme_style !== '' ) {
            $theme_deps = [ 'tt-public', 'tt-frontend-app-chrome' ];
            if ( ShellPreference::isApp() ) {
                $theme_deps[] = 'tt-frontend-app-shell';
            }
            wp_enqueue_style(
                'tt-theme',
                TT_PLUGIN_URL . 'assets/css/' . $theme_style,
                $theme_deps,
                TT_VERSION
            );
        }

        wp_enqueue_script( 'tt-public', TT_PLUGIN_URL . 'assets/js/public.js', [], TT_VERSION, true );
        // #2517 — warm the next page on hover. Navigation stays a real page
        // load; this only removes the wait. Pairs with the `Sec-Purpose`
        // guard in UsageTracker, without which a hover would be recorded as
        // a visit.
        wp_enqueue_script( 'tt-prefetch', TT_PLUGIN_URL . 'assets/js/components/prefetch.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-flash',   TT_PLUGIN_URL . 'assets/js/components/flash.js',     [], TT_VERSION, true );
        wp_enqueue_script( 'tt-drafts',  TT_PLUGIN_URL . 'assets/js/drafts.js',               [ 'tt-public' ], TT_VERSION, true );
        wp_enqueue_script( 'tt-rating',  TT_PLUGIN_URL . 'assets/js/components/rating.js',    [], TT_VERSION, true );
        wp_enqueue_script( 'tt-multitag', TT_PLUGIN_URL . 'assets/js/components/multitag.js', [], TT_VERSION, true );
        // F1-F6 sprint — autocomplete-driven player picker.
        wp_enqueue_script( 'tt-player-search-picker', TT_PLUGIN_URL . 'assets/js/components/player-search-picker.js', [], TT_VERSION, true );
        // #0019 Sprint 2 session 2 — FrontendListTable hydrator. The PHP
        // shell embeds its own config/state JSON; this script binds to
        // every `.tt-list-table` on the page and takes over filter/sort/
        // pagination so changes happen without a full reload.
        wp_enqueue_script( 'tt-list-table', TT_PLUGIN_URL . 'assets/js/components/frontend-list-table.js', [], TT_VERSION, true );
        // #0019 Sprint 2 session 2.3 — attendance helpers (bulk-present,
        // mobile pagination at 15, team filter, live summary).
        wp_enqueue_script( 'tt-attendance', TT_PLUGIN_URL . 'assets/js/components/attendance.js', [], TT_VERSION, true );
        // #0019 Sprint 3 session 3.2 — team roster add/remove + CSV import flow.
        wp_enqueue_script( 'tt-team-roster', TT_PLUGIN_URL . 'assets/js/components/team-roster.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-csv-import',  TT_PLUGIN_URL . 'assets/js/components/csv-import.js',  [], TT_VERSION, true );
        // #0019 Sprint 4 — functional roles reorder + delete buttons.
        wp_enqueue_script( 'tt-functional-roles', TT_PLUGIN_URL . 'assets/js/components/functional-roles.js', [], TT_VERSION, true );
        // #0019 Sprint 5 — admin-tier reorder helper (eval categories).
        wp_enqueue_script( 'tt-admin-reorder', TT_PLUGIN_URL . 'assets/js/components/admin-reorder.js', [], TT_VERSION, true );
        // #0016 Part B — context-aware help drawer.
        wp_enqueue_script( 'tt-docs-drawer', TT_PLUGIN_URL . 'assets/js/components/docs-drawer.js', [], TT_VERSION, true );
        wp_localize_script( 'tt-docs-drawer', 'TT_DocsDrawer', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/docs' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'view_to_topic' => self::viewToTopicMap(),
            'default_slug'  => 'getting-started',
            'i18n' => [
                'loading'   => __( 'Loading…', 'talenttrack' ),
                'failed'    => __( 'Could not load this topic. Try the full Help & Docs page.', 'talenttrack' ),
                'no_topic'  => __( 'No matching topic for this section. Showing the default.', 'talenttrack' ),
            ],
        ] );

        // #0019 Sprint 1 session 2 — public.js uses fetch() against the
        // REST API. Nonce is the standard WP REST `wp_rest` nonce,
        // sent via the `X-WP-Nonce` header by the script.
        wp_localize_script( 'tt-public', 'TT', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            // #2456 — the resolved shell, so JS can branch without
            // re-deriving it. CLAUDE.md §4: the front end reads config
            // from window.TT.*, never from PHP-rendered HTML.
            'shell'      => ShellPreference::resolve(),
            // #2512 — the resolved theme, for the same reason.
            'theme'      => ThemePreference::resolve(),
            'i18n'       => [
                'shell_nav_collapse'   => __( 'Collapse navigation', 'talenttrack' ),
                'shell_nav_expand'     => __( 'Expand navigation', 'talenttrack' ),
                // #2458 — palette + peek. Strings go through TT.i18n rather
                // than being hardcoded in JS (CLAUDE.md §4).
                'spotlight_title'         => __( 'Jump to', 'talenttrack' ),
                'spotlight_placeholder'   => __( 'Search players, teams, activities…', 'talenttrack' ),
                'spotlight_empty'         => __( 'Nothing matched.', 'talenttrack' ),
                // #2531 — announced politely when the result list changes;
                // focus stays in the field, so nothing else reports it.
                'spotlight_results'       => __( 'results', 'talenttrack' ),
                'spotlight_type_view'     => __( 'Section', 'talenttrack' ),
                'spotlight_type_player'   => __( 'Player', 'talenttrack' ),
                'spotlight_type_team'     => __( 'Team', 'talenttrack' ),
                'spotlight_type_activity' => __( 'Activity', 'talenttrack' ),
                'peek_title'              => __( 'Preview', 'talenttrack' ),
                'peek_loading'            => __( 'Loading…', 'talenttrack' ),
                'peek_open'               => __( 'Open', 'talenttrack' ),
                'peek_close'              => __( 'Close', 'talenttrack' ),
                'saving'               => __( 'Saving...', 'talenttrack' ),
                'saved'                => __( 'Saved.', 'talenttrack' ),
                'error_generic'        => __( 'Error.', 'talenttrack' ),
                'network_error'        => __( 'Network error.', 'talenttrack' ),
                'confirm_delete_goal'  => __( 'Delete this goal?', 'talenttrack' ),
                'save_evaluation'      => __( 'Save', 'talenttrack' ),
                'save_activity'        => __( 'Save', 'talenttrack' ),
                'add_goal'             => __( 'Add goal', 'talenttrack' ),
                'save'                 => __( 'Save', 'talenttrack' ),
                'draft_prompt'         => __( 'You have unsaved changes from an earlier session — restore?', 'talenttrack' ),
                'draft_restore'        => __( 'Restore', 'talenttrack' ),
                'draft_discard'        => __( 'Discard', 'talenttrack' ),
                'show_all_count'       => __( 'Show all (%d)', 'talenttrack' ),
                'attendance_summary'   => __( '%1$d of %2$d marked Present', 'talenttrack' ),
                'csv_preview_summary'  => __( 'Showing %1$d of %2$d rows.', 'talenttrack' ),
                'csv_dupe_of'          => __( 'Matches existing player #%d', 'talenttrack' ),
                'csv_created'          => __( 'Created: %d', 'talenttrack' ),
                'csv_updated'          => __( 'Updated: %d', 'talenttrack' ),
                'csv_skipped'          => __( 'Skipped (dupes): %d', 'talenttrack' ),
                'csv_errored'          => __( 'Errors: %d', 'talenttrack' ),
                // i18n audit (May 2026) — csv-import preview labels were hardcoded in JS.
                'csv_status_error'     => __( 'Error', 'talenttrack' ),
                'csv_status_dupe'      => __( 'Dupe', 'talenttrack' ),
                'csv_status_ok'        => __( 'OK', 'talenttrack' ),
                'csv_col_row'          => __( 'Row', 'talenttrack' ),
                'csv_col_status'       => __( 'Status', 'talenttrack' ),
                'csv_col_player'       => __( 'Player', 'talenttrack' ),
                'csv_col_dob'          => __( 'DOB', 'talenttrack' ),
                'csv_col_team'         => __( 'Team', 'talenttrack' ),
                'csv_col_notes'        => __( 'Notes', 'talenttrack' ),
                'csv_pick_file_first'  => __( 'Pick a CSV file first.', 'talenttrack' ),
                'csv_preview_failed'   => __( 'Could not preview the file.', 'talenttrack' ),
                'csv_import_failed'    => __( 'Import failed.', 'talenttrack' ),
                // i18n audit — aria-labels previously hardcoded.
                'remove'               => __( 'Remove', 'talenttrack' ),
                'dismiss'              => __( 'Dismiss', 'talenttrack' ),
                'fnrole_delete_confirm' => __( 'Delete this role type?', 'talenttrack' ),
                'eval_cat_delete_confirm' => __( 'Delete this category?', 'talenttrack' ),
                // #3 — confirm dialog + post-event flash strings.
                'confirm_ok'                => __( 'OK', 'talenttrack' ),
                'confirm_cancel'            => __( 'Cancel', 'talenttrack' ),
                'confirm_delete_goal_title' => __( 'Delete goal?', 'talenttrack' ),
                'delete_label'              => __( 'Delete', 'talenttrack' ),
                'deleted_goal'              => __( 'Goal deleted.', 'talenttrack' ),
                // #2023 — recycle-bin move: Undo banner + cascade-preview dialog.
                'undo'              => __( 'Undo', 'talenttrack' ),
                'cascade_none'      => __( 'No linked records.', 'talenttrack' ),
                'cascade_removed'   => __( 'Linked records that will be removed on purge:', 'talenttrack' ),
                'cascade_kept'      => __( 'References that will be cleared:', 'talenttrack' ),
                'cascade_zeroed'    => __( 'References that will be reset:', 'talenttrack' ),
                'cascade_blockers'  => __( 'Records that currently block a permanent delete:', 'talenttrack' ),
            ],
        ]);

        // #0032 — invitation acceptance must render before the login
        // guard. Token is the credential; the recipient may not have
        // an account yet.
        $tt_view_param = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $tt_view_param === 'accept-invite' ) {
            ob_start();
            echo '<div class="tt-dashboard">';
            FlashMessages::render();
            \TT\Modules\Invitations\Frontend\AcceptanceView::render();
            echo '</div>';
            return (string) ob_get_clean();
        }

        // #2709 — the match-analysis share link renders before the login
        // guard for the same reason the invitation does: the signed token
        // IS the credential, and the assistant coach it was sent to may
        // have no account on this install. The view verifies the HMAC,
        // honours the `match_analysis_sharing` feature toggle, and marks
        // the page noindex; the coach can revoke every issued link from
        // the analysis at any time.
        if ( $tt_view_param === \TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink::VIEW_SLUG ) {
            ob_start();
            echo '<div class="tt-dashboard">';
            \TT\Modules\MatchAnalysis\Frontend\FrontendMatchAnalysisView::renderShared();
            echo '</div>';
            return (string) ob_get_clean();
        }

        // #1866 — branded password reset flow renders before the login
        // guard: a logged-out visitor resetting their password must reach
        // these screens. The secure key mechanics live in
        // PasswordResetHandler; these views own the chrome only.
        if ( $tt_view_param === 'lost-password' ) {
            return \TT\Modules\Auth\PasswordResetView::renderRequest();
        }
        if ( $tt_view_param === 'reset-password' ) {
            $rp_key   = isset( $_GET['key'] )   ? trim( (string) wp_unslash( $_GET['key'] ) )   : '';
            $rp_login = isset( $_GET['login'] ) ? trim( (string) wp_unslash( $_GET['login'] ) ) : '';
            $rp_error = isset( $_GET['rp_error'] ) ? sanitize_key( (string) $_GET['rp_error'] ) : '';
            $rp_msg   = '';
            if ( $rp_error === 'mismatch' ) {
                $rp_msg = __( 'The two passwords did not match. Please try again.', 'talenttrack' );
            } elseif ( $rp_error === 'weak' ) {
                $rp_msg = __( 'Please choose a password of at least 8 characters.', 'talenttrack' );
            } elseif ( $rp_error === 'empty' ) {
                $rp_msg = __( 'Please fill in both password fields.', 'talenttrack' );
            }
            return \TT\Modules\Auth\PasswordResetView::renderReset( $rp_key, $rp_login, $rp_msg );
        }

        // #2554 — the two MFA challenge screens render *before* the shell
        // opens, alongside the other pre-authentication flows above. The
        // session holds a cookie but has not cleared its second factor, so
        // painting the nav rail, global search, notification bell and
        // persona menu around a code field is both confusing and more
        // chrome than a half-authenticated request has earned.
        $mfa_screen = self::preAuthMfaScreen( $tt_view_param );
        if ( $mfa_screen !== '' ) {
            return $mfa_screen;
        }

        // Route guard — no partial render for logged-out users.
        if ( ! is_user_logged_in() ) {
            /** @var LoginForm $form */
            $form = Kernel::instance()->container()->get( 'auth.login_form' );
            $error = isset( $_GET['tt_login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_login_error'] ) ) : '';

            $reset_notice = '';
            if ( isset( $_GET['checkemail'] ) && $_GET['checkemail'] === 'confirm' ) {
                $reset_notice = '<div class="tt-notice-inline">'
                    . esc_html__( 'If that account exists, we\'ve sent a password reset link to its email.', 'talenttrack' )
                    . '</div>';
            } elseif ( isset( $_GET['password'] ) && $_GET['password'] === 'reset' ) {
                // #1866 — landed back here after setting a new password.
                $reset_notice = '<div class="tt-notice-inline">'
                    . esc_html__( 'Your password has been updated. You can sign in now.', 'talenttrack' )
                    . '</div>';
            }
            return $reset_notice . $form->render( $error );
        }

        // NOTE: v2.17.0 — the ?tt_print=<id> frontend short-circuit was
        // removed. The PrintRouter (hooked on template_redirect) now
        // intercepts print requests before the shortcode callback fires.

        // Authenticated dashboard.
        ob_start();
        // #2456 — the resolved shell stamps a root class so per-view CSS
        // can adapt without JS and without reading the config a second
        // time. Under `classic` the class is the only difference and
        // nothing else on this path changes.
        // #2512 — the resolved theme stamps a second root class on the same
        // wrapper. `default` returns an empty string, so that path's markup
        // is byte-identical to before the theme layer existed.
        $root_classes = trim( 'tt-dashboard ' . ShellPreference::rootClass() . ' ' . ThemePreference::rootClass() );
        echo '<div class="' . esc_attr( $root_classes ) . '">';
        self::renderHeader();

        // #2456 — open the shell frame. Under `classic` this emits
        // nothing at all, which is what keeps that value a complete
        // rollback: no wrapper, no nav, no DOM for a view to depend on.
        self::openShell();

        // #0019 Sprint 1 — flush any queued flash messages ahead of the
        // body so post-save redirects surface their result.
        FlashMessages::render();

        // #2631 — extension point for chrome that belongs above the body but
        // below the flash queue. Flash messages are about the action just
        // taken; anything hooking here is ambient state (the alerts banner is
        // the first consumer). Emits nothing when nobody is listening.
        do_action( 'tt_dashboard_before_body' );

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        $player   = QueryHelpers::get_player_for_user( $user_id );

        // v3.0.0 — tile-based routing. Each ?tt_view=<slug> maps to a
        // focused FrontendXyzView class. Me-group slugs are prefixed
        // with "my-" to disambiguate from the coaching slugs of the
        // same entity (evaluations / sessions / goals).
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';

        // v3.110.172 (#764 sustainable fix) — the per-group `$xxx_slugs`
        // allowlist arrays that used to live here have been removed. The
        // top-level router below now calls `self::tryDispatch()`, which
        // chains every `dispatchXxxView` method in order via `||`
        // short-circuit. Each dispatcher returns true if it recognised
        // and rendered the slug, false otherwise. The single source of
        // truth for which slugs route to which view is the switch case
        // list inside each `dispatchXxxView` method itself — there is
        // no separate list that can drift.
        //
        // Prior to this refactor, the file shipped the same class of
        // bug three times:
        //   - team-planner (v3.110.10) — slug missing from `$coaching_slugs`
        //   - onboarding-pipeline — slug missing from `$workflow_slugs`
        //   - tournaments (v3.110.171 / #764) — slug missing from `$coaching_slugs`
        // Each fix was a one-line allowlist addition. The fundamental
        // shape (two lists that must stay in sync) was the bug. Now
        // adding a `case '<slug>':` to any dispatcher makes the slug
        // routable on the next request — no separate edit, no recurrence.

        // #0084 Child 1 — desktop-only mobile gate. When the visitor is
        // a phone-class user agent, the requested view is classified
        // `desktop_only` per `MobileSurfaceRegistry`, the per-club setting
        // `force_mobile_for_user_agents` is on, and the user has not opted
        // out via `?force_mobile=1`, render the polite prompt page instead.
        // Tablets and desktops always pass through.
        if ( $view !== ''
             && ! \TT\Shared\MobileDetector::userForcedMobile()
             && \TT\Shared\MobileDetector::isPhone()
             && \TT\Shared\MobileSurfaceRegistry::isDesktopOnly( $view )
             && ( new \TT\Shared\Mobile\MobileSettings() )->isMobileGateEnabled()
        ) {
            FrontendMobilePromptView::render( $user_id, $view );
            self::closeShell();
            echo '</div>';
            $output = ob_get_clean() ?: '';
            return apply_filters( 'tt_dashboard_data', $output, $user_id );
        }

        if ( $view !== '' ) {
            // #0084 Child 2 — conditional mobile-patterns enqueue.
            // `native`-classed surfaces get the bottom-sheet / CTA-bar /
            // segmented-control / list-item CSS components plus the
            // bottom-sheet drag-dismiss helper. Other classes never load
            // them, keeping the desktop bundle slim.
            if ( \TT\Shared\MobileSurfaceRegistry::isNative( $view ) ) {
                wp_enqueue_style(
                    'tt-mobile-patterns',
                    TT_PLUGIN_URL . 'assets/css/mobile-patterns.css',
                    [ 'tt-public' ],
                    TT_VERSION
                );
                wp_enqueue_script(
                    'tt-mobile-helpers',
                    TT_PLUGIN_URL . 'assets/js/mobile-helpers.js',
                    [],
                    TT_VERSION,
                    true
                );
            }

            // #0056 — render the desktop_preferred banner at the top of
            // any dispatched view that carries the flag. CSS-gated
            // visibility means desktop / tablet users never see it; phone
            // users see it once until dismissed.
            FrontendDesktopPreferredBanner::render( $view );
        }

        // #0042 — install nudge for player + parent personas with no
        // active push subscription. Renders above every dispatched
        // view so the prompt is unmissable but not blocking.
        FrontendInstallBanner::render();

        // Usage instrumentation — capture the frontend surface the
        // logged-in user landed on (the dashboard root records as
        // `dashboard`). wp-admin views are tracked separately. One cheap
        // insert; events are pruned at 90 days and carry no IP / UA.
        \TT\Infrastructure\Usage\UsageTracker::record(
            $user_id,
            'frontend_view',
            $view !== '' ? $view : 'dashboard'
        );

        if ( $view === '' ) {
            // #0060 — when the persona-dashboard feature flag is on,
            // PersonaLandingRenderer takes over the empty-view landing
            // and falls back to FrontendTileGrid internally if no
            // persona resolves or the resolved template is empty.
            if ( class_exists( '\\TT\\Modules\\PersonaDashboard\\Frontend\\PersonaLandingRenderer' )
                 && \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::shouldRender() ) {
                \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::render( $user_id, self::shortcodeBaseUrl() );
            } else {
                FrontendTileGrid::render();
            }
        } elseif ( TileRegistry::isViewSlugDisabled( $view ) ) {
            // #0033 finalisation — slug is owned by a module that is
            // currently disabled. Surface a friendly notice rather
            // than dispatching to a view whose backing module didn't
            // boot. Owner is resolved from the TileRegistry entry whose
            // `view_slug` matches.
            self::renderModuleDisabledNotice();
        } elseif ( class_exists( '\\TT\\Core\\FeatureRegistry' )
                   && \TT\Core\FeatureRegistry::viewSlugDisabled( $view ) ) {
            // #1485 — slug is owned by a sub-feature that is currently
            // off (parent module still on). Same friendly notice as a
            // disabled module; the surface didn't register its data.
            self::renderModuleDisabledNotice();
        } elseif ( ! self::matrixDispatchAllows( $view, $user_id ) ) {
            // #0079 — when the view's tile declares a matrix entity AND
            // the matrix is active, MatrixGate is the sole authority for
            // dispatch. Generic notice — the previous per-class strings
            // ("only available for coaches and administrators", etc.)
            // collapse here because the matrix already encodes which
            // personas reach which surface, and the operator edits that
            // truth on the matrix admin page rather than memorising
            // which view class belongs to which class of users.
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this surface.', 'talenttrack' ) . '</p>';
        } else {
            // #2387 — an informational "Under development" pill above the
            // view when its owning feature is flagged on the module page.
            // Cosmetic decoration (§5: not a nav affordance); renders
            // nothing for views no flagged feature owns.
            \TT\Shared\Frontend\Components\DevelopmentPill::render( $view );

            if ( ! self::tryDispatch( $view, $user_id, $is_admin, $player ) ) {
                // v3.110.172 — every bool-returning dispatcher passed on the
                // slug. No surface owns it; render the branded 404 (#2035). The
                // breadcrumb chain is the back affordance here (we are already
                // inside the dashboard shell), so the inner content omits its own
                // "Back to dashboard" button — §5 two-affordance contract.
                FrontendBreadcrumbs::fromDashboard( __( 'Page not found', 'talenttrack' ) );
                echo '<div class="tt-404">' . \TT\Shared\Frontend\Components\Tt404Page::innerHtml( false ) . '</div>';
            }
        }

        self::closeShell();
        echo '</div>';

        /** @var string $output */
        $output = ob_get_clean() ?: '';
        /** @var string $filtered */
        $filtered = apply_filters( 'tt_dashboard_data', $output, $user_id );
        return $filtered;
    }

    /**
     * #2456 — open the app-shell frame: the grid wrapper, the one
     * primary navigation (CLAUDE.md §5b), the drawer scrim, and the
     * main column every dispatched view renders into.
     *
     * Emits nothing under the `classic` shell. That is deliberate: with
     * no wrapper in the DOM there is nothing a view could come to depend
     * on, so flipping the setting back is a complete rollback rather
     * than a visual approximation of one.
     */
    private static function openShell(): void {
        if ( ! ShellPreference::isApp() ) {
            return;
        }

        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';

        echo '<div class="tt-shell" data-tt-shell>';
        \TT\Shared\Frontend\Components\FrontendAppNav::render( get_current_user_id(), $view );
        \TT\Shared\Frontend\Components\FrontendAppNav::renderDrawerScrim();
        echo '<div class="tt-shell-main">';
    }

    /**
     * Counterpart to {@see openShell()}. No-op under `classic`.
     *
     * #2459 — the thumb-zone bar closes the main column rather than
     * sitting inside it, so its fixed positioning is not affected by the
     * column's own stacking context.
     */
    private static function closeShell(): void {
        if ( ! ShellPreference::isApp() ) {
            return;
        }
        echo '</div>'; // .tt-shell-main

        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        \TT\Shared\Frontend\Components\FrontendAppBottomBar::render(
            get_current_user_id(),
            $view,
            self::shortcodeBaseUrl()
        );

        echo '</div>'; // .tt-shell
    }

    /**
     * #0079 — single matrix-driven gate consulted before every
     * dispatch. Replaces the per-class `$is_coach || $is_admin`,
     * `current_user_can('tt_view_reports')`, and friend gates.
     *
     * Returns true when:
     *  - The view slug has no tile-declared entity (slug-only routes,
     *    sub-views the dispatcher reaches without a tile of their own).
     *  - The matrix is dormant (`tt_authorization_active = 0`). On
     *    matrix-dormant installs the per-view cap re-checks inside
     *    each FrontendXyzView::render are the gate. #0071 documented
     *    that matrix-dormant installs are out of scope for this work.
     *  - The user is a WordPress administrator (mirrors the bypass
     *    every other matrix consumer applies).
     *  - `MatrixGate::canAnyScope($user_id, $entity, 'read')` returns
     *    true.
     */
    private static function matrixDispatchAllows( string $view, int $user_id ): bool {
        if ( $view === '' ) return true;
        if ( ! class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' ) ) return true;
        $entity = \TT\Shared\Tiles\TileRegistry::entityForViewSlug( $view );
        if ( $entity === null ) return true;
        if ( ! self::matrixActive() ) return true;
        if ( $user_id <= 0 ) return false;
        $user = get_user_by( 'id', $user_id );
        if ( $user instanceof \WP_User && in_array( 'administrator', (array) $user->roles, true ) ) return true;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) return true;
        return \TT\Modules\Authorization\MatrixGate::canAnyScope( $user_id, $entity, 'read' );
    }

    private static ?bool $matrix_active_cache = null;

    private static function matrixActive(): bool {
        if ( self::$matrix_active_cache !== null ) return self::$matrix_active_cache;
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) {
            self::$matrix_active_cache = false;
            return false;
        }
        $cfg = new \TT\Infrastructure\Config\ConfigService();
        self::$matrix_active_cache = (bool) $cfg->getBool( 'tt_authorization_active', false );
        return self::$matrix_active_cache;
    }

    /**
     * v3.0.0 — dispatch a Me-group tile slug to its FrontendXyzView
     * class. v3.110.172 — `$player` precondition moved inside; if the
     * slug is recognised but the user has no linked player, render the
     * "needs player record" notice and claim the slug.
     */
    private static function dispatchMeView( string $view, ?object $player ): bool {
        $user_id = get_current_user_id();

        // #1991 — multi-child parent gate. A parent with no own player row
        // and >1 linked child who opens a Me-view with no explicit
        // ?player_id must CHOOSE which child to view — we never silently
        // pick one. `teammate` is exempt (it reads player_id as the
        // teammate id, not the subject). The picker is only for the slugs
        // that resolve a subject from the parent's children.
        if ( $view !== 'teammate' && self::needsChildPicker( $user_id, $player ) ) {
            $children = \TT\Infrastructure\Players\ParentChildResolver::children( $user_id );
            \TT\Shared\Frontend\Components\ParentChildSwitcher::renderPickerPage( $view, $children );
            return true;
        }

        // #1849 — a parent (or any authorised viewer) opens a CHILD's My-X
        // section via ?player_id=N, scoped by canViewPlayer (own children
        // only). `$target` is that child when supplied + authorised, else the
        // viewer's own player record. #1991 — when the viewer has no own
        // player row, `$target` resolves to the parent's (single / most-
        // recent) linked child via tt_player_parents. `teammate` keeps
        // `$player` (the viewer's own) because it reads player_id as the
        // teammate id, not the subject.
        $target = self::resolveMePlayer( $user_id, $player );

        // #1867 — a player can hide development sections from a linked
        // parent. The gate only ever restricts a parent (self + staff
        // pass through); when a section is hidden, show the dignified
        // "kept private" state instead of the section.
        if ( $target !== null ) {
            $section = self::meViewSection( $view );
            if ( $section !== ''
                && ! \TT\Infrastructure\Security\AuthorizationService::parentCanViewSection( get_current_user_id(), (int) $target->id, $section ) ) {
                \TT\Shared\Frontend\Components\FrontendPrivateSection::render( self::meViewSectionLabel( $view ) );
                return true;
            }
        }

        switch ( $view ) {
            case 'my-development':
                // #1850 — the player + parent development home. Same scoped
                // subject resolution as the other Me-views: a parent reaches
                // their child's home via ?player_id=N (canViewPlayer gate).
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                FrontendMyDevelopmentView::render( $target );
                return true;
            case 'overview':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                // #2107 — "My card" now lands on the one unified, permission-
                // aware profile (FrontendPlayerDetailView), defaulting to the
                // Player-card tab (#1988/#2067). A player reaches their own
                // record; a parent reaches their child via the ?player_id
                // resolution above — both gated by canViewPlayer. Staff hit the
                // same view from the Players list.
                FrontendPlayerDetailView::render( (int) $target->id, $user_id, current_user_can( 'tt_edit_settings' ), 'card' );
                return true;
            case 'my-team':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                FrontendMyTeamView::render( $target );
                return true;
            case 'teammate':
                if ( ! self::requirePlayerOrDeny( $player ) ) return true;
                $teammate_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
                FrontendTeammateView::render( $player, $teammate_id );
                return true;
            case 'my-evaluations':
                // v3.110.215 (#846) — branch by caller context. A coach
                // (anyone with `tt_edit_evaluations` who has no linked
                // player record) sees the evaluations THEY authored; a
                // player (or a parent viewing their child) sees evaluations
                // OF that player.
                if ( $target === null && current_user_can( 'tt_edit_evaluations' ) ) {
                    FrontendMyEvaluationsView::renderForCoach( get_current_user_id() );
                    return true;
                }
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                FrontendMyEvaluationsView::render( $target );
                return true;
            case 'my-activities':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                FrontendMyActivitiesView::render( $target );
                return true;
            case 'my-goals':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                FrontendMyGoalsView::render( $target );
                return true;
            case 'my-pdp':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                \TT\Modules\Pdp\Frontend\FrontendMyPdpView::render( $target );
                return true;
            case 'profile':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                // Legacy slug — folded into My card in v3.62.0, and the card
                // now lives on the unified profile (#2107). Keep the bookmark
                // alive by landing it on the same unified profile.
                FrontendPlayerDetailView::render( (int) $target->id, $user_id, current_user_can( 'tt_edit_settings' ), 'card' );
                return true;
            case 'my-journey':
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                \TT\Modules\Journey\Frontend\FrontendJourneyView::render( $target );
                return true;
            case 'measurements':
                // #1856 — a player's tests & measurements. `$target` is the
                // viewer's own player, or a child / team player supplied via
                // ?player_id=N and gated by canViewPlayer in resolveMePlayer.
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                \TT\Modules\Measurements\Frontend\FrontendMeasurementsView::render( $target );
                return true;
            case 'strava':
                // #2061 (epic #2002) — a player's Strava connection panel.
                // Same scoped subject as the other Me-views: own player, or a
                // child / viewable player via ?player_id=N (canViewPlayer).
                if ( ! self::requirePlayerOrDeny( $target ) ) return true;
                \TT\Modules\Strava\Frontend\FrontendStravaView::render( $target );
                return true;
            // NOTE: `my-settings` intentionally NOT in this dispatcher —
            // dispatchAccountView claims it so coach / scout / admin
            // personas without a linked player can still manage their
            // account. The bool-router calls dispatchMeView FIRST, so we
            // mustn't claim the slug here or non-player personas would
            // get the "needs player record" wall they used to suffer
            // pre-v3.92.0.
            default:
                return false;
        }
    }

    /**
     * #1849 — resolve the Me-group subject. When `?player_id=N` is present
     * and the viewer is authorised to view that player (a parent of their
     * own child, via the canViewPlayer scope), return that child's record;
     * otherwise the viewer's own player record. This is what lets a parent
     * open `?tt_view=my-pdp&player_id=<child>` and reach the same rich
     * `FrontendMy*` views the player sees (the views already detect
     * is_self / is_parent). Scope is the #1725 gate — own children only.
     */
    /**
     * #1867 — map a Me-view slug to the visibility section it belongs to
     * (empty string = not a gateable section). Card / team / settings /
     * the development home are always visible.
     */
    private static function meViewSection( string $view ): string {
        switch ( $view ) {
            case 'my-evaluations': return 'evaluations';
            case 'my-goals':       return 'goals';
            case 'my-journey':     return 'journey';
            case 'my-pdp':         return 'pdp';
            case 'measurements':   return 'measurements';
            default:               return '';
        }
    }

    /** Section label for the "kept private" breadcrumb. */
    private static function meViewSectionLabel( string $view ): string {
        switch ( $view ) {
            case 'my-evaluations': return __( 'Evaluations', 'talenttrack' );
            case 'my-goals':       return __( 'Goals', 'talenttrack' );
            case 'my-journey':     return __( 'Journey', 'talenttrack' );
            case 'my-pdp':         return __( 'Development plan', 'talenttrack' );
            case 'measurements':   return __( 'Measurements', 'talenttrack' );
            default:               return __( 'Section', 'talenttrack' );
        }
    }

    private static function resolveMePlayer( int $user_id, ?object $own ): ?object {
        $pid = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        if ( $pid > 0
            && \TT\Infrastructure\Security\AuthorizationService::canViewPlayer( $user_id, $pid ) ) {
            $child = QueryHelpers::get_player( $pid );
            if ( $child ) return $child;
        }
        if ( $own ) return $own;

        // #1991 — no explicit ?player_id and no own player record: resolve
        // the parent's linked child from the canonical tt_player_parents
        // pivot. Single child → that child; multi-child → the most-recent
        // (the multi-child picker in dispatchMeView fires first, so we only
        // reach here for the single-child case or when a child was already
        // chosen). canViewPlayer below is the authority — this only picks
        // the default subject.
        return \TT\Infrastructure\Players\ParentChildResolver::defaultChild( $user_id );
    }

    /**
     * #1991 — true when the viewer is a parent (no own player row) linked
     * to more than one child AND has not yet chosen one via ?player_id.
     * Those users get the child picker instead of a silently-picked child.
     */
    private static function needsChildPicker( int $user_id, ?object $own ): bool {
        if ( $own ) return false;
        $explicit = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        if ( $explicit > 0 ) return false;
        return \TT\Infrastructure\Players\ParentChildResolver::childCount( $user_id ) > 1;
    }

    /**
     * v3.110.172 — shared precondition helper for Me-group dispatch.
     * Returns true when a player is linked and the current user is
     * authorised to view it; rendering can then proceed.
     *
     * #1991 — the gate now authorizes the RESOLVED TARGET via
     * `AuthorizationService::canViewPlayer`, not merely "is the viewer a
     * player". A parent viewing their own child passes (parent scope on
     * the pivot); a stranger supplying someone else's player_id is denied
     * even though a record loaded. Self + staff are unaffected (own-record
     * / team / global scopes all satisfy canViewPlayer).
     *
     * Returns false after emitting the breadcrumb + notice — the caller
     * still returns true so the dispatcher claims the slug.
     */
    private static function requirePlayerOrDeny( ?object $player ): bool {
        if ( $player
            && \TT\Infrastructure\Security\AuthorizationService::canViewPlayer( get_current_user_id(), (int) $player->id ) ) {
            return true;
        }
        FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
        echo '<p class="tt-notice">' . esc_html__( 'This section is only available for users linked to a player record.', 'talenttrack' ) . '</p>';
        return false;
    }

    /**
     * v3.92.0 — dispatch an account-level slug. Account-level surfaces
     * (display name, password) are available to every logged-in user,
     * regardless of whether they have a linked player record.
     * Previously routed through the Me-group dispatcher, which gated on
     * `$player` and returned "only available for users linked to a
     * player record" for coach / scout / admin users.
     */
    private static function dispatchAccountView( string $view, int $user_id ): bool {
        // v3.110.172 — the user_id pre-check stays, but moves AFTER the
        // slug-recognition switch so a not-our-slug returns false and
        // the next dispatcher gets a chance. Previously this lived above
        // the switch and short-circuited regardless of view.
        switch ( $view ) {
            case 'my-settings':
                if ( $user_id <= 0 ) return self::renderSignInRequired();
                FrontendMySettingsView::render();
                return true;
            // #2632 — alert preferences are their own screen, separate from
            // the message opt-outs on my-settings (epic #2629 decision 11).
            // Each links to the other; this one needs no capability beyond
            // being logged in, since it only changes what the user sees.
            case \TT\Modules\Alerts\Frontend\FrontendAlertSettingsView::SLUG:
                if ( $user_id <= 0 ) return self::renderSignInRequired();
                \TT\Modules\Alerts\Frontend\FrontendAlertSettingsView::render();
                return true;
            case \TT\Modules\Alerts\Frontend\FrontendAlertPolicyView::SLUG:
                if ( $user_id <= 0 ) return self::renderSignInRequired();
                \TT\Modules\Alerts\Frontend\FrontendAlertPolicyView::render();
                return true;
            case 'my-sessions':
                if ( $user_id <= 0 ) return self::renderSignInRequired();
                // #0086 Workstream B Child 2 — every logged-in user
                // can manage their own active sessions; no separate
                // capability beyond `read`, which the matrix-dispatch
                // gate above already enforces.
                FrontendMySessionsView::render();
                return true;
            // #2633 (epic #2629) — the alerts inbox. Account-group rather
            // than a group of its own: an occurrence is written per
            // recipient, so this list is scoped to "me" in SQL exactly like
            // my-sessions is. No capability beyond being signed in — the
            // capability question was already answered when the evaluator
            // decided whether to write the row.
            //
            // Not routed through `renderSignInRequired()`: that helper's copy
            // is about managing settings, and the view already emits the
            // breadcrumb chain on its signed-out path (§5 — every path,
            // including the refusals).
            case 'alerts':
                \TT\Modules\Alerts\Frontend\FrontendAlertsInboxView::render( $user_id );
                return true;
            default:
                return false;
        }
    }

    /**
     * v3.110.172 — shared helper for dispatchers that need a logged-in
     * user (Account). Renders the breadcrumb + notice and returns true
     * so the dispatcher claims the slug.
     */
    private static function renderSignInRequired(): bool {
        FrontendBreadcrumbs::fromDashboard( __( 'Sign in required', 'talenttrack' ) );
        echo '<p class="tt-notice">' . esc_html__( 'You need to be logged in to manage your settings.', 'talenttrack' ) . '</p>';
        return true;
    }

    /**
     * #0022 Sprint 2 — dispatch a workflow-group tile slug. Currently
     * one slug (`my-tasks`); the inbox doubles as the task-detail
     * surface when `?task_id=N` is present.
     */
    private static function dispatchWorkflowView( string $view, int $user_id, bool $is_admin = false ): bool {
        switch ( $view ) {
            case 'my-tasks':
                if ( ! current_user_can( 'tt_view_own_tasks' ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to tasks.', 'talenttrack' ) . '</p>';
                    return true;
                }
                $task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;
                if ( $task_id > 0 ) {
                    \TT\Modules\Workflow\Frontend\FrontendTaskDetailView::render( $user_id, $task_id );
                } else {
                    if ( ! empty( $_GET['tt_workflow_done'] ) ) {
                        echo '<div class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                            . esc_html__( 'Task completed.', 'talenttrack' ) . '</div>';
                    }
                    \TT\Modules\Workflow\Frontend\FrontendMyTasksView::render( $user_id );
                }
                return true;
            case 'tasks-dashboard':
                \TT\Modules\Workflow\Frontend\FrontendTasksDashboardView::render( $user_id );
                return true;
            case 'workflow-config':
                \TT\Modules\Workflow\Frontend\FrontendWorkflowConfigView::render( $user_id );
                return true;
            // #0081 child 3 — standalone pipeline view.
            case 'onboarding-pipeline':
                \TT\Modules\Prospects\Frontend\FrontendOnboardingPipelineView::render( $user_id );
                return true;
            // v3.110.99 — rich prospects list (FrontendListTable).
            case 'prospects-overview':
                \TT\Modules\Prospects\Frontend\FrontendProspectsOverviewView::render( $user_id );
                return true;
            // v3.110.119 — scout's scouting-visit planner.
            case 'scouting-visits':
                \TT\Modules\Prospects\Frontend\FrontendScoutingPlanView::render( $user_id, $is_admin );
                return true;
            case 'scouting-visit':
                \TT\Modules\Prospects\Frontend\FrontendScoutingVisitDetailView::render( $user_id, $is_admin );
                return true;
            // #0095 VCT-10 (#948) — coach mobile session view + A4
            // print sub-render (via ?print=a4 on the same slug).
            case 'vct-session':
                \TT\Modules\Vct\Frontend\FrontendVctSessionView::render( $user_id, $is_admin );
                return true;
            // #0095 VCT-11 (#950) — HoD exercise library editor.
            case 'vct-library':
                \TT\Modules\Vct\Frontend\FrontendVctLibraryView::render( $user_id, $is_admin );
                return true;
            // #0095 VCT-12 (#952) — HoD configuration tile
            // (macro-blocks + age-profiles + team-schedules tabs).
            case 'vct-config':
                \TT\Modules\Vct\Frontend\FrontendVctConfigView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * v3.0.0 slice 4 — dispatch a coaching-group tile slug.
     * Only called when the user has coach or admin caps.
     */
    private static function dispatchCoachingView( string $view, int $user_id, bool $is_admin ): bool {
        // #0063 — when an `?id=N` is on the URL, the three master-record
        // list slugs (players / teams / people) delegate to the matching
        // detail view. Mirrors the v3.62 precedent in FrontendMyGoalsView /
        // FrontendMyActivitiesView: same slug, same parameter shape, no
        // new dedicated detail slugs.
        $detail_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        // v3.91.2 — `?id=N` was hard-routing to the dedicated detail view
        // even when `action=edit` was set, so the row-action Edit URL
        // (`?tt_view=teams&id=N&action=edit`) silently landed on the
        // detail page. Honour `action` here: when it's `edit`, fall
        // through to the manage view, which has the existing
        // `if ( $action === 'edit' )` dispatch that renders the form.
        // Same fix applies to `players` and `people`.
        $action = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

        switch ( $view ) {
            case 'teams':
                if ( $detail_id > 0 && $action !== 'edit' ) {
                    FrontendTeamDetailView::render( $detail_id, $user_id, $is_admin );
                    return true;
                }
                FrontendTeamsManageView::render( $user_id, $is_admin );
                return true;
            case 'players':
                if ( $detail_id > 0 && $action !== 'edit' ) {
                    FrontendPlayerDetailView::render( $detail_id, $user_id, $is_admin );
                    return true;
                }
                FrontendPlayersManageView::render( $user_id, $is_admin );
                return true;
            case 'players-import':
                FrontendPlayersCsvImportView::render( $user_id, $is_admin );
                return true;
            // #1381 — season rollover: bulk cohort promotion with dated
            // journey transitions. Cap-gated on tt_manage_players inside.
            case 'season-rollover':
                \TT\Modules\Players\SeasonRollover\FrontendSeasonRolloverView::render( $user_id, $is_admin );
                return true;
            // #1771 — admin link/unlink of a WP account to a player.
            case 'player-accounts':
                \TT\Modules\Players\Frontend\FrontendPlayerAccountsView::render( $user_id, $is_admin );
                return true;
            // #1815 — admin link/unlink of parent WP accounts on players.
            case 'parent-accounts':
                \TT\Modules\Players\Frontend\FrontendParentAccountsView::render( $user_id, $is_admin );
                return true;
            // #1815 — Accounts & access hub (Player / Parent accounts, Invitations).
            case 'accounts':
                \TT\Modules\Players\Frontend\FrontendAccountsHubView::render( $user_id, $is_admin );
                return true;
            case 'people':
                if ( $detail_id > 0 && $action !== 'edit' ) {
                    FrontendPersonDetailView::render( $detail_id, $user_id, $is_admin );
                    return true;
                }
                FrontendPeopleManageView::render( $user_id, $is_admin );
                return true;
            case 'mail-compose':
                FrontendMailComposeView::render( $user_id, $is_admin );
                return true;
            case 'player-status-capture':
                FrontendPlayerStatusCaptureView::render( $user_id, $is_admin );
                return true;
            // #872 — bulk behaviour-rating grid for a team. Reachable
            // via the "Bulk-record behaviour" button on the team detail
            // page. Cap-gated inside the view.
            case 'team-behaviour-capture':
                FrontendTeamBehaviourCaptureView::render( $user_id, $is_admin );
                return true;
            // #1017 Phase 7 — populate a player's chemistry attributes.
            // Gated (canEvaluatePlayer) inside the view.
            case 'player-attributes':
                \TT\Modules\TeamDevelopment\Frontend\FrontendPlayerAttributesView::render( $user_id, $is_admin );
                return true;
            case 'functional-roles':
                FrontendFunctionalRolesView::render( $user_id, $is_admin );
                return true;
            case 'evaluations':
                FrontendEvaluationsView::render( $user_id, $is_admin );
                return true;
            // #1856 — bulk measurement result entry for a team. Matrix-
            // gated on `measurements` change inside the view.
            case 'measurements-entry':
                \TT\Modules\Measurements\Frontend\FrontendMeasurementEntryView::render( $user_id, $is_admin );
                return true;
            case 'measurements-coverage':
                \TT\Modules\Measurements\Frontend\FrontendMeasurementCoverageView::render( $user_id, $is_admin );
                return true;
            // #2609 — squad-wide "who is out right now". Matrix-gated on
            // `player_injuries` read inside the view.
            case 'injuries':
                \TT\Modules\Journey\Frontend\FrontendInjuriesView::render( $user_id, $is_admin );
                return true;
            // #2145 — the "Test results" analysis browser. Matrix-gated on
            // `measurements` read inside the view (and by matrixDispatchAllows
            // via the tile's `measurements` entity).
            case 'test-results':
                \TT\Modules\Measurements\Frontend\FrontendTestResultsView::render( $user_id, $is_admin );
                return true;
            // #2537 — the longitudinal companion: one test, every player,
            // over the window. Same `measurements` read gate as above, plus
            // its own report toggle enforced inside the view.
            case 'test-trends':
                \TT\Modules\Measurements\Frontend\FrontendTestTrendsView::render( $user_id, $is_admin );
                return true;
            // #2121 — the test-catalogue config surface. Matrix-gated on
            // `measurement_definitions` change inside the view.
            case 'measurement-tests':
                \TT\Modules\Measurements\Frontend\FrontendMeasurementTestsView::render( $user_id, $is_admin );
                return true;
            case 'activities':
                FrontendActivitiesManageView::render( $user_id, $is_admin );
                return true;
            // #2382 (epic #2381) — desktop attendance-entry grid (players ×
            // activities). Self-gates on tt_edit_activities + the
            // attendance_grid feature + team scope inside the view.
            case 'attendance-grid':
                \TT\Modules\Activities\Frontend\FrontendAttendanceGridView::render( $user_id, $is_admin );
                return true;
            // #2386 (epic #2381) — desktop minutes-entry grid (players ×
            // matches). Self-gates on tt_edit_activities + the minutes_grid
            // feature + team scope inside the view.
            case 'minutes-grid':
                \TT\Modules\Activities\Frontend\FrontendMinutesGridView::render( $user_id, $is_admin );
                return true;
            // #2414 (epic #2381) — desktop ratings-entry grid (players ×
            // evaluation categories, one activity). Self-gates on
            // tt_edit_activities + the ratings_grid feature + team scope.
            case 'ratings-grid':
                \TT\Modules\Activities\Frontend\FrontendRatingsGridView::render( $user_id, $is_admin );
                return true;
            case 'goals':
                FrontendGoalsManageView::render( $user_id, $is_admin );
                return true;
            // #0093 — Tournament planner. Admin-only in v1: the view
            // itself gates on tt_view_tournaments (mapped to admin +
            // tt_club_admin only) and surfaces a "not authorized"
            // notice for everyone else.
            case 'tournaments':
                FrontendTournamentsManageView::render( $user_id, $is_admin );
                return true;
            // v4.8.0 (#975) — post-creation Add-match surface. Reuses
            // the wizard's matches-step chip editor; submits via
            // admin-post.php and redirects back to the planner.
            case 'tournament-match':
                \TT\Modules\Tournaments\Frontend\FrontendTournamentMatchAddView::render( $user_id, $is_admin );
                return true;
            case 'pdp':
                \TT\Modules\Pdp\Frontend\FrontendPdpManageView::render( $user_id, $is_admin );
                return true;
            case 'pdp-planning':
                \TT\Modules\Pdp\Frontend\FrontendPdpPlanningView::render( $user_id, $is_admin );
                return true;
            case 'player-status-methodology':
                \TT\Modules\Players\Frontend\FrontendPlayerStatusMethodologyView::render( $user_id, $is_admin );
                return true;
            case 'team-chemistry':
                // #1922 — read gate via the team_chemistry matrix (single
                // source of truth), not the raw tt_view_team_chemistry cap.
                if ( ! \TT\Modules\TeamDevelopment\TeamChemistryAccess::canRead( $user_id ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to team chemistry boards.', 'talenttrack' ) . '</p>';
                    return true;
                }
                \TT\Modules\TeamDevelopment\Frontend\FrontendTeamChemistryView::render( $user_id, $is_admin );
                return true;
            case 'team-blueprints':
                // #1922 — read gate via the team_chemistry matrix.
                if ( ! \TT\Modules\TeamDevelopment\TeamChemistryAccess::canRead( $user_id ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to team blueprints.', 'talenttrack' ) . '</p>';
                    return true;
                }
                \TT\Modules\TeamDevelopment\Frontend\FrontendTeamBlueprintsView::render( $user_id, $is_admin );
                return true;
            // v3.110.214 (#838) — head coach match preparation surface.
            case 'match-prep':
                \TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView::render( $user_id, $is_admin );
                return true;
            // v3.110.216 (#847) — assistant coach live-match surface.
            case 'match-execution':
                \TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionView::render( $user_id, $is_admin );
                return true;
            // #2704 — the post-match analysis surface. Reachable for any
            // match activity, prepped or not; the view itself refuses
            // non-match types and matches that have not been played.
            case 'match-analysis':
                \TT\Modules\MatchAnalysis\Frontend\FrontendMatchAnalysisView::render( $user_id, $is_admin );
                return true;
            // #1047 — listing surface for past match executions.
            case 'match-executions':
                \TT\Modules\MatchExecution\Frontend\FrontendMatchExecutionsListView::render( $user_id, $is_admin );
                return true;
            case 'team-blueprint-share':
                // #0068 Phase 4 — public read-only share-link. Token
                // verification happens inside renderShared(); no cap
                // check at the dispatch layer (the URL is the auth).
                \TT\Modules\TeamDevelopment\Frontend\FrontendTeamBlueprintsView::renderShared();
                return true;
            // #0006 — team-planning calendar. Performance-group surface; the
            // view re-checks its own caps so dispatching here is safe even
            // for users who shouldn't see it.
            case 'team-planner':
                \TT\Modules\Planning\Frontend\FrontendTeamPlannerView::render( $user_id );
                return true;
            case 'podium':
                FrontendPodiumView::render( $user_id, $is_admin );
                return true;
            case 'methodology':
                \TT\Modules\Methodology\Frontend\MethodologyView::render();
                return true;
            case 'player-journey':
                $journey_player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
                $journey_player = $journey_player_id > 0 ? QueryHelpers::get_player( $journey_player_id ) : null;
                if ( ! $journey_player ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Player not found', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
                } else {
                    \TT\Modules\Journey\Frontend\FrontendJourneyView::render( $journey_player );
                }
                return true;
            default:
                return false;
        }
    }

    /**
     * v3.0.0 slice 5 — dispatch an analytics-group tile slug.
     * Gate is tt_view_reports (the default Analytics separator cap),
     * which the Read-Only Observer role has. This is the observer's
     * primary frontend entry point.
     */
    private static function dispatchAnalyticsView( string $view ): bool {
        switch ( $view ) {
            // #0063 — `?tt_view=reports` lands on the frontend reports
            // launcher, which mirrors the wp-admin tile launcher. The
            // three sub-reports themselves still render in wp-admin
            // (they use admin form-submit URLs and Chart.js), so each
            // tile opens that view in a new tab.
            case 'reports':
                FrontendReportsLauncherView::render( get_current_user_id(), current_user_can( 'tt_edit_settings' ) );
                return true;
            // #1063 standard reports (#1090-#1095). Slug-dispatched
            // curated views per the design-of-record in
            // `.local-mockups/standard-reports/`.
            case 'standard-report':
                FrontendStandardReportsView::render( get_current_user_id(), current_user_can( 'tt_edit_settings' ) );
                return true;
            // v3.110.189 (#797) — central Exports surface.
            case 'exports':
                FrontendExportsView::render( get_current_user_id(), current_user_can( 'tt_edit_settings' ) );
                return true;
            case 'rate-cards':
                FrontendRateCardView::render();
                return true;
            case 'compare':
                FrontendComparisonView::render();
                return true;
            default:
                return false;
        }
    }

    /**
     * #0019 Sprint 5 — dispatch an admin-tier slug. Cap re-check
     * happens inside each view so the friendly back-button + notice
     * pattern is consistent.
     */
    private static function dispatchAdminView( string $view, int $user_id, bool $is_admin ): bool {
        // #2559 — `team-spond` is the one per-record slug in this group: it
        // needs the `?id=N` team id. Read it here, mirroring
        // dispatchCoachingView, so the case doesn't reach for a variable
        // that only exists in that other method's scope.
        $detail_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

        switch ( $view ) {
            case 'configuration':
                FrontendConfigurationView::render( $user_id, $is_admin );
                return true;
            // #1017 Phase 5 — chemistry engine settings (component weights +
            // position matrix + v2 toggle). Gated inside the view.
            case 'chemistry-config':
                \TT\Modules\TeamDevelopment\Frontend\FrontendChemistryConfigView::render( $user_id, $is_admin );
                return true;
            case 'holidays':
                // #1480 — academy-wide holiday management.
                \TT\Shared\Frontend\FrontendHolidaysView::render( $user_id, $is_admin );
                return true;
            // #2496 (epic #2493) — training plans. Both slugs land on one
            // view: the list, and the read-only detail when ?id= is set.
            // `training-plan` (singular) is the record URL the list rows
            // and later surfaces link to.
            case 'training-plans':
            case 'training-plan':
                \TT\Modules\Training\Frontend\FrontendTrainingPlansView::render( $user_id, $is_admin );
                return true;
            // #2499 — running a plan. `&activity_id=` attaches a plan to a
            // training; `&id=` is the sideline view of that run. One route
            // because it is one errand, and whether a run row already
            // exists is bookkeeping the coach should not have to know.
            case 'training-run':
                \TT\Modules\Training\Frontend\FrontendTrainingRunView::render( $user_id, $is_admin );
                return true;
            // #2500 — the head-of-development coverage matrix: principle
            // by team, and the players whose own goals sit on a principle
            // their team barely trains. Gated inside the view on
            // `training_exposure` at GLOBAL scope, so a coach (who holds
            // it at team scope) is refused here and reads the same
            // question per player instead.
            case 'training-coverage':
                \TT\Modules\Training\Frontend\FrontendTrainingCoverageView::render( $user_id, $is_admin );
                return true;
            // #2495 — the one exercise library (club drills + the VCT
            // catalogue merged in by migration 0212). `?id=` opens a
            // single exercise.
            case 'exercises':
                \TT\Modules\Exercises\Frontend\FrontendExerciseLibraryView::render( $user_id, $is_admin );
                return true;
            case 'custom-fields':
                FrontendCustomFieldsView::render( $user_id, $is_admin );
                return true;
            case 'eval-categories':
                FrontendEvalCategoriesView::render( $user_id, $is_admin );
                return true;
            case 'roles':
                FrontendRolesView::render( $user_id, $is_admin );
                return true;
            case 'migrations':
                FrontendMigrationsView::render( $user_id, $is_admin );
                return true;
            case 'modules':
                // #1451 — frontend equivalent of the wp-admin Modules toggle.
                FrontendModulesView::render( $user_id, $is_admin );
                return true;
            case 'features':
                // #1486 — read-only "what's switched on" status, all personas.
                \TT\Shared\Frontend\FrontendFeaturesView::render( $user_id, $is_admin );
                return true;
            case 'seasons':
                // #1481 — frontend Seasons manager (create/edit/set-current/delete).
                \TT\Shared\Frontend\FrontendSeasonsView::render( $user_id, $is_admin );
                return true;
            case 'usage-stats':
                FrontendUsageStatsView::render( $user_id, $is_admin );
                return true;
            case 'usage-stats-details':
                FrontendUsageStatsDetailsView::render( $user_id, $is_admin );
                return true;
            case 'audit-log':
                FrontendAuditLogView::render( $user_id, $is_admin );
                return true;
            case 'translations':
                // #1935 — frontend port of the wp-admin Configuration →
                // Translations tab (auto-translation engine config + usage
                // + clear-cache). Gates on tt_view_translations internally.
                FrontendTranslationsView::render( $user_id, $is_admin );
                return true;
            case 'spond':
                // #1936 — frontend port of the wp-admin Spond integration
                // page (per-team sync status + "Refresh now" + encrypted
                // credential save/test/clear + API base-URL override).
                // Gates on tt_edit_teams internally; credential mutations
                // gate on tt_edit_spond_credentials at the REST layer.
                FrontendSpondView::render( $user_id, $is_admin );
                return true;
            case 'spond-monitor':
                // #2284 — dry-run Spond integration monitor. Fetches Spond
                // live and previews how each event would map onto
                // activities WITHOUT writing anything, to diagnose why a
                // printed activity differs from Spond. Gates on
                // tt_edit_teams internally; the preview endpoint re-checks
                // the same cap at the REST layer.
                FrontendSpondMonitorView::render( $user_id, $is_admin );
                return true;
            case 'team-spond':
                // #2388 — head-coach-facing per-team Spond connection panel,
                // linked from the team detail page. Gates on TeamSpondAccess
                // (spond_integration.change for this exact team) internally,
                // and the per-team credential/test/sync endpoints re-check
                // the same authority at the REST layer.
                FrontendMyTeamSpondView::render( $user_id, $detail_id );
                return true;
            case 'strava-admin':
                // #2127 (epic #2002) — Strava operator console: app
                // credentials + webhook subscription + connected-players
                // roster. Gates on tt_view_strava internally; credential +
                // webhook mutations gate on tt_edit_strava_credentials at
                // the REST layer (matrix-gated, not manage_options).
                \TT\Modules\Strava\Frontend\FrontendStravaAdminView::render( $user_id, $is_admin );
                return true;
            case 'setup':
                // #1938 — frontend port of the wp-admin first-run onboarding
                // wizard (?page=tt-welcome). Multi-step academy → first team
                // → first admin → dashboard → done, plus "Run again". Gates
                // on tt_edit_settings internally; every mutation re-checks
                // the same cap at the REST layer (OnboardingRestController).
                FrontendSetupView::render( $user_id, $is_admin );
                return true;
            case 'backups':
                // #1937 — frontend port of the wp-admin Backups page
                // (Configuration → Backups). Settings, stored-backups list
                // (download/delete), Run now, DESTRUCTIVE full restore
                // (typed-confirm RESTORE), and the .ttmig migration
                // export → upload → preview → dry-run → commit (typed-confirm
                // IMPORT) flow. Gates on tt_manage_backups internally; every
                // mutation re-checks the same cap at the REST layer
                // (BackupRestController), restore + import are audit-logged
                // and refuse to run while impersonating.
                FrontendBackupsView::render( $user_id, $is_admin );
                return true;
            case 'lookup-normalisation':
                // #987 v4.12.0 — canonical-language drift review tool.
                FrontendLookupNormalisationView::render( $user_id, $is_admin );
                return true;
            case 'docs':
                FrontendDocsView::render( $user_id, $is_admin );
                return true;
            case 'cohort-transitions':
                \TT\Modules\Journey\Frontend\FrontendCohortTransitionsView::render( $user_id, $is_admin );
                return true;
            case 'custom-css':
                \TT\Modules\CustomCss\Frontend\FrontendCustomCssView::render( $user_id, $is_admin );
                return true;
            case 'data-browser':
                // #1859 — read-only schema browser. The view reads its own
                // table / page / search query params and gates on
                // tt_view_data_browser internally.
                \TT\Shared\Frontend\FrontendDataBrowserView::render( $user_id, $is_admin );
                return true;
            case 'recycle-bin':
                // #2024 — centralized recycle bin. Cross-entity list of
                // trashed rows with inline restore / purge. Gates on
                // tt_manage_recycle_bin internally; every mutation re-checks
                // the cap + ownership at the REST layer
                // (RecycleBinRestController).
                \TT\Shared\Frontend\FrontendRecycleBinView::render( $user_id, $is_admin );
                return true;
            case 'media-retention':
                // #2666 (epic #2589) — media past its retention date,
                // waiting for a person to decide. Gates on tt_manage_media
                // internally; every mutation re-checks global create_delete
                // authority at the REST layer.
                \TT\Shared\Frontend\FrontendMediaRetentionView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0032 — dispatch an invitation-management slug.
     *
     * v3.110.172 — bool-returning dispatcher pattern. Returns true if
     * the slug was recognised + rendered (or gracefully denied), false
     * if the dispatcher does not own this slug and the router should
     * try the next one. The single source of truth for owned slugs is
     * the switch case list itself — no separate allowlist to drift.
     */
    private static function dispatchInvitationView( string $view ): bool {
        switch ( $view ) {
            case 'invitations-config':
                \TT\Modules\Invitations\Frontend\InvitationsConfigView::render();
                return true;
            default:
                return false;
        }
    }

    /**
     * #0009 — dispatch a development-management slug. Each view inside
     * the Development module re-checks its capability and renders the
     * "no permission" back-button pattern for non-eligible roles.
     */
    private static function dispatchDevView( string $view ): bool {
        switch ( $view ) {
            case 'submit-idea':
                \TT\Modules\Development\Frontend\IdeaSubmitView::render();
                return true;
            case 'ideas-board':
                \TT\Modules\Development\Frontend\IdeasBoardView::render();
                return true;
            case 'ideas-refine':
                \TT\Modules\Development\Frontend\IdeasRefineView::render();
                return true;
            case 'ideas-approval':
                \TT\Modules\Development\Frontend\IdeasApprovalView::render();
                return true;
            case 'dev-tracks':
                \TT\Modules\Development\Frontend\TracksView::render();
                return true;
            default:
                return false;
        }
    }

    /**
     * #0014 Sprints 4+5 — Reports wizard + scout flow surfaces. Each
     * view re-checks its own capability so dispatching here is safe.
     */
    private static function dispatchReportView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'report-wizard':
                FrontendReportWizardView::render( $user_id, $is_admin );
                return true;
            case 'scout-access':
                if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
                    return true;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutAccessView::render( $user_id, $is_admin );
                return true;
            case 'scout-history':
                if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
                    return true;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutHistoryView::render( $user_id, $is_admin );
                return true;
            case 'scout-my-players':
                if ( ! current_user_can( 'tt_view_scout_assignments' ) ) {
                    FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
                    echo '<p class="tt-notice">' . esc_html__( 'This area is for scout users.', 'talenttrack' ) . '</p>';
                    return true;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutMyPlayersView::render( $user_id );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0017 — Trial player module surfaces. Each view re-checks its
     * own capability so dispatching here is safe.
     */
    private static function dispatchTrialView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'trials':
                FrontendTrialsManageView::render( $user_id, $is_admin );
                return true;
            case 'trial-case':
                FrontendTrialCaseView::render( $user_id, $is_admin );
                return true;
            case 'trial-parent-meeting':
                FrontendTrialParentMeetingView::render( $user_id, $is_admin );
                return true;
            case 'trial-tracks-editor':
                FrontendTrialTracksEditorView::render( $user_id, $is_admin );
                return true;
            case 'trial-letter-templates-editor':
                FrontendTrialLetterTemplatesEditorView::render( $user_id, $is_admin );
                return true;
            case 'test-trainings':
                // v3.110.113 — minimal create-only frontend for
                // `tt_test_trainings`. Reached from the HoD dashboard's
                // `+ New test training` action card.
                FrontendTestTrainingsView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0039 — Staff development dispatch.
     */
    private static function dispatchStaffDevelopmentView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'my-staff-pdp':
                \TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffPdpView::render( $user_id, $is_admin );
                return true;
            case 'my-staff-goals':
                \TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffGoalsView::render( $user_id, $is_admin );
                return true;
            case 'my-staff-evaluations':
                \TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffEvaluationsView::render( $user_id, $is_admin );
                return true;
            case 'my-staff-certifications':
                \TT\Modules\StaffDevelopment\Frontend\FrontendMyStaffCertificationsView::render( $user_id, $is_admin );
                return true;
            case 'staff-overview':
                \TT\Modules\StaffDevelopment\Frontend\FrontendStaffOverviewView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #2646 (epic #2641) — knowledge library.
     *
     * `course` and `lesson` read their target from the query string
     * rather than the slug, because a course is addressed by its corpus
     * slug and there is no numeric id to put in the route.
     */
    private static function dispatchKnowledgeView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'knowledge':
                \TT\Modules\Knowledge\Frontend\FrontendKnowledgeLibraryView::render( $user_id, $is_admin );
                return true;
            case 'course':
                \TT\Modules\Knowledge\Frontend\FrontendCourseView::render( $user_id, $is_admin );
                return true;
            case 'lesson':
                \TT\Modules\Knowledge\Frontend\FrontendLessonView::render( $user_id, $is_admin );
                return true;
            case 'my-learning':
                \TT\Modules\Knowledge\Frontend\FrontendMyLearningView::render( $user_id, $is_admin );
                return true;
            case 'submission-review':
                \TT\Modules\Knowledge\Frontend\FrontendSubmissionReviewView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0055 — record-creation wizards. `wizard` is the user-facing
     * slug; `wizards-admin` is the configuration + analytics surface.
     */
    private static function dispatchWizardView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'wizard':
                FrontendWizardView::render( $user_id, $is_admin );
                return true;
            case 'wizards-admin':
                FrontendWizardsAdminView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0086 Workstream B Child 1 sprint 3 — MFA login challenge.
     * #2554 — rendered pre-shell, like every other pre-authentication
     * flow, rather than through the authenticated view dispatcher.
     *
     * Returns the finished markup, or an empty string when `$view` isn't
     * an MFA challenge screen (or the visitor isn't in a position to see
     * one) so the caller falls through to its normal routing.
     */
    private static function preAuthMfaScreen( string $view ): string {
        if ( $view !== \TT\Modules\Mfa\Auth\MfaLoginGuard::PROMPT_VIEW && $view !== 'wizard' ) {
            return '';
        }
        if ( ! is_user_logged_in() ) {
            // Falls through to the login form — the visitor has to
            // authenticate with a first factor before a second one means
            // anything.
            return '';
        }

        $user_id = get_current_user_id();

        if ( $view === \TT\Modules\Mfa\Auth\MfaLoginGuard::PROMPT_VIEW ) {
            if ( ! \TT\Modules\Mfa\Auth\MfaLoginGuard::isPending( $user_id ) ) {
                // No challenge outstanding — someone hand-typed the URL, or
                // reloaded a page whose challenge has since been cleared.
                // `MfaChallengeHandler` redirects them at `init`; by the
                // time the shortcode runs the headers are out and a second
                // attempt here would only truncate the page (#2668), so
                // hand them a link instead of a blank screen.
                self::enqueuePreAuthMfaStyle();
                ob_start();
                \TT\Modules\Mfa\Frontend\FrontendMfaPromptView::renderContinue(
                    \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
                );
                return (string) ob_get_clean();
            }
            self::enqueuePreAuthMfaStyle();
            ob_start();
            \TT\Modules\Mfa\Frontend\FrontendMfaPromptView::render( $user_id, current_user_can( 'tt_edit_settings' ) );
            return (string) ob_get_clean();
        }

        // Forced enrollment. `tt_mfa_required=1` is what `MfaLoginGuard::enforce()`
        // appends when it holds an un-enrolled user at the wizard, so it cleanly
        // separates this from a voluntary enrollment launched off the Account
        // page — that one keeps its normal in-shell wizard chrome.
        if ( ! isset( $_GET['tt_mfa_required'] ) ) return '';
        if ( ! \TT\Modules\Mfa\Auth\MfaLoginGuard::mustEnroll( $user_id ) ) return '';

        // #901 — primary query var is `tt_wizard`; `slug=` is the legacy form.
        $slug = isset( $_GET['tt_wizard'] ) ? sanitize_key( (string) $_GET['tt_wizard'] ) : '';
        if ( $slug === '' && isset( $_GET['slug'] ) ) {
            $slug = sanitize_key( (string) $_GET['slug'] );
        }
        if ( $slug !== \TT\Modules\Mfa\Wizards\MfaEnrollmentWizard::SLUG ) return '';

        self::enqueuePreAuthMfaStyle();
        // The shared wizard view emits a breadcrumb chain on every code
        // path. Off, here — nowhere to navigate to before the second
        // factor lands, and § 5 exempts pre-login flows from the
        // two-affordance contract.
        FrontendBreadcrumbs::suppress();
        ob_start();
        echo '<div class="tt-dashboard">';
        echo '<div class="tt-preauth tt-preauth-wide">';
        self::renderPreAuthBrand();
        FlashMessages::render();
        FrontendWizardView::render( $user_id, current_user_can( 'tt_edit_settings' ) );
        self::renderPreAuthSignOut();
        echo '</div></div>';
        return (string) ob_get_clean();
    }

    /**
     * The academy crest + name that heads a pre-authentication screen.
     * Same two elements the login card leads with, so the challenge reads
     * as part of signing in rather than as a page inside the app.
     */
    public static function renderPreAuthBrand(): void {
        $logo    = QueryHelpers::get_config( 'logo_url', '' );
        $academy = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        if ( $logo ) {
            echo '<img src="' . esc_url( $logo ) . '" alt="" class="tt-login-logo" />';
        }
        echo '<h2 class="tt-login-title">' . esc_html( $academy ) . '</h2>';
    }

    /**
     * The way out of a challenge the user can't complete — phone left at
     * home, backup codes lost. Without the shell there is no other exit,
     * and `MfaLoginGuard::enforce()` lets `wp-login.php` through precisely
     * so this works.
     */
    public static function renderPreAuthSignOut(): void {
        // Reuses the user-menu's string rather than minting a "Sign out" —
        // same action, same word, one translation.
        echo '<p class="tt-login-links tt-preauth-signout">'
            . '<a href="' . esc_url( wp_logout_url() ) . '">'
            . esc_html__( 'Log out', 'talenttrack' )
            . '</a></p>';
    }

    private static function enqueuePreAuthMfaStyle(): void {
        wp_enqueue_style(
            'tt-frontend-mfa-prompt',
            TT_PLUGIN_URL . 'assets/css/frontend-mfa-prompt.css',
            [ 'tt-public' ],
            TT_VERSION
        );
    }

    /**
     * #0084 Child 1 — mobile classification surfaces.
     */
    private static function dispatchMobileView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'mobile-settings':
                FrontendMobileSettingsView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0083 Child 3 — analytics dimension explorer.
     */
    private static function dispatchAnalyticsExploreView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'explore':
                if ( ! \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
                    self::renderAnalyticsExplorerOff();
                    return true;
                }
                \TT\Modules\Analytics\Frontend\FrontendExploreView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #1484 — the ad-hoc Analytics Explorer is turned off. Render a
     * friendly notice (with the canonical breadcrumb chain) instead of
     * the explorer surface. The reports the engine feeds are unaffected.
     */
    private static function renderAnalyticsExplorerOff(): void {
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Analytics', 'talenttrack' ) );
        echo '<p class="tt-notice">'
            . esc_html__( 'The Analytics explorer is turned off. Your reports still work — find them under Reports. An administrator can re-enable the explorer under Modules.', 'talenttrack' )
            . '</p>';
    }

    /**
     * #1383 — cohort decision board. Independent of the explorer feature
     * flag; the view self-gates on `tt_view_analytics`.
     */
    private static function dispatchCohortBoardView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'cohort-board':
                \TT\Modules\Analytics\Frontend\FrontendCohortBoardView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0083 Child 5 — central analytics surface.
     */
    private static function dispatchAnalyticsCentralView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'analytics':
                if ( ! \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
                    self::renderAnalyticsExplorerOff();
                    return true;
                }
                \TT\Modules\Analytics\Frontend\FrontendAnalyticsView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * v3.110.146 — standard report dispatchers. Each view checks
     * `tt_view_analytics` itself + renders its own breadcrumb chain.
     */
    private static function dispatchAnalyticsReportView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'attendance-report-team':
                \TT\Modules\Analytics\Frontend\FrontendAttendanceTeamReportView::render( $user_id, $is_admin );
                return true;
            case 'attendance-report-player':
                \TT\Modules\Analytics\Frontend\FrontendAttendancePlayerReportView::render( $user_id, $is_admin );
                return true;
            case 'attendance-leaderboard':
                \TT\Modules\Analytics\Frontend\FrontendAttendanceLeaderboardView::render( $user_id, $is_admin );
                return true;
            case 'minutes-report-team':
                \TT\Modules\Analytics\Frontend\FrontendMinutesTeamReportView::render( $user_id, $is_admin );
                return true;
            // #2368 — read-only minutes-audit overview (games × players
            // matrix). Self-gates on tt_view_analytics + team scope.
            // #2367 — a `match_id` param routes to the per-match minutes
            // EDITOR (self-gates on tt_edit_activities + team scope); the
            // bare view stays the read-only overview.
            case 'minutes-audit':
                if ( isset( $_GET['match_id'] ) && absint( wp_unslash( $_GET['match_id'] ) ) > 0 ) {
                    \TT\Modules\Analytics\Frontend\FrontendMinutesAuditEditView::render( $user_id, $is_admin );
                } else {
                    \TT\Modules\Analytics\Frontend\FrontendMinutesAuditView::render( $user_id, $is_admin );
                }
                return true;
            case 'eval-coverage':
                \TT\Modules\Analytics\Frontend\FrontendEvalCoverageView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * #0083 Child 6 — scheduled reports management.
     */
    private static function dispatchAnalyticsScheduleView( string $view, int $user_id, bool $is_admin ): bool {
        switch ( $view ) {
            case 'scheduled-reports':
                if ( ! \TT\Modules\Analytics\AnalyticsModule::explorerEnabled() ) {
                    self::renderAnalyticsExplorerOff();
                    return true;
                }
                \TT\Modules\Analytics\Frontend\FrontendScheduledReportsView::render( $user_id, $is_admin );
                return true;
            default:
                return false;
        }
    }

    /**
     * v3.110.172 (#764 sustainable fix) — try each bool-returning
     * dispatcher in order. The first one to recognise the slug returns
     * true; the rest are short-circuited. When every dispatcher returns
     * false, the caller renders the "Unknown section." notice.
     *
     * The single source of truth for which slugs route to which view is
     * the switch case lists inside each `dispatchXxxView` method. There
     * is no separate allowlist that can drift — adding a `case '<slug>':`
     * to a dispatcher makes the slug routable on the next request.
     *
     * Order matters when slugs overlap: dispatchMeView is called before
     * dispatchAccountView so that, e.g., `my-settings` lands on the
     * Account dispatcher (which has the version that doesn't require a
     * linked player record) regardless of whether the caller has a
     * player record.
     */
    private static function tryDispatch( string $view, int $user_id, bool $is_admin, ?object $player ): bool {
        return self::dispatchMeView( $view, $player )
            || self::dispatchAccountView( $view, $user_id )
            || self::dispatchCoachingView( $view, $user_id, $is_admin )
            || self::dispatchAnalyticsView( $view )
            || self::dispatchAdminView( $view, $user_id, $is_admin )
            || self::dispatchWorkflowView( $view, $user_id, $is_admin )
            || self::dispatchDevView( $view )
            || self::dispatchInvitationView( $view )
            || self::dispatchReportView( $view, $user_id, $is_admin )
            || self::dispatchTrialView( $view, $user_id, $is_admin )
            || self::dispatchStaffDevelopmentView( $view, $user_id, $is_admin )
            || self::dispatchKnowledgeView( $view, $user_id, $is_admin )
            || self::dispatchWizardView( $view, $user_id, $is_admin )
            || self::dispatchMobileView( $view, $user_id, $is_admin )
            || self::dispatchAnalyticsExploreView( $view, $user_id, $is_admin )
            || self::dispatchCohortBoardView( $view, $user_id, $is_admin )
            || self::dispatchAnalyticsCentralView( $view, $user_id, $is_admin )
            || self::dispatchAnalyticsReportView( $view, $user_id, $is_admin )
            || self::dispatchAnalyticsScheduleView( $view, $user_id, $is_admin );
    }

    /**
     * #0051 — friendly back-button + notice rendered when the user
     * lands on a `tt_view=<slug>` whose owning module is disabled.
     * Mirrors the "no permission" / "unknown section" pattern used
     * elsewhere in the dispatcher so the surface stays consistent.
     */
    private static function renderModuleDisabledNotice(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Section unavailable', 'talenttrack' ) );
        echo '<div class="tt-notice" style="background:#fff7e6; border-left:4px solid #f0c890; padding:12px 16px; margin:8px 0 16px;">';
        echo '<p style="margin:0 0 6px; font-weight:600;">'
            . esc_html__( 'This section is currently unavailable.', 'talenttrack' )
            . '</p>';
        echo '<p style="margin:0; color:#5b6e75;">'
            . esc_html__( 'The administrator has temporarily turned off this part of TalentTrack. Please check back later, or ask your administrator if you need access.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    private static function renderHeader(): void {
        $logo      = QueryHelpers::get_config( 'logo_url', '' );
        $show_logo = QueryHelpers::get_config( 'show_logo', '0' ) === '1';
        $name      = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        $user      = wp_get_current_user();

        // v3.62.0 — frontend-rendered settings instead of bouncing to
        // wp-admin/profile.php. The TT-internal screen handles only
        // the fields end users care about (name / email / password).
        $profile_url = add_query_arg(
            [ 'tt_view' => 'my-settings' ],
            self::shortcodeBaseUrl()
        );
        $logout_url  = LogoutHandler::url();
        $is_wp_admin = current_user_can( 'administrator' );
        $base_url    = self::shortcodeBaseUrl();
        $help_url    = $base_url ? add_query_arg( 'tt_view', 'docs', $base_url ) : admin_url( 'admin.php?page=tt-docs' );
        $demo_on     = class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) && \TT\Modules\DemoData\DemoMode::isOn();
        $demo_tip    = __( 'TalentTrack is running in demo mode. Real club records are hidden until demo mode is turned off.', 'talenttrack' );

        echo '<div class="tt-dash-header">';
        echo '<div class="tt-dash-brand">';
        // #2456 — the drawer trigger sits before the brand mark, where a
        // hamburger is expected. CSS hides it at >=1024px, where the nav
        // is a persistent sidebar and needs no trigger.
        if ( ShellPreference::isApp( (int) $user->ID ) ) {
            \TT\Shared\Frontend\Components\FrontendAppNav::renderDrawerToggle();
        }
        if ( $show_logo && $logo ) {
            echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt="" />';
        } else {
            // #1690 — gold brand mark when no custom logo is configured.
            echo '<span class="tt-appchrome-mark" aria-hidden="true">'
                . esc_html( \TT\Shared\Frontend\Components\FrontendAppChrome::initials( $name ) )
                . '</span>';
        }
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';

        echo '<div class="tt-dash-actions">';

        // #2458 — the palette's non-keyboard entry point. ⌘K opens the same
        // overlay, so the shortcut only ever accelerates (CLAUDE.md §2: a
        // shortcut needs a non-shortcut fallback). Falls back to the tile
        // hub without JS, so it is never a dead control.
        if ( ShellPreference::isApp( (int) $user->ID ) ) {
            // #2531 — a real search field, not a button that opens a modal.
            // Typing queries directly and results drop down beneath it; the
            // combobox wiring (aria-expanded / activedescendant, the listbox)
            // is completed by spotlight.js, which owns the open state.
            //
            // Wrapped in a GET form so it degrades: without JS, submitting
            // lands on the tile hub — the same fallback the old trigger had,
            // since there is no server-rendered results view to send it to.
            $label = __( 'Search players, teams, activities…', 'talenttrack' );

            echo '<form class="tt-spotlight-form" role="search" method="get" '
                . 'action="' . esc_url( self::shortcodeBaseUrl() ) . '">';
            echo '<span class="tt-spotlight-form__icon" aria-hidden="true"></span>';
            echo '<input type="search" name="q" class="tt-spotlight-input" '
                . 'data-tt-spotlight-input '
                . 'role="combobox" aria-expanded="false" aria-haspopup="listbox" '
                . 'aria-controls="tt-spotlight-results" aria-autocomplete="list" '
                . 'autocomplete="off" spellcheck="false" '
                . 'placeholder="' . esc_attr( $label ) . '" '
                . 'aria-label="' . esc_attr( $label ) . '" />';
            // #2563 — the badge renders Ctrl-K and app-shell.js swaps it to
            // the Command glyph on a Mac. It was hardcoded to the Command
            // glyph, which told every Windows user to press a key their
            // keyboard does not have. Ctrl is the safer default: the server
            // cannot know the platform, so the majority case renders correct
            // even when the script never runs.
            echo '<span class="tt-spotlight-trigger__hint" aria-hidden="true" data-tt-shortcut-hint>Ctrl K</span>';
            echo '<ul class="tt-spotlight-results" id="tt-spotlight-results" '
                . 'role="listbox" aria-label="' . esc_attr__( 'Search results', 'talenttrack' ) . '" hidden></ul>';
            // Movement between options is announced from here, because focus
            // stays in the input the whole time.
            echo '<span class="tt-screen-reader-text" aria-live="polite" data-tt-spotlight-status></span>';
            echo '</form>';
        }

        // Filter point — other modules can inject pill-style affordances
        // (open-task bell, etc.) into the actions row so they sit on the
        // same line as the DEMO pill / help icon / user menu. The
        // expected shape is HTML (already escaped). Hooks must wrap their
        // markup in an inline-flex element so flex-gap on .tt-dash-actions
        // spaces them.
        $extras = (string) apply_filters( 'tt_dashboard_actions_html', '', (int) $user->ID );
        if ( $extras !== '' ) {
            echo $extras; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — hook contract: pre-escaped HTML.
        }

        // DEMO indicator. Info-only span (not a link) — hover or focus
        // for the explanation. The wp-admin Tools page is reachable from
        // the user dropdown if an admin actually wants to manage demo
        // data.
        if ( $demo_on ) {
            echo '<span class="tt-dash-demo-pill" tabindex="0" title="' . esc_attr( $demo_tip ) . '" aria-label="' . esc_attr( $demo_tip ) . '">'
                . esc_html__( 'DEMO', 'talenttrack' )
                . '</span>';
        }

        // #1452 — running version, operator-only, sits in the actions row
        // beside the help button so operators can confirm what's deployed
        // without opening wp-admin. Players/parents don't see it.
        if ( current_user_can( 'tt_edit_settings' ) ) {
            echo '<span class="tt-dash-version" style="align-self:center;font-size:.75rem;color:#90a0a6;">'
                . esc_html( 'v' . TT_VERSION )
                . '</span>';
        }

        // Help icon — opens the context-aware docs drawer. Visible to
        // every logged-in user; the drawer's REST endpoint enforces
        // the audience-based cap gate so non-admins only see topics
        // their role can read. The href falls back to the full
        // ?tt_view=docs page so middle-click / right-click "open in
        // new tab" still works without JS.
        echo '<a href="' . esc_url( $help_url ) . '" class="tt-dash-help" '
            . 'data-tt-docs-drawer-open '
            . 'title="' . esc_attr__( 'Help & docs', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Help & docs', 'talenttrack' ) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="10"></circle>'
            . '<path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.7-2.5 2.3-2.5 4"></path>'
            . '<circle cx="12" cy="17.5" r="0.6" fill="currentColor"></circle>'
            . '</svg>'
            . '</a>';

        echo '<div class="tt-user-menu">';
        echo '<button type="button" class="tt-user-menu-trigger" aria-haspopup="true" aria-expanded="false">';
        // #1690 — persona chip (avatar + name + persona label) replaces the
        // bare name; the chip doubles as the user-menu trigger so no extra
        // nav affordance is introduced (CLAUDE.md §5).
        echo \TT\Shared\Frontend\Components\FrontendAppChrome::personaChipInner( $user ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — component escapes internally.
        echo '<span class="tt-user-menu-caret" aria-hidden="true">▾</span>';
        echo '</button>';
        echo '<div class="tt-user-menu-dropdown" role="menu">';
        echo '<a href="' . esc_url( $profile_url ) . '" class="tt-user-menu-item" role="menuitem">';
        echo esc_html__( 'My settings', 'talenttrack' );
        echo '</a>';
        // #0086 Workstream B Child 2 — entry point to the active-sessions
        // surface. Available to every logged-in user; the view scopes
        // to current_user_id() server-side.
        $sessions_url = add_query_arg( [ 'tt_view' => 'my-sessions' ], $base_url );
        echo '<a href="' . esc_url( $sessions_url ) . '" class="tt-user-menu-item" role="menuitem">';
        echo esc_html__( 'My sessions', 'talenttrack' );
        echo '</a>';

        // #0033 Sprint 4 — persona switcher. Visible only when the user
        // resolves to 2+ personas. The switch is a client-side
        // sessionStorage lens; it resets on browser close. Default view
        // is the union (no active persona).
        if ( class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) {
            $personas = \TT\Modules\Authorization\PersonaResolver::personasFor( (int) $user->ID );
            if ( count( $personas ) >= 2 ) {
                echo '<div class="tt-user-menu-section" style="border-top:1px solid #e5e7ea; padding-top:6px; margin-top:6px;">';
                echo '<div class="tt-user-menu-section-label" style="font-size:11px; color:#888; text-transform:uppercase; letter-spacing:0.05em; padding:0 12px 4px;">'
                    . esc_html__( 'View as', 'talenttrack' )
                    . '</div>';
                echo '<a href="#" class="tt-user-menu-item tt-persona-switch" data-persona="" role="menuitem">'
                    . esc_html__( 'All personas (default)', 'talenttrack' )
                    . '</a>';
                $persona_labels = [
                    'player'              => __( 'Player', 'talenttrack' ),
                    'parent'              => __( 'Parent', 'talenttrack' ),
                    'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
                    'head_coach'          => __( 'Head Coach', 'talenttrack' ),
                    'head_of_development' => __( 'Head of Development', 'talenttrack' ),
                    'scout'               => __( 'Scout', 'talenttrack' ),
                    'team_manager'        => __( 'Team Manager', 'talenttrack' ),
                    'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
                ];
                foreach ( $personas as $p ) {
                    $label = $persona_labels[ $p ] ?? $p;
                    echo '<a href="#" class="tt-user-menu-item tt-persona-switch" data-persona="' . esc_attr( $p ) . '" role="menuitem">'
                        . esc_html( $label )
                        . '</a>';
                }
                echo '</div>';
            }
        }

        if ( $is_wp_admin ) {
            echo '<a href="' . esc_url( admin_url() ) . '" class="tt-user-menu-item" role="menuitem">';
            echo esc_html__( 'Go to Admin', 'talenttrack' );
            echo '</a>';
        }
        echo '<a href="' . esc_url( $logout_url ) . '" class="tt-user-menu-item tt-user-menu-item-logout" role="menuitem">';
        echo esc_html__( 'Log out', 'talenttrack' );
        echo '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>'; // .tt-dash-actions
        echo '</div>'; // .tt-dash-header

        // #0016 Part B — context-aware docs drawer. Hidden by default;
        // opens when the help icon is clicked. The drawer fetches the
        // matching topic for the current ?tt_view= slug via REST and
        // renders inline. CSS lives in public.css; the small hydrator
        // is at assets/js/components/docs-drawer.js.
        echo '<aside class="tt-docs-drawer" data-tt-docs-drawer hidden aria-hidden="true" aria-labelledby="tt-docs-drawer-title">';
        echo '<div class="tt-docs-drawer__backdrop" data-tt-docs-drawer-close></div>';
        echo '<div class="tt-docs-drawer__panel" role="dialog">';
        echo '<header class="tt-docs-drawer__head">';
        echo '<h3 id="tt-docs-drawer-title" data-tt-docs-drawer-title>' . esc_html__( 'Help', 'talenttrack' ) . '</h3>';
        // #1365 — inline-SVG expand + close icons (were arrow / times glyphs).
        echo '<a href="' . esc_url( $help_url ) . '" class="tt-docs-drawer__expand" data-tt-docs-drawer-expand title="' . esc_attr__( 'Open full Help & Docs', 'talenttrack' ) . '" aria-label="' . esc_attr__( 'Open full Help & Docs', 'talenttrack' ) . '">'
            . \TT\Shared\Icons\IconRenderer::render( 'external-link', [ 'width' => 14, 'height' => 14 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</a>';
        echo '<button type="button" class="tt-docs-drawer__close" data-tt-docs-drawer-close aria-label="' . esc_attr__( 'Close', 'talenttrack' ) . '">'
            . \TT\Shared\Icons\IconRenderer::render( 'x', [ 'width' => 14, 'height' => 14 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</button>';
        echo '</header>';
        echo '<div class="tt-docs-drawer__body" data-tt-docs-drawer-body>';
        echo '<p class="tt-docs-drawer__loading">' . esc_html__( 'Loading…', 'talenttrack' ) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</aside>';

        // Inline dropdown behaviour.
        ?>
        <script>
        (function(){
            var menu = document.currentScript.parentNode.querySelector('.tt-user-menu');
            if (!menu) return;
            var trigger = menu.querySelector('.tt-user-menu-trigger');
            var dropdown = menu.querySelector('.tt-user-menu-dropdown');
            if (!trigger || !dropdown) return;

            function closeMenu() {
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
            function openMenu() {
                menu.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }

            trigger.addEventListener('click', function(e){
                e.stopPropagation();
                if (menu.classList.contains('is-open')) { closeMenu(); } else { openMenu(); }
            });
            document.addEventListener('click', function(e){
                if (menu.classList.contains('is-open') && !menu.contains(e.target)) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && menu.classList.contains('is-open')) {
                    closeMenu();
                    trigger.focus();
                }
            });

            // #0033 Sprint 4 — persona switcher. Stores the active lens
            // in sessionStorage so it resets on browser close. A banner
            // surfaces at the top of the dashboard showing the active
            // persona until the user clicks "back to all".
            var STORAGE_KEY = 'tt_active_persona';
            menu.querySelectorAll('.tt-persona-switch').forEach(function(link){
                link.addEventListener('click', function(e){
                    e.preventDefault();
                    var p = link.dataset.persona || '';
                    if (p) {
                        sessionStorage.setItem(STORAGE_KEY, p);
                    } else {
                        sessionStorage.removeItem(STORAGE_KEY);
                    }
                    location.reload();
                });
            });

            var active = sessionStorage.getItem(STORAGE_KEY);
            if (active) {
                var dash = document.querySelector('.tt-dashboard');
                if (dash) {
                    var banner = document.createElement('div');
                    banner.style.cssText = 'background:#fff7e6; border:1px solid #f0c890; border-radius:6px; padding:8px 12px; margin:6px 0 12px; font-size:13px; display:flex; justify-content:space-between; align-items:center;';
                    banner.innerHTML = '<span><?php echo esc_js( __( 'You are viewing as', 'talenttrack' ) ); ?> <strong>' + active.replace(/_/g, ' ') + '</strong>.</span>'
                        + '<a href="#" id="tt-persona-reset" style="text-decoration:none;"><?php echo esc_js( __( 'Switch back to all personas', 'talenttrack' ) ); ?></a>';
                    dash.insertBefore(banner, dash.firstChild.nextSibling);
                    var reset = document.getElementById('tt-persona-reset');
                    if (reset) reset.addEventListener('click', function(e){
                        e.preventDefault();
                        sessionStorage.removeItem(STORAGE_KEY);
                        location.reload();
                    });
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Current page URL with TT-specific query args stripped. Used as
     * the base for the help link. Duplicate of the same helper on
     * FrontendTileGrid so this class stays self-contained.
     */
    private static function shortcodeBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
    }

    /**
     * tt_view slug → docs topic slug map for the context-aware help
     * drawer (#0016 part B). Slugs missing from this map fall back to
     * `default_slug` (getting-started) on the JS side. Add new
     * mappings here whenever a new view ships with its own help topic.
     *
     * @return array<string, string>
     */
    private static function viewToTopicMap(): array {
        return [
            'players'             => 'teams-players',
            'players-import'      => 'teams-players',
            'teams'               => 'teams-players',
            'people'              => 'people-staff',
            'functional-roles'    => 'people-staff',
            'evaluations'         => 'evaluations',
            'eval-categories'     => 'eval-categories-weights',
            'activities'          => 'activities',
            'goals'               => 'goals',
            'reports'             => 'reports',
            'attendance-leaderboard' => 'reports',
            'rate-cards'          => 'rate-cards',
            'compare'             => 'player-comparison',
            'methodology'         => 'methodology',
            'configuration'       => 'configuration-branding',
            'custom-fields'       => 'custom-fields',
            'roles'               => 'access-control',
            'overview'            => 'player-dashboard',
            'my-team'             => 'player-dashboard',
            'my-evaluations'      => 'player-dashboard',
            'my-activities'       => 'player-dashboard',
            'my-goals'            => 'player-dashboard',
            'profile'             => 'player-dashboard',
            'my-tasks'            => 'workflow-tasks',
            'tasks-dashboard'     => 'workflow-tasks',
            'workflow-config'     => 'workflow-tasks',
        ];
    }
}
