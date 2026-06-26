<?php
namespace TT\Modules\Onboarding\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Onboarding\OnboardingState;

/**
 * OnboardingHandlers — admin-post.php endpoints for the wizard.
 *
 * Each handler:
 *   - verifies the nonce + capability
 *   - persists the step's payload via OnboardingState
 *   - performs the step's side effects (write tt_config, create team, etc.)
 *   - fires the per-step `tt_onboarding_step_completed` action
 *   - advances the state machine and redirects back to the page
 *
 * No HTML output — all rendering is in OnboardingPage.
 */
class OnboardingHandlers {

    private const NONCE_FIELD = 'tt_onboarding_nonce';
    private const CAP         = 'tt_edit_settings';

    public static function init(): void {
        add_action( 'admin_post_tt_onboarding_advance',              [ self::class, 'handleAdvance' ] );
        add_action( 'admin_post_tt_onboarding_academy',              [ self::class, 'handleAcademy' ] );
        add_action( 'admin_post_tt_onboarding_first_team',           [ self::class, 'handleFirstTeam' ] );
        add_action( 'admin_post_tt_onboarding_first_admin',          [ self::class, 'handleFirstAdmin' ] );
        add_action( 'admin_post_tt_onboarding_reset',                [ self::class, 'handleReset' ] );
        add_action( 'admin_post_tt_onboarding_dismiss',              [ self::class, 'handleDismiss' ] );
        add_action( 'admin_post_tt_onboarding_demo',                 [ self::class, 'handleDemo' ] );
        add_action( 'admin_post_tt_onboarding_create_dashboard_page',[ self::class, 'handleCreateDashboardPage' ] );
    }

    // Step submit handlers

    public static function handleAdvance(): void {
        self::guard( 'tt_onboarding_advance' );
        $from = isset( $_GET['from'] ) ? sanitize_key( (string) $_GET['from'] ) : '';
        if ( $from === 'welcome' ) {
            OnboardingState::setStep( 'academy' );
        } elseif ( $from === 'first_team' && isset( $_GET['skip'] ) ) {
            OnboardingState::setStep( 'first_admin' );
            do_action( 'tt_onboarding_step_completed', 'first_team', [ 'skipped' => true ] );
        } elseif ( $from === 'dashboard' && isset( $_GET['skip'] ) ) {
            // Skipping page creation still finishes the wizard.
            OnboardingState::setStep( 'done' );
            OnboardingState::markCompleted();
            do_action( 'tt_onboarding_step_completed', 'dashboard', [ 'skipped' => true ] );
        }
        self::redirectToPage();
    }

    public static function handleAcademy(): void {
        self::guard( 'tt_onboarding_academy' );

        self::saveAcademy( [
            'academy_name'  => (string) ( $_POST['academy_name']  ?? '' ),
            'primary_color' => (string) ( $_POST['primary_color'] ?? '' ),
            'season_label'  => (string) ( $_POST['season_label']  ?? '' ),
            'date_format'   => (string) ( $_POST['date_format']   ?? 'Y-m-d' ),
        ] );

        self::redirectToPage( [ 'tt_ob_msg' => 'saved' ] );
    }

    public static function handleFirstTeam(): void {
        self::guard( 'tt_onboarding_first_team' );

        $name = sanitize_text_field( wp_unslash( (string) ( $_POST['team_name'] ?? '' ) ) );
        if ( $name === '' ) {
            self::redirectToPage();
            return;
        }

        self::createFirstTeam( [
            'team_name' => (string) ( $_POST['team_name'] ?? '' ),
            'age_group' => (string) ( $_POST['age_group'] ?? '' ),
        ] );

        self::redirectToPage( [ 'tt_ob_msg' => 'team_made' ] );
    }

    public static function handleFirstAdmin(): void {
        self::guard( 'tt_onboarding_first_admin' );

        self::createFirstAdmin( [
            'first_name' => (string) ( $_POST['first_name'] ?? '' ),
            'last_name'  => (string) ( $_POST['last_name']  ?? '' ),
            'grant_role' => ! empty( $_POST['grant_role'] ),
        ] );

        self::redirectToPage( [ 'tt_ob_msg' => 'admin_made' ] );
    }

    // Domain side-effects — shared between the wp-admin handlers above and
    // the frontend REST controller (OnboardingRestController, #1938). The
    // request-shape parsing (nonce, $_POST, redirect) stays in the handlers;
    // the persistence + state advance + step-completed hook live here so the
    // two surfaces never drift.

