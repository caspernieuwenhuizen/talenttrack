<?php
namespace TT\Modules\Activities\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AttendanceWriter (#3451) — the only place `tt_attendance` is written.
 *
 * ## Why this class exists
 *
 * Migration 0121 split the table into two kinds of row, separated by one
 * column nothing about the table's shape advertises:
 *
 *   - `expected` — a squad somebody PLANNED. Match prep writes it, the
 *     activity wizard seeds it, and it carries real statuses (Expected →
 *     `Present`, Not coming → `Absent`, Maybe → `Excused`).
 *   - `actual` — a register somebody TOOK. Everything that reports on what
 *     happened counts these and only these.
 *
 * Ten defects came out of confusing the two in one week. Eight were reads
 * returning the wrong answer, which is recoverable the moment the query is
 * fixed. Two were writes, and those are not: one deleted a coach's planned
 * roster and relabelled the rest as a register nobody had taken (#3456 /
 * #3451), the other UPDATEd derived minutes into a planned row where no
 * correct reader could ever find them (#3445).
 *
 * The column has `DEFAULT 'actual'`, so the cheapest way to get it wrong is
 * to say nothing at all — an insert that omits it silently claims a
 * register was taken, and a delete that omits it reaches the plan.
 *
 * ## The property this class has
 *
 * **No method writes a row without naming which kind it writes, and no
 * delete can reach the other kind by omission.** There is deliberately no
 * `insert( array $row )` taking `record_type` from the caller's map, and no
 * `clear( $activity_id )` that means "everything". The two exceptions are
 * named for what they are:
 *
 *   - `clearForDeletedActivity()` — the activity itself is going, so a
 *     surviving plan would point at a row that no longer exists.
 *   - `updateRow()` / `deleteRow()` — keyed by primary key. The row is
 *     already one specific row of one specific kind; there is no scope
 *     left to get wrong.
 *
 * ## Events
 *
 * `tt_activity_attendance_changed` fires from here rather than from the
 * callers, which is the same argument `ActivitiesRepository` made for
 * firing it from its writers rather than from the eight surfaces above
 * them, one level further down: an event only some writers emit is worse
 * than none, because the alert clears when attendance is recorded one way
 * and lingers when it is recorded another, and that reads as a bug rather
 * than as staleness.
 *
 * ## The stamp (#3655)
 *
 * Every RECORDED row gets `recorded_by` / `recorded_at` from here, and
 * from nowhere else — a coach who finds a mark they did not make has to
 * be able to ask who did. It is the **last save of the register**, not
 * per-mark history: a register save deletes and re-inserts its rows, so
 * one save stamps them all alike. Planned rows are never stamped, and an
 * update only restamps when it carries a `status`.
 *
 * ## What it is not
 *
 * It is not a read layer. Reads stay where they are, scoped by the
 * `tools/check-attendance-scope.php` gate — thirty files read this table
 * and most of them get the distinction right by accident, which a lint
 * catches more proportionately than a refactor would.
 */
final class AttendanceWriter {

    /** A squad somebody planned. */
    public const PLANNED = 'expected';

    /** A register somebody took. */
    public const RECORDED = 'actual';

    /** Last DB error from a write made through this writer. */
    private string $last_error = '';

    /* ---------------------------------------------------------------
     * Inserts
     * ------------------------------------------------------------- */

    /**
     * Write one RECORDED attendance row — a register that was taken.
     *
     * `record_type` is set here, not read from `$row`: the method name is
     * the declaration. `club_id` defaults to the current club when the
     * caller has not pinned one.
     *
     * @param array<string, mixed> $row Column map. `activity_id` required.
     * @return int|null New row id, or null on a DB error (`lastError()`).
     */
    public function recordActual( array $row ): ?int {
        return $this->insertOfKind( $row, self::RECORDED );
    }

    /**
     * Write one PLANNED attendance row — part of a squad somebody selected
     * ahead of the activity. Never counted by a report of what happened.
     *
     * @param array<string, mixed> $row Column map. `activity_id` required.
     * @return int|null New row id, or null on a DB error (`lastError()`).
     */
    public function planExpected( array $row ): ?int {
        return $this->insertOfKind( $row, self::PLANNED );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insertOfKind( array $row, string $record_type ): ?int {
        global $wpdb;

        $row['record_type'] = $record_type;
        if ( ! array_key_exists( 'club_id', $row ) ) {
            $row['club_id'] = CurrentClub::id();
        }
        if ( $record_type === self::RECORDED ) {
            $row = self::stamp( $row );
        }

        $ok = $wpdb->insert( $this->table(), $row );
        if ( $ok === false ) {
            $this->last_error = (string) $wpdb->last_error;
            return null;
        }
        $this->last_error = '';
        self::announce( (int) ( $row['activity_id'] ?? 0 ) );
        return (int) $wpdb->insert_id;
    }

    /* ---------------------------------------------------------------
     * Deletes
     * ------------------------------------------------------------- */

    /**
     * Drop the RECORDED rows for an activity. The plan is untouched — that
     * is the whole point of the method existing separately.
     *
     * @param bool $include_guests Guest rows are managed through the guest
     *        endpoints and survive an ordinary rewrite (#0026); pass true
     *        only where the caller owns them too.
     * @return int Rows removed.
     */
    public function clearActual( int $activity_id, bool $include_guests = false ): int {
        return $this->clearOfKind( $activity_id, self::RECORDED, $include_guests );
    }

    /**
     * Drop the PLANNED rows for an activity. The register is untouched.
     *
     * @return int Rows removed.
     */
    public function clearExpected( int $activity_id, bool $include_guests = false ): int {
        return $this->clearOfKind( $activity_id, self::PLANNED, $include_guests );
    }

    private function clearOfKind( int $activity_id, string $record_type, bool $include_guests ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;

        $where = [
            'activity_id' => $activity_id,
            'club_id'     => CurrentClub::id(),
            'record_type' => $record_type,
        ];
        if ( ! $include_guests ) {
            $where['is_guest'] = 0;
        }

        $deleted = $wpdb->delete( $this->table(), $where );
        self::announce( $activity_id );
        return (int) $deleted;
    }

    /**
     * Drop the RECORDED rows for every player OUTSIDE the given set — the
     * reconcile half of a derived register (match execution recomputes the
     * whole thing from its availability list, so a player who left the list
     * must lose their derived row).
     *
     * The plan is untouched: the planned denominator is exactly what a
     * completeness count needs, and deleting it here is what #3445 did.
     *
     * @param list<int> $keep_player_ids
     * @return int Rows removed.
     */
    public function clearActualExcept( int $activity_id, array $keep_player_ids ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;

        $keep = [];
        foreach ( $keep_player_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) $keep[] = $pid;
        }
        if ( $keep === [] ) return 0;

        // `$p` rather than `$this->table()`: a method's declared `string`
        // return widens away the literal-string type `$wpdb->prepare()`
        // requires at PHPStan level 8. Same reason FIND_IN_SET stands in for
        // a generated `IN (%d, %d, …)` run — that placeholder string is not
        // literal either.
        $p       = $wpdb->prefix;
        $deleted = $wpdb->query( (string) $wpdb->prepare(
            "DELETE FROM {$p}tt_attendance
              WHERE activity_id = %d
                AND club_id     = %d
                AND record_type = 'actual'
                AND NOT FIND_IN_SET( player_id, %s )",
            $activity_id,
            CurrentClub::id(),
            implode( ',', $keep )
        ) );
        self::announce( $activity_id );
        return (int) $deleted;
    }

    /**
     * Drop BOTH kinds, roster and guest alike, because the activity itself
     * is being hard-deleted and a surviving plan would reference a row that
     * no longer exists.
     *
     * Deliberately does NOT announce: the caller deletes the activity row
     * after this and announces then, so the alert definitions re-read an
     * activity that is already gone and return nothing — which is what
     * resolves the occurrences it left behind. Announcing here would
     * re-evaluate against a still-live activity with no attendance.
     *
     * @return int Rows removed.
     */
    public function clearForDeletedActivity( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;

        // Both record types on purpose — see the docblock. /* both-kinds-ok */
        return (int) $wpdb->delete( $this->table(), [
            'activity_id' => $activity_id,
            'club_id'     => CurrentClub::id(),
        ] );
    }

    /**
     * Delete one row by primary key. The id already names one row of one
     * kind, so there is no scope to get wrong.
     */
    public function deleteRow( int $row_id ): bool {
        if ( $row_id <= 0 ) return false;
        global $wpdb;

        // Read before the delete — afterwards there is no row to ask.
        $activity_id = $this->activityIdOfRow( $row_id );
        $ok = $wpdb->delete( $this->table(), [ 'id' => $row_id, 'club_id' => CurrentClub::id() ] ) !== false; /* both-kinds-ok */
        self::announce( $activity_id );
        return $ok;
    }

    /* ---------------------------------------------------------------
     * Updates
     * ------------------------------------------------------------- */

    /**
     * Partial update of one row by primary key. Caller supplies the
     * sanitized column map.
     *
     * `record_type` is stripped: relabelling an existing row is not an edit,
     * it is the bug this class exists to prevent. A row of the other kind is
     * written by inserting one.
     *
     * @param array<string, mixed> $fields
     */
    public function updateRow( int $row_id, array $fields ): bool {
        if ( $row_id <= 0 || $fields === [] ) return false;
        global $wpdb;

        unset( $fields['record_type'] );
        if ( $fields === [] ) return false;
        $fields = self::stampIfRegisterSave( $fields );

        $ok = $wpdb->update( $this->table(), $fields, [ 'id' => $row_id, 'club_id' => CurrentClub::id() ] ) !== false; /* both-kinds-ok */
        self::announce( $this->activityIdOfRow( $row_id ) );
        return $ok;
    }

    /**
     * Partial update of one GUEST row by primary key.
     *
     * Separate from `updateRow()` only for the `is_guest = 1` guard, which
     * is what stops a forged id from repointing a roster row through the
     * guest-promotion form (#0026). Like `updateRow()`, the id already names
     * one row of one kind.
     *
     * @param array<string, mixed> $fields
     */
    public function updateGuestRow( int $row_id, array $fields ): bool {
        if ( $row_id <= 0 || $fields === [] ) return false;
        global $wpdb;

        unset( $fields['record_type'] );
        if ( $fields === [] ) return false;
        $fields = self::stampIfRegisterSave( $fields );

        $ok = $wpdb->update( $this->table(), $fields, [ /* both-kinds-ok */
            'id'       => $row_id,
            'is_guest' => 1,
            'club_id'  => CurrentClub::id(),
        ] ) !== false;
        self::announce( $this->activityIdOfRow( $row_id ) );
        return $ok;
    }

    /* ---------------------------------------------------------------
     * Kind-scoped lookups — what a writer needs before it writes
     * ------------------------------------------------------------- */

    /**
     * The id of the RECORDED row for one player on one activity, or 0.
     * Newest wins where legacy dirty data left more than one.
     */
    public function actualRowId( int $activity_id, int $player_id ): int {
        $ids = $this->actualRowIds( $activity_id, $player_id );
        return $ids === [] ? 0 : (int) end( $ids );
    }

    /**
     * Every RECORDED row for one player on one activity, oldest first.
     *
     * More than one is legacy dirty data (a wizard row and a match-execution
     * row), and the grid write path heals it rather than staying ambiguous —
     * hence the list rather than a single id.
     *
     * @return list<int>
     */
    public function actualRowIds( int $activity_id, int $player_id ): array {
        if ( $activity_id <= 0 || $player_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND club_id = %d
                AND is_guest = 0 AND record_type = 'actual'
              ORDER BY id ASC",
            $activity_id, $player_id, CurrentClub::id()
        ) );

        return array_values( array_map( 'intval', (array) $ids ) );
    }

    /**
     * The id of the PLANNED row for one player on one activity, or 0.
     */
    public function expectedRowId( int $activity_id, int $player_id ): int {
        if ( $activity_id <= 0 || $player_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_attendance
              WHERE activity_id = %d AND player_id = %d AND club_id = %d
                AND is_guest = 0 AND record_type = 'expected'
              ORDER BY id DESC LIMIT 1",
            $activity_id, $player_id, CurrentClub::id()
        ) );
    }

    /* ---------------------------------------------------------------
     * The line-up projection
     * ------------------------------------------------------------- */

    /**
     * #1194 — write match prep's Starting XI / bench partition onto the
     * player's attendance row (`lineup_role` + `position_played`). Match
     * prep stays canonical; this table is the projection target.
     *
     * The projection belongs on the PLAN — a line-up is chosen before the
     * match, and that is the row match prep seeds. Two fallbacks, both
     * deliberate:
     *
     *   - No plan row but a recorded one: update THAT rather than inserting
     *     a second row. On an install that ran the pre-#3451 save the plan
     *     was deleted and its projection relabelled as a register, and the
     *     Line-up card reads whichever row carries the role. Inserting a
     *     plan row here instead would leave the stale role behind on the
     *     recorded one.
     *   - Neither: seed a plan row, which is what the edit form's
     *     pre-seeded roster path (#1297) expects.
     *
     * Only the two projection columns are written. Status, notes and
     * `record_type` are left exactly as they are.
     */
    public function upsertLineupProjection(
        int $activity_id,
        int $player_id,
        ?string $lineup_role,
        ?string $position_played
    ): void {
        if ( $activity_id <= 0 || $player_id <= 0 ) return;

        $row_id = $this->expectedRowId( $activity_id, $player_id );
        if ( $row_id <= 0 ) {
            $row_id = $this->actualRowId( $activity_id, $player_id );
        }

        if ( $row_id > 0 ) {
            $this->updateRow( $row_id, [
                'lineup_role'     => $lineup_role,
                'position_played' => $position_played,
            ] );
            return;
        }

        $this->planExpected( [
            'activity_id'     => $activity_id,
            'player_id'       => $player_id,
            'is_guest'        => 0,
            'lineup_role'     => $lineup_role,
            'position_played' => $position_played,
        ] );
    }

    /* ---------------------------------------------------------------
     * Who saved the register, and when (#3655)
     * ------------------------------------------------------------- */

    /**
     * Stamp `recorded_by` / `recorded_at` onto a row about to be written.
     *
     * The stamp says **who saved the register last**, not who made each
     * mark. Saving a register deletes and re-inserts the recorded rows, so
     * every row of one save carries the same author and time — the copy
     * above it says exactly that rather than pretending to per-mark
     * history.
     *
     * A caller-supplied value wins, which is what lets the demo generator
     * and the Excel importer stamp the coach and the activity's own date
     * instead of whoever happened to run the job. `recorded_by` is NULL
     * where there is no current user (WP-CLI, cron), because 0 is a user
     * id nobody has and rendering it would invent an author.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function stamp( array $row ): array {
        if ( ! array_key_exists( 'recorded_by', $row ) ) {
            $uid = get_current_user_id();
            $row['recorded_by'] = $uid > 0 ? $uid : null;
        }
        if ( ! array_key_exists( 'recorded_at', $row ) ) {
            $row['recorded_at'] = (string) current_time( 'mysql', true );
        }
        return $row;
    }

    /**
     * Restamp an update only when it is a register save.
     *
     * `status` is what a register save writes. Minutes, the line-up
     * projection (`upsertLineupProjection()`) and a notes-only edit are
     * not somebody taking a register, and restamping on those would move
     * the author of the register onto whoever last typed a minute.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private static function stampIfRegisterSave( array $fields ): array {
        if ( ! array_key_exists( 'status', $fields ) ) return $fields;
        return self::stamp( $fields );
    }

    /* ---------------------------------------------------------------
     * Plumbing
     * ------------------------------------------------------------- */

    /** The DB error from the last write through this writer, or ''. */
    public function lastError(): string {
        return $this->last_error;
    }

    /**
     * #2731 — say that an activity's attendance rows changed.
     *
     * Static so the two paths that still write outside an instance method
     * (and any future one) can announce without holding a writer.
     */
    public static function announce( int $activity_id ): void {
        if ( $activity_id <= 0 ) return;

        /**
         * Attendance rows for an activity were created, changed or removed.
         *
         * Says nothing about what the rows now contain — a listener that
         * cares must re-read. That is deliberate: the write paths range
         * from one cell of a grid to a whole roster rewrite, and a payload
         * describing all of them would be a payload nobody could trust.
         *
         * @param int $activity_id
         */
        do_action( 'tt_activity_attendance_changed', $activity_id );
    }

    /**
     * The activity one row belongs to, or 0. A primary-key lookup on the
     * single-row write paths, which handle one cell at a time and already
     * cost a round trip.
     */
    private function activityIdOfRow( int $row_id ): int {
        if ( $row_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_id FROM {$p}tt_attendance WHERE id = %d AND club_id = %d", /* both-kinds-ok */
            $row_id, CurrentClub::id()
        ) );
    }

    /** The table name. Every method above says which kind of row it means. */
    private function table(): string {
        global $wpdb;
        /* both-kinds-ok */
        return $wpdb->prefix . 'tt_attendance';
    }
}
