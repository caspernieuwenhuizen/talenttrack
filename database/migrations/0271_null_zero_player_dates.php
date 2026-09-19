<?php
/**
 * Migration 0271 — zero dates on `tt_players` become NULL (#3590).
 *
 * A player created or saved over REST without a date of birth or join date
 * had an empty string written to those nullable `DATE` columns, which MySQL
 * in non-strict mode stores as `0000-00-00`. The value is truthy, so it went
 * out over the API as a date and fed age and age-group calculations a bogus
 * one. The writers now store NULL; this clears what they left behind.
 *
 * Compared as text, because a strict SQL mode can refuse the zero date as a
 * DATE literal. Data-only and idempotent: a second run matches nothing.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0271_null_zero_player_dates';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        foreach ( [ 'date_of_birth', 'date_joined' ] as $column ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column are fixed names.
            $wpdb->query( "UPDATE {$table} SET {$column} = NULL WHERE CAST({$column} AS CHAR) = '0000-00-00'" );
        }
    }
};
