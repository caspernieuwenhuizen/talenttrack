<?php
/**
 * Migration 0166 — carry the two legacy one-off toggles into
 * `tt_feature_state` (#1537).
 *
 * `analytics_explorer` previously lived in `tt_config` under
 * `analytics_explorer_enabled` (default off); `custom_widgets` lived in
 * `tt_config` / `wp_options` under `tt_custom_widgets_enabled` (default
 * off). Both now resolve through `FeatureRegistry`. This migration seeds a
 * `tt_feature_state` row that mirrors the install's current on/off so
 * nothing changes on upgrade.
 *
 * `INSERT IGNORE` on the composite PK `(feature_key, club_id)` makes this
 * idempotent: a club that already toggled the feature on the new page has
 * a row, so re-running is a no-op and never clobbers an operator choice.
 *
 * The new sub-features (`exercises_vision_extraction`,
 * `team_blueprints_sharing`) are intentionally NOT seeded here — they
 * default to enabled in the catalog to preserve current behaviour, and a
 * missing row resolves to that default.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0166_seed_feature_flags_from_legacy_toggles';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_feature_state';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $sql = "INSERT IGNORE INTO {$table} (feature_key, club_id, enabled) VALUES (%s, 1, %d)";

        $wpdb->query( $wpdb->prepare( $sql, 'analytics_explorer', $this->legacyAnalyticsExplorerOn() ? 1 : 0 ) );
        $wpdb->query( $wpdb->prepare( $sql, 'custom_widgets', $this->legacyCustomWidgetsOn() ? 1 : 0 ) );
    }

    /**
     * Prior state of the analytics explorer: `tt_config` row
     * `analytics_explorer_enabled === '1'`. Default off.
     */
    private function legacyAnalyticsExplorerOn(): bool {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = 1 AND config_key = %s",
            'analytics_explorer_enabled'
        ) );
        return (string) $val === '1';
    }

    /**
     * Prior state of custom widgets: `tt_config` row
     * `tt_custom_widgets_enabled`, falling back to the `wp_options` value
     * for installs predating the per-club config layer. Default off.
     */
    private function legacyCustomWidgetsOn(): bool {
        global $wpdb;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = 1 AND config_key = %s",
            'tt_custom_widgets_enabled'
        ) );
        if ( $val !== null ) {
            return (string) $val === '1' || strtolower( (string) $val ) === 'true';
        }
        return (bool) get_option( 'tt_custom_widgets_enabled', false );
    }

    public function down(): void {
        // Forward-only.
    }
};
