<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Onboarding\Admin\OnboardingHandlers;
use TT\Modules\Onboarding\OnboardingState;

/**
 * OnboardingRestController (#1938) — write surface for the frontend Setup
 * flow (`?tt_view=setup`, FrontendSetupView). Ports the wp-admin first-run
 * onboarding wizard to the frontend without a wp-admin bounce.
 *
 *   POST /onboarding/advance        — leave the welcome step for academy
 *   POST /onboarding/academy        — save academy basics, advance
 *   POST /onboarding/first-team     — create the first team (or skip), advance
 *   POST /onboarding/first-admin    — create the first-admin staff record, advance
 *   POST /onboarding/messaging      — choose which messages the academy sends (or skip), advance
 *   POST /onboarding/profile        — apply an install profile (or skip), advance
 *   POST /onboarding/dashboard-page — create / reuse the dashboard page (or skip), finish
 *   POST /onboarding/reset          — reset state and re-enter at welcome
 *
 * The controller stays thin: every persistence, team / staff creation,
 * role grant, page creation, and state advance lives in OnboardingHandlers
 * / OnboardingState (the Onboarding domain layer). The wp-admin page
 * (`?page=tt-welcome`) and this frontend surface call the same methods, so
 * a future SaaS frontend gets identical behaviour and the bespoke flow is
 * never reimplemented.
 *
 * Every route gates its permission_callback on `tt_edit_settings` (matches
 * OnboardingPage::CAP) — never a role-string compare, never __return_true.
 */
final class OnboardingRestController {

