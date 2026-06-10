<?php
namespace TT\Modules\Pdp;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\PdpVerdictsRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpHardDeleteAdminPost (#1294) — wp-admin double-confirm POST
 * handler for the PDP permanent-delete surface.
 *
 * The destructive REST primitive (POST /pdp-files/{id}/permanent-delete)
 * shipped in v4.20.65 (#1274 PR3). This handler wraps the primitive
 * with the safety chrome the pilot asked for:
 *
 *   1. Re-checks the `tt_delete_pdp` cap inside admin-post.
 *   2. Verifies the nonce captured by the typed-name confirm form.
 *   3. Validates the operator's typed `confirm_name` matches the
 *      player's display name (case-insensitive, trim-tolerant).
 *   4. Writes a pre-delete CSV snapshot under
 *      `wp-content/uploads/tt-pdp-deletes/pdp-{file_id}-{timestamp}.csv`.
 *   5. Invokes `PdpCascadeDeleter::deletePdpFile()` with the CSV
 *      path threaded into the existing `pdp.deleted_with_cascade`
 *      audit-log event.
 *   6. Redirects back to the PDP list with a success notice (or
 *      the cascade-failed notice when the inner transaction rolls
 *      back).
 *
 * The REST primitive remains the testable contract; this surface is
 * the pilot-facing safety net. They share the same domain service.
 */
final class PdpHardDeleteAdminPost {

    public const ACTION = 'tt_pdp_permanent_delete';
    public const NONCE  = '_tt_pdp_perm_delete_nonce';

    public static function init(): void {
        add_action( 'admin_post_' . self::ACTION, [ self::class, 'handle' ] );
    }

    public static function handle(): void {
        if ( ! current_user_can( 'tt_delete_pdp' ) ) {
            wp_die( esc_html__( 'You do not have permission to permanently delete PDP files.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $file_id = isset( $_POST['pdp_file_id'] ) ? absint( wp_unslash( (string) $_POST['pdp_file_id'] ) ) : 0;
        if ( $file_id <= 0 ) {
            wp_die( esc_html__( 'Invalid PDP file id.', 'talenttrack' ), '', [ 'response' => 400 ] );
        }
        check_admin_referer( self::ACTION . '_' . $file_id, self::NONCE );

        $files = new PdpFilesRepository();
        // include_archived=true — the common path is "archive first,
        // then permanently delete later"; refusing to delete archived
        // rows would defeat the workflow.
        $file = $files->find( $file_id, true );
        if ( ! $file ) {
            self::redirectWithNotice( 'not_found' );
            return;
        }

        $player = \TT\Infrastructure\Query\QueryHelpers::get_player( (int) $file->player_id );
        $expected_name = $player ? \TT\Infrastructure\Query\QueryHelpers::player_display_name( $player ) : '';
        $typed_raw     = isset( $_POST['confirm_name'] ) ? wp_unslash( (string) $_POST['confirm_name'] ) : '';
        $typed_clean   = self::normaliseName( $typed_raw );
        $expected_clean = self::normaliseName( $expected_name );

        if ( $expected_clean === '' || $typed_clean !== $expected_clean ) {
            self::redirectWithNotice( 'name_mismatch', $file_id );
            return;
        }

        // 1. Pre-delete CSV snapshot. Writing the file BEFORE the
        // cascade is intentional: even if the cascade transaction
        // rolls back the snapshot still exists on disk + the audit
        // log records the failure with the same csv_path so the
        // operator can re-run safely.
        try {
            $csv_path = self::writePreDeleteCsv( $file );
        } catch ( \Throwable $e ) {
            Logger::error( 'pdp.predelete_csv.failed', [
                'pdp_file_id' => $file_id,
                'error'       => $e->getMessage(),
            ] );
            self::redirectWithNotice( 'csv_failed', $file_id );
            return;
        }

        // 2. Cascade.
        try {
            ( new PdpCascadeDeleter() )->deletePdpFile( $file_id, [
                'csv_path'    => $csv_path,
                'confirm_via' => 'wp_admin_typed_name',
            ] );
        } catch ( \Throwable $e ) {
            self::redirectWithNotice( 'cascade_failed', $file_id, [ 'csv' => $csv_path ] );
            return;
        }

        self::redirectWithNotice( 'deleted', 0, [ 'csv' => $csv_path ] );
    }

    /**
     * Build the pre-delete CSV snapshot for one PDP file. Returns the
     * absolute path on success; throws on filesystem failure.
     *
     * Columns are blocked by section ("kind") so the operator can
     * eyeball the file before paging through DataBackups: the PDP
     * file row, every conversation, the optional verdict, every
     * calendar-link row, every PDP-block row, and every pdp_conversation
     * goal-link row.
     */
    private static function writePreDeleteCsv( object $file ): string {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            throw new \RuntimeException( 'wp_upload_dir error: ' . (string) $upload['error'] );
        }
        $dir = trailingslashit( (string) $upload['basedir'] ) . 'tt-pdp-deletes';
        if ( ! wp_mkdir_p( $dir ) ) {
            throw new \RuntimeException( 'Could not create directory: ' . $dir );
        }
        // Drop an index.html guard against directory listing — same
        // pattern wp-content/uploads/ subdirs use.
        $guard = $dir . '/index.html';
        if ( ! file_exists( $guard ) ) {
            @file_put_contents( $guard, '<!-- TalentTrack PDP pre-delete CSVs. Silence is golden. -->' );
        }

        $file_id   = (int) $file->id;
        $timestamp = gmdate( 'Ymd-His' );
        $abs_path  = $dir . '/pdp-' . $file_id . '-' . $timestamp . '.csv';

        $fh = fopen( $abs_path, 'w' );
        if ( $fh === false ) {
            throw new \RuntimeException( 'Could not open CSV for writing: ' . $abs_path );
        }

        // Header banner — gives the operator the "what is this file?"
        // context up top. Quoted so commas in season names don't
        // confuse the parser.
        $player = \TT\Infrastructure\Query\QueryHelpers::get_player( (int) $file->player_id );
        $player_name = $player ? \TT\Infrastructure\Query\QueryHelpers::player_display_name( $player ) : '';
        $season = ( new SeasonsRepository() )->find( (int) $file->season_id );
        $season_name = $season ? (string) $season->name : '';

        fputcsv( $fh, [ '# TalentTrack PDP pre-delete snapshot' ] );
        fputcsv( $fh, [ '# pdp_file_id', $file_id ] );
        fputcsv( $fh, [ '# player_id', (int) $file->player_id, 'player_name', $player_name ] );
        fputcsv( $fh, [ '# season_id', (int) $file->season_id, 'season_name', $season_name ] );
        fputcsv( $fh, [ '# exported_at_utc', gmdate( 'c' ) ] );
        fputcsv( $fh, [ '# exported_by_user_id', get_current_user_id() ] );
        fputcsv( $fh, [] );

        // 1. PDP file row itself.
        fputcsv( $fh, [ 'kind', 'id', 'player_id', 'season_id', 'owner_coach_id', 'status', 'cycle_size', 'archived_at', 'created_at', 'updated_at', 'notes' ] );
        fputcsv( $fh, [
            'pdp_file',
            (int) $file->id,
            (int) $file->player_id,
            (int) $file->season_id,
            $file->owner_coach_id !== null ? (int) $file->owner_coach_id : '',
            (string) $file->status,
            $file->cycle_size !== null ? (int) $file->cycle_size : '',
            (string) ( $file->archived_at ?? '' ),
            (string) ( $file->created_at ?? '' ),
            (string) ( $file->updated_at ?? '' ),
            (string) ( $file->notes ?? '' ),
        ] );
        fputcsv( $fh, [] );

        // 2. Conversations.
        fputcsv( $fh, [ 'kind', 'id', 'pdp_file_id', 'sequence', 'template_key', 'scheduled_at', 'conducted_at', 'coach_signoff_at', 'parent_ack_at', 'player_ack_at', 'notes', 'agreed_actions', 'player_reflection' ] );
        $convs = ( new PdpConversationsRepository() )->listForFile( $file_id );
        $conv_ids = [];
        foreach ( $convs as $c ) {
            $conv_ids[] = (int) $c->id;
            fputcsv( $fh, [
                'pdp_conversation',
                (int) $c->id,
                (int) $c->pdp_file_id,
                (int) ( $c->sequence ?? 0 ),
                (string) ( $c->template_key ?? '' ),
                (string) ( $c->scheduled_at ?? '' ),
                (string) ( $c->conducted_at ?? '' ),
                (string) ( $c->coach_signoff_at ?? '' ),
                (string) ( $c->parent_ack_at ?? '' ),
                (string) ( $c->player_ack_at ?? '' ),
                (string) ( $c->notes ?? '' ),
                (string) ( $c->agreed_actions ?? '' ),
                (string) ( $c->player_reflection ?? '' ),
            ] );
        }
        fputcsv( $fh, [] );

        // 3. Verdict (zero or one).
        fputcsv( $fh, [ 'kind', 'id', 'pdp_file_id', 'decision', 'summary', 'signed_off_at' ] );
        $verdict = ( new PdpVerdictsRepository() )->findForFile( $file_id );
        if ( $verdict !== null ) {
            fputcsv( $fh, [
                'pdp_verdict',
                (int) ( $verdict->id ?? 0 ),
                (int) ( $verdict->pdp_file_id ?? 0 ),
                (string) ( $verdict->decision ?? '' ),
                (string) ( $verdict->summary ?? '' ),
                (string) ( $verdict->signed_off_at ?? '' ),
            ] );
        }
        fputcsv( $fh, [] );

        // 4. Calendar links (joined to conversations).
        global $wpdb; $p = $wpdb->prefix;
        fputcsv( $fh, [ 'kind', 'id', 'conversation_id', 'provider', 'provider_event_id', 'created_at' ] );
        if ( ! empty( $conv_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$p}tt_pdp_calendar_links WHERE conversation_id IN ({$ph})",
                ...$conv_ids
            ) );
            foreach ( (array) $links as $l ) {
                fputcsv( $fh, [
                    'pdp_calendar_link',
                    (int) ( $l->id ?? 0 ),
                    (int) ( $l->conversation_id ?? 0 ),
                    (string) ( $l->provider ?? '' ),
                    (string) ( $l->provider_event_id ?? '' ),
                    (string) ( $l->created_at ?? '' ),
                ] );
            }
        }
        fputcsv( $fh, [] );

        // 5. Blocks — season-scoped, NOT file-scoped (migration 0107
        // keys on club_id + season_id, not pdp_file_id). The cascade
        // intentionally does not delete blocks because they outlive
        // any single PDP file. Snapshotting the season's blocks here
        // anyway, so the audit trail captures the configuration that
        // shaped the cycle this file ran.
        fputcsv( $fh, [ 'kind', 'id', 'season_id', 'sequence', 'start_date', 'end_date' ] );
        $blocks_table = $p . 'tt_pdp_blocks';
        $blocks_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $blocks_table ) ) === $blocks_table;
        if ( $blocks_exists ) {
            $blocks = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$blocks_table} WHERE season_id = %d AND club_id = %d ORDER BY sequence ASC",
                (int) $file->season_id, (int) CurrentClub::id()
            ) );
            foreach ( (array) $blocks as $b ) {
                fputcsv( $fh, [
                    'pdp_block_season_config',
                    (int) ( $b->id ?? 0 ),
                    (int) ( $b->season_id ?? 0 ),
                    (int) ( $b->sequence ?? 0 ),
                    (string) ( $b->start_date ?? '' ),
                    (string) ( $b->end_date ?? '' ),
                ] );
            }
        }
        fputcsv( $fh, [] );

        // 6. Goal links pointing at the conversations the cascade
        // will remove (link_type = 'pdp_conversation' only —
        // principle / position / etc. survive).
        fputcsv( $fh, [ 'kind', 'goal_id', 'link_type', 'link_id' ] );
        if ( ! empty( $conv_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
            $links = $wpdb->get_results( $wpdb->prepare(
                "SELECT goal_id, link_type, link_id FROM {$p}tt_goal_links
                 WHERE link_type = %s AND link_id IN ({$ph})",
                'pdp_conversation', ...$conv_ids
            ) );
            foreach ( (array) $links as $l ) {
                fputcsv( $fh, [
                    'goal_link',
                    (int) ( $l->goal_id ?? 0 ),
                    (string) ( $l->link_type ?? '' ),
                    (int) ( $l->link_id ?? 0 ),
                ] );
            }
        }

        fclose( $fh );

        return $abs_path;
    }

    /**
     * Cascade summary used by the confirm surface AND surfaced in the
     * audit-log payload. Read-only counts; no writes.
     *
     * @return array{
     *   conversations:int,
     *   verdict:int,
     *   calendar_links:int,
     *   blocks:int,
     *   goal_links:int,
     *   total:int,
     * }
     */
    public static function cascadeSummary( int $pdp_file_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $out  = [
            'conversations'  => 0,
            'verdict'        => 0,
            'calendar_links' => 0,
            'blocks'         => 0,
            'goal_links'     => 0,
            'total'          => 0,
        ];

        $out['conversations'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_pdp_conversations WHERE pdp_file_id = %d AND club_id = %d",
            $pdp_file_id, $club
        ) );
        $out['verdict'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_pdp_verdicts WHERE pdp_file_id = %d AND club_id = %d",
            $pdp_file_id, $club
        ) );

        $conv_ids = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_pdp_conversations WHERE pdp_file_id = %d AND club_id = %d",
            $pdp_file_id, $club
        ) );
        $conv_ids = array_map( 'intval', $conv_ids );
        if ( ! empty( $conv_ids ) ) {
            $ph = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
            $out['calendar_links'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_pdp_calendar_links WHERE conversation_id IN ({$ph})",
                ...$conv_ids
            ) );
            $out['goal_links'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_goal_links WHERE link_type = %s AND link_id IN ({$ph})",
                'pdp_conversation', ...$conv_ids
            ) );
        }

        // Blocks are season-scoped (see PdpCascadeDeleter step 4),
        // not file-scoped. The cascade does not touch them; report 0
        // so the summary mirrors reality.
        $out['blocks'] = 0;

        $out['total'] = $out['conversations'] + $out['verdict'] + $out['calendar_links'] + $out['blocks'] + $out['goal_links'];
        return $out;
    }

    /**
     * Lowercase + collapse whitespace so typing variations
     * ("john  doe", "John Doe ") still match the canonical name.
     */
    private static function normaliseName( string $raw ): string {
        $clean = trim( wp_strip_all_tags( $raw ) );
        if ( $clean === '' ) return '';
        $clean = preg_replace( '/\s+/u', ' ', $clean );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $clean, 'UTF-8' ) : strtolower( (string) $clean );
    }

    /**
     * Redirect back to the calling surface with a flash notice.
     * `deleted` and `cascade_failed` go to the PDP list; `name_mismatch`
     * and `csv_failed` reopen the confirm surface so the operator can
     * retry.
     *
     * @param array<string,string> $extra extra query args (e.g. csv path)
     */
    private static function redirectWithNotice( string $code, int $file_id = 0, array $extra = [] ): void {
        $dash = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
        if ( $code === 'name_mismatch' || $code === 'csv_failed' ) {
            $url = add_query_arg(
                array_merge( [
                    'tt_view'  => 'pdp',
                    'action'   => 'permanent-delete',
                    'id'       => $file_id,
                    'tt_notice' => $code,
                ], $extra ),
                $dash
            );
        } elseif ( $code === 'cascade_failed' ) {
            $url = add_query_arg(
                array_merge( [
                    'tt_view'   => 'pdp',
                    'action'    => 'permanent-delete',
                    'id'        => $file_id,
                    'tt_notice' => $code,
                ], $extra ),
                $dash
            );
        } else {
            $url = add_query_arg(
                array_merge( [
                    'tt_view'   => 'pdp',
                    'tt_notice' => $code,
                ], $extra ),
                $dash
            );
        }
        wp_safe_redirect( $url );
        exit;
    }
}
