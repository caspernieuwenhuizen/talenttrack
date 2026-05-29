<?php
namespace TT\Modules\Tournaments\Wizard;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WizardAssets — shared CSS + JS enqueue for the new-tournament wizard
 * and the post-creation Add-match standalone surface (#975).
 *
 * Idempotent. Every step's render() calls `enqueue()`; only the first
 * call actually enqueues. The wizard framework owns the surrounding
 * `.tt-wizard-form` chrome and progress strip; this stylesheet drops
 * the `.tt-tournament-wizard` scope around the step body so the new
 * card / chip / squad rules never leak out.
 */
final class WizardAssets {

    private static bool $enqueued = false;

    public static function enqueue(): void {
        if ( self::$enqueued ) return;
        self::$enqueued = true;

        wp_enqueue_style(
            'tt-tournament-wizard',
            TT_PLUGIN_URL . 'assets/css/frontend-tournament-wizard.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-tournament-wizard',
            TT_PLUGIN_URL . 'assets/js/components/tournament-wizard.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-tournament-wizard', 'TT_TournamentWizard', [
            'i18n' => [
                'vs_opponent'           => __( 'vs %s', 'talenttrack' ),
                'new_match_placeholder' => __( 'New match — fill in opponent below', 'talenttrack' ),
                'remove_chip'           => __( 'Remove %s', 'talenttrack' ),
                'squad_in'              => __( '%d in squad', 'talenttrack' ),
                'squad_out'             => __( '%d not picked', 'talenttrack' ),
            ],
        ] );
    }
}
