<?php
/**
 * Migration 0295 — the suspension record (#4103).
 *
 * A suspension used to live in a coach's head: match prep offered the
 * Suspended status (#4102), but somebody had to remember to pick it for
 * every match the ban covered, and the player's journey never said the
 * ban had happened at all. `tt_player_suspensions` records it once — how
 * many matches, from when, and why — and the planning surfaces derive
 * the rest.
 *
 * Four things, all idempotent:
 *
 *   1. The table. `club_id` and `uuid` per CLAUDE.md §4. `team_id` is the
 *      squad whose matches serve the ban, captured when it is recorded, so
 *      a later team move does not silently re-point it. `served_on` is set
 *      when the last covered match is completed; until then the ban is
 *      open. `archived_*` / `trashed_*` give it the same archive → bin →
 *      purge lifecycle as an injury.
 *   2. The `suspension_reason` vocabulary: yellow-card accumulation, red
 *      card, club decision. Labels go to `tt_translations` (migration 0087
 *      dropped `tt_lookups.translations`), from `LookupTranslationSeeds`
 *      so the seed and this migration cannot disagree.
 *   3. The `suspension_started` / `suspension_ended` journey event types,
 *      public: a suspension is an official sanction, and the player and
 *      their parents see it on the journey (decision 5 on #4103).
 *   4. The `player_suspensions` matrix entity. The seed file is only read
 *      at install time; without this top-up an existing install has no
 *      row for it and every reader is refused.
 *
 * Forward-only. Runs alone (AGENTS.md).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Modules\Configuration\LookupTranslationSeeds;

return new class extends Migration {

    private const REASON_TYPE = 'suspension_reason';

    /** Canonical English names, in display order. */
    private const REASONS = [ 'Yellow card accumulation', 'Red card', 'Club decision' ];

    /** Used only when the seed class cannot be loaded. */
    private const FALLBACK_REASON_LABELS = [
        'Yellow card accumulation' => [ 'nl_NL' => 'Te veel gele kaarten', 'fr_FR' => 'Cumul de cartons jaunes', 'de_DE' => 'Gelbsperre',           'es_ES' => 'Acumulación de amarillas' ],
        'Red card'                 => [ 'nl_NL' => 'Rode kaart',           'fr_FR' => 'Carton rouge',            'de_DE' => 'Rote Karte',           'es_ES' => 'Tarjeta roja' ],
        'Club decision'            => [ 'nl_NL' => 'Besluit van de club',  'fr_FR' => 'Décision du club',        'de_DE' => 'Vereinsentscheidung',  'es_ES' => 'Decisión del club' ],
    ];

    /** name => [ English description, meta ] */
    private const EVENT_TYPES = [
        'suspension_started' => [
            'label' => 'Suspension started',
            'meta'  => [ 'icon' => 'alert', 'color' => '#8a5300', 'severity' => 'warning', 'default_visibility' => 'public', 'group' => 'discipline', 'is_locked' => 1 ],
        ],
        'suspension_ended' => [
            'label' => 'Suspension served',
            'meta'  => [ 'icon' => 'check', 'color' => '#16a34a', 'severity' => 'info', 'default_visibility' => 'public', 'group' => 'discipline', 'is_locked' => 1 ],
        ],
    ];

    /** Used only when the seed class cannot be loaded. */
    private const FALLBACK_EVENT_LABELS = [
        'suspension_started' => [ 'nl_NL' => 'Schorsing ingegaan', 'fr_FR' => 'Suspension commencée', 'de_DE' => 'Sperre begonnen', 'es_ES' => 'Sanción iniciada' ],
        'suspension_ended'   => [ 'nl_NL' => 'Schorsing uitgezeten', 'fr_FR' => 'Suspension purgée', 'de_DE' => 'Sperre abgesessen', 'es_ES' => 'Sanción cumplida' ],
    ];

    /** The exact (persona, entity) pairs the matrix top-up may write. */
    private const GRANTS = [
        [ 'player',              'player_suspensions' ],
        [ 'parent',              'player_suspensions' ],
        [ 'assistant_coach',     'player_suspensions' ],
        [ 'head_coach',          'player_suspensions' ],
        [ 'head_of_development', 'player_suspensions' ],
        [ 'academy_admin',       'player_suspensions' ],
    ];

    public function getName(): string {
        return '0295_player_suspensions';
    }

    public function up(): void {
        $this->createTable();
        $this->seedReasons();
        $this->seedEventTypes();
        $this->topUpMatrix();
    }

    private function createTable(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();
        $table   = "{$p}tt_player_suspensions";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            player_id BIGINT UNSIGNED NOT NULL,
            team_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            started_on DATE NOT NULL,
            match_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
            reason_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT NULL,
            served_on DATE DEFAULT NULL,
            archived_at DATETIME DEFAULT NULL,
            archived_by BIGINT UNSIGNED DEFAULT NULL,
            trashed_at DATETIME DEFAULT NULL,
            trashed_by BIGINT UNSIGNED DEFAULT NULL,
            created_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            KEY idx_club_player (club_id, player_id),
            KEY idx_club_team_open (club_id, team_id, served_on)
        ) {$charset};" );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        $this->report( $exists ? 1 : 0, $exists ? 'tt_player_suspensions present' : 'tt_player_suspensions could not be created' );
    }

    private function seedReasons(): void {
        global $wpdb;
        $lookups = "{$wpdb->prefix}tt_lookups";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        $labels = $this->curatedLabels( self::REASON_TYPE, self::FALLBACK_REASON_LABELS );

        foreach ( $this->clubs() as $club_id ) {
            foreach ( self::REASONS as $i => $name ) {
                $row_id = $this->ensureLookup( $club_id, self::REASON_TYPE, $name, '', [], ( $i + 1 ) * 10 );
                $this->translate( $club_id, $row_id, [ 'name' ], $labels[ $name ] ?? [] );
            }
        }
    }

    private function seedEventTypes(): void {
        global $wpdb;
        $lookups = "{$wpdb->prefix}tt_lookups";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) return;

        $labels = $this->curatedLabels( 'journey_event_type', self::FALLBACK_EVENT_LABELS );

        foreach ( $this->clubs() as $club_id ) {
            $next = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE( MAX( sort_order ), 0 ) FROM {$lookups}
                  WHERE lookup_type = %s AND COALESCE( club_id, 1 ) = %d",
                'journey_event_type', $club_id
            ) );
            foreach ( self::EVENT_TYPES as $name => $def ) {
                $next  += 10;
                $row_id = $this->ensureLookup( $club_id, 'journey_event_type', $name, $def['label'], $def['meta'], $next );
                // The journey renders the description; the lookup admin the
                // name. Both carry the same label.
                $this->translate( $club_id, $row_id, [ 'name', 'description' ], $labels[ $name ] ?? [] );
            }
        }

        if ( class_exists( '\\TT\\Infrastructure\\Journey\\EventTypeRegistry' ) ) {
            \TT\Infrastructure\Journey\EventTypeRegistry::clearCache();
        }
    }

    private function topUpMatrix(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_authorization_matrix is missing' );
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            $this->report( 0, 'authorization_seed.php is not readable' );
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            $this->report( 0, 'authorization_seed.php did not return an array' );
            return;
        }

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        $written = 0;
        $seen    = 0;
        foreach ( $rows as $row ) {
            $persona = (string) ( $row['persona'] ?? '' );
            $entity  = (string) ( $row['entity'] ?? '' );
            if ( ! in_array( [ $persona, $entity ], self::GRANTS, true ) ) continue;

            $seen++;
            $written += (int) $wpdb->query( $wpdb->prepare(
                $sql,
                $persona,
                $entity,
                (string) $row['activity'],
                (string) $row['scope_kind'],
                (string) $row['module_class']
            ) );
        }

        // INSERT IGNORE reports 0 for a row already present, so 0 written
        // with the grants seen is the healthy repeat run. 0 seen means the
        // seed no longer carries them, which leaves every reader refused.
        $this->report( $written, sprintf( '%d player_suspensions grant row(s) found in the seed', $seen ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }

    /**
     * The row id for (club, type, name), inserting it when absent. A club
     * that already has the value under any casing keeps its own row.
     *
     * @param array<string, mixed> $meta
     */
    private function ensureLookup( int $club_id, string $type, string $name, string $description, array $meta, int $sort_order ): int {
        global $wpdb;
        $lookups = "{$wpdb->prefix}tt_lookups";

        $row_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$lookups}
              WHERE lookup_type = %s AND LOWER(name) = LOWER(%s) AND COALESCE( club_id, 1 ) = %d
              LIMIT 1",
            $type, $name, $club_id
        ) );
        if ( $row_id > 0 ) return $row_id;

        $row = [
            'club_id'     => $club_id,
            'lookup_type' => $type,
            'name'        => $name,
            'sort_order'  => $sort_order,
        ];
        if ( $description !== '' ) $row['description'] = $description;
        if ( $meta !== [] ) $row['meta'] = (string) wp_json_encode( $meta );

        $wpdb->insert( $lookups, $row );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param list<string>          $fields
     * @param array<string, string> $labels locale => label
     */
    private function translate( int $club_id, int $row_id, array $fields, array $labels ): void {
        global $wpdb;
        if ( $row_id <= 0 || $labels === [] ) return;

        $translations = "{$wpdb->prefix}tt_translations";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) !== $translations ) return;

        $now = current_time( 'mysql', true );
        foreach ( $fields as $field ) {
            foreach ( $labels as $locale => $value ) {
                if ( (string) $value === '' ) continue;
                $this->exec( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, %s, %d, %s, %s, %s, %s)",
                    $club_id,
                    'lookup',
                    $row_id,
                    (string) $field,
                    (string) $locale,
                    (string) $value,
                    $now
                ) );
            }
        }
    }

    /**
     * Every club that has a lookup vocabulary at all; club 1 on a fresh
     * install.
     *
     * @return list<int>
     */
    private function clubs(): array {
        global $wpdb;
        $clubs = $wpdb->get_col( "SELECT DISTINCT COALESCE( club_id, 1 ) FROM {$wpdb->prefix}tt_lookups" );
        $clubs = array_values( array_filter( array_map( 'intval', is_array( $clubs ) ? $clubs : [] ), static fn( int $c ): bool => $c > 0 ) );
        return $clubs === [] ? [ 1 ] : array_values( array_unique( $clubs ) );
    }

    /**
     * @param array<string, array<string, string>> $fallback
     * @return array<string, array<string, string>> name => locale => label
     */
    private function curatedLabels( string $type, array $fallback ): array {
        if ( ! class_exists( LookupTranslationSeeds::class ) ) return $fallback;
        $map = LookupTranslationSeeds::map();
        $out = $fallback;
        foreach ( array_keys( $fallback ) as $name ) {
            $labels = $map[ $type ][ $name ] ?? null;
            if ( is_array( $labels ) && $labels !== [] ) {
                $out[ $name ] = array_map( 'strval', $labels );
            }
        }
        return $out;
    }
};
