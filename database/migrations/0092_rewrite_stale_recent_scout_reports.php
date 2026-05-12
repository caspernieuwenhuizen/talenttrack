<?php
/**
 * Migration 0092 — rewrite stale `recent_scout_reports` widget references
 * in persona-dashboard published/draft override rows.
 *
 * Background. v3.110.68 rebuilt the scout persona dashboard around the
 * prospects funnel. Row 2 originally pointed `data_table` at the
 * `recent_scout_reports` preset — wired to `ScoutReportsRepository`
 * (the PDF-export sharing artifact, cap-gated on `tt_generate_scout_report`).
 * The pilot scout role doesn't carry that cap; the table rendered empty
 * and the See-all link returned "You need scout-management permission
 * to view this page."
 *
 * v3.110.78 fixed the SHIP DEFAULT (`CoreTemplates::scout()`) by
 * swapping the preset to `my_recent_prospects`. But an operator who
 * had previously published an override of the scout template via the
 * dashboard editor (under v3.110.68) carries a row in `tt_config` at
 * key `persona_dashboard.scout.published` whose JSON payload still
 * references `recent_scout_reports`. The published override wins over
 * the ship default in `PersonaTemplateRegistry::resolve()`, so the
 * fix never reaches the operator's screen.
 *
 * The dashboard editor's "Reset to standard" REST endpoint
 * (`PersonaTemplateRestController::reset()`) clears the override —
 * but only if the operator finds and clicks it AND the cache layer
 * cooperates. Pilot users have hit cases where reset didn't take.
 *
 * This migration patches the row in place: parse the JSON, find any
 * widget slot with `widget_id = data_table` AND
 * `data_source = recent_scout_reports`, rewrite `data_source` to
 * `my_recent_prospects`. Saves back via `$wpdb->update`. Scope:
 * every row in `tt_config` whose `config_key` starts with
 * `persona_dashboard.` (covers `.scout.published` AND `.scout.draft`
 * AND any other persona that might have pinned the same preset).
 *
 * Idempotent. The migration runner records the migration name in
 * `tt_migrations`; running twice is a no-op even without the
 * idempotent guards because the second pass finds no
 * `recent_scout_reports` references to rewrite. The guards exist
 * anyway for safety.
 *
 * Reversible? Strictly no — the original `recent_scout_reports`
 * reference is lost. Operators who legitimately want the
 * scout-report PDF history table back can re-add it through the
 * dashboard editor (the preset + source stay registered).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0092_rewrite_stale_recent_scout_reports';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_config';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        /** @var array<int, object> $rows */
        $rows = $wpdb->get_results(
            "SELECT club_id, config_key, config_value
               FROM {$table}
              WHERE config_key LIKE 'persona_dashboard.%'
                AND config_value LIKE '%recent_scout_reports%'"
        );
        if ( ! is_array( $rows ) || $rows === [] ) {
            return;
        }

        foreach ( $rows as $row ) {
            $raw = (string) ( $row->config_value ?? '' );
            if ( $raw === '' ) continue;
            $payload = json_decode( $raw, true );
            if ( ! is_array( $payload ) ) continue;

            // Serialized shape (per PersonaTemplate::toArray + WidgetSlot::toArray):
            //   {
            //     "hero": {"widget": "data_table:recent_scout_reports", ...} | null,
            //     "task": {...} | null,
            //     "grid": [ {"widget": "...:...", "size": "...", ...}, ... ]
            //   }
            // Slots store the widget+data-source as a single "widget" string
            // joined by ":". Rewrite that string in-place anywhere it appears.
            $stale = 'data_table:recent_scout_reports';
            $fresh = 'data_table:my_recent_prospects';
            $touched = false;

            foreach ( [ 'hero', 'task' ] as $key ) {
                if ( isset( $payload[ $key ] ) && is_array( $payload[ $key ] )
                     && ( $payload[ $key ]['widget'] ?? '' ) === $stale ) {
                    $payload[ $key ]['widget'] = $fresh;
                    $touched = true;
                }
            }
            if ( isset( $payload['grid'] ) && is_array( $payload['grid'] ) ) {
                foreach ( $payload['grid'] as &$slot ) {
                    if ( ! is_array( $slot ) ) continue;
                    if ( ( $slot['widget'] ?? '' ) === $stale ) {
                        $slot['widget'] = $fresh;
                        $touched = true;
                    }
                }
                unset( $slot );
            }
            if ( ! $touched ) continue;

            $json = wp_json_encode( $payload );
            if ( ! is_string( $json ) ) continue;

            $wpdb->update(
                $table,
                [ 'config_value' => $json ],
                [ 'club_id' => (int) $row->club_id, 'config_key' => (string) $row->config_key ]
            );
        }
    }
};
