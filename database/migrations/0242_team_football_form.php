<?php
/**
 * Migration 0242 — a team's football form (#3044).
 *
 * Six-a-side, eight-a-side and eleven-a-side are different games, and until
 * now the product only knew the third one. Every seeded formation template
 * was an eleven-player shape, so a U9 coach opening the team blueprint was
 * offered a back four and a front three for a team that plays six-a-side.
 *
 * Four additive, idempotent steps:
 *
 *   1. `tt_teams.football_form` — nullable, so nothing is backfilled and an
 *      existing team keeps working. NULL means "use the age group's
 *      default", which is what makes this safe without a backfill.
 *   2. `tt_formation_templates.football_form` — every shape shipped so far is
 *      eleven-a-side, so existing rows take '11v11' and the blueprint picker
 *      can filter without guessing.
 *   3. The `football_form` vocabulary, seeded 6v6 / 8v8 / 11v11 with Dutch
 *      labels in `tt_translations` (not the dropped `tt_lookups.translations`
 *      column). Open for a club whose federation plays 4v4, 7v7 or 9v9.
 *   4. Four small-sided formation templates, two per small form. Without
 *      them the filtered picker is empty for a young team, which is worse
 *      than offering the wrong shapes.
 *
 * The age-group default map is seeded into `tt_config` from whatever age
 * groups this academy has, so the setting arrives filled in rather than
 * blank. An operator edits it under Configuration → Football form.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    /** Oldest age each form covers, for seeding the age-group map. */
    private const BANDS = [ 9 => '6v6', 12 => '8v8', 99 => '11v11' ];

    public function getName(): string {
        return '0242_team_football_form';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->addTeamColumn( $p . 'tt_teams' );
        $this->addTemplateColumn( $p . 'tt_formation_templates' );

        $ids = $this->seedVocabulary( $p . 'tt_lookups' );
        $this->seedTranslations( $p . 'tt_translations', $ids );
        $this->seedAgeGroupDefaults( $p . 'tt_lookups' );
        $seeded = $this->seedTemplates( $p . 'tt_formation_templates' );

        Logger::info( 'migration.0242.summary', [
            'forms_seeded'     => count( $ids ),
            'templates_seeded' => $seeded,
        ] );
    }

    private function addTeamColumn( string $table ): void {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        if ( ! MigrationHelpers::addColumnIfMissing( $table, 'football_form', "VARCHAR(50) DEFAULT NULL", 'age_group' ) ) {
            throw new \RuntimeException( '0242: failed to add football_form to tt_teams' );
        }
    }

    private function addTemplateColumn( string $table ): void {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        // Existing shapes are all eleven-a-side, so the default is the
        // truthful value for every row already there.
        if ( ! MigrationHelpers::addColumnIfMissing( $table, 'football_form', "VARCHAR(50) NOT NULL DEFAULT '11v11'", 'formation_shape' ) ) {
            throw new \RuntimeException( '0242: failed to add football_form to tt_formation_templates' );
        }

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
            $table, 'idx_football_form'
        ) );
        if ( $exists === 0 ) {
            $this->exec( "ALTER TABLE `{$table}` ADD KEY `idx_football_form` (football_form)" );
        }
    }

    /**
     * Seed the vocabulary. Existence-checked on (lookup_type, name) so a
     * re-run is a no-op and a club that already added 7v7 keeps it.
     *
     * @return array<string,int> name => lookup row id
     */
    private function seedVocabulary( string $lookups ): array {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return [];

        $forms = [
            '6v6'   => 'Six a side, no keeper, quarter pitch',
            '8v8'   => 'Eight a side including a keeper, half pitch',
            '11v11' => 'Eleven a side including a keeper, full pitch',
        ];

        $max_sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( MAX(sort_order), 0 ) FROM {$lookups} WHERE lookup_type = %s",
            'football_form'
        ) );

        $ids = [];
        $i   = 0;
        foreach ( $forms as $name => $description ) {
            $i++;
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s LIMIT 1",
                'football_form', $name
            ) );
            if ( $existing > 0 ) {
                $ids[ $name ] = $existing;
                continue;
            }

            $wpdb->insert( $lookups, [
                'lookup_type' => 'football_form',
                'name'        => $name,
                'description' => $description,
                'sort_order'  => $max_sort + $i,
            ] );
            $new_id = (int) $wpdb->insert_id;
            if ( $new_id > 0 ) $ids[ $name ] = $new_id;
        }

        return $ids;
    }

    /**
     * Dutch labels into `tt_translations` — migration 0087 dropped the
     * `tt_lookups.translations` column, so this is where a lookup's label
     * lives now. en_US carries the canonical English, matching the contract
     * migration 0131 set for every other vocabulary.
     *
     * @param array<string,int> $ids
     */
    private function seedTranslations( string $translations, array $ids ): void {
        global $wpdb;
        if ( $ids === [] ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) !== $translations ) return;

        $nl = [
            '6v6'   => '6 tegen 6',
            '8v8'   => '8 tegen 8',
            '11v11' => '11 tegen 11',
        ];

        $sql = "INSERT IGNORE INTO {$translations}
                  (club_id, entity_type, entity_id, field, locale, value, updated_at)
                VALUES (%d, %s, %d, %s, %s, %s, %s)";
        $now = current_time( 'mysql', true );

        foreach ( $ids as $name => $row_id ) {
            $locales = [ 'en_US' => $name ];
            if ( isset( $nl[ $name ] ) ) $locales['nl_NL'] = $nl[ $name ];

            foreach ( $locales as $locale => $value ) {
                $wpdb->query( $wpdb->prepare(
                    $sql, 1, 'lookup', (int) $row_id, 'name', $locale, $value, $now
                ) );
            }
        }
    }

    /**
     * Pre-fill the age-group -> form map from the academy's own age groups,
     * so the setting arrives answered rather than blank. Never overwrites a
     * map an operator has already saved.
     */
    private function seedAgeGroupDefaults( string $lookups ): void {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        $config = $wpdb->prefix . 'tt_config';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $config ) ) !== $config ) return;

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$config} WHERE config_key = %s AND club_id = %d LIMIT 1",
            'football_form_by_age_group', 1
        ) );
        if ( $existing !== null && trim( (string) $existing ) !== '' ) return;

        $groups = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT name FROM {$lookups} WHERE lookup_type = %s ORDER BY sort_order ASC, name ASC",
            'age_group'
        ) );
        if ( $groups === [] ) return;

        $map = [];
        foreach ( $groups as $group ) {
            $group = trim( (string) $group );
            if ( $group === '' ) continue;
            $map[ $group ] = $this->bandFor( $group );
        }
        if ( $map === [] ) return;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$config} (club_id, config_key, config_value) VALUES (%d, %s, %s)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)",
            1, 'football_form_by_age_group', (string) wp_json_encode( $map )
        ) );
    }

    /** "U9", "JO9" and "Onder 9" all read 9. */
    private function bandFor( string $age_group ): string {
        $age = 0;
        if ( preg_match( '/(\d+)/', $age_group, $m ) ) $age = (int) $m[1];
        if ( $age <= 0 ) return '11v11';
        foreach ( self::BANDS as $max_age => $form ) {
            if ( $age <= $max_age ) return $form;
        }
        return '11v11';
    }

    /** Small-sided shapes, so the filtered picker is never empty. */
    private function seedTemplates( string $templates ): int {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates ) ) !== $templates ) return 0;

        $seeded = 0;
        foreach ( $this->smallSidedTemplates() as $tpl ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$templates} WHERE name = %s LIMIT 1",
                $tpl['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $templates, [
                'name'            => $tpl['name'],
                'formation_shape' => $tpl['formation_shape'],
                'football_form'   => $tpl['football_form'],
                'slots_json'      => (string) wp_json_encode( $tpl['slots'] ),
                'is_seeded'       => 1,
            ] );
            if ( (int) $wpdb->insert_id > 0 ) $seeded++;
        }
        return $seeded;
    }

    /**
     * Two shapes per small form: one balanced, one that pushes a player
     * forward. `pos` is normalised {x, y} on the pitch (0,0 top-left,
     * 1,1 bottom-right), and each slot's weights sum to 1.0 — the same
     * contract the eleven-a-side templates from migration 0032 use, so the
     * compatibility engine reads them without a special case.
     *
     * Six-a-side is played without keepers, so no slot carries a GK; eight-
     * a-side does, so one does.
     *
     * @return list<array{name:string, formation_shape:string, football_form:string, slots:list<array<string,mixed>>}>
     */
    private function smallSidedTemplates(): array {
        $w = static fn ( float $t, float $ta, float $p, float $m ): array =>
            [ 'technical' => $t, 'tactical' => $ta, 'physical' => $p, 'mental' => $m ];

        return [
            [
                'name'            => 'Small-sided 3-2-1',
                'formation_shape' => '3-2-1',
                'football_form'   => '6v6',
                'slots'           => [
                    [ 'label' => 'LB', 'pos' => [ 'x' => 0.20, 'y' => 0.82 ], 'side' => 'left',   'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'CB', 'pos' => [ 'x' => 0.50, 'y' => 0.88 ], 'side' => 'center', 'weights' => $w( 0.20, 0.40, 0.30, 0.10 ) ],
                    [ 'label' => 'RB', 'pos' => [ 'x' => 0.80, 'y' => 0.82 ], 'side' => 'right',  'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'LM', 'pos' => [ 'x' => 0.32, 'y' => 0.50 ], 'side' => 'left',   'weights' => $w( 0.35, 0.30, 0.25, 0.10 ) ],
                    [ 'label' => 'RM', 'pos' => [ 'x' => 0.68, 'y' => 0.50 ], 'side' => 'right',  'weights' => $w( 0.35, 0.30, 0.25, 0.10 ) ],
                    [ 'label' => 'ST', 'pos' => [ 'x' => 0.50, 'y' => 0.16 ], 'side' => 'center', 'weights' => $w( 0.40, 0.25, 0.25, 0.10 ) ],
                ],
            ],
            [
                'name'            => 'Small-sided 2-3-1',
                'formation_shape' => '2-3-1',
                'football_form'   => '6v6',
                'slots'           => [
                    [ 'label' => 'LB', 'pos' => [ 'x' => 0.32, 'y' => 0.85 ], 'side' => 'left',   'weights' => $w( 0.25, 0.35, 0.30, 0.10 ) ],
                    [ 'label' => 'RB', 'pos' => [ 'x' => 0.68, 'y' => 0.85 ], 'side' => 'right',  'weights' => $w( 0.25, 0.35, 0.30, 0.10 ) ],
                    [ 'label' => 'LM', 'pos' => [ 'x' => 0.20, 'y' => 0.50 ], 'side' => 'left',   'weights' => $w( 0.40, 0.25, 0.25, 0.10 ) ],
                    [ 'label' => 'CM', 'pos' => [ 'x' => 0.50, 'y' => 0.55 ], 'side' => 'center', 'weights' => $w( 0.35, 0.35, 0.20, 0.10 ) ],
                    [ 'label' => 'RM', 'pos' => [ 'x' => 0.80, 'y' => 0.50 ], 'side' => 'right',  'weights' => $w( 0.40, 0.25, 0.25, 0.10 ) ],
                    [ 'label' => 'ST', 'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => $w( 0.45, 0.20, 0.25, 0.10 ) ],
                ],
            ],
            [
                'name'            => 'Small-sided 3-3-1',
                'formation_shape' => '3-3-1',
                'football_form'   => '8v8',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => $w( 0.20, 0.40, 0.20, 0.20 ) ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.22, 'y' => 0.80 ], 'side' => 'left',   'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'CB',  'pos' => [ 'x' => 0.50, 'y' => 0.84 ], 'side' => 'center', 'weights' => $w( 0.20, 0.40, 0.30, 0.10 ) ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.78, 'y' => 0.80 ], 'side' => 'right',  'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'LM',  'pos' => [ 'x' => 0.22, 'y' => 0.48 ], 'side' => 'left',   'weights' => $w( 0.35, 0.30, 0.25, 0.10 ) ],
                    [ 'label' => 'CM',  'pos' => [ 'x' => 0.50, 'y' => 0.52 ], 'side' => 'center', 'weights' => $w( 0.35, 0.35, 0.20, 0.10 ) ],
                    [ 'label' => 'RM',  'pos' => [ 'x' => 0.78, 'y' => 0.48 ], 'side' => 'right',  'weights' => $w( 0.35, 0.30, 0.25, 0.10 ) ],
                    [ 'label' => 'ST',  'pos' => [ 'x' => 0.50, 'y' => 0.15 ], 'side' => 'center', 'weights' => $w( 0.40, 0.25, 0.25, 0.10 ) ],
                ],
            ],
            [
                'name'            => 'Small-sided 3-2-2',
                'formation_shape' => '3-2-2',
                'football_form'   => '8v8',
                'slots'           => [
                    [ 'label' => 'GK',  'pos' => [ 'x' => 0.50, 'y' => 0.95 ], 'side' => 'center', 'weights' => $w( 0.20, 0.40, 0.20, 0.20 ) ],
                    [ 'label' => 'LB',  'pos' => [ 'x' => 0.22, 'y' => 0.80 ], 'side' => 'left',   'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'CB',  'pos' => [ 'x' => 0.50, 'y' => 0.84 ], 'side' => 'center', 'weights' => $w( 0.20, 0.40, 0.30, 0.10 ) ],
                    [ 'label' => 'RB',  'pos' => [ 'x' => 0.78, 'y' => 0.80 ], 'side' => 'right',  'weights' => $w( 0.25, 0.30, 0.30, 0.15 ) ],
                    [ 'label' => 'LCM', 'pos' => [ 'x' => 0.34, 'y' => 0.52 ], 'side' => 'left',   'weights' => $w( 0.35, 0.35, 0.20, 0.10 ) ],
                    [ 'label' => 'RCM', 'pos' => [ 'x' => 0.66, 'y' => 0.52 ], 'side' => 'right',  'weights' => $w( 0.35, 0.35, 0.20, 0.10 ) ],
                    [ 'label' => 'LF',  'pos' => [ 'x' => 0.32, 'y' => 0.18 ], 'side' => 'left',   'weights' => $w( 0.45, 0.20, 0.25, 0.10 ) ],
                    [ 'label' => 'RF',  'pos' => [ 'x' => 0.68, 'y' => 0.18 ], 'side' => 'right',  'weights' => $w( 0.45, 0.20, 0.25, 0.10 ) ],
                ],
            ],
        ];
    }
};
