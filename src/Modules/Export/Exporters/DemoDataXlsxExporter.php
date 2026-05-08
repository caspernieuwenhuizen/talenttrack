<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\Excel\SheetSchemas;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * DemoDataXlsxExporter (#0063 use case 15) — round-tripped demo-data Excel.
 *
 * Per the spec: "Round-tripped demo data so #0020 / #0059 can re-import
 * it. Exists informally; Export formalizes it." Walks every sheet in
 * `SheetSchemas::all()` and dumps the matching live rows into a
 * multi-sheet XLSX whose layout is identical to the import template
 * `TemplateBuilder::streamDownload()` produces — so an operator can
 * download a snapshot from one club, hand it to a fresh install, and
 * re-import without column-mapping work.
 *
 * Per user-direction shaping (2026-05-08):
 *   - **Q1 — auto_key**: numeric `id`. Deterministic, collision-free,
 *     idempotent. "John_Doe" string slugs would collide on real data;
 *     numeric ids round-trip cleanly through the import → export →
 *     re-import path.
 *   - **Q2 — filter scope**: every live row in the current club. No
 *     demo-mode filter (most tables don't carry a per-row demo flag).
 *     Operators who want a subset filter post-export.
 *   - **Q3 — cap**: `tt_edit_settings`. Same gate as the seed-review
 *     surface (v3.109.2) — the export carries every player's PII.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/demo_data_xlsx?format=xlsx`
 *
 * Round-trip note: the column layout matches `SheetSchemas::all()`
 * exactly. FK columns (e.g. `team_key` on the Players sheet) carry
 * the numeric `id` of the FK target, which the importer resolves via
 * the same numeric-id lookup. Sheets the importer doesn't consume
 * (config-tier `eval_categories` / `category_weights` / `_Lookups`)
 * are still emitted so a clean re-import recreates the same
 * configuration alongside the data.
 */
final class DemoDataXlsxExporter implements ExporterInterface {

    public function key(): string { return 'demo_data_xlsx'; }

    public function label(): string { return __( 'Demo-data round-trip (XLSX)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_edit_settings'; }

    public function validateFilters( array $raw ): ?array {
        // No filters at v1 — the export is "everything in the current club".
        return [];
    }

    public function collect( ExportRequest $request ): array {
        $club_id = (int) $request->clubId;
        $sheets  = [];

        foreach ( SheetSchemas::all() as $key => $schema ) {
            $sheet_name = (string) $schema['sheet'];
            $columns    = $schema['columns'] ?? [];
            $headers    = [];
            foreach ( $columns as $meta ) {
                $headers[] = (string) ( $meta['label'] ?? '' );
            }

            $rows = self::collectRowsFor( $key, $columns, $club_id );
            $sheets[ $sheet_name ] = [ $headers, $rows ];
        }

        return [ 'sheets' => $sheets ];
    }

    /**
     * @param array<string,array<string,mixed>> $columns
     * @return array<int,array<int,mixed>>
     */
    private static function collectRowsFor( string $key, array $columns, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $col_keys = array_keys( $columns );

        switch ( $key ) {
            case 'teams':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name, age_group, level, head_coach_id, notes
                        FROM {$p}tt_teams WHERE club_id = %d
                        ORDER BY id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'        => 'id',
                    'name'            => 'name',
                    'age_group'       => 'age_group',
                    'level'           => 'level',
                    'head_coach_key'  => 'head_coach_id',
                    'notes'           => 'notes',
                ] );

            case 'people':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.id, p.first_name, p.last_name, p.role_type, p.email, p.phone, p.status,
                            (SELECT team_id FROM {$p}tt_team_staff WHERE person_id = p.id LIMIT 1) AS team_id
                        FROM {$p}tt_people p
                        WHERE p.club_id = %d
                        ORDER BY p.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'   => 'id',
                    'first_name' => 'first_name',
                    'last_name'  => 'last_name',
                    'role'       => 'role_type',
                    'team_key'   => 'team_id',
                    'email'      => 'email',
                    'phone'      => 'phone',
                    'status'     => 'status',
                ] );

            case 'players':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, first_name, last_name, date_of_birth, nationality, team_id,
                            jersey_number, preferred_foot, preferred_positions,
                            height_cm, weight_kg, photo_url,
                            guardian_name, guardian_email, guardian_phone,
                            date_joined, status
                        FROM {$p}tt_players WHERE club_id = %d
                        ORDER BY id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'             => 'id',
                    'first_name'           => 'first_name',
                    'last_name'            => 'last_name',
                    'date_of_birth'        => 'date_of_birth',
                    'nationality'          => 'nationality',
                    'team_key'             => 'team_id',
                    'jersey_number'        => 'jersey_number',
                    'preferred_foot'       => 'preferred_foot',
                    'preferred_positions'  => 'preferred_positions',
                    'height_cm'            => 'height_cm',
                    'weight_kg'            => 'weight_kg',
                    'photo_url'            => 'photo_url',
                    'guardian_name'        => 'guardian_name',
                    'guardian_email'       => 'guardian_email',
                    'guardian_phone'       => 'guardian_phone',
                    'date_joined'          => 'date_joined',
                    'status'               => 'status',
                ] );

            case 'trial_cases':
                if ( ! self::tableExists( "{$p}tt_trial_cases" ) ) return [];
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, player_id, team_id, start_date, end_date, decision, notes
                        FROM {$p}tt_trial_cases WHERE club_id = %d
                        ORDER BY id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'   => 'id',
                    'player_key' => 'player_id',
                    'team_key'   => 'team_id',
                    'start_date' => 'start_date',
                    'end_date'   => 'end_date',
                    'decision'   => 'decision',
                    'notes'      => 'notes',
                ] );

            case 'sessions':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, team_id, session_date, title, location, activity_type_key, notes
                        FROM {$p}tt_activities WHERE club_id = %d
                        ORDER BY id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'      => 'id',
                    'team_key'      => 'team_id',
                    'session_date'  => 'session_date',
                    'title'         => 'title',
                    'location'      => 'location',
                    'activity_type' => 'activity_type_key',
                    'notes'         => 'notes',
                ] );

            case 'session_attendance':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT att.activity_id, att.player_id, att.status, att.notes
                        FROM {$p}tt_attendance att
                        JOIN {$p}tt_players pl ON pl.id = att.player_id
                        WHERE pl.club_id = %d
                        ORDER BY att.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'session_key' => 'activity_id',
                    'player_key'  => 'player_id',
                    'status'      => 'status',
                    'notes'       => 'notes',
                ] );

            case 'evaluations':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT e.id, e.player_id, e.eval_date, lt.name AS eval_type_name, e.notes
                        FROM {$p}tt_evaluations e
                        JOIN {$p}tt_players pl ON pl.id = e.player_id
                        LEFT JOIN {$p}tt_lookups lt
                            ON lt.id = e.eval_type_id AND lt.lookup_type = 'eval_type'
                        WHERE pl.club_id = %d AND e.archived_at IS NULL
                        ORDER BY e.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'   => 'id',
                    'player_key' => 'player_id',
                    'eval_date'  => 'eval_date',
                    'eval_type'  => 'eval_type_name',
                    'notes'      => 'notes',
                ] );

            case 'evaluation_ratings':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT er.evaluation_id, ec.label AS category_label, er.rating
                        FROM {$p}tt_eval_ratings er
                        JOIN {$p}tt_evaluations e ON e.id = er.evaluation_id
                        JOIN {$p}tt_players pl ON pl.id = e.player_id
                        LEFT JOIN {$p}tt_eval_categories ec ON ec.id = er.category_id
                        WHERE pl.club_id = %d
                        ORDER BY er.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'evaluation_key' => 'evaluation_id',
                    'category'       => 'category_label',
                    'rating'         => 'rating',
                    'comment'        => null, // rating-level comments don't exist on the schema today
                ] );

            case 'goals':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT g.id, g.player_id, g.title, g.description, g.status, g.created_at
                        FROM {$p}tt_goals g
                        JOIN {$p}tt_players pl ON pl.id = g.player_id
                        WHERE pl.club_id = %d AND g.archived_at IS NULL
                        ORDER BY g.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'auto_key'    => 'id',
                    'player_key'  => 'player_id',
                    'title'       => 'title',
                    'description' => 'description',
                    'status'      => 'status',
                    'created_at'  => 'created_at',
                ] );

            case 'player_journey':
                if ( ! self::tableExists( "{$p}tt_player_events" ) ) return [];
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT pe.player_id, pe.event_type, pe.event_date, pe.summary, pe.visibility
                        FROM {$p}tt_player_events pe
                        JOIN {$p}tt_players pl ON pl.id = pe.player_id
                        WHERE pl.club_id = %d
                        ORDER BY pe.id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'player_key' => 'player_id',
                    'event_type' => 'event_type',
                    'event_date' => 'event_date',
                    'summary'    => 'summary',
                    'visibility' => 'visibility',
                ] );

            case 'eval_categories':
                $rows = $wpdb->get_results(
                    "SELECT ec.label AS name,
                            parent.label AS parent_label,
                            ec.display_order
                        FROM {$p}tt_eval_categories ec
                        LEFT JOIN {$p}tt_eval_categories parent ON parent.id = ec.parent_id
                        WHERE ec.is_active = 1
                        ORDER BY ec.parent_id IS NULL DESC, ec.display_order ASC, ec.id ASC",
                    ARRAY_A
                );
                return self::mapRows( $rows, $col_keys, [
                    'name'   => 'name',
                    'parent' => 'parent_label',
                    'order'  => 'display_order',
                ] );

            case 'category_weights':
                if ( ! self::tableExists( "{$p}tt_category_weights" ) ) return [];
                $rows = $wpdb->get_results(
                    "SELECT cw.age_group, ec.label AS category_label, cw.weight
                        FROM {$p}tt_category_weights cw
                        LEFT JOIN {$p}tt_eval_categories ec ON ec.id = cw.category_id
                        ORDER BY cw.age_group ASC, ec.display_order ASC",
                    ARRAY_A
                );
                return self::mapRows( $rows, $col_keys, [
                    'age_group' => 'age_group',
                    'category'  => 'category_label',
                    'weight'    => 'weight',
                ] );

            case 'generation_settings':
                // No live source — generation_settings is a hint-only
                // sheet that the import side reads to seed the
                // synthetic generator. Round-trip leaves it empty.
                return [];

            case 'lookups':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT lookup_type, name, sort_order
                        FROM {$p}tt_lookups
                        WHERE club_id = %d AND is_active = 1
                        ORDER BY lookup_type ASC, sort_order ASC, id ASC",
                    $club_id
                ), ARRAY_A );
                return self::mapRows( $rows, $col_keys, [
                    'lookup_type' => 'lookup_type',
                    'name'        => 'name',
                    'sort_order'  => 'sort_order',
                ] );

            default:
                return [];
        }
    }

    /**
     * Project DB rows onto schema-column ordering. `$mapping` maps
     * schema column key → DB row key (or `null` for "leave blank").
     *
     * @param array<int,array<string,mixed>>|null $rows
     * @param array<int,string>                   $col_keys
     * @param array<string,?string>               $mapping
     * @return array<int,array<int,mixed>>
     */
    private static function mapRows( $rows, array $col_keys, array $mapping ): array {
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $row ) {
            $emitted = [];
            foreach ( $col_keys as $col_key ) {
                $src = $mapping[ $col_key ] ?? null;
                if ( $src === null ) {
                    $emitted[] = '';
                    continue;
                }
                $value = $row[ $src ] ?? null;
                $emitted[] = $value === null ? '' : $value;
            }
            $out[] = $emitted;
        }
        return $out;
    }

    private static function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
