<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PdpFormEntriesRepository (#4977, epic #4975) — what was entered on a PDP
 * conversation form: the scores and answers of each author, and the SMART
 * points per slot.
 *
 * Rows only. Who may read or write which part is `PdpFormAccess`, and the
 * shape a surface reads is `PdpFormReader`; nothing else reads the player's
 * rows (owner decision 9: a self-assessment never mixes into the coach's
 * figures). Every query is scoped to the current club.
 *
 * Writes are per entry, never per form: an autosaving surface sends one
 * field at a time, and a key that is not sent is left alone (CLAUDE.md §6).
 */
final class PdpFormEntriesRepository {

    /** The goal fields a write may set; the slot and the goal id are not among them. */
    public const GOAL_FIELDS = [ 'action_text', 'support_text', 'due_date', 'item_key' ];

    /**
     * Run `$work` in one transaction, holding the conversation row
     * (`FOR UPDATE`), once the conversation is found not to be signed off.
     *
     * The trainer's writes and the sign-off all take this lock, so an
     * autosave that lands while the trainer signs either commits before the
     * sign-off reads the form, or finds it signed and is refused. It never
     * changes a row after the sign-off read it, and never writes after the
     * commit. A refusal is thrown as `PdpFormSignoffRefused` and rolls back.
     *
     * @template T
     * @param callable(array<string,mixed>): T $work receives the locked row
     * @return T
     */
    public function underLock( int $conversation_id, callable $work ) {
        return \TT\Infrastructure\Database\Transaction::run( static function () use ( $conversation_id, $work ) {
            global $wpdb;
            $locked = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, coach_signoff_at, conducted_at, form_key, form_version, player_prepared_at FROM {$wpdb->prefix}tt_pdp_conversations WHERE id = %d AND club_id = %d FOR UPDATE",
                $conversation_id, CurrentClub::id()
            ), ARRAY_A );
            if ( ! is_array( $locked ) ) {
                throw new PdpFormSignoffRefused( new \WP_Error( 'not_found', __( 'Conversation not found.', 'talenttrack' ), [ 'status' => 404 ] ) );
            }
            if ( ! empty( $locked['coach_signoff_at'] ) ) {
                throw new PdpFormSignoffRefused( new \WP_Error( 'already_signed_off', __( 'This conversation has already been signed off.', 'talenttrack' ), [ 'status' => 409 ] ) );
            }
            return $work( $locked );
        } );
    }

    /**
     * Scores of one author on one form, item key => 1-5 or null.
     *
     * @return array<string, int|null>
     */
    public function scores( int $conversation_id, string $form_key, string $author ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT item_key, score FROM {$wpdb->prefix}tt_pdp_form_scores
              WHERE conversation_id = %d AND form_key = %s AND author = %s AND club_id = %d
              ORDER BY id ASC",
            $conversation_id, $form_key, $author, CurrentClub::id()
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row['item_key'] ] = $row['score'] === null ? null : (int) $row['score'];
        }
        return $out;
    }

    /**
     * Answers of one author on one form, field key => text.
     *
     * @return array<string, string>
     */
    public function answers( int $conversation_id, string $form_key, string $author ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT field_key, body FROM {$wpdb->prefix}tt_pdp_form_answers
              WHERE conversation_id = %d AND form_key = %s AND author = %s AND club_id = %d
              ORDER BY id ASC",
            $conversation_id, $form_key, $author, CurrentClub::id()
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (string) $row['field_key'] ] = (string) ( $row['body'] ?? '' );
        }
        return $out;
    }

    /**
     * The SMART points by slot.
     *
     * @return array<int, array{action_text:string, support_text:string, due_date:?string, item_key:?string, goal_id:?int}>
     */
    public function goals( int $conversation_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT slot, action_text, support_text, due_date, item_key, goal_id FROM {$wpdb->prefix}tt_pdp_form_goals
              WHERE conversation_id = %d AND club_id = %d
              ORDER BY slot ASC",
            $conversation_id, CurrentClub::id()
        ), ARRAY_A );
        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row['slot'] ] = [
                'action_text'  => (string) ( $row['action_text'] ?? '' ),
                'support_text' => (string) ( $row['support_text'] ?? '' ),
                'due_date'     => $row['due_date'] !== null && $row['due_date'] !== '' ? (string) $row['due_date'] : null,
                'item_key'     => $row['item_key'] !== null && $row['item_key'] !== '' ? (string) $row['item_key'] : null,
                'goal_id'      => $row['goal_id'] !== null ? (int) $row['goal_id'] : null,
            ];
        }
        return $out;
    }

    /**
     * When one author last changed anything on one form (UTC), or null when
     * they have entered nothing.
     */
    public function lastChange( int $conversation_id, string $form_key, string $author ): ?string {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        $last = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(t.updated_at) FROM (
                SELECT updated_at FROM {$p}tt_pdp_form_scores
                 WHERE conversation_id = %d AND form_key = %s AND author = %s AND club_id = %d
                UNION ALL
                SELECT updated_at FROM {$p}tt_pdp_form_answers
                 WHERE conversation_id = %d AND form_key = %s AND author = %s AND club_id = %d
             ) t",
            $conversation_id, $form_key, $author, $club,
            $conversation_id, $form_key, $author, $club
        ) );
        return $last !== null && $last !== '' ? (string) $last : null;
    }

    /** Has this author entered anything on the conversation, on any form? */
    public function hasEntries( int $conversation_id, string $author ): bool {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();
        $hit  = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_pdp_form_scores WHERE conversation_id = %d AND author = %s AND club_id = %d
             UNION ALL
             SELECT 1 FROM {$p}tt_pdp_form_answers WHERE conversation_id = %d AND author = %s AND club_id = %d
             LIMIT 1",
            $conversation_id, $author, $club,
            $conversation_id, $author, $club
        ) );
        return $hit !== null;
    }

    public function saveScore( int $conversation_id, string $form_key, string $item_key, string $author, ?int $score, int $user_id ): bool {
        global $wpdb;
        $now  = current_time( 'mysql', true );
        $uuid = wp_generate_uuid4();
        $club = CurrentClub::id();
        if ( $score === null ) {
            return $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$wpdb->prefix}tt_pdp_form_scores
                     (uuid, club_id, conversation_id, form_key, item_key, author, score, updated_by, created_at, updated_at)
                 VALUES (%s, %d, %d, %s, %s, %s, NULL, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE score = NULL, updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
                $uuid, $club, $conversation_id, $form_key, $item_key, $author, $user_id, $now, $now
            ) ) !== false;
        }
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}tt_pdp_form_scores
                 (uuid, club_id, conversation_id, form_key, item_key, author, score, updated_by, created_at, updated_at)
             VALUES (%s, %d, %d, %s, %s, %s, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            $uuid, $club, $conversation_id, $form_key, $item_key, $author, $score, $user_id, $now, $now
        ) ) !== false;
    }

    public function saveAnswer( int $conversation_id, string $form_key, string $field_key, string $author, string $body, int $user_id ): bool {
        global $wpdb;
        $now = current_time( 'mysql', true );
        return $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}tt_pdp_form_answers
                 (uuid, club_id, conversation_id, form_key, field_key, author, body, updated_by, created_at, updated_at)
             VALUES (%s, %d, %d, %s, %s, %s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE body = VALUES(body), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
            wp_generate_uuid4(), CurrentClub::id(), $conversation_id, $form_key, $field_key, $author, $body, $user_id, $now, $now
        ) ) !== false;
    }

    /**
     * Write the given fields of one SMART slot, creating the slot when it
     * does not exist yet. Fields that are not given are left alone.
     *
     * @param array<string, string|null> $fields a subset of GOAL_FIELDS
     */
    public function saveGoal( int $conversation_id, int $slot, array $fields, int $user_id ): bool {
        global $wpdb;
        $table  = "{$wpdb->prefix}tt_pdp_form_goals";
        $fields = array_intersect_key( $fields, array_flip( self::GOAL_FIELDS ) );
        $now    = current_time( 'mysql', true );
        $club   = CurrentClub::id();

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE conversation_id = %d AND slot = %d AND club_id = %d",
            $conversation_id, $slot, $club
        ) );
        $row = $fields + [ 'updated_by' => $user_id, 'updated_at' => $now ];
        if ( $id > 0 ) {
            return $wpdb->update( $table, $row, [ 'id' => $id, 'club_id' => $club ] ) !== false;
        }
        return $wpdb->insert( $table, $row + [
            'uuid'            => wp_generate_uuid4(),
            'club_id'         => $club,
            'conversation_id' => $conversation_id,
            'slot'            => $slot,
            'created_at'      => $now,
        ] ) !== false;
    }

    /** Record the goal sign-off created for a slot. */
    public function setGoalId( int $conversation_id, int $slot, int $goal_id ): bool {
        global $wpdb;
        return $wpdb->update(
            "{$wpdb->prefix}tt_pdp_form_goals",
            [ 'goal_id' => $goal_id, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'conversation_id' => $conversation_id, 'slot' => $slot, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Delete every form entry of the given conversations, for the PDP file
     * cascade. Returns the rows deleted per table; throws on a refused
     * delete so the cascade's transaction rolls back.
     *
     * @param list<int> $conversation_ids
     * @return array<string,int>
     */
    public function deleteForConversations( array $conversation_ids ): array {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $conversation_ids ) ) );
        $out = [ 'tt_pdp_form_scores' => 0, 'tt_pdp_form_answers' => 0, 'tt_pdp_form_goals' => 0 ];
        if ( $ids === [] ) return $out;

        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        foreach ( array_keys( $out ) as $name ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $n = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}{$name} WHERE club_id = %d AND conversation_id IN ({$ph})",
                CurrentClub::id(), ...$ids
            ) );
            if ( $n === false ) throw new \RuntimeException( "Cascade delete failed on {$name}: " . $wpdb->last_error );
            $out[ $name ] = (int) $n;
        }
        return $out;
    }
}
