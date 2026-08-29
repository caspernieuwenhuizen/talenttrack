<?php
namespace TT\Infrastructure\Goals;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GoalsRepository — read-only repository for goal records.
 *
 * #1077 — module-by-module rollout of #806's architectural sweep.
 * Worked example: `Infrastructure\Evaluations\EvaluationsRepository`
 * (v4.17.2 / #1081). The goals slice differs from Evaluations in one
 * mechanical detail: goal status + priority are stored as code
 * strings on `tt_goals` (no FK into `tt_lookups`), so the per-row
 * hydration calls `LabelTranslator::goalStatus()` /
 * `LabelTranslator::goalPriority()` instead of `LookupTranslator::name()`.
 * The contract is the same — view code echoes `$row->status_localised`
 * and `$row->priority_localised`; bypass becomes structurally
 * impossible.
 *
 * Per-row shape (additive to whatever `SELECT *` returned):
 *
 *   `status`                  raw code (back-compat — KPI groupings)
 *   `status_localised`        user-facing label in active locale
 *   `priority`                raw code (back-compat)
 *   `priority_localised`      user-facing label in active locale
 *
 * Mirror pattern when shipping #1078 (Activities), #1079 (Players),
 * #1080 (Pdp).
 */
class GoalsRepository {

    /**
     * Terminal goal statuses. A goal carrying one of these is finished —
     * it is history, not something the player is still working on.
     *
     * #3033 — the plugin had grown four hand-written copies of this list
     * and one surface with no status filter at all, so the Goals tab
     * badge, its list header and the profile KPI could each show a
     * different number for the same player. Everything that asks "is
     * this goal active?" now goes through `activeStatusClause()` /
     * `closedStatusClause()` below, so the answer cannot drift again.
     *
     * @var list<string>
     */
    public const CLOSED_STATUSES = [ GoalStatus::COMPLETED, GoalStatus::CANCELLED ];

    /**
     * SQL predicate for "this goal is still being worked on".
     *
     * Contains no caller-supplied data — the status codes are class
     * constants — so it is safe to concatenate into a prepared statement
     * without a placeholder.
     */
    public static function activeStatusClause( string $alias = '' ): string {
        return sprintf(
            '( %1$s IS NULL OR %1$s NOT IN ( %2$s ) )',
            self::statusColumn( $alias ),
            self::closedStatusList()
        );
    }

    /** The exact negation of `activeStatusClause()`, so no goal falls in neither list. */
    public static function closedStatusClause( string $alias = '' ): string {
        return sprintf(
            '( %1$s IS NOT NULL AND %1$s IN ( %2$s ) )',
            self::statusColumn( $alias ),
            self::closedStatusList()
        );
    }

    /**
     * Club scope for `tt_goals`. Tolerates a NULL `club_id` because rows
     * written before the tenancy column existed carry one.
     */
    public static function clubScopeClause( string $alias = '' ): string {
        $col = $alias !== '' ? "{$alias}.club_id" : 'club_id';
        return sprintf( '( %1$s = %2$d OR %1$s IS NULL )', $col, CurrentClub::id() );
    }

    private static function statusColumn( string $alias ): string {
        return $alias !== '' ? "{$alias}.status" : 'status';
    }

    private static function closedStatusList(): string {
        return implode( ', ', array_map(
            static fn( string $code ): string => "'" . $code . "'",
            self::CLOSED_STATUSES
        ) );
    }

    /**
     * Which principles a squad currently has open goals on (#2497).
     *
     * The Training module's generator ranks candidate exercises by how many
     * of a squad's open development targets they touch. Rather than let it
     * reach into `tt_goals` and `tt_goal_links` itself, Goals answers the
     * question — the module owns its own data, and the same answer is what
     * a future SaaS front end would ask for (CLAUDE.md §4, epic #2493 D13).
     *
     * "Open" means not archived and not in a terminal status. A goal
     * carries its principle two ways, and both count:
     *
     *   - `tt_goals.linked_principle_id` — the single principle a goal
     *     supports (migration 0015)
     *   - `tt_goal_links` with `link_type = 'principle'` — the polymorphic
     *     link table added by the PDP cycle (migration 0031)
     *
     * @param list<int> $player_ids
     * @return array<int, list<int>> player id => principle ids, deduplicated
     */
    public function openPrincipleTargetsForPlayers( array $player_ids ): array {
        $player_ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
        if ( ! $player_ids ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $ph = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );

        // One pass over both link shapes. COALESCE picks whichever of the
        // two carries a principle for that row; the outer filter drops the
        // goals that carry neither.
        $sql = "SELECT g.player_id,
                       COALESCE(gl.link_id, g.linked_principle_id) AS principle_id
                  FROM {$p}tt_goals g
             LEFT JOIN {$p}tt_goal_links gl
                    ON gl.goal_id = g.id
                   AND gl.link_type = 'principle'
                   AND gl.club_id = g.club_id
                 WHERE g.player_id IN ({$ph})
                   AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                   AND ( g.club_id = %d OR g.club_id IS NULL )
                   AND " . self::activeStatusClause( 'g' ) . "
                HAVING principle_id IS NOT NULL";

        $params = array_merge( $player_ids, [ $club_id ] );

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $player    = (int) $row->player_id;
            $principle = (int) $row->principle_id;
            if ( $player <= 0 || $principle <= 0 ) continue;
            $out[ $player ][ $principle ] = true;
        }

        return array_map(
            static fn( array $set ): array => array_map( 'intval', array_keys( $set ) ),
            $out
        );
    }

    /**
     * Active goals for a player, newest-first. Used by the player's
     * "My goals" surface.
     *
     * @return array<int, object>
     */
    public function listForPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND ( g.club_id = %d OR g.club_id IS NULL )
              ORDER BY g.created_at DESC",
            $player_id,
            $club_id
        ) );

        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * #1851 — the player's top active goals for a "Your focus" preview:
     * non-archived, not completed/cancelled, nearest due date first,
     * undated last. Used by the state-aware PDP surface and the
     * development home (#1850) so both show the same short focus list
     * without re-deriving the query in a view.
     *
     * @return array<int, object>
     */
    public function topActiveForPlayer( int $player_id, int $limit = 3 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 20, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND ( g.club_id = %d OR g.club_id IS NULL )
                AND " . self::activeStatusClause( 'g' ) . "
              ORDER BY ( g.due_date IS NULL ), g.due_date ASC, g.id DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );

        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * Single goal scoped to a player. Returns null if the goal
     * doesn't exist, doesn't belong to the player, or is archived.
     *
     * Used by the player's "My goals → detail" surface so a player
     * can't drill into a goal that belongs to someone else by
     * tweaking the URL.
     */
    public function findForPlayer( int $goal_id, int $player_id ): ?object {
        if ( $goal_id <= 0 || $player_id <= 0 ) return null;

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.id = %d
                AND g.player_id = %d
                AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND ( g.club_id = %d OR g.club_id IS NULL )
              LIMIT 1",
            $goal_id,
            $player_id,
            $club_id
        ) );

        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * #1358 — the player-profile Goals tab list, ordered by urgency:
     * dated goals first by nearest due date, undated last by recency.
     *
     * #3033 — completed and cancelled goals are no longer in here. The
     * tab heading says "Active goals", so the list holds active goals;
     * the finished ones come back from `listClosedForPlayer()` into
     * their own collapsed section on the same tab.
     *
     * @return array<int, object>
     */
    public function listActiveByDueDateForPlayer( int $player_id, int $limit = 50 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND " . self::clubScopeClause( 'g' ) . "
                AND " . self::activeStatusClause( 'g' ) . "
              ORDER BY g.due_date IS NULL, g.due_date ASC, g.created_at DESC
              LIMIT %d",
            $player_id, $limit
        ) );
        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * #3033 — the finished half of the player's goal history: completed
     * and cancelled goals, most recently closed first. A finished goal is
     * part of what the player has done (CLAUDE.md §1), so it stays on the
     * player's file rather than being reachable only from the goals list.
     *
     * @return array<int, object>
     */
    public function listClosedForPlayer( int $player_id, int $limit = 50 ): array {
        if ( $player_id <= 0 ) return [];
        $limit = max( 1, min( 100, $limit ) );

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND " . self::clubScopeClause( 'g' ) . "
                AND " . self::closedStatusClause( 'g' ) . "
              ORDER BY g.updated_at DESC, g.id DESC
              LIMIT %d",
            $player_id, $limit
        ) );
        if ( ! is_array( $rows ) ) return [];
        foreach ( $rows as $row ) {
            self::hydrate( $row );
        }
        return $rows;
    }

    /**
     * #1358 — count of active (non-archived, not completed/cancelled)
     * goals, for the player-profile "Goals" KPI, the Goals tab badge and
     * the tab's list heading. One number, three surfaces (#3033).
     */
    public function countActiveForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
                AND " . self::clubScopeClause() . "
                AND " . self::activeStatusClause(),
            $player_id
        ) );
    }

    /** #3033 — count behind the collapsed "Completed goals" section heading. */
    public function countClosedForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
                AND " . self::clubScopeClause() . "
                AND " . self::closedStatusClause(),
            $player_id
        ) );
    }

    /**
     * #1358 — count of active goals due within the next `$days` days,
     * for the KPI's "N due soon" hint.
     */
    public function countDueSoonForPlayer( int $player_id, int $days = 7 ): int {
        if ( $player_id <= 0 ) return 0;
        $days = max( 1, $days );

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
                AND ( club_id = %d OR club_id IS NULL )
                AND due_date IS NOT NULL
                AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL %d DAY)
                AND " . self::activeStatusClause(),
            $player_id, CurrentClub::id(), $days
        ) );
    }

    /**
     * #1385 — count of completed (non-archived) goals for a player, for
     * the `MyGoalsCompletedSeason` player KPI.
     */
    public function countCompletedForPlayer( int $player_id ): int {
        if ( $player_id <= 0 ) return 0;

        global $wpdb;
        $p = $wpdb->prefix;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d AND " . ArchiveRepository::filterClause( 'active' ) . "
                AND ( club_id = %d OR club_id IS NULL )
                AND status = 'completed'",
            $player_id, CurrentClub::id()
        ) );
    }

    /**
     * #1385 — the player's next milestone: nearest-due active goal
     * (non-archived, not completed/cancelled, with a due date). Returns
     * null when the player has no dated active goal. Powers
     * `MyNextMilestone`.
     */
    public function nextDueActiveGoalForPlayer( int $player_id ): ?object {
        if ( $player_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT g.*
               FROM {$p}tt_goals g
              WHERE g.player_id = %d AND " . ArchiveRepository::filterClause( 'active', 'g' ) . "
                AND ( g.club_id = %d OR g.club_id IS NULL )
                AND g.due_date IS NOT NULL
                AND " . self::activeStatusClause( 'g' ) . "
              ORDER BY g.due_date ASC
              LIMIT 1",
            $player_id, CurrentClub::id()
        ) );

        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * Decorate a `tt_goals` row in place with `status_localised` and
     * `priority_localised`. Raw fields stay for back-compat — KPI
     * aggregations + filter dropdowns key off the canonical codes.
     */
    private static function hydrate( object $row ): void {
        $row->status_localised   = LabelTranslator::goalStatus( (string) ( $row->status ?? '' ) );
        $row->priority_localised = LabelTranslator::goalPriority( (string) ( $row->priority ?? '' ) );
    }
}
