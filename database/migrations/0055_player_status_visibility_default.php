<?php
/**
 * Migration 0055 — Player status visibility default (#0071 child 4).
 *
 * On upgrade installs (those with existing players) the toggle defaults
 * to TRUE so today's behaviour is preserved. On fresh installs the row
 * isn't written at all and the FeatureToggleService default of FALSE
 * applies — HoD opts in.
 *
 * Idempotent — re-runs are no-ops because the config row already exists.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Query\QueryHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0055_player_status_visibility_default';
    }

    public function up(): void {
        global $wpdb;

        $existing = QueryHelpers::get_config( 'feature.player_status_visible_to_player_parent', '__unset__' );
        if ( $existing !== '__unset__' && $existing !== '' ) {
            return; // already configured — no-op
        }

        $players_tbl = $wpdb->prefix . 'tt_players';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $players_tbl ) ) === $players_tbl;
        if ( ! $exists ) return;

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$players_tbl}" );
        if ( $count > 0 ) {
            QueryHelpers::set_config( 'feature.player_status_visible_to_player_parent', '1' );
        }
        // count === 0 → fresh install; leave unset so default `false` applies
    }
};
