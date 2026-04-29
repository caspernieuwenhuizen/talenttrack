<?php
/**
 * Migration 0042 — Player status Sprint 1 inputs (#0057).
 *
 * Two new tables: behaviour ratings (continuous capture between
 * evaluations) and potential history (trainer's stated belief about
 * how high the player can reach). Both ride alongside the existing
 * eval / attendance data and feed the status calculator.
 *
 * Also seeds two new lookup sets:
 *   - `behaviour_rating_label` — 1..5 scale (Concerning…Exemplary).
 *   - `potential_band`         — first_team / professional_elsewhere /
 *                                semi_pro / top_amateur / recreational.
 *
 * Idempotent. No data backfill.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0042_player_behaviour_and_potential';
    }

    public function up(): void {
        $this->createBehaviourTable();
        $this->createPotentialTable();
        $this->seedBehaviourLookup();
        $this->seedPotentialLookup();
    }

    private function createBehaviourTable(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_player_behaviour_ratings";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) return;

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                player_id BIGINT UNSIGNED NOT NULL,
                rated_at DATETIME NOT NULL,
                rated_by BIGINT UNSIGNED NOT NULL,
                rating DECIMAL(3,1) NOT NULL,
                context VARCHAR(64) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                related_activity_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_player_rated (player_id, rated_at),
                KEY idx_club_id (club_id)
            ) {$charset};
        " );
    }

    private function createPotentialTable(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_player_potential";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) return;

        $charset = $wpdb->get_charset_collate();
        $wpdb->query( "
            CREATE TABLE {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                club_id INT UNSIGNED NOT NULL DEFAULT 1,
                player_id BIGINT UNSIGNED NOT NULL,
                set_at DATETIME NOT NULL,
                set_by BIGINT UNSIGNED NOT NULL,
                potential_band VARCHAR(32) NOT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_player_set (player_id, set_at),
                KEY idx_club_id (club_id)
            ) {$charset};
        " );
    }

    private function seedBehaviourLookup(): void {
        $rows = [
            [ 'name' => '1', 'description' => 'Concerning',           'nl' => 'Zorgwekkend',          'sort' => 10 ],
            [ 'name' => '2', 'description' => 'Below expectations',   'nl' => 'Onder verwachting',    'sort' => 20 ],
            [ 'name' => '3', 'description' => 'Acceptable',           'nl' => 'Acceptabel',           'sort' => 30 ],
            [ 'name' => '4', 'description' => 'Strong',               'nl' => 'Sterk',                'sort' => 40 ],
            [ 'name' => '5', 'description' => 'Exemplary',            'nl' => 'Voorbeeldig',          'sort' => 50 ],
        ];
        $this->seedLookupRows( 'behaviour_rating_label', $rows );
    }

    private function seedPotentialLookup(): void {
        $rows = [
            [ 'name' => 'first_team',             'description' => 'First team',             'nl' => 'Eerste elftal',          'sort' => 10 ],
            [ 'name' => 'professional_elsewhere', 'description' => 'Pro elsewhere',          'nl' => 'Profvoetbal elders',     'sort' => 20 ],
            [ 'name' => 'semi_pro',               'description' => 'Semi-professional',      'nl' => 'Semi-professional',      'sort' => 30 ],
            [ 'name' => 'top_amateur',            'description' => 'Top amateur',            'nl' => 'Hoog amateur',           'sort' => 40 ],
            [ 'name' => 'recreational',           'description' => 'Recreational',           'nl' => 'Recreatief',             'sort' => 50 ],
        ];
        $this->seedLookupRows( 'potential_band', $rows );
    }

    /**
     * @param array<int,array{name:string,description:string,nl:string,sort:int}> $rows
     */
    private function seedLookupRows( string $lookup_type, array $rows ): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = "{$p}tt_lookups";

        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s",
                $lookup_type, $row['name']
            ) );
            if ( $existing > 0 ) continue;

            $wpdb->insert( $table, [
                'lookup_type'  => $lookup_type,
                'name'         => $row['name'],
                'description'  => $row['description'],
                'meta'         => (string) wp_json_encode( [ 'is_locked' => 1 ] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl'], 'description' => '' ],
                ] ),
                'sort_order'   => $row['sort'],
            ] );
        }
    }
};
