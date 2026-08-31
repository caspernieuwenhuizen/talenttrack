<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Onboarding\OnboardingState;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Modules\ModuleMetadata;
use TT\Shared\Modules\ProfileRegistry;
use TT\Shared\Modules\ProfileService;

/**
 * FrontendSetupView — frontend port of the wp-admin first-run onboarding
 * wizard (#1938, child of #1533). Reachable at `?tt_view=setup`.
 *
 * Answers the academy question "Set up / re-configure the academy basics
 * (name, first team, first admin, dashboard page)." — the academy
 * bootstrap; downstream the first-team step creates the team players will
 * belong to.
 *
 * A plain multi-step view (NOT the record-creation Wizard framework — the
 * bespoke flow grants caps, creates pages, and seeds the dashboard, none
 * of which maps onto record-creation wizard steps). The current step is
 * read from OnboardingState; each step's form POSTs to
 * OnboardingRestController, which delegates to the same OnboardingHandlers
 * / OnboardingState domain layer the wp-admin page uses, then the view
 * re-renders the next step. Resume / "Run again" semantics are preserved:
 * progress is persisted in OnboardingState, and the reset endpoint
 * re-enters the flow at the welcome step.
 *
 * Capability: `tt_edit_settings` (matches OnboardingPage::CAP). The view
 * gates on the same cap; every mutation re-checks it at the REST layer.
 */
class FrontendSetupView extends FrontendViewBase {

    public const SLUG = 'setup';
    private const CAP  = 'tt_edit_settings';

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            self::breadcrumb();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::enqueueViewAssets();
        self::breadcrumb();
        self::renderHeader( __( 'Setup', 'talenttrack' ) );

        $force = isset( $_GET['force_welcome'] ) && $_GET['force_welcome'] === '1';
        $state = OnboardingState::get();
        $step  = (string) $state['step'];

        // A completed install that didn't explicitly ask to re-run lands on
        // the completion summary with a "Run again" affordance — mirrors the
        // wp-admin completion screen.
        if ( OnboardingState::isCompleted() && ! $force && $step !== 'done' ) {
            $step = 'done';
        }

        $cancel_url = self::configUrl();
        ?>
        <div class="tt-setup" data-tt-setup>
            <p class="tt-setup__intro">
                <?php esc_html_e( 'This flow sets up your academy basics, your first team, your admin profile, and the frontend dashboard page. Stop and resume any time — your progress is saved automatically.', 'talenttrack' ); ?>
            </p>

            <?php self::renderStepper( $step ); ?>

            <div class="tt-setup__form-msg" data-tt-setup-msg role="status" aria-live="polite"></div>

            <div class="tt-setup__step">
                <?php
                switch ( $step ) {
                    case 'welcome':     self::renderWelcome( $cancel_url );    break;
                    case 'academy':     self::renderAcademy( $cancel_url );    break;
                    case 'first_team':  self::renderFirstTeam( $cancel_url );  break;
                    case 'first_admin': self::renderFirstAdmin( $cancel_url ); break;
                    // #3140 (step from #3113) — the messaging decision. The
                    // cheapest of the four missing steps to port and the
                    // most costly to skip: #3049 spent an epic making sure a
                    // club is *asked* what it sends rather than defaulting
                    // into silence, and a frontend operator who never met
                    // this step was the outcome that epic exists to prevent.
                    case 'messaging':   self::renderMessaging( $cancel_url );  break;
                    // #3259 (step from #3038) — the install-profile
                    // picker. The cheapest of the three remaining ports
                    // and the one that decides what the whole install
                    // looks like, so an operator who only ever sees the
                    // frontend was choosing it by not being asked.
                    case 'profile':     self::renderProfile( $cancel_url );    break;
                    // #3260 (step from #2958) — the squad import. The step
                    // a new academy's first experience of TalentTrack most
                    // depends on: it is how a club's players get into the
                    // system at all.
                    case 'import':      self::renderImport( $cancel_url );     break;
                    case 'dashboard':   self::renderDashboard( $cancel_url );  break;
                    case 'done':        self::renderDone();                    break;
                    // #3140 — `staff` is a real port (people records and
                    // held invitation credentials) and is filed separately
                    // as #3261. Until it lands it says what it is and
                    // offers a way past. What used to be here read as a bug
                    // and its only exit restarted the wizard at step 1, to
                    // hit the same wall again.
                    default:            self::renderNotYetPorted( $step, $cancel_url );
                }
                ?>
            </div>

            <?php if ( $step !== 'welcome' && $step !== 'done' ) : ?>
                <p class="tt-setup__reset">
                    <button type="button" class="tt-setup__reset-btn" data-tt-setup-reset>
                        <?php esc_html_e( 'Start over', 'talenttrack' ); ?>
                    </button>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // Step renderers

