<?php
namespace TT\Modules\Alerts\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertOccurrence;
use TT\Modules\Alerts\Domain\Severity;

/**
 * AlertOccurrencesRepository (#2631, epic #2629) — persistence for
 * `tt_alert_occurrences`.
 *
 * Every query is club-scoped through `QueryHelpers::clubScopeWhere()` per
 * CLAUDE.md §4, including the ones that are a no-op today. The sweep runs
 * unauthenticated with the club pinned by filter, so a dropped clause here
 * would cross tenants silently rather than failing.
 */
final class AlertOccurrencesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_alert_occurrences';
    }

    /**
     * Insert a new occurrence, or bump an existing one.
     *
     * The bump refreshes `last_seen_at` AND `severity` — severity can
     * escalate with age, and a bump-only update would freeze every
     * occurrence at the severity it was born with.
     *
     * It also clears `resolved_at`. A condition that resolves and then
     * recurs is the same condition again, not a new one: reusing the row
     * keeps `first_seen_at` honest about when the recipient first had this
     * problem, which is the whole point of storing it.
     *
     * Returns true when the row was newly created.
     */
    public function upsert( AlertOccurrence $occ, string $now ): bool {
        global $wpdb;
        $table  = $this->table();
        $club   = CurrentClub::id();
        $dedupe = $occ->dedupeKey();

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, resolved_at FROM {$table} WHERE club_id = %d AND dedupe_key = %s LIMIT 1",
            $club,
            $dedupe
        ) );
        $existing_id = $existing !== null ? (int) $existing->id : 0;

        $payload_json = ! empty( $occ->payload )
            ? wp_json_encode( $occ->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : null;

        if ( $existing_id > 0 ) {
            $data = [
                'last_seen_at' => $now,
                'severity'     => Severity::normalise( $occ->severity ),
                'payload_json' => $payload_json,
                'player_id'    => $occ->playerId,
                'resolved_at'  => null,
            ];
            $format = [ '%s', '%s', '%s', '%d', '%s' ];

            // #2632 — a row that had resolved and is now true again is a
            // RECURRENCE, and the user's dismissal of the previous episode
            // does not carry over: they dismissed a thing that then got
            // fixed, and it has since come back. Clearing here is what makes
            // "dismiss is not a permanent mute" true.
            //
            // A plain bump (never resolved, still true) must NOT clear it —
            // that would resurrect an alert the user dismissed an hour ago,
            // every hour, which is how a feature earns being muted outright.
            $was_resolved = $existing !== null && $existing->resolved_at !== null;
            if ( $was_resolved ) {
                $data['dismissed_at']  = null;
                $data['snoozed_until'] = null;
                $format[] = '%s';
                $format[] = '%s';
            }

            $wpdb->update( $table, $data, [ 'id' => $existing_id ], $format, [ '%d' ] );
            return false;
        }

        $wpdb->insert(
            $table,
            array_merge(
                [
                    'uuid'              => wp_generate_uuid4(),
                    'alert_key'         => $occ->alertKey,
                    'recipient_user_id' => $occ->recipientUserId,
                    'subject_type'      => $occ->subjectType,
                    'subject_id'        => $occ->subjectId,
                    'player_id'         => $occ->playerId,
                    'dedupe_key'        => $dedupe,
                    'severity'          => Severity::normalise( $occ->severity ),
                    'payload_json'      => $payload_json,
                    'first_seen_at'     => $now,
                    'last_seen_at'      => $now,
                ],
                QueryHelpers::clubScopeInsertColumn()
            ),
            [ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        return true;
    }

    /**
     * Stamp `resolved_at` on every open occurrence of `$alertKey` whose
     * dedupe key is NOT in `$seen` — the conditions that were true last run
     * and are not true now.
     *
     * `$scopeSubjectIds`, when given, limits the resolution to those
     * subjects. That is what keeps a narrowed (event-driven) run from
     * resolving the whole club's backlog just because it only looked at one
     * activity. Nothing populates it in wave 1; it exists so #2633's
     * invalidation cannot get this wrong later.
     *
     * @param list<string> $seen
     * @param list<int>    $scopeSubjectIds
     * @return int Rows resolved.
     */
    public function resolveMissing( string $alertKey, array $seen, string $now, array $scopeSubjectIds = [] ): int {
        global $wpdb;
        $table = $this->table();

        $sql    = "UPDATE {$table} SET resolved_at = %s
                    WHERE " . QueryHelpers::clubScopeWhere() . "
                      AND alert_key = %s
                      AND resolved_at IS NULL";
        $params = [ $now, $alertKey ];

        if ( ! empty( $seen ) ) {
            $sql .= ' AND dedupe_key NOT IN (' . implode( ',', array_fill( 0, count( $seen ), '%s' ) ) . ')';
            $params = array_merge( $params, $seen );
        }

        if ( ! empty( $scopeSubjectIds ) ) {
            $ids  = implode( ',', array_map( 'intval', $scopeSubjectIds ) );
            $sql .= " AND subject_id IN ({$ids})";
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->query( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Open occurrences for one recipient, loudest first.
     *
     * "Open" means unresolved, undismissed, and not currently snoozed. A
     * snoozed occurrence is deliberately still in the table and still being
     * reconciled — it is hidden, not forgotten, so it reappears on its own
     * when the snooze lapses and the condition is still true.
     *
     * @return list<object>
     */
    public function openForUser( int $userId, int $limit = 50 ): array {
        global $wpdb;
        if ( $userId <= 0 ) return [];
        $table = $this->table();
        $limit = max( 1, min( 200, $limit ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND recipient_user_id = %d
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )
              ORDER BY FIELD(severity,'urgent','attention','info'), first_seen_at ASC, id ASC
              LIMIT %d",
            $userId,
            current_time( 'mysql' ),
            $limit
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /** Count of open occurrences for one recipient — the bell's number. */
    public function openCountForUser( int $userId ): int {
        global $wpdb;
        if ( $userId <= 0 ) return 0;
        $table = $this->table();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND recipient_user_id = %d
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )",
            $userId,
            current_time( 'mysql' )
        ) );
    }

    /**
     * Open occurrences for a set of subjects, keyed by subject id (#2633).
     *
     * ONE query for a whole list. This is the only correct way to render
     * inline chips: a per-row read would put fifty queries behind a fifty-row
     * activities list, which is the shape that gets an inline surface pulled
     * back out again a release later.
     *
     * Scoped to `$userId` as recipient, not to the subject alone. That is a
     * deliberate authorization decision rather than a convenience: the
     * evaluator already answered "may this person see this condition" when it
     * decided whether to write the row, and it re-answers it on every sweep.
     * Reading by subject only would hand a chip to anyone who can open the
     * record, which for a `player_id`-bearing occurrence is exactly the leak
     * CLAUDE.md §1 forbids. Oversight users who are not recipients get the
     * aggregate in {@see rollupByTeams()} instead, per epic decision 7.
     *
     * @param list<int> $ids
     * @return array<int, list<object>> subject_id => occurrences, loudest first
     */
    public function openBySubjects( string $type, array $ids, int $userId = 0 ): array {
        global $wpdb;

        $type = sanitize_key( $type );
        $ids  = $this->intList( $ids );
        if ( $type === '' || empty( $ids ) ) return [];

        if ( $userId <= 0 ) $userId = (int) get_current_user_id();
        if ( $userId <= 0 ) return [];
        if ( ! $this->tableExists() ) return [];

        $table = $this->table();
        $list  = implode( ',', $ids );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND recipient_user_id = %d
                AND subject_type = %s
                AND subject_id IN ({$list})
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )
              ORDER BY FIELD(severity,'urgent','attention','info'), first_seen_at ASC, id ASC",
            $userId,
            $type,
            current_time( 'mysql' )
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->subject_id ][] = $row;
        }
        return $out;
    }

    /**
     * Open occurrences for a set of players, keyed by player id (#2633).
     *
     * The player-record surface. Only OPEN rows are ever returned — epic
     * decision 12: a resolved occurrence is operational exhaust, not
     * biography, and at 90-day retention it would vanish from the record
     * retroactively anyway. Nothing about an alert is written to the
     * player's journey; this read is the entire player-facing surface.
     *
     * @param list<int> $playerIds
     * @return array<int, list<object>> player_id => occurrences, loudest first
     */
    public function openByPlayers( array $playerIds, int $userId = 0 ): array {
        global $wpdb;

        $playerIds = $this->intList( $playerIds );
        if ( empty( $playerIds ) ) return [];

        if ( $userId <= 0 ) $userId = (int) get_current_user_id();
        if ( $userId <= 0 ) return [];
        if ( ! $this->tableExists() ) return [];

        $table = $this->table();
        $list  = implode( ',', $playerIds );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND recipient_user_id = %d
                AND player_id IN ({$list})
                AND resolved_at IS NULL
                AND dismissed_at IS NULL
                AND ( snoozed_until IS NULL OR snoozed_until <= %s )
              ORDER BY FIELD(severity,'urgent','attention','info'), first_seen_at ASC, id ASC",
            $userId,
            current_time( 'mysql' )
        ) );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row->player_id ][] = $row;
        }
        return $out;
    }

    /**
     * The oversight roll-up (#2633) — open conditions per team, in one
     * GROUP BY over the rows that already exist.
     *
     * This is the counterpart that makes epic decision 7 sustainable. A Head
     * of Development receives no occurrences of their own; fanning twenty
     * teams' worth of rows at the person with the least reading time is how
     * the whole feature gets ignored. Instead they read the same table
     * sideways. **Nothing here writes.**
     *
     * Two deliberate differences from the inbox reads above:
     *
     *  - **No `recipient_user_id` filter.** The point is precisely to see
     *    conditions addressed to somebody else. Team scope is the
     *    authorization boundary here, and the caller resolves it from the
     *    capability model before calling in.
     *  - **`read_at` / `snoozed_until` / `dismissed_at` are ignored.** Those
     *    are one recipient's inbox hygiene. A coach snoozing an alert must
     *    not make the condition disappear from their Head of Development's
     *    overview — that would turn a per-user convenience into a way of
     *    hiding from oversight.
     *
     * Counted DISTINCT over the subject, not over rows: an occurrence is
     * written once per recipient (decision 5), so counting rows would report
     * "6 unmarked activities" for three activities with two recipients each.
     *
     * @param list<int>    $teamIds  teams the viewer oversees; empty = no rows
     * @param list<string> $alertKeys optional narrowing to specific definitions
     * @return list<object> {team_id, team_name, subject_count, severity}
     */
    public function rollupByTeams( array $teamIds, array $alertKeys = [] ): array {
        global $wpdb;

        $teamIds = $this->intList( $teamIds );
        if ( empty( $teamIds ) ) return [];
        if ( ! $this->tableExists() ) return [];

        $p     = $wpdb->prefix;
        $table = $this->table();
        $list  = implode( ',', $teamIds );

        // An occurrence reaches a team one of two ways: it is about the team
        // itself, or it is about an activity that belongs to one. The CASE in
        // the join collapses both into a single grouped read rather than a
        // UNION the caller would have to re-aggregate.
        $sql = "SELECT t.id AS team_id,
                       t.name AS team_name,
                       COUNT(DISTINCT o.subject_type, o.subject_id) AS subject_count,
                       MAX(FIELD(o.severity,'info','attention','urgent')) AS severity_weight
                  FROM {$table} o
             LEFT JOIN {$p}tt_activities a
                    ON o.subject_type = 'activity' AND a.id = o.subject_id
            INNER JOIN {$p}tt_teams t
                    ON t.id = CASE WHEN o.subject_type = 'team' THEN o.subject_id ELSE a.team_id END
                 WHERE " . QueryHelpers::clubScopeWhere( 'o' ) . "
                   AND " . QueryHelpers::clubScopeWhere( 't' ) . "
                   AND o.resolved_at IS NULL
                   AND t.id IN ({$list})";

        $params = [];
        if ( ! empty( $alertKeys ) ) {
            $keys    = array_values( array_filter( array_map( 'strval', $alertKeys ) ) );
            $sql    .= ' AND o.alert_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
            $params  = $keys;
        }

        $sql .= ' GROUP BY t.id, t.name
                  HAVING subject_count > 0
                  ORDER BY severity_weight DESC, subject_count DESC, t.name ASC';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The inbox read (#2633) — one recipient's occurrences under the
     * module / severity / state / subject filters the view and the REST
     * list both offer.
     *
     * Both consumers call this rather than each assembling their own WHERE,
     * so the API and the rendered page can never disagree about what "open"
     * means (CLAUDE.md §4).
     *
     * @param array<string,mixed> $args state|alert_keys|severity|subject_type|subject_id|player_id|limit
     * @return list<object>
     */
    public function listForUser( int $userId, array $args = [] ): array {
        global $wpdb;
        if ( $userId <= 0 ) return [];
        if ( ! $this->tableExists() ) return [];

        $table = $this->table();
        $now   = current_time( 'mysql' );
        $limit = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );

        $where  = [ QueryHelpers::clubScopeWhere(), 'recipient_user_id = %d' ];
        $params = [ $userId ];

        switch ( (string) ( $args['state'] ?? 'open' ) ) {
            case 'resolved':
                $where[] = 'resolved_at IS NOT NULL';
                break;
            case 'unread':
                $where[] = 'resolved_at IS NULL AND dismissed_at IS NULL AND read_at IS NULL';
                $where[] = '( snoozed_until IS NULL OR snoozed_until <= %s )';
                $params[] = $now;
                break;
            default:
                $where[] = 'resolved_at IS NULL AND dismissed_at IS NULL';
                $where[] = '( snoozed_until IS NULL OR snoozed_until <= %s )';
                $params[] = $now;
        }

        if ( ! empty( $args['alert_keys'] ) && is_array( $args['alert_keys'] ) ) {
            $keys    = array_values( array_filter( array_map( 'strval', $args['alert_keys'] ) ) );
            $where[] = 'alert_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')';
            $params  = array_merge( $params, $keys );
        }

        // Not `Severity::normalise()`: that coerces an unknown value to
        // `attention`, which on a filter would silently answer a different
        // question than the one asked. An unrecognised severity means "no
        // severity filter", so the caller sees everything rather than a
        // plausible-looking subset.
        $severity = (string) ( $args['severity'] ?? '' );
        if ( ! in_array( $severity, Severity::all(), true ) ) $severity = '';
        if ( $severity !== '' ) {
            $where[]  = 'severity = %s';
            $params[] = $severity;
        }

        $subject_type = sanitize_key( (string) ( $args['subject_type'] ?? '' ) );
        $subject_id   = (int) ( $args['subject_id'] ?? 0 );
        if ( $subject_type !== '' ) {
            $where[]  = 'subject_type = %s';
            $params[] = $subject_type;
        }
        if ( $subject_id > 0 ) {
            $where[]  = 'subject_id = %d';
            $params[] = $subject_id;
        }

        $player_id = (int) ( $args['player_id'] ?? 0 );
        if ( $player_id > 0 ) {
            $where[]  = 'player_id = %d';
            $params[] = $player_id;
        }

        $sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
             . " ORDER BY FIELD(severity,'urgent','attention','info'), first_seen_at ASC, id ASC
                LIMIT %d";
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Positive, unique, integer-cast ids, capped.
     *
     * The cap is not paranoia about SQL length: it bounds the IN-list a
     * caller can build out of URL input, so a hand-crafted request cannot
     * turn one chip read into a pathological scan.
     *
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function intList( array $ids ): array {
        $out = array_values( array_unique( array_filter(
            array_map( 'intval', $ids ),
            static fn( int $id ): bool => $id > 0
        ) ) );
        return array_slice( $out, 0, 500 );
    }

    /** One occurrence by uuid, scoped to a recipient so a uuid is not a capability. */
    public function findForUser( string $uuid, int $userId ): ?object {
        global $wpdb;
        if ( $uuid === '' || $userId <= 0 ) return null;
        $table = $this->table();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE " . QueryHelpers::clubScopeWhere() . "
                AND uuid = %s AND recipient_user_id = %d
              LIMIT 1",
            $uuid,
            $userId
        ) );

        return $row ?: null;
    }

    /**
     * Stamp `read_at` for one recipient's occurrence. Idempotent — a second
     * read does not move the timestamp, so "when did they first see this"
     * stays answerable.
     */
    public function markRead( string $uuid, int $userId, string $now ): bool {
        global $wpdb;
        $row = $this->findForUser( $uuid, $userId );
        if ( $row === null ) return false;
        if ( ! empty( $row->read_at ) ) return true;

        return (bool) $wpdb->update(
            $this->table(),
            [ 'read_at' => $now ],
            [ 'id' => (int) $row->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Silence one occurrence until a moment in time.
     *
     * Deliberately per-occurrence, not per-alert: "not this activity, I know
     * about it" is a different request from "not this kind of alert, ever",
     * and the second is what the preference matrix is for. Snoozing here
     * leaves the row being reconciled, so it reappears on its own if the
     * condition is still true when the snooze lapses — and disappears for
     * good, unsnoozed, the moment someone fixes it.
     *
     * #2632 — `POST /alerts/{uuid}/snooze`.
     */
    public function snooze( string $uuid, int $userId, string $until ): bool {
        global $wpdb;
        $row = $this->findForUser( $uuid, $userId );
        if ( $row === null ) return false;

        return (bool) $wpdb->update(
            $this->table(),
            [ 'snoozed_until' => $until ],
            [ 'id' => (int) $row->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Dismiss one occurrence.
     *
     * Dismiss is **not** a permanent mute. A dismissed occurrence that later
     * resolves and then recurs produces a fresh row and reappears, because
     * the condition became true again and that is new information. Users
     * will ask about this; the docs say it explicitly.
     *
     * The reconcile clears `dismissed_at` when a row is reopened — see
     * `upsert()` — so the "recurs and reappears" behaviour is a property of
     * the reconcile rather than something this method has to arrange.
     */
    public function dismiss( string $uuid, int $userId, string $now ): bool {
        global $wpdb;
        $row = $this->findForUser( $uuid, $userId );
        if ( $row === null ) return false;

        return (bool) $wpdb->update(
            $this->table(),
            [ 'dismissed_at' => $now ],
            [ 'id' => (int) $row->id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /** @var bool|null per-request cache for tableExists() */
    private static $tableExists = null;

    /**
     * Whether the table exists. The banner and the bell call this before
     * querying so an install whose migration has not run yet degrades to
     * "no alerts" instead of a fatal on every dashboard load.
     *
     * Cached per request: the banner and the bell both render on every
     * dashboard load, and a `SHOW TABLES` each is two queries spent to
     * learn something that cannot change mid-request.
     */
    public function tableExists(): bool {
        if ( self::$tableExists !== null ) return self::$tableExists;
        global $wpdb;
        $table = $this->table();
        self::$tableExists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        return self::$tableExists;
    }

    /** Drop the per-request table cache. Used by the migration tests. */
    public static function flushTableCache(): void {
        self::$tableExists = null;
    }
}
