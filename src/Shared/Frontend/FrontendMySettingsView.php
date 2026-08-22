<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendMySettingsView — TT-rendered account settings (#0061 round 3).
 *
 * Replaces the old "Edit profile" link in the dashboard user menu that
 * used to bounce the user out to `wp-admin/profile.php`. This surface
 * is intentionally narrow:
 *
 *   - Display name
 *   - First / last name
 *   - Email
 *   - Password (change-only — confirm-current required)
 *
 * Out of scope: application passwords, color schemes, admin colour
 * palettes, biographical info — those are wp-admin features that
 * confuse end users on the frontend.
 *
 * Saves through the existing WP user APIs (`wp_update_user` for
 * profile fields, `check_password_reset_key` is not used because
 * this is an authenticated change-password flow).
 */
class FrontendMySettingsView extends FrontendViewBase {

    /**
     * Enqueue the 2026 settings stylesheet on top of the shared chrome.
     * Depends on tt-frontend-app-chrome for the brand tokens.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-my-settings',
            TT_PLUGIN_URL . 'assets/css/frontend-my-settings.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /**
     * v3.92.0 — accepts an optional player record. The render no longer
     * uses it (display name / password are WP-user concerns, not
     * player-record concerns), but the param stays nullable so the
     * Me-view dispatch path keeps working when a player happens to
     * navigate here. The dispatcher in v3.92.0 routes my-settings via
     * a separate $account_slugs branch that doesn't require a player.
     */
    public static function render( ?object $player = null ): void {
        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My settings', 'talenttrack' ) );
        self::renderHeader( __( 'My settings', 'talenttrack' ) );

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'You need to be logged in to manage your settings.', 'talenttrack' ) . '</p>';
            return;
        }

        $messages = self::handlePost( $user_id );
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            echo '<p class="tt-notice">' . esc_html__( 'Could not load your account.', 'talenttrack' ) . '</p>';
            return;
        }

        if ( $messages['success'] !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $messages['success'] ) . '</div>';
        }
        foreach ( $messages['errors'] as $err ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html( $err ) . '</div>';
        }

        ?>
        <div class="tt-msettings">
            <form method="post" class="tt-form tt-msettings-card">
                <?php wp_nonce_field( 'tt_my_settings_profile', 'tt_my_settings_profile_nonce' ); ?>
                <input type="hidden" name="tt_my_settings_action" value="update_profile" />

                <h3><?php esc_html_e( 'Profile', 'talenttrack' ); ?></h3>

                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-first-name"><?php esc_html_e( 'First name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-first-name" name="first_name" class="tt-input" autocomplete="given-name" value="<?php echo esc_attr( (string) $user->first_name ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ms-last-name"><?php esc_html_e( 'Last name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-ms-last-name" name="last_name" class="tt-input" autocomplete="family-name" value="<?php echo esc_attr( (string) $user->last_name ); ?>" />
                    </div>
                </div>

                <?php
                // #1820 — a player's display name is system-owned (set
                // from their player record as "First Last"); they can't
                // edit it here. The server-side guard in handlePost()
                // enforces this even if the readonly attribute is removed.
                $display_locked = in_array( 'tt_player', (array) $user->roles, true );
                ?>
                <div class="tt-field">
                    <label class="tt-field-label" for="tt-ms-display"><?php esc_html_e( 'Display name', 'talenttrack' ); ?></label>
                    <input type="text" id="tt-ms-display" name="display_name" class="tt-input" autocomplete="nickname" value="<?php echo esc_attr( (string) $user->display_name ); ?>"<?php echo $display_locked ? ' readonly' : ''; ?> />
                    <p class="tt-field-hint">
                        <?php
                        echo $display_locked
                            ? esc_html__( 'Set by your academy from your name — this can\'t be changed here.', 'talenttrack' )
                            : esc_html__( 'How your name appears to coaches and teammates.', 'talenttrack' );
                        ?>
                    </p>
                </div>

                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-ms-email"><?php esc_html_e( 'Email', 'talenttrack' ); ?></label>
                    <input type="email" inputmode="email" id="tt-ms-email" name="user_email" class="tt-input" autocomplete="email" required value="<?php echo esc_attr( (string) $user->user_email ); ?>" />
                </div>

                <div class="tt-form-actions">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save profile', 'talenttrack' ); ?></button>
                </div>
            </form>

            <form method="post" class="tt-form tt-msettings-card">
                <?php wp_nonce_field( 'tt_my_settings_password', 'tt_my_settings_password_nonce' ); ?>
                <input type="hidden" name="tt_my_settings_action" value="change_password" />

                <h3><?php esc_html_e( 'Change password', 'talenttrack' ); ?></h3>

                <div class="tt-field">
                    <label class="tt-field-label tt-field-required" for="tt-ms-current"><?php esc_html_e( 'Current password', 'talenttrack' ); ?></label>
                    <input type="password" id="tt-ms-current" name="current_password" class="tt-input" autocomplete="current-password" required />
                </div>
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label tt-field-required" for="tt-ms-new"><?php esc_html_e( 'New password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-ms-new" name="new_password" class="tt-input" autocomplete="new-password" minlength="8" required />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label tt-field-required" for="tt-ms-confirm"><?php esc_html_e( 'Confirm new password', 'talenttrack' ); ?></label>
                        <input type="password" id="tt-ms-confirm" name="confirm_password" class="tt-input" autocomplete="new-password" minlength="8" required />
                    </div>
                </div>
                <p class="tt-field-hint" style="margin:0 0 12px;"><?php esc_html_e( 'At least 8 characters. Saving will end any other active sessions.', 'talenttrack' ); ?></p>

                <div class="tt-form-actions">
                    <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Change password', 'talenttrack' ); ?></button>
                </div>
            </form>

            <?php self::renderAppearanceCard( $user_id ); ?>
            <?php self::renderThemeCard( $user_id ); ?>

            <?php self::renderMessagePreferencesCard( $user_id ); ?>

            <?php self::renderParentVisibilityCard( $user_id ); ?>
        </div>
        <?php
    }

    /**
     * #2603 — per-message-type opt-out. GDPR requires it, and until now
     * `OptOutPolicy` read a preference that no screen could write: a parent
     * had no way to mute anything.
     *
     * Everything is on by default, so an existing user's mail is unchanged
     * until they choose otherwise. Operational messages (safeguarding, and
     * account recovery) are shown as always-on with the reason rather than
     * hidden — a preferences screen that quietly omits the messages you
     * cannot refuse is the same silent behaviour #2602 set out to remove.
     */
    private static function renderMessagePreferencesCard( int $user_id ): void {
        $policy = new \TT\Modules\Comms\OptOut\OptOutPolicy();
        $labels = self::messageTypeLabels();
        ?>
        <form method="post" class="tt-form tt-msettings-card">
            <?php wp_nonce_field( 'tt_my_settings_comms', 'tt_my_settings_comms_nonce' ); ?>
            <input type="hidden" name="tt_my_settings_action" value="update_comms_optout" />

            <h3><?php esc_html_e( 'Messages you receive', 'talenttrack' ); ?></h3>
            <p class="tt-field-hint">
                <?php esc_html_e( 'Choose which messages the academy may send you. Unticking one stops it on every channel. You will only ever receive messages that concern you or your own child.', 'talenttrack' ); ?>
            </p>

            <?php foreach ( $labels as $type => $label ) :
                $checked  = ! $policy->isOptedOut( $user_id, $type );
                $field_id = 'tt-comms-opt-' . sanitize_html_class( $type );
                ?>
                <div class="tt-field tt-visibility-row">
                    <label class="tt-visibility-toggle" for="<?php echo esc_attr( $field_id ); ?>">
                        <input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="comms_opt_in[]" value="<?php echo esc_attr( $type ); ?>"<?php echo $checked ? ' checked' : ''; ?> />
                        <span><?php echo esc_html( $label ); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>

            <?php foreach ( self::operationalMessageTypeLabels() as $op_label ) : ?>
                <div class="tt-field tt-visibility-row">
                    <label class="tt-visibility-toggle">
                        <input type="checkbox" checked disabled />
                        <span><?php echo esc_html( $op_label ); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
            <p class="tt-field-hint">
                <?php esc_html_e( 'Safeguarding messages, and messages about getting back into your account, are always sent. They cannot be switched off.', 'talenttrack' ); ?>
            </p>

            <?php
            // #2632 — the other half of "what reaches me". Alerts are things
            // the app surfaces to you about your own data; this card is about
            // what the academy sends you. Two screens was a deliberate choice
            // (epic #2629 decision 11), and this link is what stops that
            // choice stranding someone who only knows they want less noise.
            $tt_alert_settings_url = add_query_arg(
                [ 'tt_view' => \TT\Modules\Alerts\Frontend\FrontendAlertSettingsView::SLUG ],
                \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
            );
            ?>
            <?php
            // Routed through CrossViewLink (#2304): the affordance hides
            // itself for anyone who cannot reach the target.
            \TT\Shared\Frontend\Components\CrossViewLink::render(
                \TT\Modules\Alerts\Frontend\FrontendAlertSettingsView::SLUG,
                static function () use ( $tt_alert_settings_url ): void {
                    echo '<p class="tt-field-hint">';
                    printf(
                        /* translators: %s: link to the alert settings screen */
                        esc_html__( 'Alerts about your own data — unmarked activities, missing attendance — are set separately under %s.', 'talenttrack' ),
                        '<a href="' . esc_url( $tt_alert_settings_url ) . '">' . esc_html__( 'alert settings', 'talenttrack' ) . '</a>'
                    );
                    echo '</p>';
                }
            );
            ?>

            <div class="tt-form-actions">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save message preferences', 'talenttrack' ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * Message types a user may refuse, in display order.
     *
     * Deliberately the full non-operational set rather than a role-filtered
     * one: a coach and a parent both land on this screen, and a row for a
     * message you never receive costs nothing, while a missing row for one
     * you do receive is exactly the gap this card exists to close.
     *
     * @return array<string,string> message_type => user-facing label
     */
    private static function messageTypeLabels(): array {
        return [
            \TT\Modules\Comms\Domain\MessageType::TRAINING_CANCELLED         => __( 'A training is cancelled', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::SCHEDULE_CHANGE_FROM_SPOND => __( 'An activity changes time or place', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::SELECTION_LETTER           => __( 'Selection decisions', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::PDP_READY                  => __( 'A development plan is ready to read', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::PARENT_MEETING_INVITE      => __( 'Invitations to parent meetings', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::TRIAL_PLAYER_WELCOME       => __( 'Welcome messages for trial players', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::GUEST_PLAYER_INVITE        => __( 'Invitations to join an activity as a guest', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::GOAL_NUDGE                 => __( 'Reminders to update a goal', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::ATTENDANCE_FLAG            => __( 'Alerts about repeated absence', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::METHODOLOGY_DELIVERED      => __( 'New training plans are published', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::ONBOARDING_NUDGE_INACTIVE  => __( 'Reminders when you have not logged in for a while', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::STAFF_DEVELOPMENT_REMINDER => __( 'Reminders about your own development review', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::LETTER_DELIVERY            => __( 'Formal letters', 'talenttrack' ),
            \TT\Modules\Comms\Domain\MessageType::MASS_ANNOUNCEMENT          => __( 'Academy-wide announcements', 'talenttrack' ),
            // #2604 — notifications raised in-product (a task assigned to
            // you, a reply on a conversation) now route through Comms, so
            // they belong on this list like everything else that reaches you.
            \TT\Modules\Comms\Domain\MessageType::NOTIFICATION               => __( 'Notifications about your tasks and conversations', 'talenttrack' ),
        ];
    }

    /** @return string[] labels for the types that cannot be refused. */
    private static function operationalMessageTypeLabels(): array {
        return [
            __( 'Safeguarding messages', 'talenttrack' ),
            __( 'Getting back into your account', 'talenttrack' ),
        ];
    }

    /**
     * #2456 — per-user layout override.
     *
     * The operator picks a club-wide default; this lets an individual
     * follow it or pin the other shell. That matters during a layout
     * migration: a coach mid-season shouldn't be moved onto new chrome
     * because the club flipped a default, and an early adopter shouldn't
     * have to wait for the club to flip it.
     *
     * `inherit` is the default and deletes the meta, so a user who never
     * touches this keeps following the club — including when the operator
     * changes it later.
     */
    private static function renderAppearanceCard( int $user_id ): void {
        $current    = \TT\Shared\Frontend\ShellPreference::userOverride( $user_id );
        $club       = \TT\Shared\Frontend\ShellPreference::clubDefault();
        $labels     = \TT\Shared\Frontend\ShellPreference::labels();
        $club_label = $labels[ $club ] ?? $club;
        ?>
        <form method="post" class="tt-form tt-msettings-card">
            <?php wp_nonce_field( 'tt_my_settings_shell', 'tt_my_settings_shell_nonce' ); ?>
            <input type="hidden" name="tt_my_settings_action" value="update_shell" />

            <h3><?php esc_html_e( 'Layout', 'talenttrack' ); ?></h3>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-ms-shell"><?php esc_html_e( 'Navigation layout', 'talenttrack' ); ?></label>
                <select id="tt-ms-shell" name="tt_shell" class="tt-input">
                    <option value="<?php echo esc_attr( \TT\Shared\Frontend\ShellPreference::INHERIT ); ?>"<?php selected( $current, \TT\Shared\Frontend\ShellPreference::INHERIT ); ?>>
                        <?php
                        /* translators: %s: the club-wide default layout name. */
                        printf( esc_html__( 'Use the academy default (%s)', 'talenttrack' ), esc_html( $club_label ) );
                        ?>
                    </option>
                    <?php foreach ( $labels as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="tt-field-hint">
                    <?php esc_html_e( 'The app shell keeps a navigation sidebar on screen on a laptop, and a slide-out menu on a phone. Classic returns you to the tile overview to switch between sections.', 'talenttrack' ); ?>
                </p>
            </div>

            <div class="tt-form-actions">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save layout', 'talenttrack' ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * Per-user theme override (#2512).
     *
     * Its own form rather than a second field on the layout card: the two
     * settings are independent (a theme applies under either shell), and
     * CLAUDE.md §6(a) exempts settings sub-forms from Cancel + Save
     * precisely so several of them can sit on one page.
     *
     * `inherit` is the default and deletes the meta, so a user who never
     * touches this keeps following the club — including when the operator
     * changes it later.
     */
    private static function renderThemeCard( int $user_id ): void {
        $current    = \TT\Shared\Frontend\ThemePreference::userOverride( $user_id );
        $club       = \TT\Shared\Frontend\ThemePreference::clubDefault();
        $labels     = \TT\Shared\Frontend\ThemePreference::labels();
        $club_label = $labels[ $club ] ?? $club;
        ?>
        <form method="post" class="tt-form tt-msettings-card">
            <?php wp_nonce_field( 'tt_my_settings_theme', 'tt_my_settings_theme_nonce' ); ?>
            <input type="hidden" name="tt_my_settings_action" value="update_theme" />

            <h3><?php esc_html_e( 'Theme', 'talenttrack' ); ?></h3>

            <div class="tt-field">
                <label class="tt-field-label" for="tt-ms-theme"><?php esc_html_e( 'Visual theme', 'talenttrack' ); ?></label>
                <select id="tt-ms-theme" name="tt_theme" class="tt-input">
                    <option value="<?php echo esc_attr( \TT\Shared\Frontend\ThemePreference::INHERIT ); ?>"<?php selected( $current, \TT\Shared\Frontend\ThemePreference::INHERIT ); ?>>
                        <?php
                        /* translators: %s: the club-wide default theme name. */
                        printf( esc_html__( 'Use the academy default (%s)', 'talenttrack' ), esc_html( $club_label ) );
                        ?>
                    </option>
                    <?php foreach ( $labels as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>"<?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="tt-field-hint">
                    <?php esc_html_e( 'Changes colours, corners and heading type only — never what you can see or do. A theme supplies the whole colour scheme for the application; your academy logo and name are unaffected.', 'talenttrack' ); ?>
                </p>
            </div>

            <div class="tt-form-actions">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save theme', 'talenttrack' ); ?></button>
            </div>
        </form>
        <?php
    }

    /**
     * #1867 — "What your parent can see". Shown only to a player who has
     * a linked parent. Per-section toggles, default ON; turning one off
     * hides that section from the parent (rendered views + REST). The
     * player always sees their own record; safeguarding/medical stay
     * cap-gated and are not listed here.
     */
    private static function renderParentVisibilityCard( int $user_id ): void {
        $player = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $user_id );
        if ( ! $player ) return;
        $player_id = (int) $player->id;

        $parents = ( new \TT\Modules\Invitations\PlayerParentsRepository() )->parentsForPlayer( $player_id );
        if ( empty( $parents ) ) return; // No linked parent — nothing to control.

        $prefs  = ( new \TT\Infrastructure\Players\PlayerParentVisibilityRepository() )->preferencesForPlayer( $player_id );
        $labels = self::visibilitySectionLabels();
        \TT\Shared\Frontend\Components\FrontendPrivateSection::enqueue();
        ?>
        <form method="post" class="tt-form tt-msettings-card">
            <?php wp_nonce_field( 'tt_my_settings_visibility', 'tt_my_settings_visibility_nonce' ); ?>
            <input type="hidden" name="tt_my_settings_action" value="update_parent_visibility" />

            <h3><?php esc_html_e( 'What your parent can see', 'talenttrack' ); ?></h3>
            <p class="tt-field-hint">
                <?php esc_html_e( 'Choose which parts of your record your parent or guardian can see. Everything is shared by default. Your coaches and the academy are not affected by these choices.', 'talenttrack' ); ?>
            </p>

            <?php foreach ( $labels as $key => $label ) :
                $checked = ! empty( $prefs[ $key ] );
                ?>
                <div class="tt-field tt-visibility-row">
                    <label class="tt-visibility-toggle" for="tt-vis-<?php echo esc_attr( $key ); ?>">
                        <input type="checkbox" id="tt-vis-<?php echo esc_attr( $key ); ?>" name="visible_sections[]" value="<?php echo esc_attr( $key ); ?>"<?php echo $checked ? ' checked' : ''; ?> />
                        <span><?php echo esc_html( $label ); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>

            <div class="tt-form-actions">
                <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save visibility', 'talenttrack' ); ?></button>
            </div>
        </form>
        <?php
    }

    /** @return array<string,string> section_key => user-facing label, in display order. */
    private static function visibilitySectionLabels(): array {
        return [
            'evaluations'  => __( 'Evaluations', 'talenttrack' ),
            'goals'        => __( 'Goals', 'talenttrack' ),
            'journey'      => __( 'Journey', 'talenttrack' ),
            'measurements' => __( 'Measurements', 'talenttrack' ),
            'pdp'          => __( 'Development plan (PDP)', 'talenttrack' ),
            // #2500 — minutes trained per principle, and which ones have
            // never been trained.
            'training'     => __( 'Training history', 'talenttrack' ),
        ];
    }

    /**
     * Handle POST. Returns messages for the next render. Mirrors the
     * pattern used by FrontendActivitiesManageView::handlePost — keep
     * the surface narrow, hand off to WP's update APIs for the actual
     * mutation, and surface results inline rather than via redirects.
     *
     * @return array{success:string,errors:string[]}
     */
    private static function handlePost( int $user_id ): array {
        $out = [ 'success' => '', 'errors' => [] ];
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return $out;
        $action = isset( $_POST['tt_my_settings_action'] ) ? sanitize_key( (string) $_POST['tt_my_settings_action'] ) : '';
        if ( $action === '' ) return $out;

        // #2456 — per-user layout override. No capability beyond being
        // logged in: this only changes how the current user's own chrome
        // renders, and ShellPreference rejects any value that is not a
        // known shell or `inherit`.
        if ( $action === 'update_shell' ) {
            if ( ! isset( $_POST['tt_my_settings_shell_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_shell_nonce'] ) ), 'tt_my_settings_shell' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            \TT\Shared\Frontend\ShellPreference::setUserOverride(
                $user_id,
                sanitize_key( wp_unslash( (string) ( $_POST['tt_shell'] ?? '' ) ) )
            );
            $out['success'] = __( 'Layout updated. Reload to see the change everywhere.', 'talenttrack' );
            return $out;
        }

        // #2512 — per-user theme override. Same reasoning as the layout
        // above: appearance only, and ThemePreference rejects any value
        // that is not a known theme or `inherit`.
        if ( $action === 'update_theme' ) {
            if ( ! isset( $_POST['tt_my_settings_theme_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_theme_nonce'] ) ), 'tt_my_settings_theme' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            \TT\Shared\Frontend\ThemePreference::setUserOverride(
                $user_id,
                sanitize_key( wp_unslash( (string) ( $_POST['tt_theme'] ?? '' ) ) )
            );
            $out['success'] = __( 'Theme updated. Reload to see the change everywhere.', 'talenttrack' );
            return $out;
        }

        if ( $action === 'update_profile' ) {
            if ( ! isset( $_POST['tt_my_settings_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_profile_nonce'] ) ), 'tt_my_settings_profile' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $payload = [
                'ID'           => $user_id,
                'first_name'   => sanitize_text_field( wp_unslash( (string) ( $_POST['first_name']   ?? '' ) ) ),
                'last_name'    => sanitize_text_field( wp_unslash( (string) ( $_POST['last_name']    ?? '' ) ) ),
                'user_email'   => sanitize_email( wp_unslash( (string) ( $_POST['user_email']   ?? '' ) ) ),
            ];
            // #1820 — display name is system-owned for players; ignore any
            // submitted value so they can't change it (the field is
            // readonly in the form, this enforces it server-side).
            $editor = get_userdata( $user_id );
            $is_player = $editor && in_array( 'tt_player', (array) $editor->roles, true );
            if ( ! $is_player ) {
                $payload['display_name'] = sanitize_text_field( wp_unslash( (string) ( $_POST['display_name'] ?? '' ) ) );
            }
            if ( $payload['user_email'] === '' || ! is_email( $payload['user_email'] ) ) {
                $out['errors'][] = __( 'Please enter a valid email address.', 'talenttrack' );
                return $out;
            }
            $res = wp_update_user( $payload );
            if ( is_wp_error( $res ) ) {
                $out['errors'][] = (string) $res->get_error_message();
                return $out;
            }
            $out['success'] = __( 'Profile saved.', 'talenttrack' );
            return $out;
        }

        if ( $action === 'change_password' ) {
            if ( ! isset( $_POST['tt_my_settings_password_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_password_nonce'] ) ), 'tt_my_settings_password' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $current = (string) ( $_POST['current_password'] ?? '' );
            $new     = (string) ( $_POST['new_password']     ?? '' );
            $confirm = (string) ( $_POST['confirm_password'] ?? '' );
            if ( $current === '' || $new === '' ) {
                $out['errors'][] = __( 'Please fill in both your current and new password.', 'talenttrack' );
                return $out;
            }
            if ( strlen( $new ) < 8 ) {
                $out['errors'][] = __( 'New password must be at least 8 characters.', 'talenttrack' );
                return $out;
            }
            if ( $new !== $confirm ) {
                $out['errors'][] = __( 'New password and confirmation do not match.', 'talenttrack' );
                return $out;
            }
            $user = get_userdata( $user_id );
            if ( ! $user || ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
                $out['errors'][] = __( 'Current password is incorrect.', 'talenttrack' );
                return $out;
            }
            wp_set_password( $new, $user_id );
            // wp_set_password logs the user out of every session including this one.
            // Re-authenticate the current session so the response renders as the
            // logged-in user. Best-effort — if wp_signon fails the user just sees
            // the login screen on the next request.
            wp_set_auth_cookie( $user_id, true );
            wp_set_current_user( $user_id );
            $out['success'] = __( 'Password changed. Other devices have been logged out.', 'talenttrack' );
            return $out;
        }

        // #2603 — per-message-type opt-out. No capability beyond being
        // logged in: this only changes what the current user receives.
        // `OptOutPolicy::setOptedOut` refuses operational types server-side,
        // so a forged payload cannot mute a safeguarding message even
        // though the form renders those rows as disabled.
        if ( $action === 'update_comms_optout' ) {
            if ( ! isset( $_POST['tt_my_settings_comms_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_comms_nonce'] ) ), 'tt_my_settings_comms' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $opted_in = isset( $_POST['comms_opt_in'] ) && is_array( $_POST['comms_opt_in'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['comms_opt_in'] ) )
                : [];
            $policy = new \TT\Modules\Comms\OptOut\OptOutPolicy();
            foreach ( array_keys( self::messageTypeLabels() ) as $type ) {
                $policy->setOptedOut( $user_id, $type, ! in_array( $type, $opted_in, true ) );
            }
            $out['success'] = __( 'Message preferences saved.', 'talenttrack' );
            return $out;
        }

        if ( $action === 'update_parent_visibility' ) {
            if ( ! isset( $_POST['tt_my_settings_visibility_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_my_settings_visibility_nonce'] ) ), 'tt_my_settings_visibility' ) ) {
                $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
                return $out;
            }
            $player = \TT\Infrastructure\Query\QueryHelpers::get_player_for_user( $user_id );
            if ( ! $player ) {
                $out['errors'][] = __( 'Only players can set parent visibility.', 'talenttrack' );
                return $out;
            }
            $checked = isset( $_POST['visible_sections'] ) && is_array( $_POST['visible_sections'] )
                ? array_map( 'sanitize_key', wp_unslash( $_POST['visible_sections'] ) )
                : [];
            $repo = new \TT\Infrastructure\Players\PlayerParentVisibilityRepository();
            foreach ( \TT\Infrastructure\Players\PlayerParentVisibilityRepository::SECTIONS as $section ) {
                $repo->setVisibility( (int) $player->id, $section, in_array( $section, $checked, true ) );
            }
            $out['success'] = __( 'Saved what your parent can see.', 'talenttrack' );
            return $out;
        }

        return $out;
    }
}
