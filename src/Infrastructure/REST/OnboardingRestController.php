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
     * Leave the welcome step for the academy step. Idempotent — if the
     * state has already advanced past welcome it just reports the step.
     */
    public static function advance( \WP_REST_Request $r ): \WP_REST_Response {
        $state = OnboardingState::get();
        if ( $state['step'] === 'welcome' ) {
            OnboardingState::setStep( 'academy' );
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
