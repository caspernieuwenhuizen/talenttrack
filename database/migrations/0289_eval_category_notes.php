<?php
/**
 * Migration 0289 — a note per evaluation category (#3949).
 *
 * A rating says how well, never why. `tt_eval_category_notes` holds one
 * short note per (evaluation, category): a main category or a subcategory,
 * rated or not. It is its own table rather than a column on
 * `tt_eval_ratings` because a rolled-up main category has no rating row to
 * hang a note on, and a coach may annotate a skill they left unscored.
 *
 * The note is read with the same visibility as the evaluation it belongs
 * to; it has no rule of its own. It goes with its evaluation on purge
 * (`CascadeRegistry`, `PlayerDeletionCascade`).
 *
 * `club_id` per CLAUDE.md §4. Not a root entity, so no uuid.
 * Idempotent: CREATE TABLE IF NOT EXISTS. Forward-only. Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0289_eval_category_notes';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$p}tt_eval_category_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            evaluation_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_eval_category (evaluation_id, category_id),
            KEY idx_club_eval (club_id, evaluation_id)
        ) {$charset};" );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_eval_category_notes" ) ) === "{$p}tt_eval_category_notes";
        $this->report( $exists ? 1 : 0, $exists ? 'tt_eval_category_notes present' : 'tt_eval_category_notes could not be created' );
    }
};
