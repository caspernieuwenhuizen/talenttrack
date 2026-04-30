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
            self::renderHeader( __( 'Wizard not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Unknown wizard.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ! WizardRegistry::isAvailable( $slug, $user_id ) ) {
            self::renderHeader( $wizard->label() );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to use this wizard, or it is currently disabled.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueWizardStyles();

        if ( ! empty( $_GET['restart'] ) ) {
            WizardState::clear( $user_id, $slug );
        }

        $state = WizardState::load( $user_id, $slug );
        if ( ! $state ) {
            $state = WizardState::start( $user_id, $slug, $wizard->firstStepSlug() );
            WizardAnalytics::recordStarted( $slug );
        }

        $current_slug = (string) $state['_step'];
        $current      = self::stepFor( $wizard, $current_slug ) ?: self::stepFor( $wizard, $wizard->firstStepSlug() );
        if ( ! $current ) {
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
                    WizardState::clear( $user_id, $slug );
                    wp_safe_redirect( \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
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

        self::renderHeader( $wizard->label() );
        self::renderProgress( $wizard, $current_slug, $state );
        if ( $error ) {
            echo '<div class="tt-notice tt-notice-error" role="alert">' . esc_html( $error ) . '</div>';
        }

        $cancel_url = remove_query_arg( [ 'slug', 'restart' ] );
        echo '<form method="post" class="tt-wizard-form">';
        wp_nonce_field( 'tt_wizard_' . $slug . '_' . $current->slug(), 'tt_wizard_nonce' );

        echo '<h2 class="tt-wizard-step-title">' . esc_html( $current->label() ) . '</h2>';
        $current->render( $state );

        self::renderHelpSidebar( $wizard, $current );

        echo '<div class="tt-wizard-actions">';
        // #0069 — Cancel must discard the run regardless of unfilled
        // required fields. Save-as-draft and Back already carry
        // formnovalidate; Cancel was the outlier and was tripping
        // browser-side required-field validation when the user wanted
        // to bail out of the wizard.
        echo '<button type="submit" name="tt_wizard_action" value="cancel" class="tt-button tt-button-link" formnovalidate>' . esc_html__( 'Cancel', 'talenttrack' ) . '</button>';
        if ( $wizard instanceof SupportsCancelAsDraft ) {
            echo '<button type="submit" name="tt_wizard_action" value="save-as-draft" class="tt-button" formnovalidate>' . esc_html__( 'Save as draft', 'talenttrack' ) . '</button>';
        }
        // #0063 — Back button on every step where there's prior history.
        // formnovalidate so a half-filled required field doesn't trap
        // the user; Back discards uncommitted edits by design.
        if ( WizardState::hasHistory( $user_id, $slug ) ) {
            echo '<button type="submit" name="tt_wizard_action" value="back" class="tt-button" formnovalidate>' . esc_html__( 'Back', 'talenttrack' ) . '</button>';
        }
        echo '<button type="submit" name="tt_wizard_action" value="skip" class="tt-button">' . esc_html__( 'Skip step', 'talenttrack' ) . '</button>';
        $is_last = $current->nextStep( $state ) === null;
        $label   = $is_last ? __( 'Create', 'talenttrack' ) : __( 'Next', 'talenttrack' );
        echo '<button type="submit" name="tt_wizard_action" value="next" class="tt-button tt-button-primary">' . esc_html( $label ) . '</button>';
        echo '</div>';

        echo '<input type="hidden" name="_cancel_url" value="' . esc_attr( $cancel_url ) . '">';
        echo '</form>';
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
            echo '<li class="' . esc_attr( $cls ) . '"><span class="tt-wizard-progress-num">' . (int) ( $i + 1 ) . '</span> ' . esc_html( $step->label() ) . '</li>';
        }
        echo '</ol>';
    }

    private static function renderHelpSidebar( WizardInterface $wizard, WizardStepInterface $step ): void {
        $help_topic = self::helpTopicFor( $wizard->slug() );
        if ( ! $help_topic ) return;
        $url = add_query_arg( [ 'tt_view' => 'docs', 'topic' => $help_topic ], \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
        echo '<aside class="tt-wizard-help">';
        echo '<a class="tt-wizard-help-link" target="_blank" rel="noopener" href="' . esc_url( $url ) . '">' . esc_html__( 'Open the relevant help topic', 'talenttrack' ) . '</a>';
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

    private static bool $styles_enqueued = false;
    private static function enqueueWizardStyles(): void {
        if ( self::$styles_enqueued ) return;
        self::$styles_enqueued = true;
        $css = '
            .tt-wizard-form { max-width: 640px; margin: 0 auto; }
            .tt-wizard-progress { display: flex; gap: 8px; padding: 0; margin: 0 0 24px; list-style: none; flex-wrap: wrap; }
            .tt-wizard-progress li { flex: 1 1 auto; padding: 8px 12px; border-radius: 6px; background: #f1f3f4; color: #5f6368; font-size: .9rem; }
            .tt-wizard-progress-current { background: #1d7874; color: #fff; }
            .tt-wizard-progress-done { background: #cfe7da; color: #137333; }
            .tt-wizard-progress-na { background: #f6f7f8; color: #b0b3b6; opacity: 0.5; text-decoration: line-through; }
            .tt-wizard-progress-num { display: inline-block; width: 22px; height: 22px; line-height: 22px; border-radius: 50%; background: rgba(255,255,255,.5); color: inherit; text-align: center; margin-right: 6px; font-weight: 600; }
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
            @media (min-width: 768px) {
                .tt-wizard-actions { padding-top: 18px; }
            }
        ';
        wp_register_style( 'tt-wizard-inline', false, [], TT_VERSION );
        wp_enqueue_style( 'tt-wizard-inline' );
        wp_add_inline_style( 'tt-wizard-inline', $css );
    }
}
