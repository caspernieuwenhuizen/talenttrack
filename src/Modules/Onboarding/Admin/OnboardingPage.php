<?php
namespace TT\Modules\Onboarding\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Onboarding\OnboardingState;

/**
 * OnboardingPage — renders the `TalentTrack → Welcome` wizard.
 *
 * Five steps, dispatched from a single admin page that reads the
 * current step from OnboardingState. Step transitions happen via
 * admin-post.php handlers (see OnboardingHandlers); this class is
 * pure rendering.
 *
 * Inline admin page (Q2 decision) — no full-screen takeover. Reuses
 * the standard wp-admin chrome so localization and accessibility
 * work without custom plumbing.
 */
class OnboardingPage {

    public const SLUG = 'tt-welcome';
    public const CAP  = 'tt_edit_settings';

    public static function init(): void {
        // Menu entry registered in Shared\Admin\Menu, not here, so it
        // appears alongside the other TT submenus and respects the
        // legacy-menu toggle from #0019 Sprint 6 in a controlled way.
        // OnboardingPage::render() is the callback Menu hands off to.
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        // Force-welcome param (used by the Reset link) overrides the
        // completion check so a completed install can re-enter the
        // wizard once the user explicitly asked to.
        $force = isset( $_GET['force_welcome'] ) && $_GET['force_welcome'] === '1';
        if ( OnboardingState::isCompleted() && ! $force ) {
            ?>
            <div class="wrap">
                <h1><?php esc_html_e( 'Setup wizard', 'talenttrack' ); ?></h1>
                <p><?php esc_html_e( 'Setup is complete.', 'talenttrack' ); ?>
                    <a href="<?php echo esc_url( self::actionUrl( 'tt_onboarding_reset' ) ); ?>">
                        <?php esc_html_e( 'Reset wizard', 'talenttrack' ); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        $state = OnboardingState::get();
        $step  = $state['step'];

        echo '<div class="wrap tt-onboarding-wrap">';
        self::renderHeader( $step );
        self::renderNotice();
        echo '<div class="tt-onboarding-step">';
        switch ( $step ) {
            case 'welcome':     self::renderWelcome();    break;
            case 'academy':     self::renderAcademy();    break;
            case 'first_team':  self::renderFirstTeam();  break;
            case 'first_admin': self::renderFirstAdmin(); break;
            case 'done':        self::renderDone();       break;
            default:
                echo '<p>' . esc_html__( 'Unknown step.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';
        self::renderResetLink();
        echo '</div>';
    }

    /* ═══════════════ Step renderers ═══════════════ */

    private static function renderWelcome(): void {
        ?>
        <h2><?php esc_html_e( 'Set up your academy', 'talenttrack' ); ?></h2>
        <p style="max-width:680px;">
            <?php esc_html_e( 'TalentTrack is a youth football talent management plugin. This wizard creates your first team, your admin profile, and a few defaults so you can start tracking players today. Each step takes about a minute.', 'talenttrack' ); ?>
        </p>
        <p style="max-width:680px;">
            <?php esc_html_e( 'If you would rather see what TalentTrack looks like with sample data first, the demo button below loads a realistic academy dataset. You can wipe it and start over at any time.', 'talenttrack' ); ?>
        </p>
        <p style="margin-top:24px; display:flex; gap:12px; flex-wrap:wrap;">
            <a class="button button-primary button-hero" href="<?php echo esc_url( self::actionUrl( 'tt_onboarding_advance', [ 'from' => 'welcome' ] ) ); ?>">
                <?php esc_html_e( 'Set up my academy', 'talenttrack' ); ?>
            </a>
            <a class="button button-secondary button-hero" href="<?php echo esc_url( self::actionUrl( 'tt_onboarding_demo' ) ); ?>">
                <?php esc_html_e( 'Try with sample data', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }

    private static function renderAcademy(): void {
        $payload = OnboardingState::payloadFor( 'academy' );
        $values  = [
            'academy_name'    => (string) ( $payload['academy_name']    ?? QueryHelpers::get_config( 'academy_name', '' ) ),
            'primary_color'   => (string) ( $payload['primary_color']   ?? QueryHelpers::get_config( 'primary_color', '#0b3d2e' ) ),
            'season_label'    => (string) ( $payload['season_label']    ?? QueryHelpers::get_config( 'season_label', '' ) ),
            'date_format'     => (string) ( $payload['date_format']     ?? QueryHelpers::get_config( 'date_format_pref', 'Y-m-d' ) ),
        ];
        ?>
        <h2><?php esc_html_e( 'Academy basics', 'talenttrack' ); ?></h2>
        <p style="max-width:680px;">
            <?php esc_html_e( 'These show up across the plugin: in the dashboard header, on player rate cards, and in printed reports. You can change them later under Configuration.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_onboarding_academy', 'tt_onboarding_nonce' ); ?>
            <input type="hidden" name="action" value="tt_onboarding_academy" />
            <table class="form-table">
                <tr>
                    <th><label for="tt_ob_academy_name"><?php esc_html_e( 'Academy name', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="text" id="tt_ob_academy_name" name="academy_name" class="regular-text" required value="<?php echo esc_attr( $values['academy_name'] ); ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_ob_primary_color"><?php esc_html_e( 'Primary color', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="color" id="tt_ob_primary_color" name="primary_color" value="<?php echo esc_attr( $values['primary_color'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Used for headers, links, and the FIFA-style player card.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_ob_season_label"><?php esc_html_e( 'Season label', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="text" id="tt_ob_season_label" name="season_label" class="regular-text" placeholder="2025/2026" value="<?php echo esc_attr( $values['season_label'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Free-form. Most clubs use "2025/2026" or similar.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_ob_date_format"><?php esc_html_e( 'Date format', 'talenttrack' ); ?></label></th>
                    <td>
                        <select id="tt_ob_date_format" name="date_format">
                            <option value="Y-m-d"   <?php selected( $values['date_format'], 'Y-m-d' );   ?>>2026-04-25 (Y-m-d)</option>
                            <option value="d-m-Y"   <?php selected( $values['date_format'], 'd-m-Y' );   ?>>25-04-2026 (d-m-Y)</option>
                            <option value="d/m/Y"   <?php selected( $values['date_format'], 'd/m/Y' );   ?>>25/04/2026 (d/m/Y)</option>
                            <option value="m/d/Y"   <?php selected( $values['date_format'], 'm/d/Y' );   ?>>04/25/2026 (m/d/Y)</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Continue', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    private static function renderFirstTeam(): void {
        $payload    = OnboardingState::payloadFor( 'first_team' );
        $values     = [
            'team_name' => (string) ( $payload['team_name'] ?? '' ),
            'age_group' => (string) ( $payload['age_group'] ?? '' ),
        ];
        $age_groups = QueryHelpers::get_lookup_names( 'age_group' );
        ?>
        <h2><?php esc_html_e( 'First team', 'talenttrack' ); ?></h2>
        <p style="max-width:680px;">
            <?php esc_html_e( 'Add one team now. You can add more later under Teams. Players, evaluations, sessions, and goals all attach to a team, so we need at least one to make the rest of the plugin useful.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_onboarding_first_team', 'tt_onboarding_nonce' ); ?>
            <input type="hidden" name="action" value="tt_onboarding_first_team" />
            <table class="form-table">
                <tr>
                    <th><label for="tt_ob_team_name"><?php esc_html_e( 'Team name', 'talenttrack' ); ?></label></th>
                    <td>
                        <input type="text" id="tt_ob_team_name" name="team_name" class="regular-text" required value="<?php echo esc_attr( $values['team_name'] ); ?>" />
                    </td>
                </tr>
                <tr>
                    <th><label for="tt_ob_age_group"><?php esc_html_e( 'Age group', 'talenttrack' ); ?></label></th>
                    <td>
                        <select id="tt_ob_age_group" name="age_group">
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( $age_groups as $ag ) : ?>
                                <option value="<?php echo esc_attr( $ag ); ?>" <?php selected( $values['age_group'], $ag ); ?>><?php echo esc_html( $ag ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Pick the closest match. New age groups can be added under Configuration → Lookups.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Continue', 'talenttrack' ) ); ?>
            <a class="button" href="<?php echo esc_url( self::actionUrl( 'tt_onboarding_advance', [ 'from' => 'first_team', 'skip' => '1' ] ) ); ?>"><?php esc_html_e( 'Skip', 'talenttrack' ); ?></a>
        </form>
        <?php
    }

    private static function renderFirstAdmin(): void {
        $user        = wp_get_current_user();
        $name        = $user ? ( trim( (string) $user->display_name ) ?: (string) $user->user_login ) : '';
        $email       = $user ? (string) $user->user_email : '';
        $payload     = OnboardingState::payloadFor( 'first_admin' );
        $first_name  = (string) ( $payload['first_name'] ?? ( $user ? (string) $user->first_name : '' ) );
        $last_name   = (string) ( $payload['last_name']  ?? ( $user ? (string) $user->last_name  : '' ) );
        $grant_role  = isset( $payload['grant_role'] ) ? (bool) $payload['grant_role'] : true;
        ?>
        <h2><?php esc_html_e( 'First admin', 'talenttrack' ); ?></h2>
        <p style="max-width:680px;">
            <?php
            printf(
                /* translators: %s is the WP display name + email of the current user */
                esc_html__( 'You are signed in as %s. We will create a TalentTrack staff record for you and link it to your WP account so evaluations, sessions, and notifications all reference the right person.', 'talenttrack' ),
                '<strong>' . esc_html( $name . ( $email ? " ($email)" : '' ) ) . '</strong>'
            );
            ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_onboarding_first_admin', 'tt_onboarding_nonce' ); ?>
            <input type="hidden" name="action" value="tt_onboarding_first_admin" />
            <table class="form-table">
                <tr>
                    <th><label for="tt_ob_first_name"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label></th>
                    <td><input type="text" id="tt_ob_first_name" name="first_name" class="regular-text" required value="<?php echo esc_attr( $first_name ); ?>" /></td>
                </tr>
                <tr>
                    <th><label for="tt_ob_last_name"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label></th>
                    <td><input type="text" id="tt_ob_last_name" name="last_name" class="regular-text" required value="<?php echo esc_attr( $last_name ); ?>" /></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Club admin role', 'talenttrack' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="grant_role" value="1" <?php checked( $grant_role ); ?> />
                            <?php esc_html_e( 'Grant me the Club Admin role (recommended)', 'talenttrack' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Club Admins can manage all teams, players, evaluations, and configuration.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Continue', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    private static function renderDone(): void {
        $academy = OnboardingState::payloadFor( 'academy' );
        $team    = OnboardingState::payloadFor( 'first_team' );
        $admin   = OnboardingState::payloadFor( 'first_admin' );
        ?>
        <h2><?php esc_html_e( 'Setup complete', 'talenttrack' ); ?></h2>
        <p><?php esc_html_e( 'You are ready to use TalentTrack. Here is what was set up:', 'talenttrack' ); ?></p>
        <ul style="list-style:disc; margin-left:24px;">
            <?php if ( ! empty( $academy['academy_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: %s is the academy name */
                        esc_html__( 'Academy: %s', 'talenttrack' ),
                        '<strong>' . esc_html( (string) $academy['academy_name'] ) . '</strong>'
                    );
                ?></li>
            <?php endif; ?>
            <?php if ( ! empty( $team['team_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: 1: team name, 2: age group */
                        esc_html__( 'Team: %1$s (%2$s)', 'talenttrack' ),
                        '<strong>' . esc_html( (string) $team['team_name'] ) . '</strong>',
                        esc_html( (string) ( $team['age_group'] ?? '—' ) )
                    );
                ?></li>
            <?php endif; ?>
            <?php if ( ! empty( $admin['first_name'] ) ) : ?>
                <li><?php
                    printf(
                        /* translators: %s is the admin's full name */
                        esc_html__( 'Admin: %s', 'talenttrack' ),
                        '<strong>' . esc_html( trim( ( $admin['first_name'] ?? '' ) . ' ' . ( $admin['last_name'] ?? '' ) ) ) . '</strong>'
                    );
                ?></li>
            <?php endif; ?>
        </ul>

        <h3 style="margin-top:32px;"><?php esc_html_e( 'Recommended next steps', 'talenttrack' ); ?></h3>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(240px, 1fr)); gap:12px; max-width:900px;">
            <?php
            self::renderNextStepCard(
                __( 'Add players', 'talenttrack' ),
                __( 'Build out your roster — by hand or via CSV import.', 'talenttrack' ),
                admin_url( 'admin.php?page=tt-players&action=new' )
            );
            self::renderNextStepCard(
                __( 'Invite first coach', 'talenttrack' ),
                __( 'Add a coach as a staff record so they can record evaluations.', 'talenttrack' ),
                admin_url( 'admin.php?page=tt-people&action=new' )
            );
            self::renderNextStepCard(
                __( 'Customize branding', 'talenttrack' ),
                __( 'Logo, colors, secondary palette, theme inheritance.', 'talenttrack' ),
                admin_url( 'admin.php?page=tt-config&tab=branding' )
            );
            self::renderNextStepCard(
                __( 'Create dashboard page', 'talenttrack' ),
                __( 'A frontend page with the [talenttrack_dashboard] shortcode so coaches and players can sign in.', 'talenttrack' ),
                self::actionUrl( 'tt_onboarding_create_dashboard_page' )
            );
            self::renderNextStepCard(
                __( 'Set up backups', 'talenttrack' ),
                __( 'Schedule daily backups so a hosting hiccup does not lose your data.', 'talenttrack' ),
                admin_url( 'admin.php?page=tt-config&tab=backups' )
            );
            ?>
        </div>

        <p style="margin-top:32px;">
            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=talenttrack' ) ); ?>">
                <?php esc_html_e( 'Go to dashboard', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }

    /* ═══════════════ Helpers ═══════════════ */

    private static function renderHeader( string $step ): void {
        $titles = [
            'welcome'     => __( '1. Welcome', 'talenttrack' ),
            'academy'     => __( '2. Academy basics', 'talenttrack' ),
            'first_team'  => __( '3. First team', 'talenttrack' ),
            'first_admin' => __( '4. First admin', 'talenttrack' ),
            'done'        => __( '5. Done', 'talenttrack' ),
        ];
        $current_idx = array_search( $step, OnboardingState::STEPS, true );
        ?>
        <h1><?php esc_html_e( 'TalentTrack — Setup wizard', 'talenttrack' ); ?></h1>
        <ol style="display:flex; gap:16px; padding:0; margin:8px 0 24px; list-style:none; flex-wrap:wrap;">
            <?php foreach ( $titles as $slug => $label ) :
                $idx     = array_search( $slug, OnboardingState::STEPS, true );
                $is_curr = $slug === $step;
                $is_done = is_int( $idx ) && is_int( $current_idx ) && $idx < $current_idx;
                $color   = $is_curr ? '#0b3d2e' : ( $is_done ? '#1d7874' : '#aaa' );
                $weight  = $is_curr ? '600' : '400';
                ?>
                <li style="color:<?php echo esc_attr( $color ); ?>; font-weight:<?php echo esc_attr( $weight ); ?>; font-size:13px;">
                    <?php echo $is_done ? '✓ ' : ''; ?><?php echo esc_html( $label ); ?>
                </li>
            <?php endforeach; ?>
        </ol>
        <?php
    }

    private static function renderNotice(): void {
        if ( ! isset( $_GET['tt_ob_msg'] ) ) return;
        $msg = sanitize_text_field( wp_unslash( (string) $_GET['tt_ob_msg'] ) );
        $map = [
            'saved'      => __( 'Saved.', 'talenttrack' ),
            'team_made'  => __( 'Team created.', 'talenttrack' ),
            'admin_made' => __( 'Admin record created.', 'talenttrack' ),
            'reset'      => __( 'Wizard reset.', 'talenttrack' ),
            'page_made'  => __( 'Frontend dashboard page created.', 'talenttrack' ),
        ];
        if ( ! isset( $map[ $msg ] ) ) return;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
    }

    private static function renderResetLink(): void {
        $state = OnboardingState::get();
        if ( $state['step'] === 'welcome' && empty( $state['payload'] ) ) return;
        ?>
        <p style="margin-top:32px; font-size:12px; color:#777;">
            <a href="<?php echo esc_url( self::actionUrl( 'tt_onboarding_reset' ) ); ?>" style="color:#777;">
                <?php esc_html_e( 'Reset wizard', 'talenttrack' ); ?>
            </a>
        </p>
        <?php
    }

    /**
     * @param string $title
     * @param string $desc
     * @param string $url
     */
    private static function renderNextStepCard( string $title, string $desc, string $url ): void {
        ?>
        <a href="<?php echo esc_url( $url ); ?>" style="display:block; padding:16px; border:1px solid #dcdcde; border-radius:6px; background:#fff; text-decoration:none; color:#1a1d21;">
            <strong style="display:block; margin-bottom:4px;"><?php echo esc_html( $title ); ?></strong>
            <span style="color:#666; font-size:13px;"><?php echo esc_html( $desc ); ?></span>
        </a>
        <?php
    }

    /**
     * Build a nonce-protected admin-post.php URL for a TT onboarding action.
     *
     * @param array<string,scalar> $extra
     */
    public static function actionUrl( string $action, array $extra = [] ): string {
        $url = wp_nonce_url(
            add_query_arg(
                array_merge( [ 'action' => $action ], $extra ),
                admin_url( 'admin-post.php' )
            ),
            $action,
            'tt_onboarding_nonce'
        );
        return $url;
    }
}
