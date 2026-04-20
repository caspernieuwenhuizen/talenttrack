<?php
/**
 * Migration 0008 — Evaluation categories as a first-class table with hierarchy.
 *
 * Sprint 1I (v2.12.0). Splits evaluation categories out of the generic
 * `tt_lookups` bag into a dedicated `tt_eval_categories` table that supports
 * parent/child relationships, so a main category like "Technical" can have
 * subcategories like "Short pass", "Long pass", "Shooting", etc.
 *
 * Behaviour:
 *
 *   1. Creates tt_eval_categories if it doesn't exist.
 *   2. Copies every lookup_type='eval_category' row from tt_lookups into
 *      tt_eval_categories as a main category (parent_id IS NULL). The new
 *      row's `key` is derived from the label (sanitize_key) and the
 *      category_id referenced by tt_eval_ratings is REMAPPED to the new
 *      category's id via an idempotent lookup-name → new-id map.
 *   3. Seeds 21 subcategories as children of the 4 canonical main
 *      categories (Technical / Tactical / Physical / Mental). Skipped
 *      if a subcategory with the same `key` already exists.
 *   4. Deletes the lookup_type='eval_category' rows from tt_lookups AFTER
 *      the tt_eval_ratings remapping succeeds. Non-destructive on failure:
 *      if remapping can't find a target for even one row, the migration
 *      throws and nothing gets deleted.
 *
 * Re-runnability: idempotent. If the migration partially succeeded on a
 * previous run, the category copy uses ON DUPLICATE KEY UPDATE logic
 * (checks existence by key first) and the seed skips existing keys.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0008_eval_categories_hierarchy';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->ensureCategoriesTable( $p );

        // Remap old lookup IDs → new eval_categories IDs, keyed by label.
        // Built while copying main categories over.
        $id_remap = $this->copyMainCategoriesFromLookups( $p );

        // Retarget tt_eval_ratings rows to the new category IDs. Must happen
        // BEFORE we delete the old lookup rows, or we lose the mapping.
        $retargeted = $this->retargetEvalRatings( $p, $id_remap );

        // Seed the 21 subcategories (idempotent).
        $this->seedSubcategories( $p );

        // Only after everything above succeeded, retire the lookup rows.
        // We keep them behind a safety net: if somehow a retargeting row
        // is still referencing a lookup id, we leave the lookup rows alone.
        if ( $retargeted['orphans'] === 0 ) {
            $wpdb->delete( "{$p}tt_lookups", [ 'lookup_type' => 'eval_category' ] );
        } else {
            throw new \RuntimeException( sprintf(
                'Migration 0008: %d tt_eval_ratings rows have category_id values not found in the remap. Retaining old lookup rows so no data is silently dropped.',
                $retargeted['orphans']
            ) );
        }
    }

    /* ═══════════════ Steps ═══════════════ */

    private function ensureCategoriesTable( string $p ): void {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$p}tt_eval_categories" ) ) === "{$p}tt_eval_categories" ) {
            return;
        }
        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$p}tt_eval_categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            `key` VARCHAR(64) NOT NULL,
            label VARCHAR(255) NOT NULL,
            description TEXT,
            display_order INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            is_system TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_key (`key`),
            KEY idx_parent (parent_id),
            KEY idx_active (is_active)
        ) {$charset}" );
    }

    /**
     * @return array<int,int>  old_lookup_id => new_eval_category_id
     */
    private function copyMainCategoriesFromLookups( string $p ): array {
        global $wpdb;

        $remap = [];
        $existing_rows = $wpdb->get_results(
            "SELECT id, name, description, sort_order FROM {$p}tt_lookups WHERE lookup_type = 'eval_category' ORDER BY sort_order ASC, id ASC"
        );
        if ( ! is_array( $existing_rows ) ) return $remap;

        foreach ( $existing_rows as $row ) {
            $name        = (string) $row->name;
            $description = (string) ( $row->description ?? '' );
            $sort_order  = (int) ( $row->sort_order ?? 0 );
            if ( $name === '' ) continue;

            $key = $this->keyFromLabel( $name );

            // Does this key already exist in the new table? (Re-run safety.)
            $existing_new_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_eval_categories WHERE `key` = %s LIMIT 1",
                $key
            ) );
            if ( $existing_new_id > 0 ) {
                $remap[ (int) $row->id ] = $existing_new_id;
                continue;
            }

            $insert_ok = $wpdb->insert( "{$p}tt_eval_categories", [
                'parent_id'     => null,
                'key'           => $key,
                'label'         => $name,
                'description'   => $description,
                'display_order' => $sort_order,
                'is_active'     => 1,
                'is_system'     => $this->isCanonicalMainKey( $key ) ? 1 : 0,
            ] );
            if ( $insert_ok === false ) {
                throw new \RuntimeException( sprintf(
                    'Migration 0008: failed to copy eval_category "%s" from tt_lookups. Last wpdb error: %s',
                    $name,
                    (string) $wpdb->last_error
                ) );
            }
            $remap[ (int) $row->id ] = (int) $wpdb->insert_id;
        }

        return $remap;
    }

    /**
     * @param array<int,int> $remap
     * @return array{retargeted:int, orphans:int}
     */
    private function retargetEvalRatings( string $p, array $remap ): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, category_id FROM {$p}tt_eval_ratings"
        );
        if ( ! is_array( $rows ) ) return [ 'retargeted' => 0, 'orphans' => 0 ];

        $retargeted = 0;
        $orphans    = 0;
        foreach ( $rows as $r ) {
            $old = (int) $r->category_id;

            // If this category_id is already pointing at a new-table row
            // (second run, or fresh install), skip. We detect that by
            // seeing if the ID exists in tt_eval_categories — in which
            // case we have nothing to do.
            $in_new_table = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_eval_categories WHERE id = %d",
                $old
            ) );
            if ( $in_new_table > 0 ) {
                $retargeted++;
                continue;
            }

            if ( ! isset( $remap[ $old ] ) ) {
                $orphans++;
                continue;
            }

            $wpdb->update(
                "{$p}tt_eval_ratings",
                [ 'category_id' => $remap[ $old ] ],
                [ 'id' => (int) $r->id ],
                [ '%d' ],
                [ '%d' ]
            );
            $retargeted++;
        }

        return [ 'retargeted' => $retargeted, 'orphans' => $orphans ];
    }

    private function seedSubcategories( string $p ): void {
        global $wpdb;

        // 21 subcategories across 4 main categories. Each entry: [main_key, sub_key, label, display_order].
        $seed = [
            /* Technical */
            [ 'technical', 'technical_short_pass',  'Short pass',   10 ],
            [ 'technical', 'technical_long_pass',   'Long pass',    20 ],
            [ 'technical', 'technical_first_touch', 'First touch',  30 ],
            [ 'technical', 'technical_dribbling',   'Dribbling',    40 ],
            [ 'technical', 'technical_shooting',    'Shooting',     50 ],
            [ 'technical', 'technical_heading',     'Heading',      60 ],

            /* Tactical */
            [ 'tactical', 'tactical_positioning_offensive', 'Offensive positioning', 10 ],
            [ 'tactical', 'tactical_positioning_defensive', 'Defensive positioning', 20 ],
            [ 'tactical', 'tactical_game_reading',          'Game reading',          30 ],
            [ 'tactical', 'tactical_decision_making',       'Decision making',       40 ],
            [ 'tactical', 'tactical_off_ball_movement',     'Off-ball movement',     50 ],

            /* Physical */
            [ 'physical', 'physical_speed',        'Speed',        10 ],
            [ 'physical', 'physical_endurance',    'Endurance',    20 ],
            [ 'physical', 'physical_strength',     'Strength',     30 ],
            [ 'physical', 'physical_agility',      'Agility',      40 ],
            [ 'physical', 'physical_coordination', 'Coordination', 50 ],

            /* Mental */
            [ 'mental', 'mental_focus',         'Focus',         10 ],
            [ 'mental', 'mental_leadership',    'Leadership',    20 ],
            [ 'mental', 'mental_attitude',      'Attitude',      30 ],
            [ 'mental', 'mental_resilience',    'Resilience',    40 ],
            [ 'mental', 'mental_coachability',  'Coachability',  50 ],
        ];

        foreach ( $seed as $row ) {
            [ $main_key, $sub_key, $label, $display_order ] = $row;

            // Resolve parent by key.
            $parent_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_eval_categories WHERE `key` = %s LIMIT 1",
                $main_key
            ) );
            if ( $parent_id <= 0 ) {
                // Parent main category not present on this site (admin may
                // have renamed or deleted it). Skip this subcategory rather
                // than creating an orphan.
                continue;
            }

            // Already seeded?
            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_eval_categories WHERE `key` = %s",
                $sub_key
            ) );
            if ( $exists > 0 ) continue;

            $wpdb->insert( "{$p}tt_eval_categories", [
                'parent_id'     => $parent_id,
                'key'           => $sub_key,
                'label'         => $label,
                'description'   => null,
                'display_order' => $display_order,
                'is_active'     => 1,
                'is_system'     => 1,
            ] );
        }
    }

    /* ═══════════════ Helpers ═══════════════ */

    /**
     * Derive a stable slug from a user-entered label. Matches what admins
     * would see if they renamed their main categories — e.g. "Technical"
     * becomes `technical`, "Game Awareness" becomes `game_awareness`.
     */
    private function keyFromLabel( string $label ): string {
        $key = sanitize_key( $label );
        if ( $key === '' ) {
            // Fall back to a unique-ish placeholder if sanitize_key drops
            // everything (foreign-script labels on ASCII-only installs).
            $key = 'cat_' . substr( md5( $label ), 0, 8 );
        }
        return $key;
    }

    /**
     * True when this is one of the four canonical main categories — used
     * to set is_system=1 on them. Admin-added main categories get is_system=0.
     */
    private function isCanonicalMainKey( string $key ): bool {
        return in_array( $key, [ 'technical', 'tactical', 'physical', 'mental' ], true );
    }
};