    private static function renderWelcome( string $cancel_url ): void {
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'Set up your academy', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'TalentTrack is a youth football talent management plugin. This flow creates your first team, your admin profile, and a few defaults so you can start tracking players today. Each step takes about a minute.', 'talenttrack' ); ?>
        </p>
        <div class="tt-setup__actions">
            <a class="tt-btn tt-btn-secondary tt-setup__cancel" href="<?php echo esc_url( $cancel_url ); ?>">
                <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
            </a>
            <button type="button" class="tt-btn tt-btn-primary" data-tt-setup-advance>
                <?php esc_html_e( 'Set up my academy', 'talenttrack' ); ?>
            </button>
        </div>
        <?php
    }

    private static function renderAcademy( string $cancel_url ): void {
        $payload = OnboardingState::payloadFor( 'academy' );
        $values  = [
            'academy_name'  => (string) ( $payload['academy_name']  ?? QueryHelpers::get_config( 'academy_name', '' ) ),
            'primary_color' => (string) ( $payload['primary_color'] ?? QueryHelpers::get_config( 'primary_color', '#0b3d2e' ) ),
            'season_label'  => (string) ( $payload['season_label']  ?? QueryHelpers::get_config( 'season_label', '' ) ),
            'date_format'   => (string) ( $payload['date_format']   ?? QueryHelpers::get_config( 'date_format_pref', 'Y-m-d' ) ),
        ];
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'Academy basics', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'These show up across the plugin: in the dashboard header, on player rate cards, and in printed reports. You can change them later under Configuration.', 'talenttrack' ); ?>
        </p>
        <form data-tt-setup-form data-tt-setup-endpoint="academy">
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-academy-name"><?php esc_html_e( 'Academy name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-setup-academy-name" class="tt-setup__input" name="academy_name"
                    value="<?php echo esc_attr( $values['academy_name'] ); ?>" required
                    autocomplete="organization" inputmode="text" />
            </div>
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-primary-color"><?php esc_html_e( 'Primary color', 'talenttrack' ); ?></label>
                <input type="color" id="tt-setup-primary-color" class="tt-setup__color" name="primary_color"
                    value="<?php echo esc_attr( $values['primary_color'] ); ?>" />
                <p class="tt-setup__hint"><?php esc_html_e( 'Used for headers, links, and the FIFA-style player card.', 'talenttrack' ); ?></p>
            </div>
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-season-label"><?php esc_html_e( 'Season label', 'talenttrack' ); ?></label>
                <input type="text" id="tt-setup-season-label" class="tt-setup__input" name="season_label"
                    value="<?php echo esc_attr( $values['season_label'] ); ?>" placeholder="2025/2026"
                    autocomplete="off" inputmode="text" />
                <p class="tt-setup__hint"><?php esc_html_e( 'Free-form. Most clubs use "2025/2026" or similar.', 'talenttrack' ); ?></p>
            </div>
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-date-format"><?php esc_html_e( 'Date format', 'talenttrack' ); ?></label>
                <select id="tt-setup-date-format" class="tt-setup__input" name="date_format">
                    <option value="Y-m-d" <?php selected( $values['date_format'], 'Y-m-d' ); ?>>2026-04-25 (Y-m-d)</option>
                    <option value="d-m-Y" <?php selected( $values['date_format'], 'd-m-Y' ); ?>>25-04-2026 (d-m-Y)</option>
                    <option value="d/m/Y" <?php selected( $values['date_format'], 'd/m/Y' ); ?>>25/04/2026 (d/m/Y)</option>
                    <option value="m/d/Y" <?php selected( $values['date_format'], 'm/d/Y' ); ?>>04/25/2026 (m/d/Y)</option>
                </select>
            </div>
            <?php echo FormSaveButton::render( [
                'label'        => __( 'Continue', 'talenttrack' ),
                'label_saving' => __( 'Saving…', 'talenttrack' ),
                'label_saved'  => __( 'Saved', 'talenttrack' ),
                'cancel_url'   => $cancel_url,
                'cancel_label' => __( 'Cancel', 'talenttrack' ),
            ] ); ?>
        </form>
        <?php
    }

    private static function renderFirstTeam( string $cancel_url ): void {
        $payload    = OnboardingState::payloadFor( 'first_team' );
        $values     = [
            'team_name' => (string) ( $payload['team_name'] ?? '' ),
            'age_group' => (string) ( $payload['age_group'] ?? '' ),
        ];
        $age_groups = QueryHelpers::get_lookup_label_pairs( 'age_group' );
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'First team', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'Add one team now. You can add more later under Teams. Players, evaluations, activities, and goals all attach to a team, so we need at least one to make the rest of the plugin useful.', 'talenttrack' ); ?>
        </p>
        <form data-tt-setup-form data-tt-setup-endpoint="first-team">
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-team-name"><?php esc_html_e( 'Team name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-setup-team-name" class="tt-setup__input" name="team_name"
                    value="<?php echo esc_attr( $values['team_name'] ); ?>" required
                    autocomplete="off" inputmode="text" />
            </div>
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-age-group"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></label>
                <select id="tt-setup-age-group" class="tt-setup__input" name="age_group">
                    <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                    <?php foreach ( $age_groups as $ag_value => $ag_label ) : ?>
                        <option value="<?php echo esc_attr( $ag_value ); ?>" <?php selected( $values['age_group'], $ag_value ); ?>><?php echo esc_html( $ag_label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="tt-setup__hint"><?php esc_html_e( 'Pick the closest match. New age groups can be added under Configuration → Lookups.', 'talenttrack' ); ?></p>
            </div>
            <?php echo FormSaveButton::render( [
                'label'        => __( 'Continue', 'talenttrack' ),
                'label_saving' => __( 'Saving…', 'talenttrack' ),
                'label_saved'  => __( 'Saved', 'talenttrack' ),
                'cancel_url'   => $cancel_url,
                'cancel_label' => __( 'Cancel', 'talenttrack' ),
            ] ); ?>
            <p class="tt-setup__skip-row">
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-skip="first-team">
                    <?php esc_html_e( 'Skip this step', 'talenttrack' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    private static function renderFirstAdmin( string $cancel_url ): void {
        $user       = wp_get_current_user();
        $name       = $user ? ( trim( (string) $user->display_name ) ?: (string) $user->user_login ) : '';
        $email      = $user ? (string) $user->user_email : '';
        $payload    = OnboardingState::payloadFor( 'first_admin' );
        $first_name = (string) ( $payload['first_name'] ?? ( $user ? (string) $user->first_name : '' ) );
        $last_name  = (string) ( $payload['last_name']  ?? ( $user ? (string) $user->last_name  : '' ) );
        $grant_role = isset( $payload['grant_role'] ) ? (bool) $payload['grant_role'] : true;
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'First admin', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php
            printf(
                /* translators: %s is the WP display name + email of the current user. */
                esc_html__( 'You are signed in as %s. We will create a TalentTrack staff record for you and link it to your WP account so evaluations, activities, and notifications all reference the right person.', 'talenttrack' ),
                '<strong>' . esc_html( $name . ( $email ? " ($email)" : '' ) ) . '</strong>'
            );
            ?>
        </p>
        <form data-tt-setup-form data-tt-setup-endpoint="first-admin">
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-first-name"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-setup-first-name" class="tt-setup__input" name="first_name"
                    value="<?php echo esc_attr( $first_name ); ?>" required
                    autocomplete="given-name" inputmode="text" />
            </div>
            <div class="tt-setup__field">
                <label class="tt-setup__legend" for="tt-setup-last-name"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
                <input type="text" id="tt-setup-last-name" class="tt-setup__input" name="last_name"
                    value="<?php echo esc_attr( $last_name ); ?>" required
                    autocomplete="family-name" inputmode="text" />
            </div>
            <div class="tt-setup__field tt-setup__field--check">
                <label class="tt-setup__check">
                    <input type="checkbox" name="grant_role" value="1" <?php checked( $grant_role ); ?> />
                    <span><?php esc_html_e( 'Grant me the Club Admin role (recommended)', 'talenttrack' ); ?></span>
                </label>
                <p class="tt-setup__hint"><?php esc_html_e( 'Club Admins can manage all teams, players, evaluations, and configuration.', 'talenttrack' ); ?></p>
            </div>
            <?php echo FormSaveButton::render( [
                'label'        => __( 'Continue', 'talenttrack' ),
                'label_saving' => __( 'Saving…', 'talenttrack' ),
                'label_saved'  => __( 'Saved', 'talenttrack' ),
                'cancel_url'   => $cancel_url,
                'cancel_label' => __( 'Cancel', 'talenttrack' ),
            ] ); ?>
        </form>
        <?php
    }

    /**
     * #3140 — the messaging step (#3113, epic #3049) on the frontend.
     *
     * The same three deliberate things the wp-admin step does, because
     * they are the step rather than its styling:
     *
     *   - **Nothing is pre-ticked.** The operator is choosing what to
     *     switch on, which matches the state the install is actually in
     *     (#3111 seeds a new academy with every message off).
     *   - **It recommends without pre-selecting.** The urgent family is
     *     marked Recommended in a sentence; marking a recommendation is
     *     not the same as ticking it on somebody's behalf.
     *   - **Skipping says what skipping means** — "no messages will be
     *     sent" — rather than "you can change this later", which reads as
     *     "it is fine either way". It is not.
     *
     * The copy comes from `TemplateGuide`, the same source the wp-admin
     * step and the Messages settings screen (#3112) read: one set of
     * words, three surfaces. The invitation email is absent because it is
     * account plumbing and sits outside the switch entirely (#3110) —
     * staff invited earlier in the flow get their invitations whatever is
     * chosen here.
     *
     * The write goes to `POST /onboarding/messaging`, which calls
     * `OnboardingHandlers::applyMessaging()` — the same inversion against
     * the registered switchable set the wp-admin form handler runs. Not a
     * second implementation, on purpose.
     */
    private static function renderMessaging( string $cancel_url ): void {
        $templates = \TT\Modules\Comms\Template\TemplateSwitch::switchableTemplates();
        $families  = \TT\Modules\Comms\Template\TemplateGuide::families();
        $grouped   = \TT\Modules\Comms\Template\TemplateGuide::grouped( $templates );
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'What TalentTrack tells people', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'Right now your academy sends nothing. Tick the messages you want TalentTrack to send on your behalf; leave the rest for later.', 'talenttrack' ); ?>
        </p>
        <p class="tt-setup__hint">
            <?php esc_html_e( 'Most academies want the first group at least — those are the messages people are annoyed not to get. You can change any of this afterwards under Configuration → Messages.', 'talenttrack' ); ?>
        </p>

        <?php if ( empty( $templates ) ) : ?>
            <p class="tt-setup__hint"><?php esc_html_e( 'No messages are available on this install.', 'talenttrack' ); ?></p>
        <?php endif; ?>

        <form data-tt-setup-form data-tt-setup-endpoint="messaging">
            <span data-tt-setup-multi="enabled" hidden></span>
            <?php foreach ( $grouped as $family_key => $group ) :
                $family = $families[ $family_key ] ?? null;
                if ( $family === null ) continue;
            ?>
                <fieldset class="tt-setup__msg-group">
                    <legend class="tt-setup__legend">
                        <?php echo esc_html( $family['label'] ); ?>
                        <?php if ( ! empty( $family['recommended'] ) ) : ?>
                            <span class="tt-setup__msg-recommended"><?php esc_html_e( 'Recommended', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </legend>
                    <p class="tt-setup__hint"><?php echo esc_html( $family['blurb'] ); ?></p>

                    <?php foreach ( $group as $key => $template ) :
                        $key      = (string) $key;
                        $entry    = \TT\Modules\Comms\Template\TemplateGuide::forKey( $key );
                        $field_id = 'tt-setup-msg-' . sanitize_html_class( $key );
                    ?>
                        <div class="tt-setup__msg">
                            <label class="tt-setup__check" for="<?php echo esc_attr( $field_id ); ?>">
                                <input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>"
                                    name="enabled[]" value="<?php echo esc_attr( $key ); ?>" />
                                <span class="tt-setup__msg-name"><?php echo esc_html( $template->label() ); ?></span>
                            </label>
                            <?php if ( $entry !== null ) : ?>
                                <p class="tt-setup__msg-what"><?php echo esc_html( $entry['what'] ); ?></p>
                                <p class="tt-setup__msg-who">
                                    <?php echo esc_html( $entry['who'] ); ?>
                                    <?php echo esc_html( $entry['when'] ); ?>
                                </p>
                                <?php if ( empty( $entry['triggered'] ) ) : ?>
                                    <p class="tt-setup__msg-pending">
                                        <?php esc_html_e( 'Not sent automatically yet — ticking it changes nothing until that is wired up.', 'talenttrack' ); ?>
                                    </p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>

            <?php echo FormSaveButton::render( [
                'label'        => __( 'Send these and continue', 'talenttrack' ),
                'label_saving' => __( 'Saving…', 'talenttrack' ),
                'label_saved'  => __( 'Saved', 'talenttrack' ),
                'cancel_url'   => $cancel_url,
                'cancel_label' => __( 'Cancel', 'talenttrack' ),
            ] ); ?>
            <p class="tt-setup__skip-row">
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-skip="messaging">
                    <?php esc_html_e( 'Skip — send nothing for now', 'talenttrack' ); ?>
                </button>
            </p>
        </form>

        <p class="tt-setup__hint">
            <strong><?php esc_html_e( 'If you skip this, no messages will be sent.', 'talenttrack' ); ?></strong>
            <?php esc_html_e( 'Not even a cancelled training. Nobody is told anything until you tick something here or under Configuration → Messages.', 'talenttrack' ); ?>
        </p>
        <?php
    }

    /**
     * #3259 — the install-profile step (#3038), ported.
     *
     * Two states, the same two the wp-admin step has. Before a choice, the
     * profiles; after one, the summary of what was applied, so nothing
     * about the shape of the install happens off-screen.
     *
     * The one thing this surface does that wp-admin does not: it shows the
     * **diff inline**, per profile, before anything is written. A profile
     * change is install-wide, and "choose one of these and find out" is not
     * a reasonable thing to ask of somebody on a phone. The rendering is a
     * pair of short lists rather than a before/after table — a two-column
     * grid at 360px is the case a wide table loses, and switches-on /
     * switches-off carries the same information in the width available.
     *
     * The apply goes through `OnboardingHandlers::applyProfile()`, which is
     * also what the wp-admin form posts to. That includes its refusal on an
     * install somebody has already shaped by hand: the cards stop offering
     * Apply and point at the preview screen, exactly as wp-admin does.
     * Showing the diff here arguably makes that refusal unnecessary, but
     * lifting it is a product decision about an install-wide write and does
     * not belong in a port — flagged on the parent epic instead.
     */
    private static function renderProfile( string $cancel_url ): void {
        $payload = OnboardingState::payloadFor( 'profile' );
        $applied = isset( $payload['applied'] ) ? (int) $payload['applied'] : null;
        $chosen  = (string) ( $payload['profile'] ?? '' );

        if ( $applied !== null && ProfileRegistry::exists( $chosen ) ) {
            self::renderProfileApplied( $chosen, $applied, (int) ( $payload['skipped'] ?? 0 ) );
            return;
        }

        // The diff markup is the shared component's, so its styling is the
        // shared component's sheet — the same one `FrontendInstallProfileView`
        // pulls in. Loaded here rather than with the view's own assets so
        // the other nine steps do not carry it.
        wp_enqueue_style(
            'tt-frontend-install-profile',
            TT_PLUGIN_URL . 'assets/css/frontend-install-profile.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );

        $configured = ProfileService::hasOperatorChanges();
        $categories = ModuleMetadata::categories();
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'How much product are you running?', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'TalentTrack ships a lot. Most academies want the development loop first and can turn the rest on whenever they need it. Nothing here is permanent — you can change it any time from Modules.', 'talenttrack' ); ?>
        </p>
        <p class="tt-setup__hint">
            <strong><?php esc_html_e( 'Skipping gives you the full academy.', 'talenttrack' ); ?></strong>
            <?php esc_html_e( 'That is every module switched on, which is what an install gets when no profile is chosen.', 'talenttrack' ); ?>
        </p>

        <?php if ( $configured ) : ?>
            <p class="tt-notice tt-notice--warning">
                <?php esc_html_e( 'This install has already been configured by hand, so choosing here would overwrite decisions somebody made. Review the changes on the Modules page instead, where you can see exactly what would happen before anything is written.', 'talenttrack' ); ?>
            </p>
            <?php if ( current_user_can( 'tt_manage_modules' ) ) : ?>
                <p class="tt-setup__profile-reviews">
                    <?php foreach ( ProfileRegistry::all() as $slug => $profile ) : ?>
                        <a class="tt-btn tt-btn-secondary"
                           href="<?php echo esc_url( add_query_arg(
                               [ 'tt_view' => 'install-profile', 'profile' => $slug ],
                               Components\RecordLink::dashboardUrl()
                           ) ); ?>">
                            <?php
                            printf(
                                /* translators: %s is an install profile name, e.g. "Basics". */
                                esc_html__( 'Review %s', 'talenttrack' ),
                                esc_html( (string) $profile['label'] )
                            );
                            ?>
                        </a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>

        <form data-tt-setup-form data-tt-setup-endpoint="profile">
            <div class="tt-setup__profiles">
                <?php foreach ( ProfileRegistry::all() as $slug => $profile ) :
                    $slug     = (string) $slug;
                    $field_id = 'tt-setup-profile-' . sanitize_html_class( $slug );
                ?>
                    <section class="tt-setup__profile">
                        <?php if ( $configured ) : ?>
                            <h3 class="tt-setup__profile-name"><?php echo esc_html( (string) $profile['label'] ); ?></h3>
                        <?php else : ?>
                            <label class="tt-setup__check tt-setup__profile-pick" for="<?php echo esc_attr( $field_id ); ?>">
                                <input type="radio" id="<?php echo esc_attr( $field_id ); ?>"
                                    name="profile" value="<?php echo esc_attr( $slug ); ?>" />
                                <span class="tt-setup__profile-name"><?php echo esc_html( (string) $profile['label'] ); ?></span>
                            </label>
                        <?php endif; ?>

                        <p class="tt-setup__profile-desc"><?php echo esc_html( (string) $profile['description'] ); ?></p>

                        <details class="tt-setup__profile-details">
                            <summary><?php esc_html_e( 'What it includes', 'talenttrack' ); ?></summary>
                            <?php foreach ( ProfileRegistry::includedByCategory( $slug ) as $category => $labels ) : ?>
                                <p class="tt-setup__profile-group">
                                    <strong><?php echo esc_html( (string) ( $categories[ $category ] ?? $category ) ); ?>:</strong>
                                    <?php echo esc_html( implode( ', ', $labels ) ); ?>
                                </p>
                            <?php endforeach; ?>
                        </details>

                        <?php self::renderProfileDiff( $slug ); ?>
                    </section>
                <?php endforeach; ?>
            </div>

            <?php if ( ! $configured ) : ?>
                <?php echo FormSaveButton::render( [
                    'label'        => __( 'Use this profile', 'talenttrack' ),
                    'label_saving' => __( 'Applying...', 'talenttrack' ),
                    'label_saved'  => __( 'Applied', 'talenttrack' ),
                    'cancel_url'   => $cancel_url,
                    'cancel_label' => __( 'Cancel', 'talenttrack' ),
                ] ); ?>
            <?php endif; ?>
            <p class="tt-setup__skip-row">
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-skip="profile">
                    <?php esc_html_e( 'Skip — give me everything', 'talenttrack' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /**
     * #3260 — the squad-import step (#2958), ported.
     *
     * **There is exactly one importer.** `ImportService` (#2954) parses,
     * validates and tags for both surfaces, reached through
     * `OnboardingHandlers::applyImport()`. This view renders an upload and
     * a report; it does not know what a valid workbook looks like.
     *
     * The two-pass shape is the wp-admin step's and is the point of it:
     * the first upload reports what the file holds and writes nothing, the
     * second commits. A workbook with blockers never reaches the second
     * pass. The file has to be chosen again to confirm — the browser will
     * not let a page re-submit a file it was handed, and holding the upload
     * server-side between two requests would mean storing a club's whole
     * squad list somewhere on the chance they press the button.
     *
     * Not built mobile-first, and deliberately: `config/mobile_surfaces.php`
     * already classifies the whole `setup` surface `desktop_only` — "run
     * once, at a desk, before anything else exists". Importing a squad means
     * having a spreadsheet, which means being at the machine the spreadsheet
     * is on; a responsive version of this would let somebody start on a
     * phone something they cannot finish there.
     */
    private static function renderImport( string $cancel_url ): void {
        $payload   = OnboardingState::payloadFor( 'import' );
        $blockers  = (array) ( $payload['blockers'] ?? [] );
        $warnings  = (array) ( $payload['warnings'] ?? [] );
        $imported  = (array) ( $payload['imported'] ?? [] );
        $filename  = (string) ( $payload['filename'] ?? '' );
        $error     = (string) ( $payload['error'] ?? '' );
        $previewed = ! empty( $imported ) && empty( $payload['committed'] );

        $accept = '.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'Import your squad', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'If you already keep your teams, players and staff in a spreadsheet, bring them in now rather than typing them again. Download the template, fill it in, and upload it.', 'talenttrack' ); ?>
        </p>
        <p class="tt-setup__hint">
            <strong><?php esc_html_e( 'Nothing is saved until you confirm.', 'talenttrack' ); ?></strong>
            <?php esc_html_e( 'Uploading shows you what the file contains, and anything that needs fixing, first.', 'talenttrack' ); ?>
        </p>

        <?php if ( $error !== '' ) : ?>
            <p class="tt-notice tt-notice--error"><?php echo esc_html( $error ); ?></p>
        <?php endif; ?>

        <?php if ( ! empty( $blockers ) ) : ?>
            <div class="tt-notice tt-notice--error">
                <p><strong><?php esc_html_e( 'This workbook cannot be imported yet. Nothing was saved.', 'talenttrack' ); ?></strong></p>
                <ul class="tt-setup__import-list">
                    <?php foreach ( $blockers as $blocker ) : ?>
                        <li><?php echo esc_html( (string) $blocker ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ( ! empty( $warnings ) ) : ?>
            <div class="tt-notice tt-notice--warning">
                <ul class="tt-setup__import-list">
                    <?php foreach ( $warnings as $warning ) : ?>
                        <li><?php echo esc_html( (string) $warning ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <p>
            <a class="tt-btn tt-btn-secondary"
               href="<?php echo esc_url( \TT\Modules\Onboarding\Admin\OnboardingPage::actionUrl( 'tt_onboarding_roster_template' ) ); ?>">
                <?php esc_html_e( 'Download the template', 'talenttrack' ); ?>
            </a>
        </p>

        <?php if ( ! $previewed ) : ?>
            <form data-tt-setup-upload="import" enctype="multipart/form-data">
                <p class="tt-setup__field">
                    <label class="tt-field-label" for="tt-setup-roster"><?php esc_html_e( 'Your workbook', 'talenttrack' ); ?></label>
                    <input type="file" id="tt-setup-roster" name="roster_file" class="tt-input"
                           accept="<?php echo esc_attr( $accept ); ?>" />
                    <span class="tt-field-hint">
                        <?php
                        printf(
                            /* translators: 1: upload_max_filesize, 2: post_max_size */
                            esc_html__( 'Server limits on this install: upload_max_filesize = %1$s, post_max_size = %2$s. A workbook larger than the smaller of those is rejected before TalentTrack sees it.', 'talenttrack' ),
                            esc_html( (string) ini_get( 'upload_max_filesize' ) ),
                            esc_html( (string) ini_get( 'post_max_size' ) )
                        );
                        ?>
                    </span>
                </p>
                <div class="tt-form-actions">
                    <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>">
                        <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                    </a>
                    <button type="submit" class="tt-btn tt-btn-primary">
                        <?php esc_html_e( 'Check this file', 'talenttrack' ); ?>
                    </button>
                </div>
            </form>
        <?php else : ?>
            <div class="tt-notice tt-notice--info tt-setup__import-ready">
                <p>
                    <strong><?php
                        printf(
                            /* translators: %s: the uploaded file name */
                            esc_html__( '%s is ready to import.', 'talenttrack' ),
                            esc_html( $filename )
                        );
                    ?></strong>
                </p>
                <ul class="tt-setup__import-list">
                    <?php foreach ( $imported as $entity => $count ) : ?>
                        <li><?php echo esc_html( sprintf( '%s: %d', (string) $entity, (int) $count ) ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><?php esc_html_e( 'Choose the same file again to confirm and save these records.', 'talenttrack' ); ?></p>

                <form data-tt-setup-upload="import" enctype="multipart/form-data">
                    <input type="hidden" name="commit" value="1" />
                    <p class="tt-setup__field">
                        <label class="tt-field-label" for="tt-setup-roster-confirm"><?php esc_html_e( 'Your workbook', 'talenttrack' ); ?></label>
                        <input type="file" id="tt-setup-roster-confirm" name="roster_file" class="tt-input" required
                               accept="<?php echo esc_attr( $accept ); ?>" />
                    </p>
                    <div class="tt-form-actions">
                        <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $cancel_url ); ?>">
                            <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                        </a>
                        <button type="submit" class="tt-btn tt-btn-primary">
                            <?php esc_html_e( 'Import these records', 'talenttrack' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <p class="tt-setup__skip-row">
            <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-skip="import">
                <?php esc_html_e( 'Skip — I do not have a spreadsheet', 'talenttrack' ); ?>
            </button>
        </p>
        <?php
    }

    /**
     * What choosing this profile would change, before it is chosen.
     *
     * Rendered by the shared `ProfileDiff` component, in its read-only
     * mode. The component's docblock says a second copy of this markup is
     * exactly what it exists to prevent, and it is right: the same rows,
     * the same grouping and the same "not part of your plan" wording
     * should not be maintained twice because one surface wanted them
     * inside a `<details>`.
     *
     * Read-only rather than selectable, because the unit of this step is
     * the whole profile. Picking individual rows out of one is what the
     * preview screen (`?tt_view=install-profile`) is for; offering half a
     * profile here would give the summary a number the operator could not
     * reconcile with the profile they chose.
     */
    private static function renderProfileDiff( string $slug ): void {
        $rows = ProfileService::diff( $slug );

        if ( $rows === [] ) {
            ?>
            <p class="tt-setup__profile-nochange">
                <?php esc_html_e( 'This install already matches this profile. There is nothing to change.', 'talenttrack' ); ?>
            </p>
            <?php
            return;
        }
        ?>
        <details class="tt-setup__profile-details tt-setup__profile-diff">
            <summary>
                <?php
                printf(
                    /* translators: %d is a number of modules and features. */
                    esc_html__( 'What would change (%d)', 'talenttrack' ),
                    (int) count( $rows )
                );
                ?>
            </summary>
            <?php Components\ProfileDiff::render( $rows, [ 'selectable' => false, 'heading_level' => 'h4' ] ); ?>
        </details>
        <?php
    }

    /** The what-just-happened half of the profile step (#3259). */
    private static function renderProfileApplied( string $slug, int $applied, int $skipped ): void {
        $profile = ProfileRegistry::get( $slug );
        $label   = $profile === null ? $slug : (string) $profile['label'];
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'How much product are you running?', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php
            printf(
                /* translators: 1: install profile name, e.g. "Basics"; 2: number of modules and features changed. */
                esc_html( _n(
                    'This install is now on %1$s. %2$d module or feature was switched.',
                    'This install is now on %1$s. %2$d modules and features were switched.',
                    $applied,
                    'talenttrack'
                ) ),
                esc_html( $label ),
                (int) $applied
            );
            ?>
        </p>
        <?php if ( $skipped > 0 ) : ?>
            <p class="tt-setup__hint">
                <?php
                printf(
                    /* translators: %d is a number of modules and features the plan does not carry. */
                    esc_html( _n(
                        '%d change was left out because your plan does not carry it.',
                        '%d changes were left out because your plan does not carry them.',
                        $skipped,
                        'talenttrack'
                    ) ),
                    (int) $skipped
                );
                ?>
            </p>
        <?php endif; ?>
        <p class="tt-setup__hint">
            <?php esc_html_e( 'You can change any of this later under Configuration → Modules.', 'talenttrack' ); ?>
        </p>
        <div class="tt-setup__actions">
            <button type="button" class="tt-btn tt-btn-primary" data-tt-setup-advance>
                <?php esc_html_e( 'Continue', 'talenttrack' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * #3140 — a step the wp-admin wizard carries and this surface does not
     * yet: `import` (#2958) and `staff` (#2965).
     *
     * These used to fall through to a two-word "unknown step" line, which
     * reads as a bug and whose only exit was "Start over" — putting the
     * operator back at step 1 to walk into the same wall. A dead end an
     * operator can escape is a limitation; one that loops them back to the
     * beginning is a support call.
     *
     * Two ways onward and deliberately not a third. It does **not** offer
     * to skip the step on the operator's behalf: skipping is a decision,
     * and making it for somebody who was never shown what they were
     * deciding is the exact failure #3113 exists to prevent one step
     * along. So the choice is "continue this step where it exists" or
     * "leave setup" — both explicit, neither automatic.
     *
     * The wp-admin link is not the rejected option (3) round-trip: nothing
     * bounces the operator anywhere. It is one clearly-labelled link on a
     * screen that has already said why it is there, and the two surfaces
     * share `OnboardingState`, so continuing there and coming back works.
     */
    private static function renderNotYetPorted( string $step, string $cancel_url ): void {
        $title = OnboardingState::stepTitle( $step );
        ?>
        <h2 class="tt-setup__heading"><?php echo esc_html( $title ); ?></h2>
        <p class="tt-setup__lead">
            <?php
            printf(
                /* translators: %s is the name of the setup step, e.g. "Import your squad". */
                esc_html__( '%s is not available on this screen yet. Your progress is saved — the step is waiting for you, it is just not one this page can show.', 'talenttrack' ),
                '<strong>' . esc_html( $title ) . '</strong>'
            );
            ?>
        </p>
        <p class="tt-setup__hint">
            <?php esc_html_e( 'You can carry on with this step in the WordPress admin and come back here afterwards — both screens read the same saved progress, so nothing is repeated and nothing is lost. Or leave setup for now and pick it up whenever you like.', 'talenttrack' ); ?>
        </p>
        <div class="tt-setup__actions">
            <a class="tt-btn tt-btn-secondary tt-setup__cancel" href="<?php echo esc_url( $cancel_url ); ?>">
                <?php esc_html_e( 'Leave setup', 'talenttrack' ); ?>
            </a>
            <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . \TT\Modules\Onboarding\Admin\OnboardingPage::SLUG ) ); ?>">
                <?php esc_html_e( 'Continue this step in the admin', 'talenttrack' ); ?>
            </a>
        </div>
        <?php
    }

    private static function renderDashboard( string $cancel_url ): void {
        $existing     = get_posts( [
            'post_type'   => 'page',
            'post_status' => [ 'publish', 'draft', 'private' ],
            'numberposts' => 1,
            's'           => '[talenttrack_dashboard]',
        ] );
        $has_existing = ! empty( $existing );
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'Dashboard page', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead">
            <?php esc_html_e( 'TalentTrack runs on a frontend page that hosts the [talenttrack_dashboard] shortcode. This creates that page and sets it as the site homepage, so coaches, players, and parents land straight on the dashboard when they sign in.', 'talenttrack' ); ?>
        </p>
        <?php if ( $has_existing ) : ?>
            <p class="tt-setup__hint">
                <?php esc_html_e( 'A page with the dashboard shortcode already exists — it will be reused and set as the homepage, not duplicated.', 'talenttrack' ); ?>
            </p>
        <?php endif; ?>
        <div class="tt-setup__actions">
            <a class="tt-btn tt-btn-secondary tt-setup__cancel" href="<?php echo esc_url( $cancel_url ); ?>">
                <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
            </a>
            <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-skip="dashboard-page">
                <?php esc_html_e( 'Skip', 'talenttrack' ); ?>
            </button>
            <button type="button" class="tt-btn tt-btn-primary" data-tt-setup-create-page>
                <?php esc_html_e( 'Create page', 'talenttrack' ); ?>
            </button>
        </div>
        <p class="tt-setup__hint">
            <?php esc_html_e( 'You can change the homepage later under Settings → Reading.', 'talenttrack' ); ?>
        </p>
        <?php
    }

    private static function renderDone(): void {
        $academy = OnboardingState::payloadFor( 'academy' );
        $team    = OnboardingState::payloadFor( 'first_team' );
        $admin   = OnboardingState::payloadFor( 'first_admin' );
        $dash    = OnboardingState::payloadFor( 'dashboard' );

        $dashboard_url = ! empty( $dash['page_url'] )
            ? (string) $dash['page_url']
            : \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        ?>
        <h2 class="tt-setup__heading"><?php esc_html_e( 'Setup complete', 'talenttrack' ); ?></h2>
        <p class="tt-setup__lead"><?php esc_html_e( 'You are ready to use TalentTrack. Here is what was set up:', 'talenttrack' ); ?></p>
        <ul class="tt-setup__summary">
            <?php if ( ! empty( $academy['academy_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: %s is the academy name. */
                        esc_html__( 'Academy: %s', 'talenttrack' ),
                        '<strong>' . esc_html( (string) $academy['academy_name'] ) . '</strong>'
                    );
                ?></li>
            <?php endif; ?>
            <?php if ( ! empty( $team['team_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: 1: team name, 2: age group. */
                        esc_html__( 'Team: %1$s (%2$s)', 'talenttrack' ),
                        '<strong>' . esc_html( (string) $team['team_name'] ) . '</strong>',
                        esc_html( (string) ( $team['age_group'] ?? '—' ) )
                    );
                ?></li>
            <?php endif; ?>
            <?php if ( ! empty( $admin['first_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: %s is the admin's full name. */
                        esc_html__( 'Admin: %s', 'talenttrack' ),
                        '<strong>' . esc_html( trim( ( $admin['first_name'] ?? '' ) . ' ' . ( $admin['last_name'] ?? '' ) ) ) . '</strong>'
                    );
                ?></li>
            <?php endif; ?>
        </ul>
        <div class="tt-setup__actions">
            <button type="button" class="tt-btn tt-btn-secondary" data-tt-setup-reset>
                <?php esc_html_e( 'Run again', 'talenttrack' ); ?>
            </button>
            <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $dashboard_url ); ?>">
                <?php esc_html_e( 'Go to dashboard', 'talenttrack' ); ?>
            </a>
        </div>
        <?php
    }

    // Helpers

    /**
     * #3140 — the stepper listed five titles against a ten-step state
     * machine, so it showed a flow that looked complete right up to the
     * point it stopped. It now reads `OnboardingState::stepTitles()`, the
     * registry the wp-admin wizard reads, so the progress indicator names
     * every step the operator will actually walk through — including the
     * three this surface cannot render yet, which is the honest answer:
     * they are part of the flow whether or not this screen carries them.
     */
    private static function renderStepper( string $step ): void {
        $titles      = OnboardingState::stepTitles();
        $current_idx = array_search( $step, OnboardingState::STEPS, true );
        ?>
        <ol class="tt-setup__stepper">
            <?php
            $i = 0;
            foreach ( $titles as $slug => $label ) :
                $i++;
                $idx     = array_search( $slug, OnboardingState::STEPS, true );
                $is_curr = $slug === $step;
                $is_done = is_int( $idx ) && is_int( $current_idx ) && $idx < $current_idx;
                $cls     = 'tt-setup__step-item';
                if ( $is_curr )      $cls .= ' is-current';
                elseif ( $is_done )  $cls .= ' is-done';
                ?>
                <li class="<?php echo esc_attr( $cls ); ?>" aria-current="<?php echo $is_curr ? 'step' : 'false'; ?>">
                    <span class="tt-setup__step-num" aria-hidden="true"><?php echo $is_done ? '✓' : (string) $i; ?></span>
                    <span class="tt-setup__step-label"><?php echo esc_html( $label ); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    private static function breadcrumb(): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Setup', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'configuration', __( 'Configuration', 'talenttrack' ) ) ]
        );
    }

    private static function configUrl(): string {
        return add_query_arg(
            [ 'tt_view' => 'configuration' ],
            remove_query_arg( [ 'tt_view', 'force_welcome' ] )
        );
    }

    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-frontend-setup',
            TT_PLUGIN_URL . 'assets/css/frontend-setup.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-setup',
            TT_PLUGIN_URL . 'assets/js/frontend-setup.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-frontend-setup',
            'TT_Setup',
            [
                'i18n' => [
                    'saved'         => __( 'Saved.', 'talenttrack' ),
                    'error'         => __( 'Could not save. Please try again.', 'talenttrack' ),
                    'network_error' => __( 'Network error. Please try again.', 'talenttrack' ),
                    'creating'      => __( 'Creating…', 'talenttrack' ),
                    // #3260 — the import step's own two.
                    'choose_file'   => __( 'Choose a workbook to upload first.', 'talenttrack' ),
                    'checking'      => __( 'Reading the workbook…', 'talenttrack' ),
                    'reset_confirm' => __( 'Start over? Your saved progress for this flow is cleared. Data you already created (teams, staff, pages) is kept.', 'talenttrack' ),
                ],
            ]
        );
    }
}
