<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardAnalytics;
use TT\Shared\Wizards\WizardRegistry;

/**
 * FrontendWizardsAdminView (#0055 Phase 4) — admin page combining the
 * `tt_wizards_enabled` toggle (CSV / `all` / `off`) with completion
 * analytics per registered wizard.
 *
 *   ?tt_view=wizards-admin
 *
 * Read-mostly: counts come from `WizardAnalytics`, completion rate is
 * `completed / started`, skip rate is `skipped[step] / started`.
 */
class FrontendWizardsAdminView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            self::renderHeader( __( 'Wizards', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to configure wizards.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( $user_id );
        self::renderHeader( __( 'Wizards', 'talenttrack' ) );

        self::renderConfigForm();
        self::renderAnalytics();
    }

    private static function handlePost( int $user_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_wizards_admin_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_wizards_admin_nonce'] ) ), 'tt_wizards_admin' ) ) return;

        $value = isset( $_POST['tt_wizards_enabled'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tt_wizards_enabled'] ) ) : 'all';
        $value = strtolower( trim( $value ) );
        $allowed = [ 'all', 'off' ];
        if ( ! in_array( $value, $allowed, true ) && strpos( $value, ',' ) === false ) {
            // Single slug — keep as-is if it matches a registered wizard.
            $registered_slugs = array_keys( WizardRegistry::all() );
            if ( ! in_array( $value, $registered_slugs, true ) && $value !== '' ) $value = 'all';
        }
        QueryHelpers::set_config( 'tt_wizards_enabled', $value );
    }

    private static function renderConfigForm(): void {
        $current = QueryHelpers::get_config( 'tt_wizards_enabled', 'all' );
        $registered = WizardRegistry::all();

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Configuration', 'talenttrack' ) . '</h2>';
        echo '<p>' . esc_html__( 'Wizards replace the flat create-form for the listed entries. Use "all" for every wizard, "off" to disable, or a comma-separated list of slugs.', 'talenttrack' ) . '</p>';
        echo '<form method="post"><input type="hidden" name="_action" value="save">';
        wp_nonce_field( 'tt_wizards_admin', 'tt_wizards_admin_nonce' );
        echo '<label><span>' . esc_html__( 'tt_wizards_enabled', 'talenttrack' ) . '</span><input type="text" name="tt_wizards_enabled" value="' . esc_attr( (string) $current ) . '" autocomplete="off"></label>';
        echo '<details><summary>' . esc_html__( 'Available wizard slugs', 'talenttrack' ) . '</summary><ul>';
        foreach ( $registered as $slug => $w ) {
            echo '<li><code>' . esc_html( $slug ) . '</code> — ' . esc_html( $w->label() ) . '</li>';
        }
        echo '</ul></details>';
        echo '<button type="submit" class="tt-button tt-button-primary">' . esc_html__( 'Save', 'talenttrack' ) . '</button>';
        echo '</form></section>';
    }

    private static function renderAnalytics(): void {
        $registered = WizardRegistry::all();
        if ( ! $registered ) return;

        echo '<section class="tt-trial-section"><h2>' . esc_html__( 'Completion analytics', 'talenttrack' ) . '</h2>';
        echo '<table class="tt-table"><thead><tr>';
        echo '<th>' . esc_html__( 'Wizard', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Started', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Completed', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Completion rate', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Most-skipped step', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $registered as $slug => $w ) {
            $stats = WizardAnalytics::statsFor( $slug );
            $most_skipped = '—';
            if ( $stats['skipped'] ) {
                arsort( $stats['skipped'] );
                $top_step = (string) array_key_first( $stats['skipped'] );
                $most_skipped = $top_step . ' (' . (int) $stats['skipped'][ $top_step ] . ')';
            }
            $rate = (int) round( $stats['completion_rate'] * 100 ) . '%';
            echo '<tr>';
            echo '<td>' . esc_html( $w->label() ) . ' <small>(' . esc_html( $slug ) . ')</small></td>';
            echo '<td>' . (int) $stats['started'] . '</td>';
            echo '<td>' . (int) $stats['completed'] . '</td>';
            echo '<td>' . esc_html( $rate ) . '</td>';
            echo '<td>' . esc_html( $most_skipped ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></section>';
    }
}
