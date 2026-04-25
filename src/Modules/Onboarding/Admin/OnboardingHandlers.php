<?php
namespace TT\Modules\Onboarding\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
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

    /* ═══════════════ Step submit handlers ═══════════════ */

    public static function handleAdvance(): void {
        self::guard( 'tt_onboarding_advance' );
        $from = isset( $_GET['from'] ) ? sanitize_key( (string) $_GET['from'] ) : '';
        if ( $from === 'welcome' ) {
            OnboardingState::setStep( 'academy' );
        } elseif ( $from === 'first_team' && isset( $_GET['skip'] ) ) {
            OnboardingState::setStep( 'first_admin' );
            do_action( 'tt_onboarding_step_completed', 'first_team', [ 'skipped' => true ] );
        }
        self::redirectToPage();
    }

    public static function handleAcademy(): void {
        self::guard( 'tt_onboarding_academy' );

        $payload = [
            'academy_name'  => sanitize_text_field( wp_unslash( (string) ( $_POST['academy_name']  ?? '' ) ) ),
            'primary_color' => sanitize_hex_color( (string) wp_unslash( (string) ( $_POST['primary_color'] ?? '' ) ) ) ?: '#0b3d2e',
            'season_label'  => sanitize_text_field( wp_unslash( (string) ( $_POST['season_label']  ?? '' ) ) ),
            'date_format'   => sanitize_text_field( wp_unslash( (string) ( $_POST['date_format']   ?? 'Y-m-d' ) ) ),
        ];

        QueryHelpers::set_config( 'academy_name',     $payload['academy_name'] );
        QueryHelpers::set_config( 'primary_color',    $payload['primary_color'] );
        QueryHelpers::set_config( 'season_label',     $payload['season_label'] );
        QueryHelpers::set_config( 'date_format_pref', $payload['date_format'] );

        OnboardingState::recordPayload( 'academy', $payload );
        OnboardingState::setStep( 'first_team' );
        do_action( 'tt_onboarding_step_completed', 'academy', $payload );

        self::redirectToPage( [ 'tt_ob_msg' => 'saved' ] );
    }

    public static function handleFirstTeam(): void {
        self::guard( 'tt_onboarding_first_team' );

        $name      = sanitize_text_field( wp_unslash( (string) ( $_POST['team_name'] ?? '' ) ) );
        $age_group = sanitize_text_field( wp_unslash( (string) ( $_POST['age_group'] ?? '' ) ) );

        if ( $name === '' ) {
            self::redirectToPage();
            return;
        }

        global $wpdb;
        $wpdb->insert(
            "{$wpdb->prefix}tt_teams",
            [
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

        self::redirectToPage( [ 'tt_ob_msg' => 'team_made' ] );
    }

    public static function handleFirstAdmin(): void {
        self::guard( 'tt_onboarding_first_admin' );

        $first_name = sanitize_text_field( wp_unslash( (string) ( $_POST['first_name'] ?? '' ) ) );
        $last_name  = sanitize_text_field( wp_unslash( (string) ( $_POST['last_name']  ?? '' ) ) );
        $grant_role = ! empty( $_POST['grant_role'] );

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

        if ( $grant_role && $user instanceof \WP_User && ! in_array( 'tt_club_admin', (array) $user->roles, true ) ) {
            $user->add_role( 'tt_club_admin' );
        }

        $payload = [
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'grant_role' => $grant_role,
            'person_id'  => $person_id,
        ];
        OnboardingState::recordPayload( 'first_admin', $payload );
        OnboardingState::setStep( 'done' );
        OnboardingState::markCompleted();
        do_action( 'tt_onboarding_step_completed', 'first_admin', $payload );

        self::redirectToPage( [ 'tt_ob_msg' => 'admin_made' ] );
    }

    /* ═══════════════ Auxiliary handlers ═══════════════ */

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

        // Look for an existing page with the shortcode before creating
        // a new one — re-running this should not produce duplicates.
        $existing = get_posts( [
            'post_type'   => 'page',
            'post_status' => [ 'publish', 'draft', 'private' ],
            'numberposts' => 1,
            's'           => '[talenttrack_dashboard]',
        ] );
        if ( ! empty( $existing ) ) {
            $page_id = (int) $existing[0]->ID;
        } else {
            $page_id = wp_insert_post( [
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => __( 'Dashboard', 'talenttrack' ),
                'post_content' => '[talenttrack_dashboard]',
            ] );
        }

        $url = is_int( $page_id ) && $page_id > 0
            ? get_permalink( $page_id )
            : admin_url( 'admin.php?page=talenttrack' );
        wp_safe_redirect( $url ?: admin_url( 'admin.php?page=talenttrack' ) );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

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
