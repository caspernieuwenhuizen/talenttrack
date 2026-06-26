<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Onboarding\OnboardingState;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

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
                    case 'dashboard':   self::renderDashboard( $cancel_url );  break;
                    case 'done':        self::renderDone();                    break;
                    default:
                        echo '<p>' . esc_html__( 'Unknown step.', 'talenttrack' ) . '</p>';
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
                <?php esc_html_e( 'Create page & set as homepage', 'talenttrack' ); ?>
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

    private static function renderStepper( string $step ): void {
        $titles = [
            'academy'     => __( 'Academy basics', 'talenttrack' ),
            'first_team'  => __( 'First team', 'talenttrack' ),
            'first_admin' => __( 'First admin', 'talenttrack' ),
            'dashboard'   => __( 'Dashboard page', 'talenttrack' ),
            'done'        => __( 'Done', 'talenttrack' ),
        ];
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
                    'reset_confirm' => __( 'Start over? Your saved progress for this flow is cleared. Data you already created (teams, staff, pages) is kept.', 'talenttrack' ),
                ],
            ]
        );
    }
}
