<?php
/**
 * Migration 0176 — Add optional `match_length_minutes` to
 * `tt_activities` (#1726).
 *
 * Direct entry of per-player match minutes needs a per-match full
 * length so "subs off" (starters who played less than the full match)
 * is derivable, and so a coach can correct the length when a match ran
 * short. Prefilled in the UI from the match prep (half_length × 2) when
 * a prep row exists, else a club default of 70; persisted here so later
 * reporting doesn't have to recompute it.
 *
 * Nullable + additive only; idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0176_activity_match_length';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $t = "{$p}tt_activities";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
            return;
        }

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM {$t} LIKE %s",
            'match_length_minutes'
        ) );
        if ( $exists === 'match_length_minutes' ) return;

        $wpdb->query( "ALTER TABLE {$t} ADD COLUMN match_length_minutes SMALLINT UNSIGNED DEFAULT NULL AFTER end_time" );
    }
};
