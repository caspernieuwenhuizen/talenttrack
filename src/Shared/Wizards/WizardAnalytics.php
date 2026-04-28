<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase-4 analytics for the wizard framework.
 *
 * Three rolled-up counters per wizard slug, kept in `wp_options` to
 * avoid a new table for what is genuinely a small per-install
 * counter:
 *
 *   tt_wizard_started_<slug>    — total times the user clicked the entry point.
 *   tt_wizard_completed_<slug>  — total times the final step's submit() succeeded.
 *   tt_wizard_skipped_<slug>    — JSON map { step_slug: count }
 *
 * Completion rate = completed / started (per wizard, all-time). Skip
 * rate = skipped[step] / started.
 *
 * Why options and not a table: the data is per-install, low cardinality
 * (4 wizards), and never needs to be queried by anything other than the
 * admin dashboard tile. A table would be over-engineering.
 */
final class WizardAnalytics {

    public static function recordStarted( string $slug ): void {
        $key = 'tt_wizard_started_' . sanitize_key( $slug );
        update_option( $key, (int) get_option( $key, 0 ) + 1, false );
    }

    public static function recordCompleted( string $slug ): void {
        $key = 'tt_wizard_completed_' . sanitize_key( $slug );
        update_option( $key, (int) get_option( $key, 0 ) + 1, false );
    }

    public static function recordSkipped( string $slug, string $step_slug ): void {
        $key = 'tt_wizard_skipped_' . sanitize_key( $slug );
        $map = get_option( $key, [] );
        if ( ! is_array( $map ) ) $map = [];
        $map[ $step_slug ] = (int) ( $map[ $step_slug ] ?? 0 ) + 1;
        update_option( $key, $map, false );
    }

    /**
     * @return array{started:int, completed:int, completion_rate:float, skipped:array<string,int>}
     */
    public static function statsFor( string $slug ): array {
        $started   = (int) get_option( 'tt_wizard_started_' . sanitize_key( $slug ), 0 );
        $completed = (int) get_option( 'tt_wizard_completed_' . sanitize_key( $slug ), 0 );
        $skipped   = get_option( 'tt_wizard_skipped_' . sanitize_key( $slug ), [] );
        if ( ! is_array( $skipped ) ) $skipped = [];
        $rate = $started > 0 ? round( $completed / $started, 2 ) : 0.0;
        return [
            'started'         => $started,
            'completed'       => $completed,
            'completion_rate' => $rate,
            'skipped'         => $skipped,
        ];
    }
}
