<?php
namespace TT\Modules\Pdp;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PdpCascadeDeleter (#1274 PR3) — hard delete of a PDP file with its
 * five-table cascade. Same pattern as `PersonDeletionCascade` from
 * #1138: single transaction, ordered child-first deletes, last-error
 * surfacing.
 *
 * Cascade order (children first):
 *
 *   1. `tt_pdp_calendar_links`  WHERE conversation_id IN (...)
 *   2. `tt_pdp_conversations`   WHERE pdp_file_id = N
 *   3. `tt_pdp_verdicts`         WHERE pdp_file_id = N
 *   4. `tt_pdp_blocks`           WHERE pdp_file_id = N
 *   5. `tt_pdp_files`            WHERE id = N AND club_id = X
 *
 * `tt_goal_links` rows of type `pdp_conversation` whose `link_id`
 * pointed at a now-deleted conversation are also removed in step 1.5
 * — see commentary inline.
 *
 * Caller MUST verify the cap (`tt_delete_pdp`) before invoking; this
 * service performs the destructive op without re-checking. Pattern
 * matches `PersonDeletionCascade::cascade()`.
 */
final class PdpCascadeDeleter {

    /**
     * Run the cascade for a single PDP file id. Returns per-table
     * row counts. Throws on any wpdb failure (caller catches +
     * surfaces 500 to the operator).
     *
     * @return array<string, int>
     */
    public function deletePdpFile( int $pdp_file_id ): array {
        if ( $pdp_file_id <= 0 ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $deleted = [];

        $wpdb->query( 'START TRANSACTION' );
        try {
            // Conversation ids needed for both the calendar-link delete
            // and the goal-link cleanup below.
            $convo_ids = (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$p}tt_pdp_conversations WHERE pdp_file_id = %d AND club_id = %d",
                $pdp_file_id, $club_id
            ) );
            $convo_ids = array_map( 'intval', $convo_ids );

            if ( ! empty( $convo_ids ) ) {
                $ph = implode( ',', array_fill( 0, count( $convo_ids ), '%d' ) );
                // 1. calendar links
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$p}tt_pdp_calendar_links WHERE conversation_id IN ({$ph})",
                    ...$convo_ids
                ) );
                if ( $n === false ) throw new \RuntimeException( 'Cascade delete failed on tt_pdp_calendar_links: ' . $wpdb->last_error );
                $deleted['tt_pdp_calendar_links'] = (int) $n;

                // 1.5 goal links — polymorphic; only the rows pointing
                // at our soon-to-be-deleted conversations need to go.
                // Other link_type values (principle / football_action /
                // position / value) survive.
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$p}tt_goal_links WHERE link_type = %s AND link_id IN ({$ph})",
                    'pdp_conversation', ...$convo_ids
                ) );
                if ( $n === false ) throw new \RuntimeException( 'Cascade delete failed on tt_goal_links (pdp_conversation): ' . $wpdb->last_error );
                $deleted['tt_goal_links'] = (int) $n;
            } else {
                $deleted['tt_pdp_calendar_links'] = 0;
                $deleted['tt_goal_links']         = 0;
            }

            // 2. conversations
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}tt_pdp_conversations WHERE pdp_file_id = %d AND club_id = %d",
                $pdp_file_id, $club_id
            ) );
            if ( $n === false ) throw new \RuntimeException( 'Cascade delete failed on tt_pdp_conversations: ' . $wpdb->last_error );
            $deleted['tt_pdp_conversations'] = (int) $n;

            // 3. verdicts
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}tt_pdp_verdicts WHERE pdp_file_id = %d AND club_id = %d",
                $pdp_file_id, $club_id
            ) );
            if ( $n === false ) throw new \RuntimeException( 'Cascade delete failed on tt_pdp_verdicts: ' . $wpdb->last_error );
            $deleted['tt_pdp_verdicts'] = (int) $n;

            // 4. blocks (self-gates on table existence — migration 0107
            // is recent; some installs may lack it).
            $blocks_table = $p . 'tt_pdp_blocks';
            $blocks_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $blocks_table ) ) === $blocks_table;
            if ( $blocks_exists ) {
                $n = $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$blocks_table} WHERE pdp_file_id = %d AND club_id = %d",
                    $pdp_file_id, $club_id
                ) );
                if ( $n === false ) throw new \RuntimeException( 'Cascade delete failed on tt_pdp_blocks: ' . $wpdb->last_error );
                $deleted['tt_pdp_blocks'] = (int) $n;
            } else {
                $deleted['tt_pdp_blocks'] = 0;
            }

            // 5. final parent delete
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}tt_pdp_files WHERE id = %d AND club_id = %d",
                $pdp_file_id, $club_id
            ) );
            if ( $n === false ) throw new \RuntimeException( 'Final delete failed on tt_pdp_files: ' . $wpdb->last_error );
            $deleted['tt_pdp_files'] = (int) $n;

            $wpdb->query( 'COMMIT' );

            Logger::info( 'pdp.deleted_with_cascade', [
                'pdp_file_id' => $pdp_file_id,
                'club_id'     => $club_id,
                'by_user'     => get_current_user_id(),
                'deleted'     => $deleted,
            ] );

            return $deleted;
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            Logger::error( 'pdp.cascade.failed', [
                'pdp_file_id' => $pdp_file_id,
                'club_id'     => $club_id,
                'error'       => $e->getMessage(),
            ] );
            throw $e;
        }
    }
}