    private const NS  = 'talenttrack/v1';
    private const CAP = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        $routes = [
            'advance'        => 'advance',
            'academy'        => 'academy',
            'first-team'     => 'firstTeam',
            'first-admin'    => 'firstAdmin',
            'messaging'      => 'messaging',
            'profile'        => 'profile',
            'dashboard-page' => 'dashboardPage',
            'reset'          => 'reset',
        ];
        foreach ( $routes as $path => $method ) {
            register_rest_route( self::NS, '/onboarding/' . $path, [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, $method ],
                'permission_callback' => [ __CLASS__, 'canEdit' ],
            ] );
        }
    }

    public static function canEdit(): bool {
        return current_user_can( self::CAP );
    }

    /**
     * Move on from a step whose only action is "I have read this".
     *
     * Two of them. `welcome`, which has nothing to save. And `profile`
     * once it has been applied (#3259): the write already happened, and
     * this is the operator confirming they have seen what it did — the
     * reason the apply deliberately does not advance on its own.
     *
     * Idempotent by keying off the state rather than a `from` parameter:
     * a repeated call from a stale tab reports the current step instead
     * of pushing the flow forward a second time.
     */
    public static function advance( \WP_REST_Request $r ): \WP_REST_Response {
        $state = OnboardingState::get();

        if ( $state['step'] === 'welcome' ) {
            OnboardingState::setStep( 'academy' );
        } elseif ( $state['step'] === 'profile'
            && isset( OnboardingState::payloadFor( 'profile' )['applied'] ) ) {
            OnboardingState::setStep( 'import' );
        }

        return self::stateResponse();
    }

    public static function academy( \WP_REST_Request $r ): \WP_REST_Response {
        $name = sanitize_text_field( (string) ( $r->get_param( 'academy_name' ) ?? '' ) );
        if ( $name === '' ) {
            return RestResponse::error(
                'academy_name_required',
                __( 'An academy name is required.', 'talenttrack' ),
                422
            );
        }
        OnboardingHandlers::saveAcademy( [
            'academy_name'  => (string) ( $r->get_param( 'academy_name' )  ?? '' ),
            'primary_color' => (string) ( $r->get_param( 'primary_color' ) ?? '' ),
            'season_label'  => (string) ( $r->get_param( 'season_label' )  ?? '' ),
            'date_format'   => (string) ( $r->get_param( 'date_format' )    ?? 'Y-m-d' ),
        ] );
        Logger::info( 'rest.onboarding.academy_saved', [ 'user' => get_current_user_id() ] );
        return self::stateResponse();
    }

    public static function firstTeam( \WP_REST_Request $r ): \WP_REST_Response {
        $skip = ! empty( $r->get_param( 'skip' ) );
        if ( $skip ) {
            OnboardingHandlers::skipFirstTeam();
            return self::stateResponse();
        }
        $name = sanitize_text_field( (string) ( $r->get_param( 'team_name' ) ?? '' ) );
        if ( $name === '' ) {
            return RestResponse::error(
                'team_name_required',
                __( 'A team name is required, or skip this step.', 'talenttrack' ),
                422
            );
        }
        OnboardingHandlers::createFirstTeam( [
            'team_name' => (string) ( $r->get_param( 'team_name' ) ?? '' ),
            'age_group' => (string) ( $r->get_param( 'age_group' ) ?? '' ),
        ] );
        Logger::info( 'rest.onboarding.team_created', [ 'user' => get_current_user_id() ] );
        return self::stateResponse();
    }

    public static function firstAdmin( \WP_REST_Request $r ): \WP_REST_Response {
        $first = sanitize_text_field( (string) ( $r->get_param( 'first_name' ) ?? '' ) );
        $last  = sanitize_text_field( (string) ( $r->get_param( 'last_name' ) ?? '' ) );
        if ( $first === '' || $last === '' ) {
            return RestResponse::error(
                'name_required',
                __( 'A first and last name are required.', 'talenttrack' ),
                422
            );
        }
        OnboardingHandlers::createFirstAdmin( [
            'first_name' => (string) ( $r->get_param( 'first_name' ) ?? '' ),
            'last_name'  => (string) ( $r->get_param( 'last_name' ) ?? '' ),
            'grant_role' => ! empty( $r->get_param( 'grant_role' ) ),
        ] );
        Logger::info( 'rest.onboarding.admin_created', [ 'user' => get_current_user_id() ] );
        return self::stateResponse();
    }

    /**
     * #3140 — the messaging step (#3113) on the frontend.
     *
     * Both branches delegate to `OnboardingHandlers`, which is the whole
     * point of the route existing rather than this layer writing the
     * template switch itself: the handler inverts the ticked list against
     * the **registered** switchable set, so a template that was never
     * rendered ends up switched off rather than switched on by omission,
     * and skipping writes nothing at all. Re-deriving either of those here
     * would put #3113's guarantee in two places.
     */
    public static function messaging( \WP_REST_Request $r ): \WP_REST_Response {
        if ( ! empty( $r->get_param( 'skip' ) ) ) {
            OnboardingHandlers::skipMessaging();
            Logger::info( 'rest.onboarding.messaging_skipped', [ 'user' => get_current_user_id() ] );
            return self::stateResponse();
        }

        $enabled = $r->get_param( 'enabled' );
        $result  = OnboardingHandlers::applyMessaging( is_array( $enabled ) ? $enabled : [] );

        Logger::info( 'rest.onboarding.messaging_saved', [
            'user'    => get_current_user_id(),
            'enabled' => count( $result['enabled'] ),
        ] );
        return self::stateResponse();
    }

    /**
     * #3259 — the install-profile step (#3038) on the frontend.
     *
     * Thin on purpose. `OnboardingHandlers::applyProfile()` owns the
     * refusal rule, the payload keys and the completion action, so this
     * surface cannot drift from the wp-admin one — which is what makes
     * starting the step in wp-admin and finishing it here work at all.
     *
     * The `null` return is a refusal, not a failure: either the slug is
     * not a profile, or the install has already been shaped by hand and
     * applying would quietly undo somebody's decisions. 409 rather than
     * 422 — the request is well-formed; the install's state is what
     * rejects it.
     */
    public static function profile( \WP_REST_Request $r ): \WP_REST_Response {
        if ( ! empty( $r->get_param( 'skip' ) ) ) {
            OnboardingHandlers::skipProfile();
            Logger::info( 'rest.onboarding.profile_skipped', [ 'user' => get_current_user_id() ] );
            return self::stateResponse();
        }

        $slug    = sanitize_key( (string) ( $r->get_param( 'profile' ) ?? '' ) );
        $applied = OnboardingHandlers::applyProfile( $slug );

        if ( $applied === null ) {
            return RestResponse::error(
                'profile_not_applicable',
                __( 'That profile could not be applied. Either it does not exist, or this install has already been configured by hand — review the change on the Modules page instead.', 'talenttrack' ),
                409
            );
        }

        Logger::info( 'rest.onboarding.profile_applied', [
            'user'    => get_current_user_id(),
            'profile' => $slug,
            'applied' => $applied['applied'],
        ] );
        return self::stateResponse();
    }

    public static function dashboardPage( \WP_REST_Request $r ): \WP_REST_Response {
        $skip = ! empty( $r->get_param( 'skip' ) );
        if ( $skip ) {
            OnboardingHandlers::skipDashboardPage();
        } else {
            OnboardingHandlers::createDashboardPage();
        }
        Logger::info( 'rest.onboarding.dashboard_done', [
            'user'    => get_current_user_id(),
            'skipped' => $skip,
        ] );
        return self::stateResponse();
    }

    public static function reset( \WP_REST_Request $r ): \WP_REST_Response {
        OnboardingState::reset();
        Logger::info( 'rest.onboarding.reset', [ 'user' => get_current_user_id() ] );
        return self::stateResponse();
    }

    /**
     * Standard envelope reporting the post-mutation state so the frontend
     * can re-render the right step without a second request.
     */
    private static function stateResponse(): \WP_REST_Response {
        $state = OnboardingState::get();
        return RestResponse::success( [
            'step'      => $state['step'],
            'completed' => OnboardingState::isCompleted(),
            'payload'   => $state['payload'],
        ] );
    }
}
