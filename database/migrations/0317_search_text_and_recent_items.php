<?php
/**
 * Migration 0317 — the folded search columns and the recent-items table
 * (#4671, epic #4668).
 *
 * `tt_players`, `tt_teams`, `tt_activities` and `tt_people` each get three
 * columns, added in one `ALTER` per table:
 *
 *   - `search_title` VARCHAR(255) ASCII NULL — the folded primary label;
 *   - `search_text`  VARCHAR(500) ASCII NULL — the folded secondary text,
 *                    from the row's own columns only;
 *   - `search_v`     SMALLINT UNSIGNED NULL — the `SearchText::VERSION` that
 *                    produced them; NULL means not folded yet.
 *
 * No index on them: `LIKE '%tok%'` cannot use a B-tree index, and the
 * providers narrow through the scope indexes the tables already have.
 *
 * The rows are NOT folded here, so a live upgrade cannot time out on a large
 * club. `SearchTextSweep` folds them on the engine heartbeat, 500 per club
 * and kind per tick; until then a provider matches an unfolded row on its
 * raw columns, so search has no gap after the upgrade.
 *
 * `tt_user_recent` holds what a user opened last, at most 20 rows per user,
 * re-checked on every read. `wp_user_id` names the authentication mapping,
 * as `tt_user_mfa` does; it is not a root entity, so no `uuid`.
 *
 * Idempotent: a column or the table is added only when missing
 * (`information_schema`, the 0304 pattern). Schema change: runs alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** @var list<string> */
    public const TABLES = [ 'tt_players', 'tt_teams', 'tt_activities', 'tt_people' ];

    /**
     * The folded text is ASCII by construction (`SearchText::fold()` keeps
     * a-z, 0-9 and spaces), so the columns are ASCII with a binary
     * collation: half the bytes, and a `LIKE` that compares bytes instead of
     * walking a Unicode collation on every row.
     *
     * @var array<string,string> column => definition
     */
    public const COLUMNS = [
        'search_title' => 'VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
        'search_text'  => 'VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL',
        'search_v'     => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
    ];

    public function getName(): string {
        return '0317_search_text_and_recent_items';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $written = 0;
        $notes   = [];

        foreach ( self::TABLES as $bare ) {
            $table = $p . $bare;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
                $notes[] = "{$bare} missing";
                continue;
            }
            $adds = [];
            foreach ( self::COLUMNS as $column => $definition ) {
                $exists = (bool) $wpdb->get_var( $wpdb->prepare(
                    'SELECT 1 FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                      LIMIT 1',
                    $table, $column
                ) );
                if ( ! $exists ) $adds[] = "ADD COLUMN {$column} {$definition}";
            }
            if ( $adds === [] ) continue;
            $this->exec( "ALTER TABLE {$table} " . implode( ', ', $adds ) );
            $written += count( $adds );
            $notes[]  = sprintf( '%s: %d column(s)', $bare, count( $adds ) );
        }

        $recent = $p . 'tt_user_recent';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $recent ) ) !== $recent ) {
            $charset = $wpdb->get_charset_collate();
            $this->exec( "CREATE TABLE {$recent} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                wp_user_id BIGINT UNSIGNED NOT NULL,
                entity_type VARCHAR(32) NOT NULL,
                entity_id BIGINT UNSIGNED NOT NULL,
                opened_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_user_entity (club_id, wp_user_id, entity_type, entity_id),
                KEY idx_user_opened (club_id, wp_user_id, opened_at)
            ) {$charset}" );
            $written++;
            $notes[] = 'tt_user_recent created';
        }

        $this->report( $written, $notes === [] ? 'every column and the table were already there' : implode( '; ', $notes ) );
    }
};
