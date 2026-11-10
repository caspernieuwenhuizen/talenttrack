<?php
/**
 * Migration 0323 — storage for the PDP conversation forms (#4977, epic
 * #4975).
 *
 * A form conversation holds two sets of scores on one list of items, the
 * player's and the trainer's, plus the free text and the two SMART points.
 * The trainer's scores also become an ordinary evaluation at sign-off.
 *
 * `tt_pdp_conversations` gains three columns:
 *  - `form_key` / `form_version`: the form and version the conversation
 *    uses, pinned so a later version never changes what was answered;
 *  - `player_prepared_at`: when the player marked their preparation
 *    finished ("klaar"), in UTC like the other `*_at` columns. Until then
 *    nobody but the player reads the player's part.
 *
 * Three new tables, each with `club_id` and `uuid`:
 *  - `tt_pdp_form_scores`: one row per item, author (`player` or `coach`)
 *    and form. `form_key` is in the key so switching the form keeps what
 *    was entered on the other one.
 *  - `tt_pdp_form_answers`: the same frame for the free text, by
 *    `field_key` (a prompt of the definition, or a section's notes).
 *  - `tt_pdp_form_goals`: the SMART points per slot. `goal_id` records the
 *    goal sign-off created for the slot; the conversation's goal links also
 *    hold the goals the coach marked as discussed, so the link alone cannot
 *    say which goal is point 1.
 *
 * `tt_evaluations.pdp_conversation_id` links the evaluation sign-off writes
 * to its conversation. Its UNIQUE key makes "one evaluation per
 * conversation" a database rule; MySQL allows any number of NULLs under it.
 *
 * Every statement goes through `exec()` or throws; the column adds use
 * `addColumnIfMissing()` and the index is guarded on
 * `information_schema.STATISTICS`, so a second run changes nothing. Run
 * alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0323_pdp_form_storage';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        $conversations = "{$p}tt_pdp_conversations";
        $evaluations   = "{$p}tt_evaluations";
        foreach ( [ $conversations, $evaluations ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                throw new \RuntimeException( "0323: {$table} is missing" );
            }
        }

        $this->addColumn( $conversations, 'form_key', 'VARCHAR(64) DEFAULT NULL', 'not_held_reason' );
        $this->addColumn( $conversations, 'form_version', 'SMALLINT UNSIGNED DEFAULT NULL', 'form_key' );
        $this->addColumn( $conversations, 'player_prepared_at', 'DATETIME DEFAULT NULL', 'form_version' );

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $this->createIfMissing( "{$p}tt_pdp_form_scores", "CREATE TABLE {$p}tt_pdp_form_scores (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            conversation_id BIGINT UNSIGNED NOT NULL,
            form_key VARCHAR(64) NOT NULL,
            item_key VARCHAR(64) NOT NULL,
            author VARCHAR(16) NOT NULL,
            score TINYINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_score (conversation_id, form_key, item_key, author),
            KEY idx_conversation (club_id, conversation_id)
        ) {$charset};" );

        $this->createIfMissing( "{$p}tt_pdp_form_answers", "CREATE TABLE {$p}tt_pdp_form_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            conversation_id BIGINT UNSIGNED NOT NULL,
            form_key VARCHAR(64) NOT NULL,
            field_key VARCHAR(64) NOT NULL,
            author VARCHAR(16) NOT NULL,
            body LONGTEXT,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_answer (conversation_id, form_key, field_key, author),
            KEY idx_conversation (club_id, conversation_id)
        ) {$charset};" );

        $this->createIfMissing( "{$p}tt_pdp_form_goals", "CREATE TABLE {$p}tt_pdp_form_goals (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            conversation_id BIGINT UNSIGNED NOT NULL,
            slot TINYINT UNSIGNED NOT NULL,
            action_text LONGTEXT,
            support_text LONGTEXT,
            due_date DATE DEFAULT NULL,
            item_key VARCHAR(64) DEFAULT NULL,
            goal_id BIGINT UNSIGNED DEFAULT NULL,
            updated_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_slot (conversation_id, slot),
            KEY idx_conversation (club_id, conversation_id)
        ) {$charset};" );

        foreach ( [ 'tt_pdp_form_scores', 'tt_pdp_form_answers', 'tt_pdp_form_goals' ] as $name ) {
            $table = $p . $name;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                throw new \RuntimeException( "0323: {$table} could not be created: " . $wpdb->last_error );
            }
        }

        $this->addColumn( $evaluations, 'pdp_conversation_id', 'BIGINT UNSIGNED DEFAULT NULL', 'activity_id' );
        $this->addUniqueIfMissing( $evaluations, 'uniq_pdp_conversation', 'pdp_conversation_id' );

        $this->report( 1, 'form columns, the three form tables and the evaluation link are in place' );
    }

    /**
     * `dbDelta` for a table that does not exist yet, and nothing for one
     * that does: a second run must not reach `dbDelta` with an existing
     * table (docs/migrations.md rule 2).
     */
    private function createIfMissing( string $table, string $sql ): void {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) return;
        dbDelta( $sql );
    }

    private function addColumn( string $table, string $column, string $definition, string $after ): void {
        global $wpdb;
        if ( ! MigrationHelpers::addColumnIfMissing( $table, $column, $definition, $after ) ) {
            throw new \RuntimeException( "Migration statement failed: adding {$table}.{$column} — " . $wpdb->last_error );
        }
    }

    private function addUniqueIfMissing( string $table, string $index, string $columns ): void {
        global $wpdb;
        $exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table, $index
        ) );
        if ( $exists ) return;
        // The column is new in this migration, so every row is NULL and no
        // existing data can violate the key.
        $this->exec( "ALTER TABLE `{$table}` ADD UNIQUE KEY `{$index}` (`{$columns}`)" );
    }
};
