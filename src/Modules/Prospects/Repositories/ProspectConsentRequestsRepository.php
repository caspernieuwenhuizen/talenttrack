<?php
namespace TT\Modules\Prospects\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Domain\ConsentOutcome;

/**
 * ProspectConsentRequestsRepository — `tt_prospect_consent_requests` (#3812).
 *
 * The dated log of the academy asking a child's own club to pass a consent
 * request on to the family: when, who was asked, what came back. It holds
 * **no family-identifying column** — that is the point of the step, and a
 * reviewer should refuse a PR that adds one.
 *
 * Follows `ProspectVisitObservationsRepository`, the module's existing
 * dated-log repository: every query filters `club_id` (CLAUDE.md §4), and
 * `$wpdb` is read with `global` inside each method rather than held as a
 * property, which is what keeps the level-8 gate's `literal-string`
 * narrowing on `$wpdb->prefix` intact.
 */
class ProspectConsentRequestsRepository {

    private const TABLE = 'tt_prospect_consent_requests';

    /**
     * Record that the academy asked. Returns the new entry's id, or 0 when
     * the write failed or the arguments do not describe a request.
     */
    public function create( int $prospect_id, string $asked_at, string $asked_of, string $outcome, string $notes = '' ): int {
        global $wpdb;
        if ( $prospect_id <= 0 ) return 0;

        $asked_of = trim( $asked_of );
        if ( $asked_of === '' ) return 0;
        if ( ! ConsentOutcome::isValid( $outcome ) ) return 0;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $asked_at ) ) return 0;

        $ok = $wpdb->insert( $wpdb->prefix . self::TABLE, [
            'uuid'        => wp_generate_uuid4(),
            'club_id'     => CurrentClub::id(),
            'prospect_id' => $prospect_id,
            'asked_at'    => $asked_at,
            'asked_of'    => $asked_of,
            'outcome'     => $outcome,
            'notes'       => $notes !== '' ? $notes : null,
            'created_by'  => get_current_user_id(),
        ] );
        if ( ! $ok ) return 0;

        $id = (int) $wpdb->insert_id;
        self::announce( $prospect_id, $id, $outcome );
        return $id;
    }

    /**
     * Change what an existing request came back with. Only the outcome and
     * the notes move: the date and the club that was asked are the record
     * of what happened, and rewriting them would rewrite history.
     */
    public function setOutcome( int $id, string $outcome, ?string $notes = null ): bool {
        global $wpdb;
        if ( $id <= 0 || ! ConsentOutcome::isValid( $outcome ) ) return false;

        $data = [ 'outcome' => $outcome ];
        if ( $notes !== null ) {
            $data['notes'] = $notes !== '' ? $notes : null;
        }

        $ok = false !== $wpdb->update(
            $wpdb->prefix . self::TABLE,
            $data,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( ! $ok ) return false;

        $row = $this->findById( $id );
        self::announce( (int) ( $row['prospect_id'] ?? 0 ), $id, $outcome );
        return true;
    }

    /**
     * #4017 — say that this prospect's consent state moved.
     *
     * Fired from the repository rather than from each caller, because there
     * are three: the REST route, the workflow form the scout fills in, and
     * the demo generator. `prospects.consent_awaiting` is state-derived, so
     * one of those three quietly not announcing would not break anything
     * loudly — the alert would simply stay up to an hour stale, which is the
     * failure this hook exists to avoid.
     *
     * Fires on any outcome, not only on a move away from `awaiting`. A
     * correction back to `awaiting` is equally a reason to re-derive, and the
     * listener only ever asks the definition to look again.
     */
    private static function announce( int $prospect_id, int $entry_id, string $outcome ): void {
        if ( $prospect_id <= 0 || ! function_exists( 'do_action' ) ) return;

        /**
         * A consent request's outcome was recorded or changed.
         *
         * @param int    $prospect_id The prospect the request belongs to.
         * @param int    $entry_id    The consent-request entry.
         * @param string $outcome     What it came back with.
         */
        do_action( 'tt_prospect_consent_outcome_recorded', $prospect_id, $entry_id, $outcome );
    }

    /**
     * One entry, as an associative row — its caller reads a handful of
     * fields, and a `?object` return has no declared properties to read.
     *
     * @return array<string,mixed>|null
     */
    public function findById( int $id ): ?array {
        global $wpdb; $p = $wpdb->prefix;
        if ( $id <= 0 ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_prospect_consent_requests WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Every request for one prospect, most recent first — the trail the
     * scout is asked for five times over six weeks.
     *
     * @return list<array<string,mixed>>
     */
    public function forProspect( int $prospect_id ): array {
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 ) return [];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_prospect_consent_requests
              WHERE club_id = %d AND prospect_id = %d
           ORDER BY asked_at DESC, id DESC",
            CurrentClub::id(), $prospect_id
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }

    /**
     * The most recent request for one prospect, or null when the academy
     * has not asked.
     *
     * @return array<string,mixed>|null
     */
    public function latestForProspect( int $prospect_id ): ?array {
        $rows = $this->forProspect( $prospect_id );
        return $rows[0] ?? null;
    }

    /** Has any request for this prospect come back `agreed`? */
    public function hasAgreed( int $prospect_id ): bool {
        global $wpdb; $p = $wpdb->prefix;
        if ( $prospect_id <= 0 ) return false;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_prospect_consent_requests
              WHERE club_id = %d AND prospect_id = %d AND outcome = %s",
            CurrentClub::id(), $prospect_id, ConsentOutcome::AGREED
        ) ) > 0;
    }

    /**
     * #4017 — how long each of these prospects has been waiting for an
     * answer, keyed by prospect id.
     *
     * A prospect with no open request, or with consent already on record by
     * either route, is absent from the result rather than present with a
     * zero: "not waiting" and "asked today" are different answers and a list
     * has to show them differently.
     *
     * One query for the whole page. The list this feeds shows up to a
     * hundred rows, and `forProspect()` per row would be a hundred queries
     * for one column.
     *
     * @param list<int> $prospect_ids
     * @return array<int,int> prospect id => days waiting
     */
    public function waitingDaysFor( array $prospect_ids ): array {
        global $wpdb; $p = $wpdb->prefix;

        $ids = [];
        foreach ( $prospect_ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 ) $ids[] = $id;
        }
        $ids = array_values( array_unique( $ids ) );
        if ( $ids === [] || ! self::tableExists() ) return [];

        // Every id is an int by construction, so the list is safe to inline.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT cr.prospect_id AS prospect_id,
                    DATEDIFF( CURDATE(), MIN( cr.asked_at ) ) AS waiting_days
               FROM {$p}tt_prospect_consent_requests cr
              WHERE cr.club_id = %d
                AND cr.outcome = %s
                AND cr.prospect_id IN (" . implode( ',', $ids ) . ")
                AND NOT EXISTS (
                        SELECT 1 FROM {$p}tt_prospect_consent_requests agreed
                         WHERE agreed.prospect_id = cr.prospect_id
                           AND agreed.club_id = cr.club_id
                           AND agreed.outcome = %s
                    )
           GROUP BY cr.prospect_id",
            CurrentClub::id(),
            ConsentOutcome::AWAITING,
            ConsentOutcome::AGREED
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $id = (int) ( $row['prospect_id'] ?? 0 );
            if ( $id <= 0 ) continue;
            $out[ $id ] = max( 0, (int) ( $row['waiting_days'] ?? 0 ) );
        }
        return $out;
    }

    /** Does the table exist yet? Installs that predate migration 0284 answer no. */
    public static function tableExists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public function deleteForProspect( int $prospect_id ): int {
        global $wpdb;
        if ( $prospect_id <= 0 ) return 0;
        return (int) $wpdb->delete( $wpdb->prefix . self::TABLE, [
            'prospect_id' => $prospect_id,
            'club_id'     => CurrentClub::id(),
        ] );
    }
}
