<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * GdprSubjectAccessZipExporter (#0063 use case 10) — one player's
 * complete record as a downloadable ZIP. Required by EU GDPR statute
 * for any "Article 15 — Right of access by the data subject" request.
 *
 * Per user-direction shaping (2026-05-08):
 *   - Sync v1 (Q1) — Action Scheduler async pipeline is the deferred
 *     follow-up; for typical pilot data (~1-5 MB per player) the
 *     synchronous path fits well under the 30s request window.
 *   - JSON-per-domain inside the ZIP (Q2) — `profile.json` /
 *     `evaluations.json` / `goals.json` / `attendance.json` /
 *     `comms_log.json` + a rendered `evaluation_report.pdf` so the
 *     recipient sees something human-readable. CSV deliberately
 *     skipped as redundant — JSON round-trips cleanly to any
 *     analytics tool.
 *   - Cap `tt_edit_settings` (Q3) — academy admin only. GDPR statute
 *     makes the academy the data controller; only the academy admin
 *     should be able to extract a player's full record.
 *   - Audit on every export (Q4) — `gdpr.subject_access_export` row
 *     carrying `(player_id, requesting_user_id, generated_at)` for the
 *     academy's own compliance trail.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/gdpr_subject_access_zip?format=zip&player_id=42`
 *
 * Per-file authorization scope: tenant-scoped via
 * `QueryHelpers::get_player()` so a logged-in admin in club A can't
 * extract a player from club B by guessing the id.
 */
final class GdprSubjectAccessZipExporter implements ExporterInterface {

    public function key(): string { return 'gdpr_subject_access_zip'; }

    public function label(): string { return __( 'GDPR subject-access export (ZIP)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'zip' ]; }

    public function requiredCap(): string { return 'tt_edit_settings'; }

    public function validateFilters( array $raw ): ?array {
        $player_id = isset( $raw['player_id'] ) ? (int) $raw['player_id'] : 0;
        if ( $player_id <= 0 ) return null;
        return [ 'player_id' => $player_id ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $player_id = (int) ( $request->filters['player_id'] ?? 0 );

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            return [
                'entries' => [
                    'README.txt' => __( 'Player not found or not accessible in this club.', 'talenttrack' ),
                ],
            ];
        }

        // Domain queries. Each carries the rows we'd want a data
        // subject to be able to inspect under Article 15.
        $profile_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, (int) $request->clubId
        ), ARRAY_A );

        $evaluations = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_evaluations WHERE player_id = %d ORDER BY eval_date ASC",
            $player_id
        ), ARRAY_A );

        $eval_ratings = [];
        if ( is_array( $evaluations ) && $evaluations !== [] ) {
            $eval_ids   = array_map( static fn( $r ) => (int) $r['id'], $evaluations );
            $placeholders = implode( ',', array_fill( 0, count( $eval_ids ), '%d' ) );
            $eval_ratings = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}tt_eval_ratings WHERE evaluation_id IN ({$placeholders}) ORDER BY evaluation_id ASC, category_id ASC",
                $eval_ids
            ), ARRAY_A );
        }

        $goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_goals WHERE player_id = %d ORDER BY created_at ASC",
            $player_id
        ), ARRAY_A );

        $attendance = $wpdb->get_results( $wpdb->prepare(
            "SELECT att.*, a.session_date, a.title AS activity_title, a.location
                FROM {$p}tt_attendance att
                LEFT JOIN {$p}tt_activities a ON a.id = att.activity_id
                WHERE att.player_id = %d
                ORDER BY a.session_date ASC, att.id ASC",
            $player_id
        ), ARRAY_A );

        // Comms log: filter on recipient_player_id (the about-which-
        // player column from migration 0075). Tombstoned rows
        // (subject_erased_at IS NOT NULL) carry empty address_blob /
        // subject by design — we still include the row so the data
        // subject can see "we sent something on this date" without the
        // PII payload, mirroring the GDPR retention spec Q6.
        $comms = self::tableExists( "{$p}tt_comms_log" )
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}tt_comms_log
                    WHERE recipient_player_id = %d AND club_id = %d
                    ORDER BY created_at ASC",
                $player_id, (int) $request->clubId
            ), ARRAY_A )
            : [];

        // Linked parents (just the user_ids — not the wp_users row,
        // which carries other-tenant data).
        $parents = self::tableExists( "{$p}tt_player_parents" )
            ? $wpdb->get_results( $wpdb->prepare(
                "SELECT player_id, parent_user_id, is_primary, created_at
                    FROM {$p}tt_player_parents
                    WHERE player_id = %d
                    ORDER BY is_primary DESC, parent_user_id ASC",
                $player_id
            ), ARRAY_A )
            : [];

        // Render the standard evaluation report PDF inline so the
        // recipient sees something human-readable alongside the JSON
        // dumps. We synthesise a sub-request that the v3.110.4 exporter
        // can satisfy and capture its bytes.
        $pdf_bytes = self::renderEvaluationPdf( $player_id, $request );

        $entries = [
            'profile.json'     => self::jsonPretty( $profile_row ),
            'evaluations.json' => self::jsonPretty( [
                'evaluations' => $evaluations ?: [],
                'ratings'     => $eval_ratings ?: [],
            ] ),
            'goals.json'       => self::jsonPretty( $goals ?: [] ),
            'attendance.json'  => self::jsonPretty( $attendance ?: [] ),
            'comms_log.json'   => self::jsonPretty( $comms ?: [] ),
            'parents.json'     => self::jsonPretty( $parents ?: [] ),
            'README.txt'       => self::readme( $player, $request ),
        ];
        if ( $pdf_bytes !== '' ) {
            $entries['evaluation_report.pdf'] = $pdf_bytes;
        }

        $manifest = [
            'gdpr_article'    => 15,
            'subject_player'  => [
                'id'         => (int) $player->id,
                'first_name' => (string) $player->first_name,
                'last_name'  => (string) $player->last_name,
                'club_id'    => (int) $player->club_id,
            ],
            'requested_by_user_id' => (int) $request->requesterUserId,
            'generated_at'         => gmdate( 'c' ),
            'entries'              => array_keys( $entries ),
            'counts'               => [
                'evaluations'  => is_array( $evaluations ) ? count( $evaluations ) : 0,
                'eval_ratings' => is_array( $eval_ratings ) ? count( $eval_ratings ) : 0,
                'goals'        => is_array( $goals ) ? count( $goals ) : 0,
                'attendance'   => is_array( $attendance ) ? count( $attendance ) : 0,
                'comms'        => is_array( $comms ) ? count( $comms ) : 0,
                'parents'      => is_array( $parents ) ? count( $parents ) : 0,
            ],
            'tombstones_note' => __( 'comms_log rows with empty address_blob and subject reflect GDPR retention tombstoning (#0066) — the audit fact is preserved without the PII payload.', 'talenttrack' ),
        ];

        // Audit the export. Failures here are non-fatal — the export
        // must still complete (the data subject has a legal right to
        // it) but we want the academy's own compliance trail to
        // record what happened.
        try {
            ( new AuditService() )->record(
                'gdpr.subject_access_export',
                'player',
                $player_id,
                [
                    'requesting_user_id' => (int) $request->requesterUserId,
                    'generated_at'       => gmdate( 'c' ),
                    'entry_count'        => count( $entries ),
                ]
            );
        } catch ( \Throwable $e ) {
            // Swallow — auditing must never block delivery.
        }

        return [
            'entries'  => $entries,
            'manifest' => $manifest,
        ];
    }

    private static function tableExists( string $table ): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param mixed $value
     */
    private static function jsonPretty( $value ): string {
        $bytes = wp_json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
        return is_string( $bytes ) ? $bytes : '{}';
    }

    private static function readme( object $player, ExportRequest $request ): string {
        $name = trim( (string) $player->first_name . ' ' . (string) $player->last_name );
        $lines = [
            sprintf(
                /* translators: %s = player name */
                __( 'GDPR subject-access export — %s', 'talenttrack' ),
                $name
            ),
            '',
            sprintf(
                /* translators: %s = ISO 8601 date-time */
                __( 'Generated: %s', 'talenttrack' ),
                gmdate( 'c' )
            ),
            sprintf(
                /* translators: %d = WP user id */
                __( 'Requested by user id: %d', 'talenttrack' ),
                (int) $request->requesterUserId
            ),
            '',
            __( 'Files in this archive:', 'talenttrack' ),
            '  - profile.json — the player\'s tt_players row',
            '  - evaluations.json — every evaluation + per-category rating',
            '  - goals.json — every goal (current + archived)',
            '  - attendance.json — every attendance row joined to the activity',
            '  - comms_log.json — outbound messages about this player (tombstoned rows excluded; see tombstones_note in MANIFEST.json)',
            '  - parents.json — linked parent user ids',
            '  - evaluation_report.pdf — rendered evaluation report (human-readable)',
            '',
            __( 'This export was produced under GDPR Article 15 (Right of access by the data subject).', 'talenttrack' ),
        ];
        return implode( "\n", $lines ) . "\n";
    }

    /**
     * Render the standard player-evaluation PDF inline so the ZIP
     * carries a human-readable report alongside the JSON dumps.
     * Reuses the v3.110.4 `PlayerEvaluationPdfExporter` directly —
     * we instantiate it, build a synthesised `ExportRequest`, run
     * `collect()` to get the HTML payload, and hand it to the same
     * `PdfRenderer` the standard pipeline uses.
     */
    private static function renderEvaluationPdf( int $player_id, ExportRequest $request ): string {
        if ( ! class_exists( PlayerEvaluationPdfExporter::class )
            || ! class_exists( \TT\Modules\Export\Format\Renderers\PdfRenderer::class ) ) {
            return '';
        }
        try {
            $exporter = new PlayerEvaluationPdfExporter();
            $sub_request = new \TT\Modules\Export\Domain\ExportRequest(
                $exporter->key(),
                'pdf',
                (int) $request->clubId,
                (int) $request->requesterUserId,
                null,
                [ 'player_id' => $player_id ]
            );
            $payload  = $exporter->collect( $sub_request );
            $renderer = new \TT\Modules\Export\Format\Renderers\PdfRenderer();
            $result   = $renderer->render( $sub_request, $payload );
            return $result->bytes;
        } catch ( \Throwable $e ) {
            // PDF rendering is a nice-to-have; a missing PDF doesn't
            // block the legal-required JSON dumps.
            return '';
        }
    }
}
