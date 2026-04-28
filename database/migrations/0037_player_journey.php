<?php
/**
 * Migration 0037 — Player journey (#0053).
 *
 * Two new tables that turn cross-module records into a chronological
 * journey per player:
 *
 *   tt_player_events    — unifying spine; one row per event, soft-correct
 *                         via superseded_by_event_id; per-event visibility
 *                         (public / coaching_staff / medical / safeguarding).
 *   tt_player_injuries  — minimal injury record; emits journey events on
 *                         insert and on actual_return set.
 *
 * Plus four lookup type seeds (journey_event_type with 14 v1 types,
 * injury_type, body_part, injury_severity), plus a one-shot backfill
 * walking existing data: evaluations -> evaluation_completed, signed-off
 * PDP verdicts -> pdp_verdict_recorded, goals -> goal_set, players ->
 * joined_academy, trial cases -> trial_started + trial_ended.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, lookup seed checks on
 * (lookup_type, name), backfill upserts via uk_natural so re-running the
 * migration does not multiply events.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0037_player_journey';
    }

    public function up(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $events = "CREATE TABLE IF NOT EXISTS {$p}tt_player_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            uuid CHAR(36) NOT NULL,
            player_id BIGINT UNSIGNED NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            event_date DATETIME NOT NULL,
            effective_from DATE DEFAULT NULL,
            effective_to DATE DEFAULT NULL,
            summary VARCHAR(500) NOT NULL,
            payload LONGTEXT,
            payload_valid TINYINT(1) NOT NULL DEFAULT 1,
            visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            source_module VARCHAR(64) NOT NULL,
            source_entity_type VARCHAR(64) NOT NULL,
            source_entity_id BIGINT UNSIGNED DEFAULT NULL,
            superseded_by_event_id BIGINT UNSIGNED DEFAULT NULL,
            superseded_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_uuid (uuid),
            UNIQUE KEY uk_natural (source_module, source_entity_type, source_entity_id, event_type),
            KEY idx_player_date (player_id, event_date, id),
            KEY idx_player_type_date (player_id, event_type, event_date),
            KEY idx_source (source_module, source_entity_type, source_entity_id),
            KEY idx_visibility (visibility),
            KEY idx_club (club_id)
        ) $charset;";
        dbDelta( $events );

        $injuries = "CREATE TABLE IF NOT EXISTS {$p}tt_player_injuries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            club_id INT UNSIGNED NOT NULL DEFAULT 1,
            player_id BIGINT UNSIGNED NOT NULL,
            started_on DATE NOT NULL,
            expected_return DATE DEFAULT NULL,
            actual_return DATE DEFAULT NULL,
            injury_type_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            body_part_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            severity_lookup_id BIGINT UNSIGNED DEFAULT NULL,
            notes TEXT,
            is_recovery_logged TINYINT(1) NOT NULL DEFAULT 0,
            archived_at DATETIME DEFAULT NULL,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_player (player_id),
            KEY idx_open (player_id, actual_return),
            KEY idx_started (started_on),
            KEY idx_club (club_id)
        ) $charset;";
        dbDelta( $injuries );

        $this->seedJourneyEventTypes();
        $this->seedInjuryLookups();
        $this->seedInjuryRecoveryTrigger();
        $this->backfillEvents();
    }

    /**
     * Wire `tt_journey_injury_logged` -> injury_recovery_due workflow.
     * Idempotent — gated on (template_key + event_hook) absence.
     */
    private function seedInjuryRecoveryTrigger(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $triggers_table = "{$p}tt_workflow_triggers";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $triggers_table ) ) !== $triggers_table ) {
            return;
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$triggers_table}
              WHERE template_key = %s AND event_hook = %s",
            'injury_recovery_due',
            'tt_journey_injury_logged'
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( $triggers_table, [
            'template_key' => 'injury_recovery_due',
            'trigger_type' => 'event',
            'event_hook'   => 'tt_journey_injury_logged',
            'enabled'      => 1,
            'created_at'   => current_time( 'mysql' ),
        ] );
    }

    /**
     * 14 v1 journey event types, each carrying icon / color / severity /
     * default_visibility / group in `meta`. Per-club editable.
     */
    private function seedJourneyEventTypes(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        $rows = [
            [ 'name' => 'joined_academy',       'severity' => 'milestone', 'default_visibility' => 'public',          'group' => 'lifecycle',   'icon' => 'badge',       'color' => '#16a34a', 'label' => 'Joined the academy',     'nl' => 'Bij de academie gekomen' ],
            [ 'name' => 'trial_started',        'severity' => 'info',      'default_visibility' => 'public',          'group' => 'lifecycle',   'icon' => 'clock',       'color' => '#0d9488', 'label' => 'Trial started',          'nl' => 'Stage gestart' ],
            [ 'name' => 'trial_ended',          'severity' => 'info',      'default_visibility' => 'public',          'group' => 'lifecycle',   'icon' => 'flag',        'color' => '#0d9488', 'label' => 'Trial ended',            'nl' => 'Stage afgerond' ],
            [ 'name' => 'signed',               'severity' => 'milestone', 'default_visibility' => 'public',          'group' => 'lifecycle',   'icon' => 'check',       'color' => '#16a34a', 'label' => 'Signed',                 'nl' => 'Vastgelegd' ],
            [ 'name' => 'released',             'severity' => 'milestone', 'default_visibility' => 'coaching_staff', 'group' => 'lifecycle',   'icon' => 'x',           'color' => '#b91c1c', 'label' => 'Released',               'nl' => 'Afscheid genomen' ],
            [ 'name' => 'graduated',            'severity' => 'milestone', 'default_visibility' => 'public',          'group' => 'lifecycle',   'icon' => 'star',        'color' => '#d97706', 'label' => 'Graduated',              'nl' => 'Doorgestroomd' ],
            [ 'name' => 'team_changed',         'severity' => 'info',      'default_visibility' => 'public',          'group' => 'roster',      'icon' => 'shuffle',     'color' => '#2563eb', 'label' => 'Team changed',           'nl' => 'Team gewisseld' ],
            [ 'name' => 'age_group_promoted',   'severity' => 'milestone', 'default_visibility' => 'public',          'group' => 'roster',      'icon' => 'arrow-up',    'color' => '#16a34a', 'label' => 'Promoted to next age group', 'nl' => 'Naar volgende leeftijdscategorie' ],
            [ 'name' => 'position_changed',     'severity' => 'info',      'default_visibility' => 'public',          'group' => 'roster',      'icon' => 'compass',     'color' => '#2563eb', 'label' => 'Position changed',       'nl' => 'Positie gewijzigd' ],
            [ 'name' => 'injury_started',       'severity' => 'warning',   'default_visibility' => 'medical',         'group' => 'health',      'icon' => 'alert',       'color' => '#dc2626', 'label' => 'Injury started',         'nl' => 'Blessure ingetreden' ],
            [ 'name' => 'injury_ended',         'severity' => 'info',      'default_visibility' => 'medical',         'group' => 'health',      'icon' => 'heart',       'color' => '#16a34a', 'label' => 'Injury ended',           'nl' => 'Blessure hersteld' ],
            [ 'name' => 'evaluation_completed', 'severity' => 'info',      'default_visibility' => 'public',          'group' => 'development', 'icon' => 'list-check',  'color' => '#5b6e75', 'label' => 'Evaluation completed',   'nl' => 'Evaluatie ingevoerd' ],
            [ 'name' => 'pdp_verdict_recorded', 'severity' => 'milestone', 'default_visibility' => 'public',          'group' => 'development', 'icon' => 'gavel',       'color' => '#7c3aed', 'label' => 'PDP verdict recorded',   'nl' => 'POP-eindbeoordeling vastgelegd' ],
            [ 'name' => 'note_added',           'severity' => 'info',      'default_visibility' => 'coaching_staff', 'group' => 'development', 'icon' => 'note',        'color' => '#5b6e75', 'label' => 'Note added',             'nl' => 'Notitie toegevoegd' ],
        ];

        $sort_order = 10;
        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s",
                'journey_event_type',
                $row['name']
            ) );
            if ( $existing > 0 ) { $sort_order += 10; continue; }

            $wpdb->insert( $table, [
                'lookup_type'  => 'journey_event_type',
                'name'         => $row['name'],
                'description'  => $row['label'],
                'meta'         => (string) wp_json_encode( [
                    'icon'               => $row['icon'],
                    'color'              => $row['color'],
                    'severity'           => $row['severity'],
                    'default_visibility' => $row['default_visibility'],
                    'group'              => $row['group'],
                    'is_locked'          => 1,
                ] ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl'], 'description' => '' ],
                ] ),
                'sort_order'   => $sort_order,
            ] );
            $sort_order += 10;
        }
    }

    private function seedInjuryLookups(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_lookups';

        $injury_types = [
            [ 'name' => 'sprain',     'nl' => 'Verstuiking' ],
            [ 'name' => 'strain',     'nl' => 'Verrekking' ],
            [ 'name' => 'fracture',   'nl' => 'Botbreuk' ],
            [ 'name' => 'concussion', 'nl' => 'Hersenschudding' ],
            [ 'name' => 'overuse',    'nl' => 'Overbelasting' ],
            [ 'name' => 'illness',    'nl' => 'Ziekte' ],
            [ 'name' => 'other',      'nl' => 'Overig' ],
        ];

        $body_parts = [
            [ 'name' => 'ankle',       'nl' => 'Enkel' ],
            [ 'name' => 'knee',        'nl' => 'Knie' ],
            [ 'name' => 'hamstring',   'nl' => 'Hamstring' ],
            [ 'name' => 'groin',       'nl' => 'Lies' ],
            [ 'name' => 'hip',         'nl' => 'Heup' ],
            [ 'name' => 'lower_back',  'nl' => 'Onderrug' ],
            [ 'name' => 'upper_back',  'nl' => 'Bovenrug' ],
            [ 'name' => 'shoulder',    'nl' => 'Schouder' ],
            [ 'name' => 'wrist',       'nl' => 'Pols' ],
            [ 'name' => 'hand',        'nl' => 'Hand' ],
            [ 'name' => 'foot',        'nl' => 'Voet' ],
            [ 'name' => 'head',        'nl' => 'Hoofd' ],
            [ 'name' => 'other',       'nl' => 'Overig' ],
        ];

        $severities = [
            [ 'name' => 'minor',         'nl' => 'Licht (max 2 weken)',         'meta' => [ 'expected_weeks_max' => 2 ] ],
            [ 'name' => 'moderate',      'nl' => 'Matig (2-6 weken)',           'meta' => [ 'expected_weeks_max' => 6 ] ],
            [ 'name' => 'serious',       'nl' => 'Ernstig (6+ weken)',          'meta' => [ 'expected_weeks_max' => 16 ] ],
            [ 'name' => 'season_ending', 'nl' => 'Seizoensbeperkend',           'meta' => [ 'expected_weeks_max' => 36 ] ],
        ];

        $this->seedLookupSet( $table, 'injury_type',     $injury_types );
        $this->seedLookupSet( $table, 'body_part',       $body_parts );
        $this->seedLookupSet( $table, 'injury_severity', $severities );
    }

    /**
     * @param array<int, array{name:string, nl:string, meta?: array<string,mixed>}> $rows
     */
    private function seedLookupSet( string $table, string $type, array $rows ): void {
        global $wpdb;
        $sort_order = 10;
        foreach ( $rows as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE lookup_type = %s AND name = %s",
                $type,
                $row['name']
            ) );
            if ( $existing > 0 ) { $sort_order += 10; continue; }

            $meta = isset( $row['meta'] ) ? array_merge( [ 'is_locked' => 0 ], $row['meta'] ) : [ 'is_locked' => 0 ];
            $wpdb->insert( $table, [
                'lookup_type'  => $type,
                'name'         => $row['name'],
                'description'  => '',
                'meta'         => (string) wp_json_encode( $meta ),
                'translations' => (string) wp_json_encode( [
                    'nl_NL' => [ 'name' => $row['nl'], 'description' => '' ],
                ] ),
                'sort_order'   => $sort_order,
            ] );
            $sort_order += 10;
        }
    }

    /**
     * One-shot backfill of journey events from existing tables.
     *
     * Walks evaluations / pdp verdicts / goals / players.date_joined /
     * trial cases. Each insert is gated by uk_natural so the migration
     * is idempotent — re-running does not multiply events.
     *
     * Skipped (per spec § Backfill): team / position changes (current-state
     * only, no history), injuries (no source data), pre-migration
     * `signed` events.
     */
    private function backfillEvents(): void {
        global $wpdb;
        $p     = $wpdb->prefix;
        $table = $p . 'tt_player_events';

        // Evaluations -> evaluation_completed.
        $rows = $wpdb->get_results(
            "SELECT id, player_id, eval_date, overall_rating
               FROM {$p}tt_evaluations
              WHERE archived_at IS NULL
              ORDER BY id ASC"
        );
        foreach ( (array) $rows as $r ) {
            $payload = [
                'evaluation_id' => (int) $r->id,
                'overall'       => isset( $r->overall_rating ) ? (float) $r->overall_rating : null,
            ];
            $this->insertBackfillEvent( $table, [
                'player_id'          => (int) $r->player_id,
                'event_type'         => 'evaluation_completed',
                'event_date'         => $this->dateOnly( $r->eval_date ) . ' 00:00:00',
                'summary'            => sprintf( 'Evaluation on %s', $this->dateOnly( $r->eval_date ) ),
                'payload'            => $payload,
                'visibility'         => 'public',
                'source_module'      => 'Evaluations',
                'source_entity_type' => 'evaluation',
                'source_entity_id'   => (int) $r->id,
            ] );
        }

        // PDP verdicts -> pdp_verdict_recorded (signed-off only).
        $rows = $wpdb->get_results(
            "SELECT v.id AS verdict_id, v.pdp_file_id, v.decision, v.signed_off_at, f.player_id
               FROM {$p}tt_pdp_verdicts v
               JOIN {$p}tt_pdp_files f ON f.id = v.pdp_file_id
              WHERE v.signed_off_at IS NOT NULL
              ORDER BY v.id ASC"
        );
        foreach ( (array) $rows as $r ) {
            $this->insertBackfillEvent( $table, [
                'player_id'          => (int) $r->player_id,
                'event_type'         => 'pdp_verdict_recorded',
                'event_date'         => (string) $r->signed_off_at,
                'summary'            => sprintf( 'PDP verdict: %s', (string) $r->decision ),
                'payload'            => [
                    'pdp_file_id' => (int) $r->pdp_file_id,
                    'decision'    => (string) $r->decision,
                ],
                'visibility'         => 'public',
                'source_module'      => 'Pdp',
                'source_entity_type' => 'pdp_verdict',
                'source_entity_id'   => (int) $r->verdict_id,
            ] );
        }

        // Goals -> goal_set (creation only, per Q2 lock).
        $rows = $wpdb->get_results(
            "SELECT id, player_id, title, created_at
               FROM {$p}tt_goals
              WHERE archived_at IS NULL
              ORDER BY id ASC"
        );
        foreach ( (array) $rows as $r ) {
            $title = (string) $r->title;
            $this->insertBackfillEvent( $table, [
                'player_id'          => (int) $r->player_id,
                'event_type'         => 'goal_set',
                'event_date'         => (string) $r->created_at,
                'summary'            => $title !== '' ? sprintf( 'Goal set: %s', $title ) : 'Goal set',
                'payload'            => [ 'goal_id' => (int) $r->id ],
                'visibility'         => 'public',
                'source_module'      => 'Goals',
                'source_entity_type' => 'goal',
                'source_entity_id'   => (int) $r->id,
            ] );
        }

        // Players.date_joined -> joined_academy.
        $rows = $wpdb->get_results(
            "SELECT id, date_joined, first_name, last_name
               FROM {$p}tt_players
              WHERE date_joined IS NOT NULL AND date_joined != '0000-00-00'
              ORDER BY id ASC"
        );
        foreach ( (array) $rows as $r ) {
            $this->insertBackfillEvent( $table, [
                'player_id'          => (int) $r->id,
                'event_type'         => 'joined_academy',
                'event_date'         => $this->dateOnly( $r->date_joined ) . ' 00:00:00',
                'summary'            => 'Joined the academy',
                'payload'            => [],
                'visibility'         => 'public',
                'source_module'      => 'Players',
                'source_entity_type' => 'player',
                'source_entity_id'   => (int) $r->id,
            ] );
        }

        // Trial cases -> trial_started + trial_ended (where decided).
        $trials_table = $p . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $trials_table ) ) === $trials_table ) {
            $rows = $wpdb->get_results(
                "SELECT id, player_id, start_date, end_date, decision, decision_made_at, status
                   FROM {$trials_table}
                  WHERE archived_at IS NULL AND status != 'draft'
                  ORDER BY id ASC"
            );
            foreach ( (array) $rows as $r ) {
                $this->insertBackfillEvent( $table, [
                    'player_id'          => (int) $r->player_id,
                    'event_type'         => 'trial_started',
                    'event_date'         => $this->dateOnly( $r->start_date ) . ' 00:00:00',
                    'summary'            => 'Trial started',
                    'payload'            => [ 'trial_case_id' => (int) $r->id ],
                    'visibility'         => 'public',
                    'source_module'      => 'Trials',
                    'source_entity_type' => 'trial_case',
                    'source_entity_id'   => (int) $r->id,
                ] );
                if ( ! empty( $r->decision ) && ! empty( $r->decision_made_at ) ) {
                    $this->insertBackfillEvent( $table, [
                        'player_id'          => (int) $r->player_id,
                        'event_type'         => 'trial_ended',
                        'event_date'         => (string) $r->decision_made_at,
                        'summary'            => sprintf( 'Trial ended: %s', (string) $r->decision ),
                        'payload'            => [
                            'trial_case_id' => (int) $r->id,
                            'decision'      => (string) $r->decision,
                            'context'       => 'post_trial',
                        ],
                        'visibility'         => 'public',
                        'source_module'      => 'Trials',
                        'source_entity_type' => 'trial_case',
                        'source_entity_id'   => (int) $r->id,
                    ] );
                }
            }
        }
    }

    /**
     * @param array{
     *   player_id:int,
     *   event_type:string,
     *   event_date:string,
     *   summary:string,
     *   payload:array<string,mixed>,
     *   visibility:string,
     *   source_module:string,
     *   source_entity_type:string,
     *   source_entity_id:int
     * } $row
     */
    private function insertBackfillEvent( string $table, array $row ): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE source_module = %s
                AND source_entity_type = %s
                AND source_entity_id = %d
                AND event_type = %s",
            $row['source_module'],
            $row['source_entity_type'],
            $row['source_entity_id'],
            $row['event_type']
        ) );
        if ( $exists > 0 ) return;

        $wpdb->insert( $table, [
            'club_id'            => 1,
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $row['player_id'],
            'event_type'         => $row['event_type'],
            'event_date'         => $row['event_date'],
            'summary'            => mb_substr( $row['summary'], 0, 500 ),
            'payload'            => (string) wp_json_encode( $row['payload'] ),
            'payload_valid'      => 1,
            'visibility'         => $row['visibility'],
            'source_module'      => $row['source_module'],
            'source_entity_type' => $row['source_entity_type'],
            'source_entity_id'   => $row['source_entity_id'],
            'created_by'         => 0,
        ] );
    }

    private function dateOnly( $value ): string {
        $s = (string) $value;
        if ( strlen( $s ) >= 10 ) return substr( $s, 0, 10 );
        return gmdate( 'Y-m-d' );
    }
};
