<?php
namespace TT\Modules\Onboarding\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Import\ImportService;
use TT\Modules\Invitations\InvitationKind;
use TT\Modules\Invitations\InvitationService;
use TT\Modules\Onboarding\OnboardingState;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

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
        add_action( 'admin_post_tt_onboarding_profile',              [ self::class, 'handleProfile' ] );
        add_action( 'admin_post_tt_onboarding_skip_profile',         [ self::class, 'handleSkipProfile' ] );
        add_action( 'admin_post_tt_onboarding_import',               [ self::class, 'handleImport' ] );
        add_action( 'admin_post_tt_onboarding_roster_template',      [ self::class, 'handleRosterTemplate' ] );
        add_action( 'admin_post_tt_onboarding_staff',                [ self::class, 'handleStaff' ] );
        add_action( 'admin_post_tt_onboarding_skip_staff',           [ self::class, 'handleSkipStaff' ] );
        add_action( 'admin_post_tt_onboarding_send_invites',         [ self::class, 'handleSendInvites' ] );
        add_action( 'admin_post_tt_onboarding_messaging',            [ self::class, 'handleMessaging' ] );
        add_action( 'admin_post_tt_onboarding_skip_messaging',       [ self::class, 'handleSkipMessaging' ] );
        add_action( 'admin_post_tt_onboarding_skip_import',          [ self::class, 'handleSkipImport' ] );
        add_action( 'admin_post_tt_onboarding_first_team',           [ self::class, 'handleFirstTeam' ] );
        add_action( 'admin_post_tt_onboarding_first_admin',          [ self::class, 'handleFirstAdmin' ] );
        add_action( 'admin_post_tt_onboarding_reset',                [ self::class, 'handleReset' ] );
        add_action( 'admin_post_tt_onboarding_dismiss',              [ self::class, 'handleDismiss' ] );
        add_action( 'admin_post_tt_onboarding_demo',                 [ self::class, 'handleDemo' ] );
        add_action( 'admin_post_tt_onboarding_create_dashboard_page',[ self::class, 'handleCreateDashboardPage' ] );
        add_action( 'admin_post_tt_onboarding_finish',               [ self::class, 'handleFinish' ] );
    }

    // Step submit handlers

    public static function handleAdvance(): void {
        self::guard( 'tt_onboarding_advance' );
        $from = isset( $_GET['from'] ) ? sanitize_key( (string) $_GET['from'] ) : '';
        if ( $from === 'welcome' ) {
            OnboardingState::setStep( 'academy' );
        } elseif ( $from === 'profile' ) {
            // #3038 — Continue from the applied-summary half of the
            // profile step. The apply already happened; this only moves on.
            OnboardingState::setStep( 'import' );
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

    /**
     * The install-profile step (#3038).
     *
     * On a fresh install the diff is uncontroversial — nothing has been
     * configured yet, so there is nothing an apply could quietly undo —
     * and the step applies directly. On an install somebody has already
     * shaped, it refuses and leaves them to the preview screen, which is
     * the only surface that shows the diff before writing.
     *
     * The step does NOT advance here. The operator sees the summary of
     * what was applied and presses Continue, so nothing about the shape
     * of their install happens off-screen.
     */
    public static function handleProfile(): void {
        self::guard( 'tt_onboarding_profile' );

        self::applyProfile( sanitize_key( wp_unslash( (string) ( $_POST['profile'] ?? '' ) ) ) );

        self::redirectToPage();
    }

    /**
     * Put the install into a profile's shape and record the step (#3259).
     *
     * The domain half of `handleProfile()`, extracted so the frontend
     * Setup step calls exactly this rather than re-deriving the refusal
     * rule, the payload keys and the completion action. Both surfaces
     * inherit `ProfileService::apply()`'s tier skipping and its audit
     * trail by calling through instead of copying.
     *
     * Returns null when nothing was applied — an unknown slug, or an
     * install somebody has already shaped by hand. The second is the
     * interesting one: applying silently there would undo decisions
     * without showing them, and the preview screen is where that
     * conversation belongs.
     *
     * @return array{profile:string, applied:int, skipped:int}|null
     */
    public static function applyProfile( string $slug ): ?array {
        if ( ! ProfileRegistry::exists( $slug ) || ProfileService::hasOperatorChanges() ) {
            return null;
        }

        $summary = ProfileService::apply( $slug );
        $counts  = [
            'profile' => $slug,
            'applied' => count( $summary['applied'] ),
            'skipped' => count( $summary['skipped'] ),
        ];

        OnboardingState::recordPayload( 'profile', $counts );
        do_action( 'tt_onboarding_step_completed', 'profile', [ 'profile' => $slug ] );

        return $counts;
    }

    /**
     * Skip the profile step. Skipping means Full academy — today's
     * behaviour — which is reached by applying nothing at all, so the
     * operator who skips gets exactly what they get now.
     */
    public static function handleSkipProfile(): void {
        self::guard( 'tt_onboarding_skip_profile' );
        self::skipProfile();
        self::redirectToPage();
    }

    /** The domain half of `handleSkipProfile()` (#3259). */
    public static function skipProfile(): void {
        // `step_skipped`, not `skipped` — the applied-summary payload uses
        // `skipped` for a count of rows the plan would not allow, and one
        // key holding a bool on one path and an int on another is how a
        // later reader gets it wrong.
        OnboardingState::recordPayload( 'profile', [ 'step_skipped' => true ] );
        OnboardingState::setStep( 'import' );
        do_action( 'tt_onboarding_step_completed', 'profile', [ 'skipped' => true ] );
    }

    /**
     * The squad-import step (#2958).
     *
     * Two passes over the same upload. The first validates and reports
     * without writing anything, so the admin sees what the workbook holds
     * before committing; the second, from the confirm button, imports for
     * real. A workbook with blockers never reaches the second pass.
     */
    public static function handleImport(): void {
        self::guard( 'tt_onboarding_import' );

        $file = $_FILES['roster_file'] ?? null;

        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
            OnboardingState::recordPayload( 'import', [
                'error' => __( 'Choose a workbook to upload first.', 'talenttrack' ),
            ] );
            self::redirectToPage();
            return;
        }

        if ( ! empty( $file['error'] ) ) {
            OnboardingState::recordPayload( 'import', [
                'error' => __( 'The upload did not complete. It may be larger than this server accepts.', 'talenttrack' ),
            ] );
            self::redirectToPage();
            return;
        }

        $tmp_path = (string) $file['tmp_name'];
        $name     = sanitize_file_name( (string) ( $file['name'] ?? 'workbook.xlsx' ) );
        $commit   = ! empty( $_POST['tt_ob_import_confirm'] );

        $service = new ImportService();
        $result  = $commit
            ? $service->import( $tmp_path, $name )
            : $service->preview( $tmp_path, $name );

        if ( empty( $result['ok'] ) ) {
            OnboardingState::recordPayload( 'import', [
                'blockers' => array_values( (array) ( $result['blockers'] ?? [] ) ),
                'filename' => $name,
            ] );
            self::redirectToPage();
            return;
        }

        $payload = [
            'filename'  => $name,
            'imported'  => (array) ( $result['imported'] ?? [] ),
            'warnings'  => array_values( (array) ( $result['warnings'] ?? [] ) ),
            'committed' => (bool) $commit,
        ];
        OnboardingState::recordPayload( 'import', $payload );

        if ( $commit ) {
            OnboardingState::setStep( 'first_team' );
            do_action( 'tt_onboarding_step_completed', 'import', $payload );
            self::redirectToPage( [ 'tt_ob_msg' => 'imported' ] );
            return;
        }

        // Preview only — stay on the step so the report is what the admin
        // sees next, with the confirm button under it.
        self::redirectToPage();
    }

    /**
     * Add one staff member, with their invitation held (#2965).
     *
     * Every invitation this step creates uses `defer_send`, so nobody is
     * emailed while the admin is still setting the place up. The sending
     * happens later, from the Done step, once they have looked around.
     */
    public static function handleStaff(): void {
        self::guard( 'tt_onboarding_staff' );

        $first = sanitize_text_field( wp_unslash( (string) ( $_POST['first_name'] ?? '' ) ) );
        $last  = sanitize_text_field( wp_unslash( (string) ( $_POST['last_name']  ?? '' ) ) );
        $email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
        $role  = sanitize_text_field( wp_unslash( (string) ( $_POST['role_type'] ?? 'staff' ) ) );

        if ( $first === '' || $last === '' ) {
            self::recordStaffError( __( 'A first and last name are both needed.', 'talenttrack' ) );
            return;
        }

        if ( $email !== '' && ! is_email( $email ) ) {
            self::recordStaffError( __( 'That does not look like a valid email address.', 'talenttrack' ) );
            return;
        }

        $repo      = new PeopleRepository();
        $person_id = (int) $repo->create( [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => $email !== '' ? $email : null,
            'role_type'  => $role !== '' ? $role : 'staff',
            'status'     => 'active',
        ] );

        if ( $person_id <= 0 ) {
            self::recordStaffError( __( 'That person could not be saved. They may already be on the list.', 'talenttrack' ) );
            return;
        }

        $invited = false;
        if ( $email !== '' ) {
            $result = ( new InvitationService() )->create( [
                'kind'               => InvitationKind::STAFF,
                'target_person_id'   => $person_id,
                'prefill_first_name' => $first,
                'prefill_last_name'  => $last,
                'prefill_email'      => $email,
                // #2964 — held. Nothing reaches them yet.
                'defer_send'         => true,
            ] );
            $invited = ! empty( $result['ok'] );
        }

        $payload         = OnboardingState::payloadFor( 'staff' );
        $added           = (array) ( $payload['added'] ?? [] );
        $added[]         = [
            'name'    => trim( $first . ' ' . $last ),
            'email'   => $email,
            'invited' => $invited,
        ];
        $payload['added'] = $added;
        unset( $payload['error'] );

        OnboardingState::recordPayload( 'staff', $payload );
        self::redirectToPage( [ 'tt_ob_msg' => 'staff_added' ] );
    }

    /** Finish the staff step without sending anything. */
    public static function handleSkipStaff(): void {
        self::guard( 'tt_onboarding_skip_staff' );

        OnboardingState::setStep( 'messaging' );
        self::redirectToPage();
    }

    /**
     * #3113 — record which messages this academy wants to send.
     *
     * Writes through `TemplateSwitch::setDisabled()`, the same domain
     * writer the Messages settings screen's payload is normalised by, so
     * there is no second representation of what the stored value means.
     * The stored set is the DISABLED one, so what is submitted here is
     * inverted: everything registered and switchable that was NOT ticked.
     *
     * Reading the registered set rather than the submitted one matters —
     * a template that exists but was not rendered (a family the operator
     * never scrolled to, a checkbox a browser dropped) has to end up
     * switched off, which is where it already was. Trusting the POST to
     * be exhaustive would switch such a template on by omission.
     */
    public static function handleMessaging(): void {
        self::guard( 'tt_onboarding_messaging' );

        $submitted = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] )
            ? array_map( 'strval', wp_unslash( $_POST['enabled'] ) )
            : [];

        self::applyMessaging( $submitted );

        self::redirectToPage( [ 'tt_ob_msg' => 'messaging_saved' ] );
    }

    /**
     * #3140 — the messaging decision itself, with no surface attached.
     *
     * The wp-admin form handler above and the frontend Setup view's REST
     * route both call this. That is the point: the inversion described in
     * this method's parent docblock is the whole guarantee of #3113, and a
     * second surface re-deriving it is exactly how a template ends up
     * switched on by omission on one screen and not the other.
     *
     * `$submitted` is whatever the operator ticked, in any shape a form or
     * a JSON body produces; it is sanitised and intersected here, never
     * trusted as the exhaustive list.
     *
     * @param list<string> $submitted template keys the operator ticked
     * @return array{enabled: list<string>, disabled: list<string>}
     */
    public static function applyMessaging( array $submitted ): array {
        $submitted = array_map( 'sanitize_key', array_map( 'strval', $submitted ) );

        $switchable = array_keys( TemplateSwitch::switchableTemplates() );
        $enabled    = array_values( array_intersect( $switchable, $submitted ) );
        $disabled   = array_values( array_diff( $switchable, $enabled ) );

        TemplateSwitch::setDisabled( $disabled );

        OnboardingState::recordPayload( 'messaging', [
            'enabled' => $enabled,
            'skipped' => false,
        ] );
        do_action( 'tt_onboarding_step_completed', 'messaging', [ 'enabled' => count( $enabled ) ] );

        OnboardingState::setStep( 'dashboard' );

        return [ 'enabled' => $enabled, 'disabled' => $disabled ];
    }

    /**
     * #3113 — skipping leaves every message switched off.
     *
     * Deliberately writes nothing. The seeded state from #3111 already
     * says "send nothing", and re-asserting it here would be a second
     * writer claiming the same authority — and would quietly re-disable
     * anything an operator had switched on before re-entering the wizard.
     */
    public static function handleSkipMessaging(): void {
        self::guard( 'tt_onboarding_skip_messaging' );

        self::skipMessaging();

        self::redirectToPage();
    }

    /**
     * #3140 — the skip, shared with the frontend Setup view for the same
     * reason `applyMessaging()` is: "skipping writes nothing" is a
     * guarantee, and a guarantee kept in two places is kept in one and a
     * half.
     */
    public static function skipMessaging(): void {
        OnboardingState::recordPayload( 'messaging', [ 'skipped' => true ] );
        do_action( 'tt_onboarding_step_completed', 'messaging', [ 'skipped' => true ] );

        OnboardingState::setStep( 'dashboard' );
    }

    /**
     * Release every held invitation, then move on (#2964, #2965).
     *
     * This is the moment the admin has been setting up towards: they have
     * added their people, looked around, and are ready for those people to
     * be let in.
     */
    public static function handleSendInvites(): void {
        self::guard( 'tt_onboarding_send_invites' );

        $result  = ( new InvitationService() )->sendDeferred();
        $payload = OnboardingState::payloadFor( 'staff' );

        $payload['sent']    = count( $result['sent'] );
        $payload['skipped'] = count( $result['skipped'] );
        OnboardingState::recordPayload( 'staff', $payload );

        OnboardingState::setStep( 'messaging' );
        self::redirectToPage( [ 'tt_ob_msg' => 'invites_sent' ] );
    }

    private static function recordStaffError( string $message ): void {
        $payload          = OnboardingState::payloadFor( 'staff' );
        $payload['error'] = $message;
        OnboardingState::recordPayload( 'staff', $payload );
        self::redirectToPage();
    }

    /** Stream the three-sheet squad template (#2957). */
    public static function handleRosterTemplate(): void {
        self::guard( 'tt_onboarding_roster_template' );

        if ( ! \TT\Modules\Import\Excel\TemplateBuilder::streamRosterDownload() ) {
            wp_die( esc_html__( 'The spreadsheet library is not installed on this server, so the template cannot be generated.', 'talenttrack' ) );
        }
        exit;
    }

    /** Skip the import step — a club with no spreadsheet is not blocked. */
    public static function handleSkipImport(): void {
        self::guard( 'tt_onboarding_skip_import' );

        OnboardingState::recordPayload( 'import', [ 'skipped' => true ] );
        OnboardingState::setStep( 'first_team' );

        self::redirectToPage();
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
        // #3038 — the install-profile step comes next.
        OnboardingState::setStep( 'profile' );
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
        OnboardingState::setStep( 'staff' );
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
        // Deep-link to the TalentTrack → Demo data page where the admin
        // picks a preset, domain, and password rather than us guessing
        // sensible defaults. The wizard is dismissed so the
        // admin lands cleanly on the dashboard after generating.
        OnboardingState::setDismissed( true );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-demo-data' ) );
        exit;
    }

    public static function handleCreateDashboardPage(): void {
        self::guard( 'tt_onboarding_create_dashboard_page' );
        self::createDashboardPage();
        self::redirectToPage( [ 'tt_ob_msg' => 'page_made' ] );
    }

    /**
     * Leave the Done screen (#3025).
     *
     * The wizard is already marked completed by the dashboard step; this
     * only moves the step off `done` so `OnboardingPage::render()`'s
     * completion guard takes over again and a later visit to the wizard
     * gets the short "Setup is complete" screen rather than the summary.
     * The payload is left alone — it is the record of what the wizard did.
     */
    public static function handleFinish(): void {
        self::guard( 'tt_onboarding_finish' );
        OnboardingState::setStep( 'welcome' );

        $dash = OnboardingState::payloadFor( 'dashboard' );
        $url  = ! empty( $dash['page_url'] )
            ? (string) $dash['page_url']
            : admin_url( 'admin.php?page=talenttrack' );

        wp_safe_redirect( $url );
        exit;
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
