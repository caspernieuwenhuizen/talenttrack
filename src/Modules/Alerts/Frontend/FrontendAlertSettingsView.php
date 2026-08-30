<?php
namespace TT\Modules\Alerts\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Alerts\Policy\AlertPolicyResolver;
use TT\Modules\Alerts\Repositories\AlertPreferencesRepository;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FlashMessages;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendAlertSettingsView (#2632, epic #2629) — `?tt_view=alert-settings`.
 *
 * The per-user matrix: one row per alert definition, grouped by module, with
 * a toggle per surface. This is the phone-notification-settings analogy the
 * epic was built around, one level finer than a phone's — per condition
 * rather than per app.
 *
 * **A separate screen from comms opt-outs** (epic decision 11), with a
 * cross-link each way. Message preferences live on `?tt_view=my-settings`
 * and govern what the academy *sends* you; this governs what the app
 * *surfaces* to you. Different vocabularies, different storage, and a user
 * hunting for "stop nagging me" needs to find the other half in one click —
 * which is what that link is for, and why it is not optional decoration.
 *
 * Locked rows render **visibly locked with a reason** rather than being
 * hidden. A preferences screen that quietly omits what you cannot change
 * teaches you the list is complete when it is not, which is the same silent
 * behaviour #2602 set out to remove from Comms.
 *
 * §6 exemption (a): a settings sub-form, so Save-only with no Cancel.
 */
final class FrontendAlertSettingsView extends FrontendViewBase {

    public const SLUG = 'alert-settings';

    public static function render(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Alert settings', 'talenttrack' ) );

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            echo '<p class="tt-notice">' . esc_html__( 'You need to be logged in to manage your alert settings.', 'talenttrack' ) . '</p>';
            return;
        }

        self::handleSave( $user_id );

        $resolver = new AlertPolicyResolver();
        $matrix   = $resolver->matrixFor( $user_id );

        echo '<div class="tt-alert-settings">';
        echo '<h2>' . esc_html__( 'Alerts', 'talenttrack' ) . '</h2>';
        echo '<p class="tt-field-hint">'
            . esc_html__( 'Alerts tell you about things in your data that need attention right now — an activity still marked as planned, a training with no attendance recorded. You never tick them off: fix the thing and the alert clears itself.', 'talenttrack' )
            . '</p>';

        self::renderCrossLink();

        if ( empty( $matrix ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No alerts are available on this installation yet.', 'talenttrack' ) . '</p>';
            echo '</div>';
            return;
        }

        echo '<form method="post" class="tt-form">';
        wp_nonce_field( 'tt_alert_settings', 'tt_alert_settings_nonce' );

        foreach ( self::groupByModule( $matrix ) as $module => $entries ) {
            echo '<section class="tt-alert-settings-group">';
            echo '<h3>' . esc_html( self::moduleLabel( $module ) ) . '</h3>';
            foreach ( $entries as $key => $entry ) {
                self::renderRow( (string) $key, $entry );
            }
            echo '</section>';
        }

        echo '<div class="tt-form-actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save alert settings', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    /**
     * One alert's row: its name, what it means, and a toggle per surface.
     *
     * On a phone this stacks — the label above its toggles — rather than
     * becoming a horizontally scrolling table. A settings matrix that needs
     * sideways scrolling at 360px is one nobody adjusts.
     *
     * @param array{definition:mixed,surfaces:list<string>,locked:?string,choosable:list<string>} $entry
     */
    private static function renderRow( string $key, array $entry ): void {
        $definition = $entry['definition'];
        $locked     = $entry['locked'];
        $field_base = 'tt-alert-' . sanitize_html_class( str_replace( '.', '-', $key ) );

        echo '<div class="tt-alert-settings-row' . ( $locked !== null ? ' tt-alert-settings-locked' : '' ) . '">';

        echo '<div class="tt-alert-settings-meta">';
        echo '<span class="tt-alert-settings-name">' . esc_html( $definition->label() ) . '</span>';
        echo '<span class="tt-alert-settings-desc">' . esc_html( $definition->description() ) . '</span>';
        if ( $locked !== null ) {
            echo '<span class="tt-alert-settings-lock">' . esc_html( $locked ) . '</span>';
        }
        echo '</div>';

        echo '<div class="tt-alert-settings-toggles">';
        foreach ( $entry['choosable'] as $surface ) {
            $id      = $field_base . '-' . sanitize_html_class( $surface );
            $checked = in_array( $surface, $entry['surfaces'], true );
            printf(
                '<label class="tt-alert-settings-toggle" for="%1$s">'
                    . '<input type="checkbox" id="%1$s" name="alert_surfaces[%2$s][]" value="%3$s"%4$s%5$s />'
                    . '<span>%6$s</span>'
                . '</label>',
                esc_attr( $id ),
                esc_attr( $key ),
                esc_attr( $surface ),
                $checked ? ' checked' : '',
                $locked !== null ? ' disabled' : '',
                esc_html( Surface::label( $surface ) )
            );
        }
        echo '</div>';

        // A locked row's checkboxes are disabled, and disabled inputs are not
        // submitted — so without this the save would read "no surfaces" and
        // try to write an empty set. The server-side lock check in
        // handleSave() would catch it, but sending an honest payload is
        // cheaper than relying on the guard.
        if ( $locked === null ) {
            printf(
                '<input type="hidden" name="alert_present[]" value="%s" />',
                esc_attr( $key )
            );
        }

        echo '</div>';
    }

    /**
     * The other half of "what reaches me". Mandated by epic decision 11:
     * two screens is the shape we chose, and this link is the entire
     * mitigation for the support question that choice predicts.
     */
    private static function renderCrossLink(): void {
        // Routed through CrossViewLink (#2304) rather than emitted directly:
        // a navigation affordance must disappear for a user who cannot reach
        // its target. `my-settings` needs nothing beyond being logged in, so
        // the gate is permissive today — but going through the helper is what
        // keeps that true if the target ever grows a guard.
        // The `tt-xview-ok` marker on the URL line below is the gate's
        // escape hatch for "gated by another mechanism": the lint matches
        // per line, and the CrossViewLink call that actually gates this
        // affordance is two lines up rather than on the URL line itself.
        CrossViewLink::render( 'my-settings', static function (): void {
            $url = add_query_arg( [ 'tt_view' => 'my-settings' ], self::dashboardUrl() ); /* tt-xview-ok */
            echo '<p class="tt-alert-settings-crosslink">';
            printf(
                /* translators: %s: link to the message preferences screen */
                esc_html__( 'Looking for emails and messages the academy sends you? Those live under %s.', 'talenttrack' ),
                '<a href="' . esc_url( $url ) . '">' . esc_html__( 'message preferences', 'talenttrack' ) . '</a>'
            );
            echo '</p>';
        } );
    }

    /**
     * Persist a submitted matrix.
     *
     * Only alerts whose row was actually rendered unlocked are written —
     * `alert_present[]` carries that list. An alert absent from it keeps
     * whatever it had, so a club forcing a row between render and submit
     * cannot be overwritten by a stale form.
     */
    private static function handleSave( int $user_id ): void {
        if ( ! isset( $_POST['tt_alert_settings_nonce'] ) ) return;

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_alert_settings_nonce'] ) ), 'tt_alert_settings' ) ) {
            FlashMessages::add( FlashMessages::TYPE_ERROR, __( 'Security check failed. Reload the page and try again.', 'talenttrack' ) );
            return;
        }

        $present = isset( $_POST['alert_present'] ) && is_array( $_POST['alert_present'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['alert_present'] ) )
            : [];

        $submitted = isset( $_POST['alert_surfaces'] ) && is_array( $_POST['alert_surfaces'] )
            ? wp_unslash( $_POST['alert_surfaces'] )
            : [];

        $repo     = new AlertPreferencesRepository();
        $resolver = new AlertPolicyResolver();

        foreach ( $present as $alert_key ) {
            if ( $resolver->lockReason( $alert_key ) !== null ) continue;

            // Absent from the payload means every box was unticked, which is
            // a legitimate choice ("nowhere") and NOT the same as having no
            // stored row. `save()` writes the empty set deliberately.
            $surfaces = isset( $submitted[ $alert_key ] ) && is_array( $submitted[ $alert_key ] )
                ? array_map( 'sanitize_key', $submitted[ $alert_key ] )
                : [];

            $repo->save( $user_id, $alert_key, $surfaces );
        }

        $resolver->flush();
        FlashMessages::add( FlashMessages::TYPE_SUCCESS, __( 'Alert settings saved.', 'talenttrack' ) );
    }

    /**
     * @param array<string,array<string,mixed>> $matrix
     * @return array<string,array<string,array<string,mixed>>>
     */
    private static function groupByModule( array $matrix ): array {
        $out = [];
        foreach ( $matrix as $key => $entry ) {
            $out[ $entry['definition']->module() ][ $key ] = $entry;
        }
        ksort( $out );
        return $out;
    }

    private static function moduleLabel( string $module ): string {
        $labels = [
            'activities'  => __( 'Activities and attendance', 'talenttrack' ),
            // #3139 — 'comms' would otherwise render as "Comms", which is
            // an internal name; the screen it points at is called Messages.
            'comms'       => __( 'Messages', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'pdp'         => __( 'Development plans', 'talenttrack' ),
            'people'      => __( 'People', 'talenttrack' ),
            'workflow'    => __( 'Tasks', 'talenttrack' ),
        ];
        return $labels[ $module ] ?? ucfirst( $module );
    }

    private static function dashboardUrl(): string {
        if ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' ) ) {
            return \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl();
        }
        return home_url( '/' );
    }
}