    /**
     * Persist the academy basics, advance to first_team, fire the hook.
     *
     * @param array{academy_name?:string,primary_color?:string,season_label?:string,date_format?:string} $input
     * @return array<string,mixed> The recorded payload.
     */
    public static function saveAcademy( array $input ): array {
        $payload = [
            'academy_name'  => sanitize_text_field( wp_unslash( (string) ( $input['academy_name']  ?? '' ) ) ),
            'primary_color' => sanitize_hex_color( (string) wp_unslash( (string) ( $input['primary_color'] ?? '' ) ) ) ?: '#0b3d2e',
            'season_label'  => sanitize_text_field( wp_unslash( (string) ( $input['season_label']  ?? '' ) ) ),
            'date_format'   => sanitize_text_field( wp_unslash( (string) ( $input['date_format']   ?? 'Y-m-d' ) ) ),
        ];

        QueryHelpers::set_config( 'academy_name',     $payload['academy_name'] );
        QueryHelpers::set_config( 'primary_color',    $payload['primary_color'] );
        QueryHelpers::set_config( 'season_label',     $payload['season_label'] );
        QueryHelpers::set_config( 'date_format_pref', $payload['date_format'] );

        OnboardingState::recordPayload( 'academy', $payload );
        OnboardingState::setStep( 'first_team' );
        do_action( 'tt_onboarding_step_completed', 'academy', $payload );

        return $payload;
    }

