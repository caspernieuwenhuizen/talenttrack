<?php
/**
 * Migration 0212 — merge the VCT exercise catalogue into `tt_exercises` (#2494).
 *
 * Two exercise catalogues shipped independently and could not see each
 * other: `tt_exercises` (#0016 — versioned, principle-linked, visibility
 * model, never given a UI) and `tt_vct_exercises` (#0095 — intensity band,
 * player counts, MD-suitability flags, driving the VCT rules engine).
 * Everything in the Training epic (#2493) reads one catalogue or it reads
 * neither well, and a coach must never meet two drill libraries with
 * different fields.
 *
 * This migration widens `tt_exercises` to carry every VCT attribute, moves
 * the rows across preserving `uuid`, and repoints the two child tables that
 * referenced `tt_vct_exercises.id`. `tt_vct_exercises` is left in place and
 * empty; dropping it is a separate, reversible migration in a later release.
 *
 * Design notes worth not re-deriving:
 *
 *   - MD suitability stays denormalised as eight TINYINT columns rather
 *     than JSON or a child table. The candidate query seeks on the
 *     composite index; JSON_CONTAINS would force a row scan. This is
 *     architecture review H5 on migration 0122 — do not "tidy" it.
 *   - `category_key` is carried denormalised alongside `category_id` for
 *     the same reason: joining `tt_exercise_categories` per candidate
 *     would defeat the covering index. `category_id` stays the
 *     authoring-side reference.
 *   - The VCT category string is copied verbatim into `category_key`, so
 *     the hot-path predicate is byte-identical before and after. That is
 *     what makes "identical block list after the merge" achievable rather
 *     than merely likely.
 *   - Coaching points are NOT moved. `tt_vct_coaching_points` resolves its
 *     text through `tt_translations` keyed on its own row id; a new table
 *     would change `entity_id` and orphan every translation for a cosmetic
 *     gain. Only its `exercise_id` is repointed.
 *   - Column adds go through MigrationHelpers::addColumnIfMissing, never
 *     dbDelta — dbDelta silently no-ops ALTERs on a drifted live table
 *     (the #1331/0129 incident class; CI lints this).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0212_exercise_library_merge';
    }

    public function up(): void {
        global $wpdb;
        $p         = $wpdb->prefix;
        $exercises = $p . 'tt_exercises';
        $vct       = $p . 'tt_vct_exercises';

        if ( ! $this->tableExists( $exercises ) ) {
            // 0088 never ran — nothing to widen. The runner records this
            // migration as applied; 0088 will create the table with its
            // own shape and a later install re-runs nothing. Bail rather
            // than ALTER a table that does not exist.
            return;
        }

        $this->addVctColumns( $exercises );
        $this->addCandidateIndex( $exercises );

        if ( ! $this->tableExists( $vct ) ) {
            return; // VCT schema never installed — nothing to backfill.
        }

        $category_ids = $this->ensureCategories( $p );
        $id_map       = $this->backfillExercises( $exercises, $vct, $category_ids );

        if ( $id_map ) {
            $this->remapChildren( $p, $id_map );
        }
    }

    /**
     * Widen `tt_exercises` with the VCT attribute set. Every column is
     * nullable (or defaulted) so the rows that predate the merge stay
     * valid — and, deliberately, stay OUT of VCT candidate selection: a
     * row with a NULL `age_min` fails `age_min <= %d`, so the engine's
     * result set is unchanged until someone fills the fields in.
     */
    private function addVctColumns( string $t ): void {
        $columns = [
            // Identity + taxonomy.
            'code'                     => 'VARCHAR(64) NULL',
            'category_key'             => 'VARCHAR(64) NULL',
            'tactical_theme'           => 'VARCHAR(64) NULL',
            'source'                   => "VARCHAR(20) NOT NULL DEFAULT 'club'",

            // Prescription.
            'intensity_band'           => 'TINYINT UNSIGNED NULL',
            'duration_minutes_min'     => 'SMALLINT UNSIGNED NULL',
            'duration_minutes_max'     => 'SMALLINT UNSIGNED NULL',
            'players_min'              => 'TINYINT UNSIGNED NULL',
            'players_max'              => 'TINYINT UNSIGNED NULL',
            'sided_size'               => 'VARCHAR(16) NULL',
            'age_min'                  => 'TINYINT UNSIGNED NULL',
            'age_max'                  => 'TINYINT UNSIGNED NULL',
            'pitch_preset'             => 'VARCHAR(24) NULL',

            // MD-suitability bit flags (H5 — denormalised on purpose).
            'md_minus_4'               => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_minus_3'               => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_minus_2'               => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_minus_1'               => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_zero'                  => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_plus_1'                => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_plus_2'                => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'md_none'                  => 'TINYINT UNSIGNED NOT NULL DEFAULT 1',

            // Carried straight over.
            'equipment_json'           => 'LONGTEXT NULL',
            'verheijen_classification' => 'VARCHAR(64) NULL',
            'seed_revision'            => 'INT UNSIGNED NOT NULL DEFAULT 0',
        ];

        foreach ( $columns as $name => $definition ) {
            if ( ! MigrationHelpers::addColumnIfMissing( $t, $name, $definition ) ) {
                throw new \RuntimeException(
                    "Migration 0212: failed to add column {$name} to {$t}"
                );
            }
        }
    }

    /**
     * The hot-path covering index, mirroring `idx_candidate_lookup` on
     * `tt_vct_exercises`. Without it the engine degrades from an index
     * seek to a table scan at 1000+ exercises per club.
     */
    private function addCandidateIndex( string $t ): void {
        global $wpdb;

        $existing = $wpdb->get_results( "SHOW INDEX FROM `{$t}` WHERE Key_name = 'idx_candidate_lookup'" );
        if ( ! $existing ) {
            $this->exec(
                "ALTER TABLE `{$t}` ADD KEY idx_candidate_lookup
                 (club_id, archived_at, category_key, intensity_band, age_min, age_max)"
            );
        }

        // Preserves the VCT per-club code invariant. NULLs are exempt from
        // UNIQUE in MySQL, so every pre-merge row (code IS NULL) is fine.
        $existing_code = $wpdb->get_results( "SHOW INDEX FROM `{$t}` WHERE Key_name = 'uk_club_code'" );
        if ( ! $existing_code ) {
            $this->exec( "ALTER TABLE `{$t}` ADD UNIQUE KEY uk_club_code (club_id, code)" );
        }
    }

    /**
     * Map each VCT category string to a `tt_exercise_categories` row,
     * seeding the ones 0088 did not ship. `cool_down` reuses 0088's
     * `cooldown` row rather than seeding a near-duplicate; the exercises
     * still carry `category_key = 'cool_down'` so the engine predicate is
     * unchanged.
     *
     * @return array<string,int> VCT category string => category row id
     */
    private function ensureCategories( string $p ): array {
        global $wpdb;

        $table = $p . 'tt_exercise_categories';
        $map   = [
            'warmup'       => [ 'warmup',       'Warm-up',      10 ],
            'technical'    => [ 'technical',    'Technical',    15 ],
            'sided_game'   => [ 'sided_game',   'Sided game',   35 ],
            'conditioning' => [ 'conditioning', 'Conditioning', 45 ],
            'finishing'    => [ 'finishing',    'Finishing',    50 ],
            'cool_down'    => [ 'cooldown',     'Cool-down',    70 ],
        ];

        $now = current_time( 'mysql' );
        $out = [];

        foreach ( $map as $vct_key => [ $slug, $label, $sort ] ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE club_id = 1 AND slug = %s LIMIT 1",
                $slug
            ) );
            if ( ! $id ) {
                $wpdb->insert( $table, [
                    'club_id'    => 1,
                    'slug'       => $slug,
                    'label'      => $label,
                    'sort_order' => $sort,
                    'is_system'  => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ] );
                $id = (int) $wpdb->insert_id;
            }
            if ( $id ) $out[ $vct_key ] = $id;
        }

        return $out;
    }

    /**
     * Copy every `tt_vct_exercises` row into `tt_exercises`, preserving
     * `uuid` so a re-run is a no-op and so the child remap can be driven
     * off a stable key.
     *
     * @param array<string,int> $category_ids
     * @return array<int,int> old tt_vct_exercises.id => new tt_exercises.id
     */
    private function backfillExercises( string $exercises, string $vct, array $category_ids ): array {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT * FROM `{$vct}` ORDER BY id ASC" );
        if ( ! is_array( $rows ) || ! $rows ) return [];

        $now    = current_time( 'mysql' );
        $id_map = [];

        foreach ( $rows as $r ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$exercises}` WHERE uuid = %s LIMIT 1",
                $r->uuid
            ) );
            if ( $existing ) {
                // Idempotent re-run — the row already moved.
                $id_map[ (int) $r->id ] = $existing;
                continue;
            }

            $inserted = $wpdb->insert( $exercises, [
                'uuid'                     => $r->uuid,
                'club_id'                  => (int) $r->club_id,
                'name'                     => $r->name_canonical,
                'description'              => null,
                'duration_minutes'         => (int) $r->duration_minutes_min,
                'category_id'              => $category_ids[ $r->category ] ?? null,
                'diagram_url'              => $r->diagram_url,
                'author_user_id'           => null,
                'visibility'               => 'club',
                'version'                  => 1,
                'superseded_by_id'         => null,
                'archived_at'              => $r->archived_at,
                'created_at'               => $r->created_at ?: $now,
                'updated_at'               => $r->updated_at ?: $now,

                'code'                     => $r->code,
                'category_key'             => $r->category,
                'tactical_theme'           => $r->tactical_theme,
                'source'                   => 'vct',
                'intensity_band'           => (int) $r->intensity_band,
                'duration_minutes_min'     => (int) $r->duration_minutes_min,
                'duration_minutes_max'     => (int) $r->duration_minutes_max,
                'players_min'              => (int) $r->players_min,
                'players_max'              => (int) $r->players_max,
                'sided_size'               => $r->sided_size,
                'age_min'                  => (int) $r->age_min,
                'age_max'                  => (int) $r->age_max,
                'md_minus_4'               => (int) $r->md_minus_4,
                'md_minus_3'               => (int) $r->md_minus_3,
                'md_minus_2'               => (int) $r->md_minus_2,
                'md_minus_1'               => (int) $r->md_minus_1,
                'md_zero'                  => (int) $r->md_zero,
                'md_plus_1'                => (int) $r->md_plus_1,
                'md_plus_2'                => (int) $r->md_plus_2,
                'md_none'                  => (int) $r->md_none,
                'equipment_json'           => $r->equipment_json,
                'verheijen_classification' => $r->verheijen_classification,
                'seed_revision'            => (int) $r->seed_revision,
            ] );

            if ( $inserted === false ) {
                throw new \RuntimeException(
                    'Migration 0212: failed to move VCT exercise ' . $r->code . ' — ' . $wpdb->last_error
                );
            }

            $id_map[ (int) $r->id ] = (int) $wpdb->insert_id;
        }

        // The catalogue now lives in tt_exercises. Emptying the old table
        // is what makes the merge observable and stops a stale second
        // catalogue drifting back into existence.
        $this->exec( "DELETE FROM `{$vct}`" );

        return $id_map;
    }

    /**
     * Repoint the two tables that referenced `tt_vct_exercises.id`.
     *
     * `tt_vct_session_blocks.exercise_id` — historical sessions must keep
     * rendering the same drill. `tt_vct_coaching_points.exercise_id` — the
     * points themselves stay put, translations and all.
     *
     * @param array<int,int> $id_map
     */
    private function remapChildren( string $p, array $id_map ): void {
        $targets = [
            $p . 'tt_vct_session_blocks',
            $p . 'tt_vct_coaching_points',
        ];

        foreach ( $targets as $table ) {
            if ( ! $this->tableExists( $table ) ) continue;

            // Two passes through a disjoint id space. Old and new ids are
            // both dense from 1, so a single UPDATE would risk mapping a
            // row twice (old 3 -> new 7, then 7 -> something else). The
            // offset parks every value out of range first.
            $offset = 1000000000;

            foreach ( $id_map as $old => $new ) {
                $this->exec( sprintf(
                    "UPDATE `%s` SET exercise_id = %d WHERE exercise_id = %d",
                    $table,
                    $offset + $new,
                    $old
                ) );
            }
            $this->exec( sprintf(
                "UPDATE `%s` SET exercise_id = exercise_id - %d WHERE exercise_id >= %d",
                $table,
                $offset,
                $offset
            ) );
        }
    }

    private function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
};
