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
 *   POST /onboarding/import         — preview or commit a squad workbook (or skip)
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

    /**
     * #3819 — the ten steps were registered from a loop over their names,
     * so neither the path nor the endpoint array could be read statically
     * and the args gate saw one unreadable route standing for all ten.
     * Each step spells its own out now, with the fields that step takes.
     */
    public static function register(): void {
        register_rest_route( self::NS, '/onboarding/advance', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'advance' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => [] ],
        ] );
        register_rest_route( self::NS, '/onboarding/academy', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'academy' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::academyArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/first-team', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'firstTeam' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::firstTeamArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/first-admin', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'firstAdmin' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::firstAdminArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/staff', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'staff' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::staffArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/messaging', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'messaging' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::messagingArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/profile', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'profile' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::profileArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/import', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'import' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::importArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/dashboard-page', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'dashboardPage' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => self::skipArgs() ],
        ] );
        register_rest_route( self::NS, '/onboarding/reset', [
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'reset' ], 'permission_callback' => [ __CLASS__, 'canEdit' ], 'args' => [] ],
        ] );
    }

    // Body contracts (#3819) -------------------------------------------

    /*
     * Nothing here is declared `required`. Core checks required params
     * before the permission callback, so a required field would answer an
     * unauthorised POST with a 400 describing the install wizard rather
     * than the 403 it is owed. Each step's handler names what it needs.
     *
     * Every step that can be passed over takes a `skip`, which is why it
     * is declared on each of them rather than hidden in a shared base:
     * what a step does with being skipped is the step's own business.
     */

    /** @return array<string, array<string, mixed>> */
    private static function skipArgs(): array {
        return [ 'skip' => [
            'type'        => [ 'boolean', 'integer', 'string' ],
            'description' => 'Pass over this step and move on.',
        ] ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function academyArgs(): array {
        return [
            'academy_name'  => [ 'type' => 'string', 'description' => 'What the academy is called.' ],
            'season_label'  => [ 'type' => 'string', 'description' => 'What the current season is called, e.g. 2026/2027.' ],
            'date_format'   => [ 'type' => 'string', 'description' => 'How dates read across the plugin.' ],
            'primary_color' => [ 'type' => 'string', 'description' => 'The club colour the dashboard is themed with.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function firstTeamArgs(): array {
        return self::skipArgs() + [
            'team_name' => [ 'type' => 'string', 'description' => 'What the first team is called.' ],
            'age_group' => [ 'type' => 'string', 'description' => 'Which age group it plays in.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function firstAdminArgs(): array {
        return [
            'first_name' => [ 'type' => 'string', 'description' => 'The administrator\'s first name.' ],
            'last_name'  => [ 'type' => 'string', 'description' => 'Their last name.' ],
            'grant_role' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Give this account the academy-admin role as well as its WordPress one.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function staffArgs(): array {
        // The four name lists declare no `type`: one that lists `array`
        // goes through `rest_sanitize_array()`, which splits a plain
        // string on whitespace and commas — so a single "Jan de Vries"
        // would arrive as three staff members.
        return self::skipArgs() + [
            'first_name'   => [ 'description' => 'The staff members\' first names, in the same order as the other three lists.' ],
            'last_name'    => [ 'description' => 'Their last names.' ],
            'email'        => [ 'description' => 'Their email addresses.' ],
            'role_type'    => [ 'description' => 'What each of them does.' ],
            'send_invites' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Mail each of them an invitation now.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function messagingArgs(): array {
        return self::skipArgs() + [
            'enabled' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Switch the messaging module on.' ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function profileArgs(): array {
        return self::skipArgs() + [
            'profile' => [ 'type' => 'string', 'description' => 'Which install profile to apply: the set of modules and defaults this academy starts from.' ],
        ];
    }

    /**
     * The roster arrives as a multipart file, so only the two switches are
     * body keys. `commit` absent means preview, which is what keeps an
     * accidental request from writing a roster.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function importArgs(): array {
        return self::skipArgs() + [
            'commit' => [ 'type' => [ 'boolean', 'integer', 'string' ], 'description' => 'Write the roster. Absent previews it and reports what would happen.' ],
        ];
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
        // #3819 — the step takes no body.
        $refused = BaseController::checkBody( $r, [] );
        if ( $refused !== null ) return $refused;

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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::academyArgs() );
        if ( $refused !== null ) return $refused;

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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::firstTeamArgs() );
        if ( $refused !== null ) return $refused;

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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::firstAdminArgs() );
        if ( $refused !== null ) return $refused;

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
     * #3261 — the staff step (#2964/#2965) on the frontend.
     *
     * Three actions on one route, because they are three buttons on one
     * step and the frontend's form handler posts to a single endpoint per
     * step. All three delegate to `OnboardingHandlers`, which is the point
     * of the route rather than this layer creating people itself: the
     * handler creates the invitation with `defer_send`, so it is **held**
     * rather than sent. A second implementation that reproduced this by
     * reading the screen would mail a club's coaches the moment their
     * names were typed — the outcome #2964 exists to prevent.
     *
     * No credential is returned. See `FrontendSetupView::renderStaff()`
     * for why that is a decision rather than an omission.
     */
    public static function staff( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::staffArgs() );
        if ( $refused !== null ) return $refused;

        if ( ! empty( $r->get_param( 'skip' ) ) ) {
            OnboardingHandlers::skipStaff();
            Logger::info( 'rest.onboarding.staff_skipped', [ 'user' => get_current_user_id() ] );
            return self::stateResponse();
        }

        if ( ! empty( $r->get_param( 'send_invites' ) ) ) {
            $result = OnboardingHandlers::sendInvites();
            Logger::info( 'rest.onboarding.invites_sent', [
                'user'    => get_current_user_id(),
                'sent'    => $result['sent'],
                'skipped' => $result['skipped'],
            ] );
            return self::stateResponse();
        }

        $result = OnboardingHandlers::addStaff( [
            'first_name' => (string) ( $r->get_param( 'first_name' ) ?? '' ),
            'last_name'  => (string) ( $r->get_param( 'last_name' ) ?? '' ),
            'email'      => (string) ( $r->get_param( 'email' ) ?? '' ),
            'role_type'  => (string) ( $r->get_param( 'role_type' ) ?? 'staff' ),
        ] );

        if ( ! $result['ok'] ) {
            return RestResponse::error( 'staff_invalid', (string) $result['error'], 422 );
        }

        // The person id is logged, never the invitation. Nothing that
        // could be used to sign in leaves the server on this route.
        Logger::info( 'rest.onboarding.staff_added', [
            'user'    => get_current_user_id(),
            'person'  => $result['person_id'],
            'invited' => $result['invited'],
        ] );
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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::messagingArgs() );
        if ( $refused !== null ) return $refused;

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
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::profileArgs() );
        if ( $refused !== null ) return $refused;

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

    /**
     * #3260 — the squad-import step (#2958) on the frontend.
     *
     * The only route here that takes a file. `get_file_params()` returns
     * the same `$_FILES` shape `OnboardingHandlers::applyImport()` expects,
     * which is why that method takes the array rather than reading the
     * superglobal — a handler that read `$_FILES` directly could serve
     * wp-admin or this, not both.
     *
     * **There is exactly one importer.** `ImportService` (#2954) does the
     * parsing, validation and batch tagging for both surfaces. A second one
     * would have to keep up with the mapping rules, the workbook validation
     * and the error reporting, and it would not.
     *
     * Two passes over the same upload, and the pass is the caller's choice:
     * without `commit` the workbook is only reported on, with it the rows
     * are written. A workbook with blockers never reaches the second pass —
     * that rule lives in the handler, not here.
     *
     * The response is always 200 with the step's payload, including for a
     * refusal. The refusals here are things about the *workbook* — a column
     * missing, a date that will not parse — which the step renders as a
     * report the operator acts on, not as a failed request.
     */
    public static function import( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values. The roster itself
        // arrives as a multipart file, so only the two switches are here.
        $refused = BaseController::checkBody( $r, self::importArgs() );
        if ( $refused !== null ) return $refused;

        if ( ! empty( $r->get_param( 'skip' ) ) ) {
            OnboardingHandlers::skipImport();
            Logger::info( 'rest.onboarding.import_skipped', [ 'user' => get_current_user_id() ] );
            return self::stateResponse();
        }

        $files = $r->get_file_params();
        $file  = isset( $files['roster_file'] ) && is_array( $files['roster_file'] ) ? $files['roster_file'] : [];

        $payload = OnboardingHandlers::applyImport( $file, ! empty( $r->get_param( 'commit' ) ) );

        Logger::info( 'rest.onboarding.import', [
            'user'      => get_current_user_id(),
            'committed' => ! empty( $payload['committed'] ),
            'blockers'  => count( (array) ( $payload['blockers'] ?? [] ) ),
        ] );

        return self::stateResponse();
    }

    public static function dashboardPage( \WP_REST_Request $r ): \WP_REST_Response {
        // #3819 — the body's shape before its values.
        $refused = BaseController::checkBody( $r, self::skipArgs() );
        if ( $refused !== null ) return $refused;

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
        // #3819 — the step takes no body.
        $refused = BaseController::checkBody( $r, [] );
        if ( $refused !== null ) return $refused;

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
