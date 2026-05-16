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
        $slug = isset( $_GET['slug'] ) ? sanitize_key( (string) $_GET['slug'] ) : '';
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

        if ( ! empty( $_GET['restart'] ) ) {
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

        $error = null;
        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST['tt_wizard_nonce'] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_wizard_nonce'] ) ), 'tt_wizard_' . $slug . '_' . $current->slug() )
        ) {
            $action = isset( $_POST['tt_wizard_action'] ) ? sanitize_key( (string) $_POST['tt_wizard_action'] ) : 'next';
            switch ( $action ) {
                case 'cancel':
                    // v3.70.1 hotfix — Cancel returns to the page the user
                    // came from (carried as _cancel_url hidden field) rather
                    // than dropping them on the dashboard, so cancelling an
                    // edit-flow wizard lands back on the list / detail they
                    // were viewing. Falls back to dashboard when no
                    // referrer was preserved (e.g. direct entry to the
                    // wizard URL).
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
                    // #0063 — every wizard renders a Back button on
                    // step ≥ 2. Pop the visited-step history so
                    // conditional branches (e.g. NewPlayer's trial
                    // path) round-trip correctly. Fall through to a
                    // re-render at the popped step. Form input on the
                    // current step is intentionally NOT persisted —
                    // Back is "discard this step's edits, return to
                    // the previous one"; persist would happen on Next.
                    $prev = WizardState::popHistory( $user_id, $slug );
                    if ( $prev !== null ) {
                        WizardState::setStep( $user_id, $slug, $prev );
                    }
                    wp_safe_redirect( add_query_arg(
                        [ 'tt_view' => 'wizard', 'slug' => $slug ],
                        \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
                    ) );
                    exit;

                case 'save-as-draft':
                    if ( $wizard instanceof SupportsCancelAsDraft ) {
                        $result = $wizard->cancelAsDraft( $state );
                        if ( is_wp_error( $result ) ) {
                            $error = $result->get_error_message();
                            break;
                        }
                        WizardState::clear( $user_id, $slug );
                        $redirect = (string) ( $result['redirect_url'] ?? \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
                        wp_safe_redirect( $redirect );
                        exit;
                    }
                    // Wizard doesn't support drafts — fall through to a
                    // plain cancel rather than silently doing nothing.
                    WizardState::clear( $user_id, $slug );
                    wp_safe_redirect( \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
                    exit;

                case 'skip':
                    WizardState::recordSkip( $user_id, $slug, $current->slug() );
                    WizardAnalytics::recordSkipped( $slug, $current->slug() );
                    $next_slug = $current->nextStep( $state );
                    self::transitionOrSubmit( $wizard, $current, $next_slug, $state, $user_id );
                    return;

                case 'next':
                default:
                    $post = self::sanitisePost( $_POST );
                    $result = $current->validate( $post, $state );
                    if ( is_wp_error( $result ) ) {
                        $error = $result->get_error_message();
                        break;
                    }
                    $state = WizardState::merge( $user_id, $slug, (array) $result );
                    $next_slug = $current->nextStep( $state );
                    self::transitionOrSubmit( $wizard, $current, $next_slug, $state, $user_id );
                    return;
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
        echo '<form method="post" class="tt-wizard-form">';
        wp_nonce_field( 'tt_wizard_' . $slug . '_' . $current->slug(), 'tt_wizard_nonce' );

        echo '<h2 class="tt-wizard-step-title">' . esc_html( $current->label() ) . '</h2>';
        $current->render( $state );

        self::renderHelpSidebar( $wizard, $current );

        // v3.110.84 — autosave status indicator removed alongside the
        // runtime. Wizards that want a manual "Save as draft" button
        // implement `SupportsCancelAsDraft`; the action bar below
        // renders the button when present.
        echo '<div class="tt-wizard-actions">';
        // #0069 — Cancel must discard the run regardless of unfilled
        // required fields. Save-as-draft and Back already carry
        // formnovalidate; Cancel was the outlier and was tripping
        // browser-side required-field validation when the user wanted
        // to bail out of the wizard.
        // v3.70.1 hotfix — Cancel renders as a regular secondary button
        // (was `tt-button-link` which made it visually disappear into
        // the surrounding text). Still `formnovalidate` so it ignores
        // unfilled required fields per #0069.
        echo '<button type="submit" name="tt_wizard_action" value="cancel" class="tt-button tt-button-secondary" formnovalidate>' . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        if ( $wizard instanceof SupportsCancelAsDraft ) {
            echo '<button type="submit" name="tt_wizard_action" value="save-as-draft" class="tt-button" formnovalidate>' . esc_html__( 'Save as draft', 'talenttrack' ) . '</button>';
        }
        // #0063 — Back button on every step where there's prior history.
        // formnovalidate so a half-filled required field doesn't trap
        // the user; Back discards uncommitted edits by design.
        if ( WizardState::hasHistory( $user_id, $slug ) ) {
            echo '<button type="submit" name="tt_wizard_action" value="back" class="tt-button" formnovalidate>' . esc_html__( 'Back', 'talenttrack' ) . '</button>';
        }
        // v3.85.2 — "Skip step" removed per operator feedback. Steps that
        // are genuinely optional should declare `notApplicableFor()`
        // returning true and be auto-skipped by the wizard runner; a
        // user-clickable Skip button just produced half-filled records
        // without a clear contract for what got persisted.
        $is_last = $current->nextStep( $state ) === null;
        $label   = $is_last ? __( 'Create', 'talenttrack' ) : __( 'Next', 'talenttrack' );
        echo '<button type="submit" name="tt_wizard_action" value="next" class="tt-button tt-button-primary">' . esc_html( $label ) . '</button>';
        echo '</div>';

        echo '<input type="hidden" name="_cancel_url" value="' . esc_attr( $cancel_url ) . '">';
        echo '</form>';
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
            if ( $redirect === '' ) $redirect = \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
            wp_safe_redirect( $redirect );
            exit;
        }
        // #0063 — record where the user just was so the Back button on
        // the next step can pop back to it. Tracking actual visit
        // history (rather than computing previous-step from the static
        // list) keeps Back correct under conditional branching.
        WizardState::pushHistory( $user_id, $slug, $current->slug() );
        WizardState::setStep( $user_id, $slug, $next_slug );
        wp_safe_redirect( add_query_arg( [ 'tt_view' => 'wizard', 'slug' => $slug ], \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() ) );
        exit;
    }

    private static function renderProgress( WizardInterface $wizard, string $current_slug, array $state = [] ): void {
        $steps = $wizard->steps();
        if ( count( $steps ) <= 1 ) return;
        echo '<ol class="tt-wizard-progress" aria-label="' . esc_attr__( 'Wizard steps', 'talenttrack' ) . '">';
        $found_current = false;
        foreach ( $steps as $i => $step ) {
            $is_current = $step->slug() === $current_slug;
            if ( $is_current ) $found_current = true;
            // #0063 — a step can opt-in to a `notApplicableFor( state )`
            // method. NewPlayer's TrialDetailsStep returns true when
            // path != 'trial' so the progress bar greys it out instead
            // of showing a future step the user will never visit.
            $not_applicable = method_exists( $step, 'notApplicableFor' )
                ? (bool) $step->notApplicableFor( $state )
                : false;

            if ( $not_applicable && ! $is_current ) {
                $cls = 'tt-wizard-progress-na';
            } else {
                $cls = $is_current ? 'tt-wizard-progress-current' : ( ! $found_current ? 'tt-wizard-progress-done' : 'tt-wizard-progress-pending' );
            }
            // v3.110.102 — done steps render a `✓` instead of the
            // number. Combined with the v3.110.102 CSS tightening
            // (stronger contrast on `--current`, more obvious dim on
            // `--pending`) this makes the at-a-glance state read
            // without having to compare colour codes. Aria-label still
            // includes the index so screen readers announce step
            // number + label normally.
            $is_done = ( $cls === 'tt-wizard-progress-done' );
            $marker  = $is_done ? '✓' : (string) ( $i + 1 );
            $aria    = sprintf(
                /* translators: 1: step number, 2: step label, 3: state (Completed / Current / Pending / Skipped) */
                __( 'Step %1$d: %2$s (%3$s)', 'talenttrack' ),
                (int) ( $i + 1 ),
                (string) $step->label(),
                $is_done    ? __( 'Completed', 'talenttrack' )
                : ( $is_current ? __( 'Current',  'talenttrack' )
                : ( $not_applicable ? __( 'Skipped', 'talenttrack' ) : __( 'Pending', 'talenttrack' ) ) )
            );
            echo '<li class="' . esc_attr( $cls ) . '" aria-label="' . esc_attr( $aria ) . '">'
                . '<span class="tt-wizard-progress-num" aria-hidden="true">' . esc_html( $marker ) . '</span> '
                . esc_html( $step->label() )
                . '</li>';
        }
        echo '</ol>';
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

    private static bool $styles_enqueued = false;
    private static function enqueueWizardStyles(): void {
        if ( self::$styles_enqueued ) return;
        self::$styles_enqueued = true;
        $css = '
            .tt-wizard-form { max-width: 640px; margin: 0 auto; }
            .tt-wizard-progress { display: flex; gap: 8px; padding: 0; margin: 0 0 24px; list-style: none; flex-wrap: wrap; }
            /* v3.110.102 — progress-step contrast pass. Done = solid
               green + checkmark; Current = primary teal with a 2px
               outer ring so it pops; Pending = noticeably dimmer; NA
               keeps its line-through. The marker circle is filled with
               more contrast (rgba 0.85 white) so the digit / checkmark
               reads on every state. */
            .tt-wizard-progress li {
                flex: 1 1 auto;
                padding: 8px 12px;
                border-radius: 6px;
                background: #eef0f2;
                color: #6b7280;
                font-size: .9rem;
                font-weight: 500;
                transition: background-color 120ms ease, color 120ms ease, box-shadow 120ms ease;
            }
            .tt-wizard-progress-current {
                background: #1d7874;
                color: #fff;
                font-weight: 700;
                box-shadow: 0 0 0 2px rgba(29, 120, 116, 0.30);
            }
            .tt-wizard-progress-done {
                background: #137333;
                color: #fff;
                font-weight: 600;
            }
            .tt-wizard-progress-pending {
                background: #eef0f2;
                color: #9ca3af;
            }
            .tt-wizard-progress-na {
                background: #f6f7f8;
                color: #b0b3b6;
                opacity: 0.55;
                text-decoration: line-through;
            }
            .tt-wizard-progress-num {
                display: inline-block;
                width: 22px;
                height: 22px;
                line-height: 22px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.85);
                color: inherit;
                text-align: center;
                margin-right: 6px;
                font-weight: 700;
            }
            .tt-wizard-progress-done .tt-wizard-progress-num,
            .tt-wizard-progress-current .tt-wizard-progress-num {
                color: #1a1a1a;
            }
            .tt-wizard-progress-pending .tt-wizard-progress-num {
                background: rgba(255, 255, 255, 0.65);
                color: #9ca3af;
            }
            .tt-wizard-step-title { font-size: 1.4rem; margin: 0 0 16px; }
            .tt-wizard-form label { display: block; margin-bottom: 14px; }
            .tt-wizard-form label span { display: block; font-weight: 600; margin-bottom: 4px; }
            .tt-wizard-form input[type=text], .tt-wizard-form input[type=email], .tt-wizard-form input[type=tel], .tt-wizard-form input[type=date], .tt-wizard-form input[type=number], .tt-wizard-form select, .tt-wizard-form textarea { width: 100%; min-height: 48px; padding: 12px 14px; font-size: 16px; border: 1px solid #c4c7c5; border-radius: 8px; box-sizing: border-box; }
            .tt-wizard-form textarea { min-height: 96px; }
            .tt-wizard-form fieldset { border: 1px solid #e0e0e0; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
            .tt-wizard-form legend { font-weight: 600; padding: 0 6px; }
            .tt-wizard-actions { display: flex; gap: 12px; flex-wrap: wrap; justify-content: flex-end; padding-top: 12px; border-top: 1px solid #e0e0e0; margin-top: 20px; }
            .tt-wizard-actions .tt-button { min-height: 48px; padding: 12px 20px; font-size: 1rem; }
            .tt-wizard-actions .tt-button-link { background: transparent; border: none; color: #5f6368; padding: 12px; }
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
            .tt-rate-grid { display: grid; gap: 12px; margin-top: var(--tt-sp-2, 12px); }
            /* v3.110.125 — was a 2-row grid on mobile (label / control)
             * where the control's flex-wrap+full-bleed input made the
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
            .tt-rate-input {
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
             * row's vertical rhythm while sitting below the 48px
             * floor (it's a secondary mode-toggle, not a primary
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

            /* #0084 Child 3 — wizard action bar becomes a sticky CTA on
               phones, mirroring the `.tt-mobile-cta-bar` component
               registered in `assets/css/mobile-patterns.css`. The wizard
               aggregator slug is classified `native` so the pattern
               library is enqueued — but the rule below applies even
               without it because wizards stand alone in this stylesheet.
               Submit / Next stays visible while the coach scrolls long
               forms (closes the v3.78.0 RateActorsStep deferred polish
               from #0072). */
            @media (max-width: 720px) {
                .tt-wizard-form .tt-wizard-actions {
                    position: sticky;
                    bottom: 0;
                    background: #fff;
                    margin: 16px -12px 0;
                    padding: 12px 12px calc(12px + env(safe-area-inset-bottom, 0)) 12px;
                    border-top: 1px solid #e0e0e0;
                    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.04);
                    z-index: 50;
                    justify-content: stretch;
                }
                .tt-wizard-form .tt-wizard-actions .tt-button[type="submit"],
                .tt-wizard-form .tt-wizard-actions button[type="submit"] {
                    flex: 1 1 100%;
                    min-height: 48px;
                }
            }
        ';
        wp_register_style( 'tt-wizard-inline', false, [], TT_VERSION );
        wp_enqueue_style( 'tt-wizard-inline' );
        wp_add_inline_style( 'tt-wizard-inline', $css );
    }
}