    /**
     * Create the first team, advance to first_admin, fire the hook.
     *
     * @param array{team_name?:string,age_group?:string} $input
     * @return array<string,mixed> The recorded payload (incl. team_id).
     */
    public static function createFirstTeam( array $input ): array {
        $name      = sanitize_text_field( wp_unslash( (string) ( $input['team_name'] ?? '' ) ) );
        $age_group = sanitize_text_field( wp_unslash( (string) ( $input['age_group'] ?? '' ) ) );

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}tt_teams",
            [
                'club_id'   => CurrentClub::id(),
                'name'      => $name,
                'age_group' => $age_group,
                'created_at'=> current_time( 'mysql', true ),
            ]
        );
        $team_id = (int) $wpdb->insert_id;

        $payload = [ 'team_name' => $name, 'age_group' => $age_group, 'team_id' => $team_id ];
        OnboardingState::recordPayload( 'first_team', $payload );
        OnboardingState::setStep( 'first_admin' );
        do_action( 'tt_onboarding_step_completed', 'first_team', $payload );

        return $payload;
    }

    /**
     * Skip the first-team step (no team created), advance to first_admin.
     *
     * @return array<string,mixed>
     */
    public static function skipFirstTeam(): array {
        OnboardingState::setStep( 'first_admin' );
        do_action( 'tt_onboarding_step_completed', 'first_team', [ 'skipped' => true ] );
        return [ 'skipped' => true ];
    }

    /**
     * Create the first-admin staff record (+ optional Club Admin grant),
     * advance to dashboard, fire the hook.
     *
     * @param array{first_name?:string,last_name?:string,grant_role?:bool} $input
     * @return array<string,mixed> The recorded payload (incl. person_id).
     */
    public static function createFirstAdmin( array $input ): array {
        $first_name = sanitize_text_field( wp_unslash( (string) ( $input['first_name'] ?? '' ) ) );
        $last_name  = sanitize_text_field( wp_unslash( (string) ( $input['last_name']  ?? '' ) ) );
        $grant_role = ! empty( $input['grant_role'] );

        $user_id = get_current_user_id();
        $user    = $user_id > 0 ? get_userdata( $user_id ) : null;
        $email   = $user ? (string) $user->user_email : '';

        $repo      = new PeopleRepository();
        $person_id = $repo->create( [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'wp_user_id' => $user_id > 0 ? $user_id : null,
            'status'     => 'active',
        ] );

        if ( $grant_role && $user instanceof \WP_User
             && ! \TT\Infrastructure\Security\RoleResolver::userHasRole( (int) $user->ID, 'tt_club_admin' ) ) {
            $user->add_role( 'tt_club_admin' );
        }

        $payload = [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'grant_role' => $grant_role,
            'person_id'  => $person_id,
        ];
        OnboardingState::recordPayload( 'first_admin', $payload );
        OnboardingState::setStep( 'dashboard' );
        do_action( 'tt_onboarding_step_completed', 'first_admin', $payload );

        return $payload;
    }

    // Auxiliary handlers

    public static function handleReset(): void {
        self::guard( 'tt_onboarding_reset' );
        OnboardingState::reset();
        self::redirectToPage( [ 'tt_ob_msg' => 'reset', 'force_welcome' => '1' ] );
    }

    public static function handleDismiss(): void {
        self::guard( 'tt_onboarding_dismiss' );
        OnboardingState::setDismissed( true );
        wp_safe_redirect( admin_url( 'admin.php?page=talenttrack' ) );
        exit;
    }

    public static function handleDemo(): void {
        self::guard( 'tt_onboarding_demo' );
        // Deep-link to the existing Tools → TalentTrack Demo page where
        // the admin picks a preset, domain, and password rather than us
        // guessing sensible defaults. The wizard is dismissed so the
        // admin lands cleanly on the dashboard after generating.
        OnboardingState::setDismissed( true );
        wp_safe_redirect( admin_url( 'tools.php?page=tt-demo-data' ) );
        exit;
    }

    public static function handleCreateDashboardPage(): void {
        self::guard( 'tt_onboarding_create_dashboard_page' );
        self::createDashboardPage();
        self::redirectToPage( [ 'tt_ob_msg' => 'page_made' ] );
    }

    /**
     * Create (or reuse) the frontend dashboard page, set it as the site
     * homepage, finish the wizard. Shared with OnboardingRestController.
     *
     * @return array<string,mixed> The recorded payload (page_id + page_url).
     */
    public static function createDashboardPage(): array {
        // Reuse an existing page that already holds the shortcode so
        // re-running never produces duplicates.
        $existing = get_posts( [
            'post_type'   => 'page',
            'post_status' => [ 'publish', 'draft', 'private' ],
            'numberposts' => 1,
            's'           => '[talenttrack_dashboard]',
        ] );
        if ( ! empty( $existing ) ) {
            $page_id = (int) $existing[0]->ID;
            // Make sure a reused draft is publicly reachable as the homepage.
            if ( get_post_status( $page_id ) !== 'publish' ) {
                wp_update_post( [ 'ID' => $page_id, 'post_status' => 'publish' ] );
            }
        } else {
            $page_id = (int) wp_insert_post( [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => __( 'Dashboard', 'talenttrack' ),
                // #1457 — wrap in an alignfull group so block themes (which
                // constrain post content to ~645px) render the dashboard
                // full-width; the plugin CSS then caps it at 1600px.
                'post_content' => "<!-- wp:group {\"align\":\"full\"} -->\n<div class=\"wp-block-group alignfull\">[talenttrack_dashboard]</div>\n<!-- /wp:group -->",
            ] );
        }

        $page_url = '';
        if ( $page_id > 0 ) {
            // Set the dashboard page as the site front page so the root URL
            // lands on it (#1441).
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', $page_id );
            // #1462 — pin the link-builder to this page so internal
            // dashboard links and the homepage can't drift apart.
            QueryHelpers::set_config( 'dashboard_page_id', (string) $page_id );
            $page_url = (string) get_permalink( $page_id );
        }

        $payload = [ 'page_id' => $page_id, 'page_url' => $page_url ];
        OnboardingState::recordPayload( 'dashboard', $payload );
        OnboardingState::setStep( 'done' );
        OnboardingState::markCompleted();
        do_action( 'tt_onboarding_step_completed', 'dashboard', [ 'page_id' => $page_id ] );

        return $payload;
    }

    /**
     * Skip the dashboard-page step — still finishes the wizard. Shared
     * with OnboardingRestController.
     *
     * @return array<string,mixed>
     */
    public static function skipDashboardPage(): array {
        OnboardingState::setStep( 'done' );
        OnboardingState::markCompleted();
        do_action( 'tt_onboarding_step_completed', 'dashboard', [ 'skipped' => true ] );
        return [ 'skipped' => true ];
    }

    // Helpers

    private static function guard( string $action ): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( $action, self::NONCE_FIELD );
    }

    /** @param array<string,scalar> $extra */
    private static function redirectToPage( array $extra = [] ): void {
        $url = add_query_arg(
            array_merge( [ 'page' => OnboardingPage::SLUG ], $extra ),
            admin_url( 'admin.php' )
        );
        wp_safe_redirect( $url );
        exit;
    }
}
