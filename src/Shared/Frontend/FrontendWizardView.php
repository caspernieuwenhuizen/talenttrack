<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\SupportsCancelAsDraft;
use TT\Shared\Wizards\WizardAnalytics;
use TT\Shared\Wizards\WizardInterface;
use TT\Shared\Wizards\WizardRegistry;
use TT\Shared\Wizards\WizardState;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * FrontendWizardView — generic driver for any registered wizard.
 *
 *   ?tt_view=wizard&slug=<wizard-slug>            — start (resumes if state exists)
 *   ?tt_view=wizard&slug=<…>&restart=1            — clear state, start fresh
 *
 * The view loads the current step from `WizardState`, renders it,
 * and on POST validates → persists → advances. When the next step is
 * `null`, the framework calls `submit()` and clears state. Step
 * ordering and branching live entirely in the step classes; this
 * driver is generic.
 *
 * Mobile-first: each step is a single-column form with a progress
 * dots bar at the top and Back / Next buttons at the bottom.
 */
class FrontendWizardView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        // #901 — primary query var is `tt_wizard`. Back-compat: also
        // accept legacy `slug=` URLs (bookmarks, shared links) for one
        // release. Removal candidate in the next minor — by then any
        // surviving bookmark predates v4.x and an operator should
        // re-bookmark.
        $slug = isset( $_GET['tt_wizard'] ) ? sanitize_key( (string) $_GET['tt_wizard'] ) : '';
        if ( $slug === '' && isset( $_GET['slug'] ) ) {
            $slug = sanitize_key( (string) $_GET['slug'] );
        }
        $wizard = WizardRegistry::find( $slug );
        if ( ! $wizard ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Wizard not found', 'talenttrack' ) );
            self::renderHeader( __( 'Wizard not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Unknown wizard.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! WizardRegistry::isAvailable( $slug, $user_id ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $wizard->label() );
            self::renderHeader( $wizard->label() );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to use this wizard, or it is currently disabled.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueWizardStyles();
        // v3.110.190 (#796) — live mandatory-field validation.
        self::enqueueValidationScript();
        // v3.110.84 — autosave runtime removed. The periodic POSTs were
        // racing with `WizardState::clear()`: a Cancel or Submit would
        // clear the transient + the `tt_wizard_drafts` row, then an
        // in-flight autosave POST from a moment earlier would re-insert
        // the row, and the next wizard load would resume from the
        // resurrected draft (pilot symptom: "it keeps coming back at
        // the check stage. Only if I click cancel a few times it
        // clears"). Wizards that genuinely want a cross-session draft
        // implement `SupportsCancelAsDraft` and surface an explicit
        // "Save as draft" button — that path is unchanged.

        // v3.110.186 (#792) — gate `restart` to GET requests only. The
        // hero CTAs carry `restart=1` to force a fresh wizard run on
        // first entry; the wizard's form has no `action` attribute, so
        // every Cancel / Next / Back POST returned to the same URL and
        // re-triggered `WizardState::clear()` mid-flight. That wiped
        // the step pointer just before the POST handler verified the
        // form's nonce against the (now-reset) first-step slug —
        // mismatch → "session expired" → cancel handler never fired.
        // `restart` is a one-shot entry signal; once the user is inside
        // the wizard, POSTs must preserve state.
        if ( ! empty( $_GET['restart'] ) && $_SERVER['REQUEST_METHOD'] === 'GET' ) {
            WizardState::clear( $user_id, $slug );
        }

        // v3.110.84 — defensive cleanup of any stale persistent draft
        // row left behind by the pre-v3.110.84 autosave runtime. For
        // wizards that don't explicitly support drafts via
        // `SupportsCancelAsDraft` (e.g. `mark-attendance`,
        // `new-evaluation`), the `tt_wizard_drafts` row should never
        // exist after a Cancel / Submit — but rows from in-flight
        // autosave POSTs that landed AFTER the v3.110.83 clear keep
        // resurfacing the wizard at the step it was on. Wipe any
        // persistent row on every render for non-draft wizards. The
        // transient stays untouched so a real in-flight wizard run
        // keeps its state through the wizard's own back/next chrome.
        if ( ! ( $wizard instanceof SupportsCancelAsDraft ) ) {
            WizardState::clearPersistentDraft( $user_id, $slug );
        }

        $state = WizardState::load( $user_id, $slug );
        if ( ! $state ) {
            $state = WizardState::start( $user_id, $slug, $wizard->firstStepSlug() );
            WizardAnalytics::recordStarted( $slug );

            // #0092 — opt-in seed hook. Wizards that need to read URL
            // params on first hit (e.g. `mark-attendance` taking an
            // `activity_id` from the dashboard widget) implement
            // `initialState( array $get ): array`. Returned values are
            // merged into wizard state before the first step renders.
            if ( method_exists( $wizard, 'initialState' ) ) {
                $get_snapshot = is_array( $_GET ) ? $_GET : [];
                $seed = $wizard->initialState( $get_snapshot );
                if ( is_array( $seed ) && ! empty( $seed ) ) {
                    $state = WizardState::merge( $user_id, $slug, $seed );
                }
            }
        }

        $current_slug = (string) $state['_step'];
        $current      = self::stepFor( $wizard, $current_slug ) ?: self::stepFor( $wizard, $wizard->firstStepSlug() );
        if ( ! $current ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $wizard->label() );
            self::renderHeader( $wizard->label() );
            echo '<p class="tt-notice">' . esc_html__( 'Wizard misconfigured (no steps).', 'talenttrack' ) . '</p>';
            return;
        }

        // #940 — render() is now GET-only. All POST handling lives in
        // `handleAdminPostStep()`, registered as `admin_post_tt_wizard_step`.
        // The handler verifies nonce, dispatches the action, persists state,
        // and redirects back to the wizard URL captured in
        // `tt_wizard_return_url`. The only remaining error case here is
        // a nonce mismatch detected by the handler before redirecting back
        // with `?tt_wizard_error=expired`.
        $error = null;
        $error_key = isset( $_GET['tt_wizard_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_wizard_error'] ) ) : '';
        if ( $error_key === 'expired' ) {
            $error = __( 'Your session expired while this step was open. Please reload the page and try again — your edits on this step will need to be re-entered.', 'talenttrack' );
        } elseif ( in_array( $error_key, [ 'validation', 'submit', 'draft_failed' ], true ) ) {
            $transient_key = 'tt_wizard_err_' . $user_id . '_' . $slug;
            $stashed = get_transient( $transient_key );
            if ( $stashed !== false ) {
                $error = (string) $stashed;
                delete_transient( $transient_key );
            }
        }

        // #0092 — auto-skip steps whose `notApplicableFor()` returns
        // true. The eval wizard's comments referenced this since #0072
        // ("framework auto-skips this step via notApplicableFor()");
        // until now it only greyed the progress bar. Coaches landing
        // on the mark-attendance wizard with `activity_id` pre-seeded
        // would otherwise see the activity-picker step pointlessly.
        // Bounded by the step count to prevent loops on a misconfigured
        // chain.
        $max_skips = count( $wizard->steps() ) + 1;
        $skip_count = 0;
        while ( $skip_count++ < $max_skips
                && method_exists( $current, 'notApplicableFor' )
                && (bool) $current->notApplicableFor( $state ) ) {
            $next_slug = $current->nextStep( $state );
            if ( $next_slug === null ) break;
            WizardState::recordSkip( $user_id, $slug, $current->slug() );
            WizardAnalytics::recordSkipped( $slug, $current->slug() );
            $state = WizardState::setStep( $user_id, $slug, $next_slug );
            $next_step = self::stepFor( $wizard, $next_slug );
            if ( ! $next_step ) break;
            $current = $next_step;
            $current_slug = $next_slug;
        }

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $wizard->label() );
        self::renderHeader( $wizard->label() );
        self::renderResumeBanner( $user_id, $slug, $state );

        // #1036 — V3 chrome wraps the rail + form area in a 2-col grid
        // on desktop. The grid's parent owns data-rail-open which the
        // mobile rail-toggle JS flips.
        // #1514 — single-step wizards emit no rail (renderProgress() returns
        // early when count(steps) <= 1), so the lone form child would land in
        // the 220px rail column and get squished. Flag the no-rail case so
        // the CSS drops to a single full-width column.
        $has_rail = count( $wizard->steps() ) > 1 ? '1' : '0';
        echo '<div class="tt-wizard-layout" data-rail-open="false" data-has-rail="' . esc_attr( $has_rail ) . '">';
        self::renderProgress( $wizard, $current_slug, $state );
        if ( $error ) {
            echo '<div class="tt-notice tt-notice-error" role="alert">' . esc_html( $error ) . '</div>';
        }

        // v3.70.1 hotfix — Cancel destination prefers `return_to` query
        // arg (when callers wire it through) → HTTP referrer → dashboard.
        // The previous behaviour of `remove_query_arg([slug, restart])`
        // landed on `?tt_view=wizard` (an empty wizards landing) when no
        // referrer was preserved; the user wants to go back to where they
        // were before opening the wizard.
        $return_to = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['return_to'] ) ) : '';
        // v3.110.102 — `tt_back` is the canonical back-target across
        // every frontend surface (per CLAUDE.md §5). The wizard
        // historically only honoured `return_to`; honouring `tt_back`
        // as a fallback means every entry CTA can use the same
        // `tt_back` URL param it would on any other surface and Cancel
        // routes correctly. Pilot symptom: clicking **New evaluation**
        // from the evaluations list, then **Cancel** in the wizard,
        // dropped the coach on the dashboard instead of back on the
        // evaluations list.
        if ( $return_to === '' && isset( $_GET['tt_back'] ) ) {
            $return_to = esc_url_raw( wp_unslash( (string) $_GET['tt_back'] ) );
        }
        $referer   = wp_get_referer();
        $cancel_url = $return_to !== ''
            ? $return_to
            : ( $referer ?: \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
        // v3.110.137 — `novalidate` disables the browser's native
        // HTML5 form validation across every wizard step. Reasons:
        //   - Steps already have their own server-side validate()
        //     methods that are the source of truth.
        //   - Native validation jumps focus to the first invalid input
        //     on submit attempts. When that input lives inside a
        //     collapsed `<details>` (e.g. an un-expanded player card
        //     in RateActorsStep) or a hidden `[hidden]` panel (e.g.
        //     the Basic/Detailed subs panel from v3.110.125), the
        //     validation tooltip can't render against the hidden
        //     parent. Result: the page silently "jumps" to nowhere
        //     visible with no error message — exactly the symptom
        //     the rate-actors step exhibited when a typed number fell
        //     outside [min, max] anywhere in the form (pilot: "I
        //     cannot click when in rating step. It seems to jump back
        //     to an input field but without message and actually
        //     seemingly without proper reason").
        //   - The Cancel / Back / Save-as-draft buttons below already
        //     carry `formnovalidate`; only Next lacked it, and that's
        //     the only path that triggered the bug. Adding `novalidate`
        //     on the form is the global fix.
        // #940 — wizard form POSTs target admin-post.php instead of the
        // current dashboard URL. Reason: WP::parse_request() reads public
        // query vars from $_POST before $_GET, so any form field whose
        // name happens to match a WP-reserved public query var (notably
        // `name`, but the class extends to ~25 others) caused
        // `is_singular = true → 404` before the [talenttrack_dashboard]
        // shortcode ever ran. admin-post.php loads wp-load.php but does
        // NOT call wp() / WP::main() / WP::parse_request() for the
        // front-end template path, so the public-query-var resolution
        // that 404'd the wizard doesn't run there. Forward-compatible
        // against future WP-reserved-name collisions (no field rename
        // can outlive a future plugin registering a new public query
        // var); battle-tested by the WP ecosystem for 15+ years; security
        // plugins / hosting WAFs whitelist admin-post.php out of the box.
        echo '<form method="post" class="tt-wizard-form" novalidate action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tt_wizard_step">';
        echo '<input type="hidden" name="tt_wizard_slug" value="' . esc_attr( $slug ) . '">';
        echo '<input type="hidden" name="tt_wizard_step" value="' . esc_attr( $current->slug() ) . '">';
        echo '<input type="hidden" name="tt_wizard_return_url" value="' . esc_attr( self::wizardStepUrl( $slug ) ) . '">';
        wp_nonce_field( 'tt_wizard_' . $slug . '_' . $current->slug(), 'tt_wizard_nonce' );

        echo '<h2 class="tt-wizard-step-title">' . esc_html( $current->label() ) . '</h2>';
        $current->render( $state );

        self::renderHelpSidebar( $wizard, $current );

        // v3.110.84 — autosave status indicator removed alongside the
        // runtime. Wizards that want a manual "Save as draft" button
        // implement `SupportsCancelAsDraft`; the action bar below
        // renders the button when present.
        // #1036 — V3 actions layout: Cancel on the left, [Save + Back] in
        // a middle group (collapsed via `display: contents` on mobile
        // so they participate in the parent grid directly), Next on the
        // right. Each button carries `data-role` so the mobile button
        // grid can reorder via `grid-template-areas` regardless of DOM
        // order.
        echo '<div class="tt-wizard-actions">';
        // #0069 — Cancel must discard the run regardless of unfilled
        // required fields. Save-as-draft and Back already carry
        // formnovalidate; Cancel was the outlier and was tripping
        // browser-side required-field validation when the user wanted
        // to bail out of the wizard.
        echo '<button type="submit" name="tt_wizard_action" value="cancel" class="tt-button tt-wizard-btn-cancel" formnovalidate data-role="cancel">' . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        echo '<div class="tt-wizard-actions-middle">';
        if ( $wizard instanceof SupportsCancelAsDraft ) {
            echo '<button type="submit" name="tt_wizard_action" value="save-as-draft" class="tt-button tt-wizard-btn-text" formnovalidate data-role="save">' . esc_html__( 'Save as draft', 'talenttrack' ) . '</button>';
        }
        // #0063 — Back button on every step where there's prior history.
        // formnovalidate so a half-filled required field doesn't trap
        // the user; Back discards uncommitted edits by design.
        if ( WizardState::hasHistory( $user_id, $slug ) ) {
            echo '<button type="submit" name="tt_wizard_action" value="back" class="tt-button tt-wizard-btn-text" formnovalidate data-role="back">' . esc_html__( 'Back', 'talenttrack' ) . '</button>';
        }
        echo '</div>';
        // v3.85.2 — "Skip step" removed per operator feedback. Steps that
        // are genuinely optional should declare `notApplicableFor()`
        // returning true and be auto-skipped by the wizard runner.
        $is_last = $current->nextStep( $state ) === null;
        $label   = $is_last ? __( 'Create', 'talenttrack' ) : __( 'Next', 'talenttrack' );
        $loading_label = $is_last ? __( 'Creating…', 'talenttrack' ) : __( 'Loading…', 'talenttrack' );
        echo '<button type="submit" name="tt_wizard_action" value="next" class="tt-button tt-wizard-btn-primary" data-role="next" data-tt-wizard-next data-loading-label="' . esc_attr( $loading_label ) . '">' . esc_html( $label ) . ' <span class="tt-wizard-btn-chev" aria-hidden="true">›</span></button>';
        echo '</div>';

        echo '<input type="hidden" name="_cancel_url" value="' . esc_attr( $cancel_url ) . '">';
        echo '</form>';
        echo '</div>'; // #1036 — close .tt-wizard-layout

        // v3.110.156 — visible click feedback on Next. When the
        // operator clicks, the button disables and swaps label to
        // "Loading…" so the click registers visually even if the
        // POST takes a moment (or fails silently). Cancel / Back /
        // Save-as-draft don't need this; their behaviour is
        // immediate (no validate + persist round-trip).
        ?>
        <script>
        (function () {
            var form = document.querySelector( '.tt-wizard-form' );
            if ( form ) {
                var next = form.querySelector( '[data-tt-wizard-next]' );
                if ( next ) {
                    form.addEventListener( 'submit', function ( e ) {
                        var submitter = e.submitter || document.activeElement;
                        if ( ! submitter || submitter !== next ) return;
                        var label = next.getAttribute( 'data-loading-label' ) || 'Loading…';
                        next.disabled = true;
                        next.textContent = label;
                    } );
                }
            }

            // #1036 — mobile rail toggle. Flip data-rail-open on the
            // wizard layout container + aria-expanded on the button.
            var layout = document.querySelector( '.tt-wizard-layout' );
            var toggle = layout && layout.querySelector( '[data-tt-wizard-rail-toggle]' );
            if ( layout && toggle ) {
                toggle.addEventListener( 'click', function () {
                    var open = layout.getAttribute( 'data-rail-open' ) === 'true';
                    layout.setAttribute( 'data-rail-open', open ? 'false' : 'true' );
                    toggle.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
                } );
            }
        })();
        </script>
        <?php
    }

    /**
     * #940 — admin-post.php handler for every wizard step POST.
     *
     * Why: WordPress's `WP::parse_request()` reads public query vars
     * from `$_POST` BEFORE `$_GET`. If a wizard form field's `name=`
     * collides with any of ~25 WP-reserved public query vars (`name`,
     * `m`, `p`, `cat`, `s`, `tag`, `feed`, …), every POST 404s before
     * the `[talenttrack_dashboard]` shortcode runs. `admin-post.php`
     * is a separate PHP entry point that loads `wp-load.php` but does
     * not invoke `WP::main()` for the front-end template path, so the
     * public-query-var resolution doesn't run there. Forward-compatible
     * against any future plugin registering a new public query var.
     *
     * The handler mirrors the legacy POST branch from `render()`:
     * verify nonce → dispatch on `tt_wizard_action` → persist state /
     * redirect. On any failure, redirect back to the wizard URL
     * carried in `tt_wizard_return_url` with `?tt_wizard_error=…` so
     * `render()` can surface a visible notice on the next GET.
     */
    public static function handleAdminPostStep(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $slug = isset( $_POST['tt_wizard_slug'] ) ? sanitize_key( (string) wp_unslash( $_POST['tt_wizard_slug'] ) ) : '';
        $wizard = WizardRegistry::find( $slug );
        if ( ! $wizard || ! WizardRegistry::isAvailable( $slug, $user_id ) ) {
            wp_safe_redirect( \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
            exit;
        }

        $return_url = isset( $_POST['tt_wizard_return_url'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['tt_wizard_return_url'] ) )
            : '';
        if ( $return_url === '' ) {
            $return_url = \TT\Shared\Wizards\WizardEntryPoint::buildUrl( $slug );
        }

        // #940 follow-up — the wizard's POST is being processed via
        // admin-post.php; REQUEST_URI is `/wp-admin/admin-post.php`.
        // Step `submit()` handlers that use
        // `WizardEntryPoint::currentDashboardUrl()` to build redirects
        // would otherwise land the user on a bogus admin-post URL.
        // Install the dashboard URL override before invoking step
        // methods. `dashboardOnly()` strips the wizard query args from
        // the return URL so step handlers see a clean dashboard base.
        \TT\Shared\Wizards\WizardEntryPoint::setRequestContextOverride( self::dashboardOnly( $return_url ) );

        $step_slug = isset( $_POST['tt_wizard_step'] ) ? sanitize_key( (string) wp_unslash( $_POST['tt_wizard_step'] ) ) : '';
        $current = self::stepFor( $wizard, $step_slug ) ?: self::stepFor( $wizard, $wizard->firstStepSlug() );
        if ( ! $current ) {
            wp_safe_redirect( $return_url );
            exit;
        }

        $nonce_present = isset( $_POST['tt_wizard_nonce'] );
        $nonce_valid   = $nonce_present && wp_verify_nonce(
            sanitize_text_field( wp_unslash( (string) $_POST['tt_wizard_nonce'] ) ),
            'tt_wizard_' . $slug . '_' . $current->slug()
        );
        if ( ! $nonce_valid ) {
            wp_safe_redirect( add_query_arg( 'tt_wizard_error', 'expired', $return_url ) );
            exit;
        }

        $state = WizardState::load( $user_id, $slug );
        if ( ! $state ) {
            $state = WizardState::start( $user_id, $slug, $wizard->firstStepSlug() );
        }

        $action = isset( $_POST['tt_wizard_action'] ) ? sanitize_key( (string) $_POST['tt_wizard_action'] ) : 'next';

        switch ( $action ) {
            case 'cancel':
                WizardState::clear( $user_id, $slug );
                $cancel_redirect = isset( $_POST['_cancel_url'] )
                    ? esc_url_raw( wp_unslash( (string) $_POST['_cancel_url'] ) )
                    : '';
                if ( $cancel_redirect === '' ) {
                    $cancel_redirect = \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
                }
                wp_safe_redirect( $cancel_redirect );
                exit;

            case 'back':
                // v4.8.0 (#975) — tournament wizard's Review-step Edit
                // links submit `tt_wizard_jump_to=<slug>` so the user
                // lands directly on the named step, not whichever step
                // sits on top of the history stack. Validated against
                // the wizard's declared steps; unknown / malicious
                // values fall back to the default Back behaviour.
                $jump_to = isset( $_POST['tt_wizard_jump_to'] )
                    ? sanitize_key( (string) wp_unslash( $_POST['tt_wizard_jump_to'] ) )
                    : '';
                if ( $jump_to !== '' && self::stepFor( $wizard, $jump_to ) ) {
                    WizardState::setStep( $user_id, $slug, $jump_to );
                } else {
                    $prev = WizardState::popHistory( $user_id, $slug );
                    if ( $prev !== null ) {
                        WizardState::setStep( $user_id, $slug, $prev );
                    }
                }
                wp_safe_redirect( $return_url );
                exit;

            case 'save-as-draft':
                if ( $wizard instanceof SupportsCancelAsDraft ) {
                    $result = $wizard->cancelAsDraft( $state );
                    if ( is_wp_error( $result ) ) {
                        wp_safe_redirect( add_query_arg( 'tt_wizard_error', 'draft_failed', $return_url ) );
                        exit;
                    }
                    WizardState::clear( $user_id, $slug );
                    $redirect = (string) ( $result['redirect_url'] ?? \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
                    wp_safe_redirect( $redirect );
                    exit;
                }
                WizardState::clear( $user_id, $slug );
                wp_safe_redirect( \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
                exit;

            case 'skip':
                WizardState::recordSkip( $user_id, $slug, $current->slug() );
                WizardAnalytics::recordSkipped( $slug, $current->slug() );
                $next_slug = $current->nextStep( $state );
                self::redirectTransitionOrSubmit( $wizard, $current, $next_slug, $state, $user_id, $return_url );
                exit;

            case 'next':
            default:
                $post = self::sanitisePost( $_POST );
                $result = $current->validate( $post, $state );
                if ( is_wp_error( $result ) ) {
                    // Carry the validation error in a transient so the
                    // next GET can surface it. Per-user, short TTL.
                    set_transient(
                        'tt_wizard_err_' . $user_id . '_' . $slug,
                        $result->get_error_message(),
                        60
                    );
                    wp_safe_redirect( add_query_arg( 'tt_wizard_error', 'validation', $return_url ) );
                    exit;
                }
                $state = WizardState::merge( $user_id, $slug, (array) $result );
                $next_slug = $current->nextStep( $state );
                self::redirectTransitionOrSubmit( $wizard, $current, $next_slug, $state, $user_id, $return_url );
                exit;
        }
    }

    /**
     * #940 — admin-post variant of transitionOrSubmit. The legacy
     * helper assumed it was running inside `render()` and could `echo`
     * an error page; the handler instead carries every error back via
     * the return URL so `render()` surfaces it on the next GET.
     */
    private static function redirectTransitionOrSubmit( WizardInterface $wizard, WizardStepInterface $current, ?string $next_slug, array $state, int $user_id, string $return_url ): void {
        $slug = $wizard->slug();
        if ( $next_slug === null ) {
            $result = $current->submit( $state );
            if ( is_wp_error( $result ) ) {
                set_transient(
                    'tt_wizard_err_' . $user_id . '_' . $slug,
                    $result->get_error_message(),
                    60
                );
                wp_safe_redirect( add_query_arg( 'tt_wizard_error', 'submit', $return_url ) );
                exit;
            }
            WizardAnalytics::recordCompleted( $slug );
            WizardState::clear( $user_id, $slug );
            $redirect = (string) ( ( is_array( $result ) ? $result['redirect_url'] : '' ) ?? '' );
            if ( $redirect === '' ) $redirect = \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl();
            wp_safe_redirect( $redirect );
            exit;
        }
        WizardState::pushHistory( $user_id, $slug, $current->slug() );
        WizardState::setStep( $user_id, $slug, $next_slug );
        wp_safe_redirect( $return_url );
        exit;
    }

    /**
     * #0072 follow-up — resume banner. Renders when a persistent draft
     * exists older than 10 minutes (the cross-session signal) and the
     * user hasn't dismissed it for this view via `?dismiss_resume=1`.
     *
     * @param array<string,mixed> $state
     */
    private static function renderResumeBanner( int $user_id, string $slug, array $state ): void {
        if ( ! empty( $_GET['dismiss_resume'] ) ) return;
        if ( ! WizardState::hasPersistentDraft( $user_id, $slug ) ) return;

        $age_str = WizardState::persistentDraftAge( $user_id, $slug );
        if ( $age_str === null ) return;

        try {
            $saved_at = new \DateTimeImmutable( $age_str, new \DateTimeZone( 'UTC' ) );
        } catch ( \Exception $e ) {
            return;
        }
        $age_seconds = time() - $saved_at->getTimestamp();
        if ( $age_seconds < 600 ) return; // Less than 10 minutes — same-session.

        // Only meaningful if there's actual state beyond the bookkeeping.
        $payload = array_filter( $state, static function ( $v, $k ): bool {
            if ( in_array( $k, [ '_step', '_history', '_skipped', '_started_at' ], true ) ) return false;
            return $v !== null && $v !== '' && $v !== [];
        }, ARRAY_FILTER_USE_BOTH );
        if ( $payload === [] ) return;

        $age_human = human_time_diff( $saved_at->getTimestamp(), time() );

        $continue_url = remove_query_arg( [ 'restart' ], add_query_arg( 'dismiss_resume', '1' ) );
        $restart_url  = add_query_arg( 'restart', '1', remove_query_arg( [ 'dismiss_resume' ] ) );

        echo '<div class="tt-notice tt-notice-info tt-pd-resume-banner" role="status">';
        echo '<span class="tt-pd-resume-banner-text">' . esc_html( sprintf(
            /* translators: %s: human-readable age e.g. "2 days". */
            __( 'You started this %s ago. Continue where you left off, or start over?', 'talenttrack' ),
            $age_human
        ) ) . '</span>';
        echo ' <a class="tt-button tt-button-primary" href="' . esc_url( $continue_url ) . '">'
            . esc_html__( 'Continue', 'talenttrack' ) . '</a>';
        echo ' <a class="tt-button tt-button-secondary" href="' . esc_url( $restart_url ) . '">'
            . esc_html__( 'Start over', 'talenttrack' ) . '</a>';
        echo '</div>';
    }

    /**
     * #940 follow-up — strip wizard-specific query args from a
     * wizard-step URL so step `submit()` handlers see a clean
     * dashboard base when calling `WizardEntryPoint::currentDashboardUrl()`.
     * Returns the URL with `tt_view`, `tt_wizard`, `slug`, `restart`,
     * `dismiss_resume`, `return_to`, and `tt_back` query args removed.
     */
    private static function dashboardOnly( string $wizard_step_url ): string {
        return remove_query_arg(
            [ 'tt_view', 'tt_wizard', 'slug', 'restart', 'dismiss_resume', 'return_to', 'tt_back' ],
            $wizard_step_url
        );
    }

    private static function stepFor( WizardInterface $wizard, string $slug ): ?WizardStepInterface {
        foreach ( $wizard->steps() as $step ) {
            if ( $step->slug() === $slug ) return $step;
        }
        return null;
    }

    private static function transitionOrSubmit( WizardInterface $wizard, WizardStepInterface $current, ?string $next_slug, array $state, int $user_id ): void {
        $slug = $wizard->slug();
        if ( $next_slug === null ) {
            $result = $current->submit( $state );
            if ( is_wp_error( $result ) ) {
                \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $wizard->label() );
                self::renderHeader( $wizard->label() );
                echo '<div class="tt-notice tt-notice-error" role="alert">' . esc_html( $result->get_error_message() ) . '</div>';
                return;
            }
            WizardAnalytics::recordCompleted( $slug );
            WizardState::clear( $user_id, $slug );
            $redirect = (string) ( ( is_array( $result ) ? $result['redirect_url'] : '' ) ?? '' );
            // v3.110.182 (#782) — when the wizard didn't return a
            // redirect_url, fall back to the same robust same-page URL
            // builder that wizardStepUrl() uses, not dashboardBaseUrl()
            // (whose four-stage chain can resolve to a URL that doesn't
            // route to the shortcode on certain installs).
            if ( $redirect === '' ) $redirect = \TT\Shared\Wizards\WizardEntryPoint::currentDashboardUrl();
            wp_safe_redirect( $redirect );
            exit;
        }
        // #0063 — record where the user just was so the Back button on
        // the next step can pop back to it. Tracking actual visit
        // history (rather than computing previous-step from the static
        // list) keeps Back correct under conditional branching.
        WizardState::pushHistory( $user_id, $slug, $current->slug() );
        WizardState::setStep( $user_id, $slug, $next_slug );
        wp_safe_redirect( self::wizardStepUrl( $slug ) );
        exit;
    }

    /**
     * Build the post-step redirect URL.
     *
     * v3.110.172 (#766) — initial fix used REQUEST_URI through esc_url_raw
     * + remove_query_arg, returning a relative URL. Insufficient: pilot
     * (#782) reported the tournament wizard's step-1 → step-2 transition
     * still 404'ing, and the team-blueprint wizard wasn't truly fixed
     * either on this install.
     *
     * v3.110.180 (#782) — robust rewrite. Three changes vs. the v3.110.172
     * shape:
     *
     *   1. Extract the REQUEST_URI path WITHOUT `esc_url_raw`. The URL
     *      validation step was suspected of mangling content on the
     *      pilot's setup (proxy / SSL termination / atypical server
     *      configuration). Manual `strpos`/`substr` on the `?` separator
     *      is unambiguous.
     *
     *   2. Wrap with `home_url($path)` so the result is a fully-qualified
     *      URL on the site's canonical host + scheme. `wp_safe_redirect`
     *      runs targets through `wp_validate_redirect`'s host whitelist;
     *      a relative URL or one on a slightly-different host (e.g.
     *      proxy SSL termination producing a scheme mismatch) silently
     *      falls back to `admin_url()`, which on the pilot install
     *      reads as "page not found" for non-admin users.
     *
     *   3. Drop the `dashboardBaseUrl()` config-chain fallback on the
     *      happy path. The form just POSTed to the current URL, so by
     *      definition REQUEST_URI is a path that routes; the dashboard-
     *      config chain is only relevant when there's no REQUEST_URI
     *      at all (CLI runs / unusual proxy configs).
     */
    private static function wizardStepUrl( string $slug ): string {
        $path = '/';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $raw   = wp_unslash( (string) $_SERVER['REQUEST_URI'] );
            $q_pos = strpos( $raw, '?' );
            $path  = $q_pos === false ? $raw : substr( $raw, 0, $q_pos );
            if ( $path === '' ) $path = '/';
        }
        // #1491 — REQUEST_URI's path is already absolute from the web root
        // and, on a subdirectory install, already includes the WP subdir
        // (e.g. /wordpress/...). Passing it through home_url() prepends the
        // subdir a SECOND time (/wordpress/wordpress/… → 404 on wizard
        // Next). Combine the site's scheme+host (no path) with the request
        // path instead — same fix as currentDashboardUrl() under #1455.
        $home = wp_parse_url( home_url() );
        $base = ! empty( $home['host'] )
            ? ( ( $home['scheme'] ?? 'http' ) . '://' . $home['host']
                . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path )
            : home_url( $path ); // defensive — shouldn't happen
        return add_query_arg(
            [ 'tt_view' => 'wizard', 'tt_wizard' => $slug ],
            $base
        );
    }

    /**
     * #1036 — V3 vertical sidebar rail. Replaces the flat horizontal
     * progress pill row. Desktop (≥720px): rail on the left of the
     * form. Mobile (≤719px): rail collapses to a single disclosure
     * button (`Step X of Y · {current label} ▾`) that expands to show
     * the rail beneath.
     *
     * State classes are preserved from the prior implementation
     * (`is-done`, `is-current`, `is-pending`, `is-na`) — only the
     * markup + CSS structure change. Each step renders as a list
     * item with a dot (left), a label and an optional caption (right).
     * Vertical line between adjacent dots fills teal between two
     * `is-done` steps.
     *
     * The "Edit" link on completed steps (mockup convention) is
     * intentionally NOT emitted — `WizardState` is single-step-back
     * only, so a functional Edit jump-back needs a separate change
     * (out of scope per the issue).
     */
    private static function renderProgress( WizardInterface $wizard, string $current_slug, array $state = [] ): void {
        $steps = $wizard->steps();
        if ( count( $steps ) <= 1 ) return;

        // Pre-pass: resolve state for each step so we can compute the
        // "Step X of Y" label for the mobile toggle without re-walking.
        $resolved = [];
        $found_current = false;
        $current_idx = 0;
        $current_label = '';
        foreach ( $steps as $i => $step ) {
            $is_current = $step->slug() === $current_slug;
            if ( $is_current ) {
                $found_current = true;
                $current_idx = $i;
                $current_label = (string) $step->label();
            }
            $not_applicable = method_exists( $step, 'notApplicableFor' )
                ? (bool) $step->notApplicableFor( $state )
                : false;
            if ( $not_applicable && ! $is_current ) {
                $cls = 'is-na';
            } else {
                $cls = $is_current ? 'is-current' : ( ! $found_current ? 'is-done' : 'is-pending' );
            }
            $resolved[] = [
                'step'  => $step,
                'cls'   => $cls,
                'na'    => $not_applicable,
                'i'     => $i,
            ];
        }

        $total = count( $steps );

        ?>
        <aside class="tt-wizard-rail-region" aria-label="<?php esc_attr_e( 'Wizard steps', 'talenttrack' ); ?>">
            <button type="button"
                    class="tt-wizard-rail-toggle"
                    data-tt-wizard-rail-toggle
                    aria-expanded="false"
                    aria-controls="tt-wizard-rail">
                <span class="tt-wizard-rail-toggle-meta">
                    <?php
                    printf(
                        /* translators: 1: current step number, 2: total step count */
                        esc_html__( 'Step %1$d of %2$d', 'talenttrack' ),
                        (int) ( $current_idx + 1 ),
                        (int) $total
                    );
                    ?>
                </span>
                <span class="tt-wizard-rail-toggle-label"><?php echo esc_html( $current_label ); ?></span>
                <span class="tt-wizard-rail-toggle-chev" aria-hidden="true">▾</span>
            </button>
            <ol class="tt-wizard-rail" id="tt-wizard-rail">
                <?php foreach ( $resolved as $r ) :
                    $is_done    = $r['cls'] === 'is-done';
                    $is_current = $r['cls'] === 'is-current';
                    $is_na      = $r['cls'] === 'is-na';
                    $aria = sprintf(
                        /* translators: 1: step number, 2: step label, 3: state */
                        __( 'Step %1$d: %2$s (%3$s)', 'talenttrack' ),
                        (int) ( $r['i'] + 1 ),
                        (string) $r['step']->label(),
                        $is_done    ? __( 'Completed', 'talenttrack' )
                        : ( $is_current ? __( 'Current',  'talenttrack' )
                        : ( $is_na ? __( 'Not applicable', 'talenttrack' ) : __( 'Pending', 'talenttrack' ) ) )
                    );
                    ?>
                    <li class="<?php echo esc_attr( $r['cls'] ); ?>" aria-label="<?php echo esc_attr( $aria ); ?>">
                        <span class="tt-wizard-rail-dot" aria-hidden="true"></span>
                        <span class="tt-wizard-rail-label"><?php echo esc_html( $r['step']->label() ); ?></span>
                        <?php if ( $is_current ) : ?>
                            <span class="tt-wizard-rail-caption"><?php esc_html_e( 'You are here', 'talenttrack' ); ?></span>
                        <?php elseif ( $is_na ) : ?>
                            <span class="tt-wizard-rail-caption"><?php esc_html_e( 'Not applicable', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </aside>
        <?php
    }

    private static function renderHelpSidebar( WizardInterface $wizard, WizardStepInterface $step ): void {
        $help_topic = self::helpTopicFor( $wizard->slug() );
        if ( ! $help_topic ) return;
        echo '<aside class="tt-wizard-help">';
        \TT\Shared\Frontend\Components\HelpDrawer::button( $help_topic, __( 'Help for this step', 'talenttrack' ) );
        echo '</aside>';
    }

    private static function helpTopicFor( string $wizard_slug ): ?string {
        switch ( $wizard_slug ) {
            case 'new-player':     return 'teams-players';
            case 'new-team':       return 'teams-players';
            case 'new-evaluation': return 'evaluations';
            case 'new-goal':       return 'goals';
            case 'new-activity':   return 'activities';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function sanitisePost( array $post ): array {
        unset( $post['tt_wizard_nonce'], $post['tt_wizard_action'], $post['_cancel_url'] );
        return $post;
    }

    /**
     * #0072 follow-up — enqueue the autosave runtime + localise its
     * config (REST url + nonce + translatable status strings).
     */
    private static function enqueueAutosaveScript( string $slug ): void {
        wp_enqueue_script(
            'tt-wizard-autosave',
            TT_PLUGIN_URL . 'assets/js/wizard-autosave.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-wizard-autosave', 'TT_WizardAutosave', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'slug'       => $slug,
            'i18n_saving' => __( 'Saving…', 'talenttrack' ),
            'i18n_saved'  => __( 'Saved · ', 'talenttrack' ),
            'i18n_failed' => __( 'Save failed', 'talenttrack' ),
        ] );
    }

    /**
     * v3.110.190 (#796) — enqueues the live mandatory-field validator
     * that runs alongside the wizard form. Visual + aria layer; the
     * authoritative `validate()` still runs server-side on Next-click.
     * The form already carries `novalidate` (v3.110.137) to stop the
     * browser-native popup; this script provides the user-facing
     * layer instead.
     */
    private static function enqueueValidationScript(): void {
        wp_enqueue_script(
            'tt-wizard-validation',
            TT_PLUGIN_URL . 'assets/js/wizard-validation.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-wizard-validation', 'TT_WizardValidation', [
            'i18n' => [
                'required'     => __( 'This field is required.', 'talenttrack' ),
                'next_blocked' => __( 'Fill in every required field to continue.', 'talenttrack' ),
            ],
        ] );
    }

    private static bool $styles_enqueued = false;
    private static function enqueueWizardStyles(): void {
        if ( self::$styles_enqueued ) return;
        self::$styles_enqueued = true;
        $css = '
            .tt-wizard-form { max-width: 640px; margin: 0 auto; }

            /* #1036 — V3 chrome: 2-column layout, rail left + form right.
               Mobile-first: single column with the rail collapsed into
               a disclosure button. Desktop (≥720px) splits into a 220px
               rail + 1fr form area. */
            .tt-wizard-layout {
                display: grid;
                grid-template-columns: 1fr;
                gap: 24px;
                margin: 0 0 24px;
            }
            @media (min-width: 720px) {
                .tt-wizard-layout {
                    grid-template-columns: 220px 1fr;
                    align-items: start;
                }
                /* #1514 — single-step wizards emit no rail; drop to one
                   full-width column so the form is not squished into 220px. */
                .tt-wizard-layout[data-has-rail="0"] {
                    grid-template-columns: 1fr;
                }
                .tt-wizard-layout .tt-wizard-form { margin: 0; }
            }
            .tt-wizard-rail-region { min-width: 0; }

            /* Mobile-collapsed rail header. Tap expands the rail
               beneath. Hidden on desktop (rail is always visible). */
            .tt-wizard-rail-toggle {
                display: flex;
                width: 100%;
                padding: 12px 14px;
                background: rgba(29, 120, 116, 0.08);
                border: 1px solid #c8dcdb;
                border-radius: 8px;
                font: inherit;
                font-weight: 600;
                color: #155c5a;
                text-align: left;
                cursor: pointer;
                align-items: center;
                gap: 10px;
            }
            .tt-wizard-rail-toggle-meta {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #5b6e75;
                font-weight: 700;
            }
            .tt-wizard-rail-toggle-label { flex: 1; }
            .tt-wizard-rail-toggle-chev {
                color: #1d7874;
                font-size: 14px;
                transition: transform 120ms;
            }
            .tt-wizard-rail-toggle[aria-expanded="true"] .tt-wizard-rail-toggle-chev {
                transform: rotate(180deg);
            }
            @media (min-width: 720px) {
                .tt-wizard-rail-toggle { display: none; }
            }

            /* Vertical rail */
            .tt-wizard-rail {
                list-style: none;
                margin: 12px 0 0;
                padding: 0;
                position: relative;
            }
            @media (max-width: 719px) {
                .tt-wizard-rail { display: none; }
                .tt-wizard-layout[data-rail-open="true"] .tt-wizard-rail {
                    display: block;
                    margin-bottom: 4px;
                }
            }
            @media (min-width: 720px) {
                .tt-wizard-rail { margin-top: 0; }
            }
            .tt-wizard-rail li {
                position: relative;
                padding: 10px 0 10px 30px;
                font-size: 13px;
                line-height: 1.3;
            }
            /* Vertical line connecting the dots */
            .tt-wizard-rail li::before {
                content: "";
                position: absolute;
                left: 9px;
                top: 24px;
                bottom: -2px;
                width: 2px;
                background: #d6dadd;
                z-index: 1;
            }
            .tt-wizard-rail li:last-child::before { display: none; }
            .tt-wizard-rail li.is-done::before { background: #1d7874; }

            /* The dot */
            .tt-wizard-rail-dot {
                position: absolute;
                left: 0;
                top: 10px;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                background: #fff;
                border: 2px solid #d6dadd;
                z-index: 2;
            }
            .tt-wizard-rail li.is-done .tt-wizard-rail-dot {
                background: #1d7874;
                border-color: #1d7874;
            }
            .tt-wizard-rail li.is-done .tt-wizard-rail-dot::after {
                content: "✓";
                position: absolute;
                inset: 0;
                color: #fff;
                font-size: 11px;
                font-weight: 700;
                text-align: center;
                line-height: 16px;
            }
            .tt-wizard-rail li.is-current .tt-wizard-rail-dot {
                border-color: #1d7874;
                background: #fff;
                box-shadow: 0 0 0 4px rgba(29, 120, 116, 0.15);
            }
            .tt-wizard-rail li.is-current .tt-wizard-rail-dot::after {
                content: "";
                position: absolute;
                inset: 4px;
                background: #1d7874;
                border-radius: 50%;
            }
            .tt-wizard-rail li.is-na .tt-wizard-rail-dot {
                border-style: dashed;
                background: #f0f3f2;
            }

            .tt-wizard-rail-label {
                display: inline-block;
                font-weight: 500;
                color: #5b6e75;
            }
            .tt-wizard-rail li.is-done .tt-wizard-rail-label    { color: #1a1d21; }
            .tt-wizard-rail li.is-current .tt-wizard-rail-label { color: #155c5a; font-weight: 700; font-size: 14px; }
            .tt-wizard-rail li.is-na .tt-wizard-rail-label      { color: #b0b3b6; text-decoration: line-through; }
            .tt-wizard-rail-caption {
                display: block;
                margin-top: 2px;
                font-size: 11px;
                color: #5b6e75;
                font-weight: 400;
            }

            .tt-wizard-step-title { font-size: 1.4rem; margin: 0 0 16px; }
            .tt-wizard-form label { display: block; margin-bottom: 14px; }
            .tt-wizard-form label span { display: block; font-weight: 600; margin-bottom: 4px; }
            .tt-wizard-form input[type=text], .tt-wizard-form input[type=email], .tt-wizard-form input[type=tel], .tt-wizard-form input[type=date], .tt-wizard-form input[type=number], .tt-wizard-form select, .tt-wizard-form textarea { width: 100%; min-height: 48px; padding: 12px 14px; font-size: 16px; border: 1px solid #c4c7c5; border-radius: 8px; box-sizing: border-box; }
            .tt-wizard-form textarea { min-height: 96px; }
            .tt-wizard-form fieldset { border: 1px solid #e0e0e0; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
            .tt-wizard-form legend { font-weight: 600; padding: 0 6px; }
            /* #1036 — V3 actions. Desktop: Cancel left, [Save + Back]
               middle, Next far right, all inside a tinted container.
               Mobile: a 3-row grid (next / back / save · cancel) via
               grid-template-areas so DOM order doesn\'t matter; the
               middle wrapper goes display:contents so its children
               participate in the parent grid directly. */
            .tt-wizard-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                padding: 14px 16px;
                background: #f6f7f7;
                border: 1px solid #e3e6ea;
                border-radius: 8px;
                margin-top: 24px;
            }
            .tt-wizard-actions-middle { display: flex; gap: 4px; margin-left: auto; }
            .tt-wizard-btn-cancel {
                background: #fff;
                border: 1.5px solid #d6dadd;
                color: #5b6e75;
                font: inherit;
                font-size: 14px;
                font-weight: 600;
                padding: 0 16px;
                min-height: 48px;
                border-radius: 8px;
                cursor: pointer;
            }
            .tt-wizard-btn-cancel:hover { border-color: #d63638; color: #d63638; }
            .tt-wizard-btn-text {
                background: transparent;
                border: none;
                color: #5b6e75;
                font: inherit;
                font-size: 13px;
                font-weight: 600;
                padding: 0 14px;
                min-height: 48px;
                border-radius: 8px;
                cursor: pointer;
            }
            .tt-wizard-btn-text:hover { color: #1d7874; background: rgba(29, 120, 116, 0.08); }
            .tt-wizard-btn-primary {
                min-height: 48px;
                padding: 0 26px;
                border-radius: 999px;
                font: inherit;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
                background: #1d7874;
                color: #fff;
                border: 1.5px solid #1d7874;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                line-height: 1;
                transition: background 120ms, transform 120ms;
            }
            .tt-wizard-btn-primary:hover { background: #155c5a; }
            .tt-wizard-btn-primary:active { transform: translateY(1px); }
            .tt-wizard-btn-chev { display: inline-block; transition: transform 120ms; }
            .tt-wizard-btn-primary:hover .tt-wizard-btn-chev { transform: translateX(2px); }
            @media (prefers-reduced-motion: reduce) {
                .tt-wizard-btn-primary, .tt-wizard-btn-primary:active,
                .tt-wizard-btn-primary:hover .tt-wizard-btn-chev { transition: none; transform: none; }
            }

            /* Mobile button grid — unified per mockup. Below 720px the
               row becomes a 3-row grid: Next full-width on top (thumb
               spot), Back full-width below (subordinate), Save + Cancel
               as text-link row at the bottom. data-role attrs map to
               grid-areas, so DOM order doesn\'t matter. */
            @media (max-width: 719px) {
                .tt-wizard-actions {
                    display: grid;
                    grid-template-columns: 1fr 1fr;
                    grid-template-areas:
                        "next   next"
                        "back   back"
                        "save   cancel";
                    gap: 8px;
                    align-items: stretch;
                    background: transparent;
                    border: none;
                    padding: 14px 0 0;
                    border-top: 1px solid #e3e6ea;
                    border-radius: 0;
                }
                .tt-wizard-actions-middle { display: contents; }
                .tt-wizard-actions [data-role="next"] {
                    grid-area: next;
                    width: 100%;
                    justify-content: center;
                }
                .tt-wizard-actions [data-role="back"] {
                    grid-area: back;
                    width: 100%;
                    justify-content: center;
                    background: #fff;
                    border: 1.5px solid #d6dadd;
                    color: #1a1d21;
                    font-size: 15px;
                    font-weight: 600;
                    border-radius: 8px;
                    padding: 0 18px;
                    min-height: 48px;
                }
                .tt-wizard-actions [data-role="save"] {
                    grid-area: save;
                    background: transparent;
                    border: none;
                    color: #5b6e75;
                    font-size: 14px;
                    font-weight: 600;
                    padding: 12px 8px;
                    text-align: center;
                    min-height: 48px;
                    border-radius: 8px;
                }
                .tt-wizard-actions [data-role="save"]:hover {
                    color: #1d7874;
                    background: rgba(29, 120, 116, 0.08);
                }
                .tt-wizard-actions [data-role="cancel"] {
                    grid-area: cancel;
                    background: transparent;
                    border: none;
                    color: #5b6e75;
                    font-size: 14px;
                    font-weight: 600;
                    padding: 12px 8px;
                    text-align: center;
                    min-height: 48px;
                    border-radius: 8px;
                }
                .tt-wizard-actions [data-role="cancel"]:hover {
                    color: #d63638;
                    background: #fdecec;
                }
            }
            .tt-wizard-help { margin: 20px 0; padding: 12px; background: #f8f9fa; border-left: 3px solid #1d7874; border-radius: 4px; }
            .tt-wizard-help-link { color: #1d7874; text-decoration: none; }
            .tt-wizard-autosave-status { font-size: .8125rem; color: #5f6368; margin-top: 8px; min-height: 1.25em; text-align: right; }
            .tt-wizard-autosave-status[data-state="saving"] { color: #5f6368; font-style: italic; }
            .tt-wizard-autosave-status[data-state="saved"]  { color: #137333; }
            .tt-wizard-autosave-status[data-state="error"]  { color: #b91c1c; }
            .tt-pd-resume-banner { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 12px 16px; margin-bottom: 16px; }
            .tt-pd-resume-banner-text { flex: 1 1 auto; min-width: 200px; }
            .tt-pd-eval-progress { margin: 16px 0; }
            .tt-pd-eval-progress-bar { width: 100%; height: 14px; }
            .tt-pd-eval-progress-status { margin: 6px 0 0; font-size: .875rem; color: #5f6368; }

            /* #0080 Wave B4 — RateActorsStep mobile-first card stack.
             * v3.110.75 — collapsed by default with status pill + sticky
             * overall progress. Coach scrolls a list of player rows,
             * taps to expand the one they want to rate. The status pill
             * (Not rated / Rating… / Rated / Skipped) updates live as
             * inputs change; the sticky progress strip at the top
             * counts complete + skipped against the total. */
            .tt-rate-progress {
                position: sticky;
                top: 0;
                z-index: 30;
                background: #1d7874;
                color: #fff;
                padding: 8px 12px;
                border-radius: 6px;
                font-size: .9rem;
                font-weight: 600;
                margin: 0 0 12px;
                text-align: center;
            }
            .tt-rate-roster { display: block; }
            .tt-rate-player { margin: 0 0 8px; border: 1px solid var(--tt-line, #e0e0e0); border-radius: 8px; padding: 0; background: #fff; }
            .tt-rate-player[open] { padding: 0 var(--tt-sp-3, 16px) var(--tt-sp-3, 16px); }
            .tt-rate-player-summary {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                min-height: 56px;
                padding: 8px 14px;
                cursor: pointer;
                list-style: none;
                font-weight: 600;
                font-size: 1.05rem;
                user-select: none;
                touch-action: manipulation;
            }
            .tt-rate-player-summary::-webkit-details-marker { display: none; }
            .tt-rate-player-summary::marker { content: ""; }
            .tt-rate-player-summary::before {
                content: "▸";
                color: var(--tt-muted, #5f6368);
                font-size: 1rem;
                transition: transform 120ms ease;
            }
            .tt-rate-player[open] > .tt-rate-player-summary::before { transform: rotate(90deg); }
            .tt-rate-player-name { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .tt-rate-player-status {
                flex: 0 0 auto;
                font-size: .75rem;
                font-weight: 600;
                letter-spacing: .02em;
                padding: 4px 10px;
                border-radius: 999px;
                text-transform: uppercase;
                background: #f1f3f4;
                color: #5f6368;
                white-space: nowrap;
            }
            .tt-rate-player-status--empty    { background: #f1f3f4; color: #5f6368; }
            .tt-rate-player-status--partial  { background: #fff4d4; color: #92651b; }
            .tt-rate-player-status--complete { background: #cfe7da; color: #137333; }
            .tt-rate-player-status--skipped  { background: #e8e8e8; color: #8a8a8a; text-decoration: line-through; }
            /* #1032 — Guest pill on rows where the player\'s home team
             * differs from the activity\'s team (i.e. on loan for this
             * match). Sits between name + status so it reads as a
             * subordinate qualifier on the player\'s identity. */
            .tt-rate-player-guest {
                flex: 0 0 auto;
                font-size: .7rem;
                font-weight: 600;
                letter-spacing: .02em;
                padding: 3px 8px;
                border-radius: 999px;
                text-transform: uppercase;
                background: #fff4d4;
                color: #92651b;
                white-space: nowrap;
            }
            .tt-rate-grid { display: grid; gap: 12px; margin-top: var(--tt-sp-2, 12px); }
            /* v3.110.125 — was a 2-row grid on mobile (label / control)
             * where the control\'s flex-wrap+full-bleed input made the
             * "/ max" suffix wrap to a third visual row. Now the row
             * is a horizontal flex strip on every viewport: label
             * grows, input is fixed at 80px, suffix sits inline. Saves
             * the second-line wrap entirely. */
            .tt-rate-row {
                display: flex;
                align-items: center;
                gap: 12px;
                flex-wrap: wrap;
            }
            .tt-rate-label { font-weight: 500; font-size: .95rem; flex: 1 1 0; min-width: 120px; }
            .tt-rate-control { display: flex; align-items: center; gap: 8px; flex-wrap: nowrap; flex: 0 0 auto; }
            /* #1270 — narrowed selector to exclude type="range". The
             * legacy `.tt-rate-input` block was authored for a number
             * input; the #1067 component reused the classname on its
             * slider for JS back-compat. The 80px width + border +
             * padding here painted a fake input frame over what
             * should be a CSS-grid-expanding slider lane, leaving the
             * coach with an invisible track on desktop. Excluding
             * type="range" keeps the legacy number-input styling
             * working without clobbering the new slider. */
            .tt-rate-input:not([type="range"]) {
                width: 80px;
                flex: 0 0 80px;
                min-height: 48px;
                font-size: 16px;
                padding: 10px 12px;
                border: 1px solid #c4c7c5;
                border-radius: 8px;
                box-sizing: border-box;
                text-align: center;
            }
            .tt-rate-notes { width: 100%; min-height: 72px; font-size: 16px; padding: 10px 12px; border: 1px solid #c4c7c5; border-radius: 8px; box-sizing: border-box; resize: vertical; }
            .tt-rate-max { color: var(--tt-muted, #5f6368); font-size: 14px; white-space: nowrap; flex-shrink: 0; }
            .tt-rate-skip { display: flex; align-items: center; gap: 8px; min-height: 48px; cursor: pointer; }
            .tt-rate-skip input[type=checkbox] { width: 22px; height: 22px; flex: 0 0 22px; }
            /* v3.110.125 — Basic / Detailed segmented toggle per main
             * category. Replaces the native <details> disclosure with
             * an inline pill control: clicking Detailed reveals the
             * sub-category inputs below. Pure-CSS via a `data-state`
             * attribute the inline JS flips; pill highlights the
             * current state, the other half acts as the affordance
             * to switch. Mobile-first 36px height keeps it within the
             * row\'s vertical rhythm while sitting below the 48px
             * floor (it\'s a secondary mode-toggle, not a primary
             * action button — Apple HIG style guidance permits 32–36
             * for in-form mode toggles). */
            .tt-rate-detail-toggle {
                display: inline-flex;
                margin: 6px 0 0 auto;
                border: 1px solid #c4c7c5;
                border-radius: 999px;
                padding: 2px;
                font-size: 12px;
                line-height: 1.3;
                background: #fff;
                user-select: none;
            }
            .tt-rate-detail-toggle button {
                appearance: none;
                background: transparent;
                border: 0;
                color: var(--tt-muted, #5f6368);
                padding: 4px 12px;
                border-radius: 999px;
                cursor: pointer;
                min-height: 32px;
                font: inherit;
            }
            .tt-rate-detail-toggle button:focus-visible {
                outline: 2px solid var(--tt-accent, #2563eb);
                outline-offset: 1px;
            }
            .tt-rate-detail-toggle[data-state="basic"] button[data-mode="basic"],
            .tt-rate-detail-toggle[data-state="detailed"] button[data-mode="detailed"] {
                background: var(--tt-ink, #1a1d21);
                color: #fff;
            }
            /* The subs panel — hidden by default; revealed when the
             * toggle flips to detailed. Same indent + border-left
             * treatment as the v3.108.4 details, just driven by a
             * data attribute instead of <details>/<summary> chrome. */
            .tt-rate-subs {
                margin: 4px 0 0;
                padding-left: 18px;
                border-left: 2px solid var(--tt-line, #e0e0e0);
                width: 100%;
            }
            .tt-rate-subs[hidden] { display: none; }
            .tt-rate-row--sub { margin-top: 6px; }
            .tt-rate-row--sub .tt-rate-label { font-size: .875rem; color: var(--tt-text, #1a1d21); font-weight: 500; }
            @media (min-width: 720px) {
                .tt-rate-skip-row { justify-content: flex-start; }
            }

            @media (min-width: 768px) {
                .tt-wizard-actions { padding-top: 18px; }
            }

            /* v3.110.126 — sticky-on-mobile wizard action bar removed
               per pilot ask ("the bottom sticky buttons I do not like").
               Pre-fix #0084 Child 3 pinned Previous / Next / Submit to
               the bottom of the viewport via `position: sticky` on
               phones. Pilot prefers them to scroll with the form. The
               buttons still stretch to full width via the existing
               flex layout but no longer hover over the form content.
               Tablet/desktop padding rule kept (it was already non-
               sticky there). */
            @media (max-width: 720px) {
                .tt-wizard-form .tt-wizard-actions {
                    margin-top: 16px;
                }
                .tt-wizard-form .tt-wizard-actions .tt-button[type="submit"],
                .tt-wizard-form .tt-wizard-actions button[type="submit"] {
                    min-height: 48px;
                }
            }

            /* v3.110.190 (#796) — live mandatory-field validation. */
            .tt-wizard-form .tt-input-invalid,
            .tt-wizard-form select.tt-input-invalid,
            .tt-wizard-form textarea.tt-input-invalid {
                border-color: #b32d2e;
                box-shadow: 0 0 0 2px rgba( 179, 45, 46, 0.15 );
            }
            .tt-wizard-error-msg {
                display: block;
                color: #b32d2e;
                font-size: 11px;
                line-height: 1.4;
                margin-top: 4px;
            }
            .tt-wizard-form button[data-tt-wizard-next][disabled] {
                opacity: 0.55;
                cursor: not-allowed;
            }
        ';
        wp_register_style( 'tt-wizard-inline', false, [], TT_VERSION );
        wp_enqueue_style( 'tt-wizard-inline' );
        wp_add_inline_style( 'tt-wizard-inline', $css );
    }
}
