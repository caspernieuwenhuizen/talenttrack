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

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE club_id = %d AND dedupe_key = %s LIMIT 1",
            $club,
            $dedupe
        ) );

        $payload_json = ! empty( $occ->payload )
            ? wp_json_encode( $occ->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : null;

        if ( $existing_id > 0 ) {
            $wpdb->update(
                $table,
                [
                    'last_seen_at' => $now,
                    'severity'     => Severity::normalise( $occ->severity ),
                    'payload_json' => $payload_json,
                    'player_id'    => $occ->playerId,
                    'resolved_at'  => null,
                ],
                [ 'id' => $existing_id ],
                [ '%s', '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );
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
