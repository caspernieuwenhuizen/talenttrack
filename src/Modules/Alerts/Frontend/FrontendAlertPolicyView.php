<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\ClubAlertPolicy;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FlashMessages;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAlertPolicyView (#2632, epic #2629) — `?tt_view=alert-policy`.
 *
 * The club-admin half of the control layer: for each alert definition, who
 * decides. Same matrix shape as the per-user screen, with a mode selector
 * and the two settings only a club may set.
 *
 * `interrupt` lives here and nowhere else (epic decision 4). A definition
 * can never declare itself blocking — that is the difference between a
 * feature author deciding to interrupt everyone's login and an academy
 * deciding it. The tier exists for consent expiry and safeguarding, not for
 * whichever alert its author felt strongest about.
 *
 * `escalate_after_days` is stored here in wave 2 and consumed by #2635. An
 * academy marking attendance weekly needs a different threshold from one
 * doing it nightly, and a threshold that is wrong for the rhythm
 * manufactures workflow tasks nobody asked for.
 *
 * §6 exemption (a): a settings sub-form, so Save-only with no Cancel.
 */
final class FrontendAlertPolicyView extends FrontendViewBase {

    public const SLUG = 'alert-policy';

    public static function render(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Alert policy', 'talenttrack' ) );

        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage alert policy.', 'talenttrack' ) . '</p>';
            return;
        }

        self::handleSave();

        $policy      = new ClubAlertPolicy();
        $definitions = AlertRegistry::all();

        echo '<div class="tt-alert-settings tt-alert-policy">';
        echo '<h2>' . esc_html__( 'Alert policy', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-field-hint">'
            . esc_html__( 'Decide which alerts your academy uses and who controls them. "Each person chooses" is the default and suits almost everything — reach for the others when an alert matters too much to be optional, or when your academy does not use that part of the system at all.', 'talenttrack' )
            . '</p>';

        if ( empty( $definitions ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No alerts are available on this installation yet.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" class="tt-form">';
        wp_nonce_field( 'tt_alert_policy', 'tt_alert_policy_nonce' );

        foreach ( $definitions as $key => $definition ) {
            self::renderRow( (string) $key, $definition, $policy );
        }

        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save alert policy', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private static function renderRow( string $key, object $definition, ClubAlertPolicy $policy ): void {
        $base        = 'tt-policy-' . sanitize_html_class( str_replace( '.', '-', $key ) );
        $mode        = $policy->modeFor( $key );
        $operational = $definition->isOperational();

        echo '<div class="tt-alert-settings-row">';

        echo '<div class="tt-alert-settings-meta">';
        echo '<span class="tt-alert-settings-name">' . esc_html( $definition->label() ) . '</span>';
        echo '<span class="tt-alert-settings-desc">' . esc_html( $definition->description() ) . '</span>';
        if ( $operational ) {
            echo '<span class="tt-alert-settings-lock">'
                . esc_html__( 'This alert concerns a child\'s safety and cannot be switched off.', 'talenttrack' )
                . '</span>';
        }
        echo '</div>';

        echo '<div class="tt-alert-policy-controls">';

        // Mode
        printf( '<label class="tt-field" for="%s-mode">', esc_attr( $base ) );
        echo '<span class="tt-field-label">' . esc_html__( 'Who decides', 'talenttrack' ) . '</span>';
        printf( '<select id="%s-mode" name="alert_policy[%s][mode]">', esc_attr( $base ), esc_attr( $key ) );
        foreach ( ClubAlertPolicy::modes() as $candidate ) {
            // Forcing an operational alert off is refused server-side; the
            // option is omitted rather than rendered-and-rejected so an admin
            // is not invited to make a choice that cannot be honoured.
            if ( $candidate === ClubAlertPolicy::MODE_FORCE_OFF && $operational ) continue;
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $candidate ),
                selected( $mode, $candidate, false ),
                esc_html( ClubAlertPolicy::modeLabel( $candidate ) )
            );
        }
        echo '</select></label>';

        // Forced surfaces — only meaningful under force_on, but always
        // rendered: hiding them until the mode changes would need JS, and a
        // settings screen that depends on JS to be complete is one that
        // silently loses half its controls when a script fails.
        echo '<fieldset class="tt-field">';
        echo '<legend class="tt-field-label">' . esc_html__( 'When always on, show it', 'talenttrack' ) . '</legend>';
        $forced = $policy->forcedSurfacesFor( $key );
        foreach ( Surface::userChoosable() as $surface ) {
            $id = $base . '-s-' . sanitize_html_class( $surface );
            printf(
                '<label class="tt-alert-settings-toggle" for="%1$s"><input type="checkbox" id="%1$s" name="alert_policy[%2$s][surfaces][]" value="%3$s"%4$s /><span>%5$s</span></label>',
                esc_attr( $id ),
                esc_attr( $key ),
                esc_attr( $surface ),
                in_array( $surface, $forced, true ) ? ' checked' : '',
                esc_html( Surface::label( $surface ) )
            );
        }
        echo '</fieldset>';

        // Interrupt
        printf(
            '<label class="tt-alert-settings-toggle" for="%1$s-int"><input type="checkbox" id="%1$s-int" name="alert_policy[%2$s][interrupt]" value="1"%3$s /><span>%4$s</span></label>',
            esc_attr( $base ),
            esc_attr( $key ),
            $policy->interruptEnabled( $key ) ? ' checked' : '',
            esc_html__( 'Require people to acknowledge this before continuing', 'talenttrack' )
        );

        // Escalation threshold
        $days = $policy->escalateAfterDays( $key );
        printf( '<label class="tt-field" for="%s-esc">', esc_attr( $base ) );
        echo '<span class="tt-field-label">' . esc_html__( 'Turn into a task after (days)', 'talenttrack' ) . '</span>';
        printf(
            '<input type="number" inputmode="numeric" min="0" step="1" id="%s-esc" name="alert_policy[%s][escalate_after_days]" value="%s" />',
            esc_attr( $base ),
            esc_attr( $key ),
            esc_attr( $days !== null ? (string) $days : '' )
        );
        echo '<span class="tt-field-hint">' . esc_html__( 'Leave empty to use the built-in default. Takes effect once task escalation ships.', 'talenttrack' ) . '</span>';
        echo '</label>';

        echo '</div>';
        echo '</div>';
    }

    private static function handleSave(): void {
        if ( ! isset( $_POST['tt_alert_policy_nonce'] ) ) return;

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_alert_policy_nonce'] ) ), 'tt_alert_policy' ) ) {
            FlashMessages::add( FlashMessages::TYPE_ERROR, __( 'Security check failed. Reload the page and try again.', 'talenttrack' ) );
            return;
        }
        if ( ! current_user_can( 'tt_edit_settings' ) ) return;

        $submitted = isset( $_POST['alert_policy'] ) && is_array( $_POST['alert_policy'] )
            ? wp_unslash( $_POST['alert_policy'] )
            : [];

        $policy = new ClubAlertPolicy();
        $errors = [];

        foreach ( AlertRegistry::keys() as $key ) {
            $entry = isset( $submitted[ $key ] ) && is_array( $submitted[ $key ] ) ? $submitted[ $key ] : [];

            $days = isset( $entry['escalate_after_days'] ) && $entry['escalate_after_days'] !== ''
                ? (int) $entry['escalate_after_days']
                : null;

            $error = $policy->set(
                $key,
                isset( $entry['mode'] ) ? sanitize_key( (string) $entry['mode'] ) : ClubAlertPolicy::MODE_USER_CHOICE,
                isset( $entry['surfaces'] ) && is_array( $entry['surfaces'] ) ? array_map( 'sanitize_key', $entry['surfaces'] ) : [],
                ! empty( $entry['interrupt'] ),
                $days
            );
            if ( $error !== null ) $errors[] = $error;
        }

        if ( ! empty( $errors ) ) {
            FlashMessages::add( FlashMessages::TYPE_WARNING, implode( ' ', $errors ) );
            return;
        }
        FlashMessages::add( FlashMessages::TYPE_SUCCESS, __( 'Alert policy saved.', 'talenttrack' ) );
    }
}
