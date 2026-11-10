<?php
/**
 * Migration 0324 — evaluation category keys are unique per club (#4986).
 *
 * `tt_eval_categories` carried `UNIQUE KEY uniq_category_key (category_key)`
 * from migration 0008: one key per install. Every read already scopes to the
 * club (`EvalCategoriesRepository`), so on a multi-club install a second
 * academy could not create a category whose key another academy holds, and
 * its insert failed instead (#4976 avoided it for the conversation forms with
 * form-qualified keys). The key is the club's, as the rest of the table is
 * (CLAUDE.md §4).
 *
 * - `club_id` is added when missing (0038 adds it to every table; this is
 *   the guard for an install where it did not), `NOT NULL DEFAULT 1`.
 * - `UNIQUE KEY uniq_club_category_key (club_id, category_key)` is added
 *   first, then `uniq_category_key` is dropped, so the table is never
 *   without a key on `category_key`.
 * - Both are guarded on `information_schema.STATISTICS` and run through
 *   `exec()`; a second run changes nothing.
 *
 * Existing rows cannot violate the new key: the old one was stricter. An
 * install that lost the old key could hold duplicates within one club; the
 * migration then fails naming them, and changes nothing. It does not dedupe:
 * ratings, notes and evaluation-type mappings point at each duplicate's id,
 * so removing one would mean re-pointing them, a merge only an operator can
 * judge (`docs/migrations.md` rule 4). Run alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    private const OLD_KEY = 'uniq_category_key';
    private const NEW_KEY = 'uniq_club_category_key';

    public function getName(): string {
        return '0324_eval_category_key_per_club';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_eval_categories';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            throw new \RuntimeException( "0324: {$table} is missing" );
        }

        if ( ! MigrationHelpers::addColumnIfMissing( $table, 'club_id', 'INT UNSIGNED NOT NULL DEFAULT 1', 'id' ) ) {
            throw new \RuntimeException( "Migration statement failed: adding {$table}.club_id — " . $wpdb->last_error );
        }

        $changed = 0;
        if ( ! $this->hasIndex( $table, self::NEW_KEY ) ) {
            $duplicates = $wpdb->get_col(
                "SELECT CONCAT( club_id, ':', category_key ) FROM `{$table}` GROUP BY club_id, category_key HAVING COUNT(*) > 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
            if ( ! empty( $duplicates ) ) {
                throw new \RuntimeException( '0324: these club:category_key pairs are held by more than one category and need merging first: ' . implode( ', ', array_map( 'strval', $duplicates ) ) );
            }
            $this->exec( "ALTER TABLE `{$table}` ADD UNIQUE KEY `" . self::NEW_KEY . "` (`club_id`, `category_key`)" );
            $changed++;
        }
        if ( $this->hasIndex( $table, self::OLD_KEY ) ) {
            $this->exec( "ALTER TABLE `{$table}` DROP INDEX `" . self::OLD_KEY . "`" );
            $changed++;
        }

        $this->report( $changed, $changed > 0 ? 'category keys are unique per club' : 'category keys were already unique per club' );
    }

    private function hasIndex( string $table, string $index ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            $table, $index
        ) );
    }
};
