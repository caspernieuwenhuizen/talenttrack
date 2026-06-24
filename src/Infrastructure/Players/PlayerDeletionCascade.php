<?php
namespace TT\Infrastructure\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerDeletionCascade — per-table cleanup accompanying a permanent
 * delete of `tt_players` rows (#1355, mirrors #1138's
 * PersonDeletionCascade).
 *
 * Before this class, `ArchiveRepository::deletePermanently('player')`
 * removed only the entity row, stranding evaluations, goals, journey
 * events and injuries (a minor's medical history) keyed to a dead
 * player_id — a right-to-erasure request didn't actually erase.
 *
 * Two relationship classes:
 *
 *   CASCADE DELETE — data about the player IS the player's data.
 *     Found dynamically: every `tt_*` table holding a `player_id` /
 *     `guest_player_id` column (information_schema), plus the
 *     parent-keyed children (eval ratings, goal threads/links, PDP
 *     conversations/verdicts/calendar links, trial staff/inputs/
 *     extensions) which are removed first.
 *
 *   SET NULL — the row is a team/match fact that outlives the player.
 *     tt_match_execution_goal_events.player_id (the match score stays),
 *     tt_prospects.player_id (the prospect record has its own
 *     retention clock; only the conversion link clears).
 *
 * The dynamic sweep self-heals as the schema grows: a future table
 * with a player_id column is covered without editing this class.
 * Player photos are `photo_url` strings (no attachment linkage) —
 * upload cleanup stays a manual step per the privacy operator guide.
 *
 * Single transaction; any failure rolls back the whole batch.
 */
class PlayerDeletionCascade {

    /** Tables whose player reference is cleared instead of deleted. */
    private const SET_NULL = [
        'tt_match_execution_goal_events' => true,
        'tt_prospects'                   => true,
    ];

    /**
     * @param int[] $player_ids
     * @return array{deleted:int, per_table:array<string,int>, nulled:array<string,int>}
     */
    public function cascade( array $player_ids ): array {
        $ids = $this->cleanIds( $player_ids );
        if ( empty( $ids ) ) {
            return [ 'deleted' => 0, 'per_table' => [], 'nulled' => [] ];
        }

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $per_table = [];
        $nulled    = [];

        $wpdb->query( 'START TRANSACTION' );
        try {
            // Parent-keyed children first — these carry no player_id of
            // their own, so the dynamic sweep below can't see them.
            // PDP calendar links are keyed by conversation_id (not
            // player_id or pdp_file_id), so they sit two joins from the
            // player: calendar_link -> conversation -> pdp_file. Delete
            // them BEFORE tt_pdp_conversations is removed below, otherwise
            // the join finds no parent rows and the links orphan.
            if ( $this->tableExists( 'tt_pdp_calendar_links' )
                && $this->tableExists( 'tt_pdp_conversations' )
                && $this->tableExists( 'tt_pdp_files' ) ) {
                $sql = "DELETE cl FROM {$p}tt_pdp_calendar_links cl
                         INNER JOIN {$p}tt_pdp_conversations cv ON cv.id = cl.conversation_id
                         INNER JOIN {$p}tt_pdp_files pf ON pf.id = cv.pdp_file_id
                         WHERE pf.player_id IN ({$ph})";
                $n = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                if ( $n === false ) {
                    throw new \RuntimeException( 'Cascade failed on tt_pdp_calendar_links: ' . $wpdb->last_error );
                }
                if ( (int) $n > 0 ) $per_table['tt_pdp_calendar_links'] = (int) $n;
            }

            $children = [
                [ 'tt_eval_ratings',          'evaluation_id', 'tt_evaluations',  'id' ],
                [ 'tt_goal_links',            'goal_id',       'tt_goals',        'id' ],
                [ 'tt_pdp_conversations',     'pdp_file_id',   'tt_pdp_files',    'id' ],
                [ 'tt_pdp_verdicts',          'pdp_file_id',   'tt_pdp_files',    'id' ],
                [ 'tt_trial_case_staff',        'case_id',     'tt_trial_cases',  'id' ],
                [ 'tt_trial_case_staff_inputs', 'case_id',     'tt_trial_cases',  'id' ],
                [ 'tt_trial_extensions',        'case_id',     'tt_trial_cases',  'id' ],
            ];
            foreach ( $children as [ $child, $fk, $parent, $pk ] ) {
                if ( ! $this->tableExists( $child ) || ! $this->tableExists( $parent ) ) continue;
                $sql = "DELETE c FROM {$p}{$child} c
                         INNER JOIN {$p}{$parent} pa ON pa.{$pk} = c.{$fk}
                         WHERE pa.player_id IN ({$ph})";
                $n = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                if ( $n === false ) {
                    throw new \RuntimeException( "Cascade failed on {$child}: " . $wpdb->last_error );
                }
                if ( (int) $n > 0 ) $per_table[ $child ] = (int) $n;
            }

            // Goal threads (thread_type='goal', thread_id=goal_id).
            foreach ( [ 'tt_thread_messages', 'tt_thread_reads' ] as $thread_table ) {
                if ( ! $this->tableExists( $thread_table ) ) continue;
                $sql = "DELETE tm FROM {$p}{$thread_table} tm
                         INNER JOIN {$p}tt_goals g ON tm.thread_type = 'goal' AND tm.thread_id = g.id
                         WHERE g.player_id IN ({$ph})";
                $n = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                if ( $n === false ) {
                    throw new \RuntimeException( "Cascade failed on {$thread_table}: " . $wpdb->last_error );
                }
                if ( (int) $n > 0 ) $per_table[ $thread_table ] = (int) $n;
            }

            // Dynamic sweep: every tt_* table with a player reference.
            foreach ( $this->playerColumns() as $ref ) {
                $bare = $ref['bare_table'];
                $col  = $ref['column'];
                if ( $bare === 'tt_players' ) continue;

                if ( isset( self::SET_NULL[ $bare ] ) && $ref['nullable'] ) {
                    $sql = "UPDATE {$p}{$bare} SET {$col} = NULL WHERE {$col} IN ({$ph})";
                    $n = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                    if ( $n === false ) {
                        throw new \RuntimeException( "Set-null failed on {$bare}.{$col}: " . $wpdb->last_error );
                    }
                    if ( (int) $n > 0 ) $nulled[ "{$bare}.{$col}" ] = (int) $n;
                } else {
                    $sql = "DELETE FROM {$p}{$bare} WHERE {$col} IN ({$ph})";
                    $n = $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
                    if ( $n === false ) {
                        throw new \RuntimeException( "Cascade failed on {$bare}.{$col}: " . $wpdb->last_error );
                    }
                    if ( (int) $n > 0 ) {
                        $per_table[ $bare ] = ( $per_table[ $bare ] ?? 0 ) + (int) $n;
                    }
                }
            }

            $sql_final = "DELETE FROM {$p}tt_players WHERE id IN ({$ph}) AND club_id = %d";
            $deleted = $wpdb->query( $wpdb->prepare( $sql_final, ...array_merge( $ids, [ $club ] ) ) );
            if ( $deleted === false ) {
                throw new \RuntimeException( 'Final delete failed on tt_players: ' . $wpdb->last_error );
            }

            $wpdb->query( 'COMMIT' );

            Logger::info( 'player.deleted_with_cascade', [
                'player_ids' => $ids,
                'club_id'    => $club,
                'deleted'    => (int) $deleted,
                'per_table'  => $per_table,
                'nulled'     => $nulled,
                'by_user'    => get_current_user_id(),
            ] );

            return [ 'deleted' => (int) $deleted, 'per_table' => $per_table, 'nulled' => $nulled ];
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            Logger::error( 'player.cascade.failed', [
                'player_ids' => $ids,
                'club_id'    => $club,
                'error'      => $e->getMessage(),
            ] );
            throw $e;
        }
    }

    /**
     * All (table, column) pairs in this install referencing a player:
     * `player_id` or `guest_player_id` on any prefixed `tt_*` table.
     *
     * @return list<array{bare_table:string, column:string, nullable:bool}>
     */
    private function playerColumns(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        // LIKE-escape the prefix's own underscores so e.g. `wp_` can't
        // match `wpx`.
        $pattern = str_replace( '_', '\\_', $p ) . 'tt\\_%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME LIKE %s
                AND COLUMN_NAME IN ('player_id', 'guest_player_id')",
            $pattern
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'bare_table' => substr( (string) $row->t, strlen( $p ) ),
                'column'     => (string) $row->c,
                'nullable'   => strtoupper( (string) $row->n ) === 'YES',
            ];
        }
        return $out;
    }

    private function tableExists( string $bare_table ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $bare_table;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param int[] $raw
     * @return int[]
     */
    private function cleanIds( array $raw ): array {
        $out = [];
        foreach ( $raw as $v ) {
            $i = (int) $v;
            if ( $i > 0 ) $out[ $i ] = true;
        }
        return array_keys( $out );
    }
}
