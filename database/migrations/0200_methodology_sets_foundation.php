<?php
/**
 * Migration 0200 — Methodology sets foundation (#2317, epic #2316).
 *
 * Turns methodologies into first-class, named, selectable sets. Until
 * now the module shipped exactly one methodology; its entities were
 * scoped only by `club_id` + an `is_shipped` flag, with no way to run a
 * second methodology or choose which is active.
 *
 * This migration:
 *
 *   - Creates `tt_methodologies` — the grouping entity (name, slug,
 *     uuid, is_default, is_shipped, club_id tenancy scaffold).
 *   - Adds a nullable `methodology_id` FK to every methodology entity
 *     table (principles, visions, formations, phases, learning goals,
 *     influence factors, set pieces, football actions, framework
 *     primers). Positions inherit their set via their formation.
 *   - Seeds a shipped default set per club ("JO14-1 Hedel", the
 *     1-4-2-3-1 methodology already shipped) and backfills every
 *     existing entity row into it, so no content is orphaned.
 *
 * The per-row `is_shipped` flag is untouched — it still drives the
 * read-only "Shipped" badge. The *set* is the new, orthogonal
 * selection axis; which set is active per team/install is #2318.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, addColumnIfMissing, and a
 * per-club default that is reused (not duplicated) on re-run.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    /**
     * Entity tables that gain a direct `methodology_id`. Positions are
     * excluded — they scope through their parent formation.
     *
     * @var list<string>
     */
    private array $entityTables = [
        'tt_principles',
        'tt_methodology_visions',
        'tt_formations',
        'tt_methodology_phases',
        'tt_methodology_learning_goals',
        'tt_methodology_influence_factors',
        'tt_set_pieces',
        'tt_football_actions',
        'tt_methodology_framework_primers',
    ];

    public function getName(): string {
        return '0200_methodology_sets_foundation';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        // 1) The grouping entity.
        $this->exec( "CREATE TABLE IF NOT EXISTS {$p}tt_methodologies (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id         INT UNSIGNED    NOT NULL DEFAULT 1,
            uuid            CHAR(36)        DEFAULT NULL,
            slug            VARCHAR(64)     NOT NULL,
            name_json       TEXT            DEFAULT NULL,
            description_json TEXT           DEFAULT NULL,
            is_default      TINYINT(1)      NOT NULL DEFAULT 0,
            is_shipped      TINYINT(1)      NOT NULL DEFAULT 0,
            sort_order      INT             NOT NULL DEFAULT 0,
            archived_at     DATETIME        DEFAULT NULL,
            created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_uuid (uuid),
            UNIQUE KEY uniq_club_slug (club_id, slug),
            KEY idx_club_default (club_id, is_default)
        ) {$charset};" );

        // 2) Thread the FK through every entity table.
        foreach ( $this->entityTables as $table ) {
            $full = $p . $table;
            if ( ! MigrationHelpers::addColumnIfMissing( $full, 'methodology_id', 'BIGINT UNSIGNED DEFAULT NULL', 'is_shipped' ) ) {
                throw new \RuntimeException( "0200: failed to add methodology_id to {$full}" );
            }
            $this->addIndexIfMissing( $full, 'idx_club_methodology', '(club_id, methodology_id)' );
        }

        // 3) Seed a shipped default set per club and backfill content.
        foreach ( $this->distinctClubIds( $p ) as $club_id ) {
            $mid = $this->ensureDefaultMethodology( $p, $club_id );
            if ( $mid <= 0 ) {
                throw new \RuntimeException( "0200: could not resolve default methodology for club {$club_id}" );
            }
            foreach ( $this->entityTables as $table ) {
                $this->exec( $wpdb->prepare(
                    "UPDATE {$p}{$table} SET methodology_id = %d
                      WHERE club_id = %d AND methodology_id IS NULL",
                    $mid, $club_id
                ) );
            }
        }
    }

    /**
     * Distinct club_ids across the entity tables (union). Always
     * includes 1 so a fresh install lands with a default even before
     * any content row exists.
     *
     * @return list<int>
     */
    private function distinctClubIds( string $p ): array {
        global $wpdb;
        $selects = [];
        foreach ( $this->entityTables as $table ) {
            $selects[] = "SELECT DISTINCT club_id FROM {$p}{$table}";
        }
        $ids = (array) $wpdb->get_col( implode( ' UNION ', $selects ) );
        $ids = array_map( 'intval', $ids );
        $ids[] = 1;
        return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
    }

    /**
     * Return the club's default methodology id, creating the shipped
     * "JO14-1 Hedel" set if none exists yet.
     */
    private function ensureDefaultMethodology( string $p, int $club_id ): int {
        global $wpdb;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodologies
              WHERE club_id = %d AND is_default = 1 AND archived_at IS NULL
              ORDER BY id ASC LIMIT 1",
            $club_id
        ) );
        if ( $existing > 0 ) {
            return $existing;
        }

        $this->exec( $wpdb->prepare(
            "INSERT INTO {$p}tt_methodologies
                (club_id, uuid, slug, name_json, description_json, is_default, is_shipped, sort_order)
             VALUES (%d, %s, %s, %s, %s, 1, 1, 0)",
            $club_id,
            wp_generate_uuid4(),
            'jo14-1-hedel',
            wp_json_encode( [ 'nl' => 'JO14-1 Hedel', 'en' => 'JO14-1 Hedel' ] ),
            wp_json_encode( [
                'nl' => 'Standaard voetbalmethode (1-4-2-3-1).',
                'en' => 'Default methodology (1-4-2-3-1).',
            ] )
        ) );

        return (int) $wpdb->insert_id;
    }

    /**
     * Add a KEY iff absent (addColumnIfMissing covers columns, not
     * indexes). Best-effort — a duplicate-index error is swallowed.
     */
    private function addIndexIfMissing( string $table, string $index, string $cols ): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, $index
        ) );
        if ( $exists === 0 ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `{$index}` {$cols}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
    }
};
