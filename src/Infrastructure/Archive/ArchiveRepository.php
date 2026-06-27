<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\People\PersonDeletionCascade;
use TT\Infrastructure\Players\PlayerDeletionCascade;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\RecycleBin\RecycleBinAuditActions;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;

/**
 * ArchiveRepository — archive / restore / hard-delete across entity tables.
 *
 * Sprint v2.17.0. The archive pattern is uniform across tables — each
 * entity has `archived_at DATETIME NULL` + `archived_by BIGINT NULL`
 * columns (see migration 0010). This repository encapsulates that
 * pattern so UI code doesn't need to know which table or columns.
 *
 * Supported entities — identified by a short string key that maps to
 * a DB table name:
 *   'player'     → tt_players
 *   'team'       → tt_teams
 *   'evaluation' → tt_evaluations
 *   'activity'    → tt_activities
 *   'goal'       → tt_goals
 *   'person'     → tt_people
 *
 * All methods accept arrays of ids for bulk operations. Empty/invalid
 * ids are filtered out; methods return the number of rows actually
 * affected.
 */
class ArchiveRepository {

    /** Entity-key → table-name (without prefix) */
    private const TABLE_MAP = [
        'player'     => 'tt_players',
        'team'       => 'tt_teams',
        'evaluation' => 'tt_evaluations',
        'activity'    => 'tt_activities',
        'goal'       => 'tt_goals',
        'person'     => 'tt_people',
        'tournament' => 'tt_tournaments',
        'trial_case' => 'tt_trial_cases',
        'holiday'       => 'tt_holidays',
        'test_training' => 'tt_test_trainings',
        'trial_track'   => 'tt_trial_tracks',
        'vct_exercise'  => 'tt_vct_exercises',
        'custom_widget' => 'tt_custom_widgets',
        'injury'        => 'tt_player_injuries',
        'scheduled_report' => 'tt_scheduled_reports',
        'measurement_definition' => 'tt_measurement_definitions',
        'measurement_session'    => 'tt_measurement_sessions',
        'measurement_target'     => 'tt_measurement_targets',
        'measurement_result'     => 'tt_measurement_results',
        'player_attribute_def'   => 'tt_player_attribute_defs',
    ];

    /**
     * tt_config key holding the per-club purge window (seeded by migration
     * 0186, #2020). Read with a 30-day fallback so a club whose seed row is
     * absent still gets a sane window.
     */
    private const RETENTION_CONFIG_KEY  = 'tt_recycle_bin_retention_days';
    private const RETENTION_DEFAULT_DAYS = 30;

    /** @var AuditService */
    private $audit;

    /** @var ConfigService */
    private $config;

    /**
     * The audit + config collaborators are injectable so tests can stub
     * them, but default to plain instances — the recycle-bin lifecycle must
     * always write an audit trail and read the retention window without the
     * caller wiring dependencies. AuditService self-no-ops when the
     * audit_log feature toggle is off; that's the only place toggles gate
     * the write.
     */
    public function __construct( ?AuditService $audit = null, ?ConfigService $config = null ) {
        $this->audit  = $audit  ?? new AuditService();
        $this->config = $config ?? new ConfigService();
    }

    // Public API

    /**
     * The entity-key → table-name map (table names un-prefixed). Exposed
     * read-only so callers that need the canonical list of archivable /
     * bin-archivable entities (the recycle-bin audit vocabulary #2020, the
     * bin list view #2022) anchor to one source of truth rather than
     * re-listing the entities. Returns a copy — the constant stays private.
     *
     * @return array<string,string>
     */
    public static function entityMap(): array {
        return self::TABLE_MAP;
    }

    /**
     * Archive N rows. Stamps archived_at + archived_by. Rows that are
     * already archived are left untouched (idempotent).
     *
     * @param string $entity  'player'|'team'|'evaluation'|'activity'|'goal'|'person'
     * @param int[]  $ids
     * @param int    $by_user_id  wp user id of the person archiving
     * @return int  Number of rows affected.
     */
    public function archive( string $entity, array $ids, int $by_user_id ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET archived_at = %s, archived_by = %d
                WHERE id IN ({$ph}) AND club_id = %d AND archived_at IS NULL";
        $args = array_merge( [ current_time( 'mysql' ), $by_user_id ], $ids, [ CurrentClub::id() ] );

        // #1274 PR2 — player archive cascades to PDP files so PDPs for
        // players who leave the academy stop appearing on dashboards
        // and KPI rollups. Wrapped in a transaction so a PDP-cascade
        // failure doesn't leave the player archived without their
        // PDPs (or vice versa). Repository pattern keeps the cascade
        // in the same layer as the entity-write itself — UI code
        // doesn't need to know about it.
        if ( $entity === 'player' ) {
            $wpdb->query( 'START TRANSACTION' );
            try {
                $count = (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
                if ( class_exists( PdpFilesRepository::class ) ) {
                    $pdp_repo = new PdpFilesRepository();
                    foreach ( $ids as $player_id ) {
                        $pdp_repo->archiveAllForPlayer( (int) $player_id );
                    }
                }
                $wpdb->query( 'COMMIT' );
                return $count;
            } catch ( \Throwable $e ) {
                $wpdb->query( 'ROLLBACK' );
                throw $e;
            }
        }

        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
    }

    /**
     * Restore N archived rows. Clears archived_at + archived_by.
     *
     * @param string $entity
     * @param int[]  $ids
     * @return int
     */
    public function restore( string $entity, array $ids ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET archived_at = NULL, archived_by = NULL
                WHERE id IN ({$ph}) AND club_id = %d AND archived_at IS NOT NULL";
        $args = array_merge( $ids, [ CurrentClub::id() ] );
        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
    }

    /**
     * Hard-delete N rows. Irreversible. Caller must verify permissions
     * before calling this — anyone holding the repo can wipe data.
     *
     * Persons (#1138) and players (#1355) route through dedicated
     * cascade services. Other entities delete exactly the entity rows:
     * dependent rows are the caller's concern. Foreign-key constraints
     * would enforce cascades at the DB level; currently TalentTrack
     * tables don't declare FKs.
     *
     * @param string $entity
     * @param int[]  $ids
     * @return int
     */
    public function deletePermanently( string $entity, array $ids ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        // #1138 — person delete must cascade across 9 reference tables.
        // Route through the dedicated service so the raw DELETE doesn't
        // strand orphan team-role / staff-dev / scope-grant rows.
        if ( $entity === 'person' ) {
            $result = ( new PersonDeletionCascade() )->cascade( $ids );
            return (int) $result['deleted'];
        }

        // #1355 — player delete cascades across every player-keyed
        // table (incl. injuries — a minor's medical history) so a
        // right-to-erasure hard-delete actually erases.
        if ( $entity === 'player' ) {
            $result = ( new PlayerDeletionCascade() )->cascade( $ids );
            return (int) $result['deleted'];
        }

        // #1783 — referential-integrity-checked delete for entities with
        // a declarative cascade plan (evaluation, goal, team, activity).
        // Fail-closed: GenericCascadeDeleter throws DeleteBlockedException
        // (no writes) when an undeclared reference would be orphaned, so a
        // raw DELETE can no longer strand child rows. The exception
        // propagates to the caller, which surfaces the dependency report.
        if ( CascadeRegistry::has( $entity ) ) {
            $result = ( new GenericCascadeDeleter() )->cascade( $entity, $ids );
            return (int) $result['deleted'];
        }

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "DELETE FROM {$table} WHERE id IN ({$ph}) AND club_id = %d";
        $args = array_merge( $ids, [ CurrentClub::id() ] );
        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
    }

    // Recycle-bin lifecycle (#2021, epic #2018)

    /**
     * Move N archived rows into the recycle bin (Archived → Trashed).
     * Stamps `trashed_at` + `trashed_by`. Only rows that are already
     * archived (`archived_at IS NOT NULL`) move — a row that was never
     * archived cannot be trashed directly, so the soft-delete tiers stay
     * ordered (active → archived → bin). Already-trashed rows are left
     * untouched (idempotent). Club-scoped on every branch.
     *
     * Permission is the caller's responsibility at the REST/view boundary
     * (cap `tt_edit_settings`); this domain method assumes an authorized
     * caller and enforces only the tenant + state invariants. It writes a
     * `{entity}.trashed` audit row per call so the act survives the eventual
     * purge that destroys `trashed_by`.
     *
     * @param string $entity
     * @param int[]  $ids
     * @param int    $by_user_id  wp user id of the person trashing
     * @return int  Number of rows actually moved to the bin.
     */
    public function trash( string $entity, array $ids, int $by_user_id ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET trashed_at = %s, trashed_by = %d
                WHERE id IN ({$ph})
                  AND {$this->clubScope()}
                  AND archived_at IS NOT NULL
                  AND trashed_at IS NULL";
        $args = array_merge( [ current_time( 'mysql' ), $by_user_id ], $ids );
        $n = (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

        if ( $n > 0 ) {
            $this->audit->record(
                RecycleBinAuditActions::trashed( $entity ),
                $entity,
                count( $ids ) === 1 ? (int) $ids[0] : 0,
                [ 'ids' => $ids, 'affected' => $n, 'by_user' => $by_user_id ]
            );
        }
        return $n;
    }

    /**
     * Restore N trashed rows out of the bin (Trashed → Archived, NOT
     * active). Clears `trashed_at` + `trashed_by`; `archived_at` is left
     * intact so the row returns to the archive tier it came from rather
     * than silently reactivating. Club-scoped.
     *
     * Caller must hold `tt_manage_recycle_bin` (verified at the boundary);
     * this method enforces the tenant + state invariants. Writes a
     * `{entity}.restored` audit row.
     *
     * @param string $entity
     * @param int[]  $ids
     * @param int    $by_user_id
     * @return int  Number of rows restored to the archive.
     */
    public function restoreFromTrash( string $entity, array $ids, int $by_user_id ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET trashed_at = NULL, trashed_by = NULL
                WHERE id IN ({$ph})
                  AND {$this->clubScope()}
                  AND trashed_at IS NOT NULL";
        $args = $ids;
        $n = (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );

        if ( $n > 0 ) {
            $this->audit->record(
                RecycleBinAuditActions::restored( $entity ),
                $entity,
                count( $ids ) === 1 ? (int) $ids[0] : 0,
                [ 'ids' => $ids, 'affected' => $n, 'by_user' => $by_user_id ]
            );
        }
        return $n;
    }

    /**
     * Permanently purge N trashed rows (Trashed → gone). The ONLY method
     * that issues a real DELETE, and it does so exclusively through the
     * existing `deletePermanently()` — so the fail-closed cascade services
     * (PlayerDeletionCascade / PersonDeletionCascade / GenericCascadeDeleter)
     * run, and a `DeleteBlockedException` from an undeclared reference
     * propagates to the caller unchanged.
     *
     * Only rows that are actually in the bin (`trashed_at IS NOT NULL`) and
     * owned by the current club are eligible: ids that are merely archived,
     * active, or belong to another tenant are filtered out before the
     * cascade runs, so purge can never reach outside the bin.
     *
     * Caller must hold `tt_manage_recycle_bin` (verified at the boundary).
     * Writes a `{entity}.purged` audit row carrying the cascade row counts
     * BEFORE the rows are gone, because `trashed_by` and the rows themselves
     * are destroyed by the purge.
     *
     * @param string $entity
     * @param int[]  $ids
     * @param int    $by_user_id
     * @return int  Number of entity rows actually deleted.
     */
    public function purge( string $entity, array $ids, int $by_user_id ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        // Restrict to ids that are genuinely in this club's bin. deletePermanently()
        // re-applies club_id on its final DELETE, but it does NOT check
        // trashed_at — so without this gate an archived-but-not-trashed id
        // could be hard-deleted straight through the purge path, bypassing
        // the bin tier. Treat anything outside the bin as not-found.
        $eligible = $this->trashedIdsIn( $entity, $ids );
        if ( empty( $eligible ) ) return 0;

        // Route through the existing cascade-aware delete. Any
        // DeleteBlockedException propagates unchanged — purge never
        // swallows a fail-closed refusal.
        $result = $this->deletePermanentlyWithReport( $entity, $eligible );
        $deleted = (int) $result['deleted'];

        if ( $deleted > 0 ) {
            $this->audit->record(
                RecycleBinAuditActions::purged( $entity ),
                $entity,
                count( $eligible ) === 1 ? (int) $eligible[0] : 0,
                [
                    'ids'       => $eligible,
                    'deleted'   => $deleted,
                    'by_user'   => $by_user_id,
                    // Cascade collateral so the audit log records what else
                    // the purge removed/cleared — the row counts vanish with
                    // the rows otherwise.
                    'per_table' => $result['per_table'] ?? [],
                    'nulled'    => $result['nulled'] ?? [],
                    'zeroed'    => $result['zeroed'] ?? [],
                ]
            );
        }
        return $deleted;
    }

    /**
     * Run the existing cascade-aware permanent delete and return the full
     * cascade report (deleted + per_table + nulled + zeroed). Mirrors
     * `deletePermanently()` exactly but surfaces the collateral counts the
     * cascade services compute, which `deletePermanently()` discards by
     * returning only the int. Player/Person cascades omit `zeroed`; the
     * generic cascade includes it — the missing key is normalised to [].
     *
     * @param string $entity
     * @param int[]  $ids  pre-validated, club-scoped, in-bin ids
     * @return array{deleted:int, per_table:array<string,int>, nulled:array<string,int>, zeroed:array<string,int>}
     */
    private function deletePermanentlyWithReport( string $entity, array $ids ): array {
        $base = [ 'deleted' => 0, 'per_table' => [], 'nulled' => [], 'zeroed' => [] ];

        if ( $entity === 'person' ) {
            return array_merge( $base, ( new PersonDeletionCascade() )->cascade( $ids ) );
        }
        if ( $entity === 'player' ) {
            return array_merge( $base, ( new PlayerDeletionCascade() )->cascade( $ids ) );
        }
        if ( CascadeRegistry::has( $entity ) ) {
            return array_merge( $base, ( new GenericCascadeDeleter() )->cascade( $entity, $ids ) );
        }

        // Entities with no cascade plan: a plain club-scoped DELETE, same as
        // deletePermanently()'s fallback branch.
        global $wpdb;
        $table = $this->resolveTable( $entity );
        $ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql   = "DELETE FROM {$table} WHERE id IN ({$ph}) AND club_id = %d";
        $deleted = (int) $wpdb->query( $wpdb->prepare( $sql, ...array_merge( $ids, [ CurrentClub::id() ] ) ) );
        return array_merge( $base, [ 'deleted' => $deleted ] );
    }

    /**
     * Subset of $ids that are in THIS club's bin (`trashed_at IS NOT NULL`
     * AND `club_id` = current). The gate purge() relies on so it can never
     * hard-delete a row that isn't actually trashed or belongs to another
     * tenant.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function trashedIdsIn( string $entity, array $ids ): array {
        $table = $this->resolveTable( $entity );
        if ( $table === null || empty( $ids ) ) return [];
        global $wpdb;
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT id FROM {$table}
                WHERE id IN ({$ph}) AND {$this->clubScope()} AND trashed_at IS NOT NULL";
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, ...$ids ) );
        return array_map( 'intval', is_array( $rows ) ? $rows : [] );
    }

    // Caller-aware lookup + ownership backstop (#2021 security #1, #2)

    /**
     * Load one row across all soft-delete states, returning the row plus a
     * computed `state` (`active` | `archived` | `trashed`), with the
     * trashed-visibility gate enforced HERE so no view can leak a trashed
     * minor's PII by forgetting the check.
     *
     * Visibility rules:
     *   - active / archived row → returned (callers still own the
     *     entity-read capability check; existence is not itself sensitive
     *     for a non-trashed row).
     *   - trashed row → returned ONLY when the current user holds
     *     `tt_manage_recycle_bin`. Otherwise `null`, so the caller renders a
     *     404 — never a permission-denied page, which would confirm the
     *     trashed record exists.
     *
     * Deliberately does NOT build on `QueryHelpers::get_player()`: that
     * helper dropped its `club_id` clause and never filters `archived_at` /
     * `trashed_at` (QueryHelpers.php ~325), so reusing it would leak trashed
     * rows across the visibility gate AND across tenants. This is a fresh,
     * club-scoped query.
     *
     * @return array{row:object, state:string}|null
     */
    public function findIncludingArchived( string $entity, int $id ): ?array {
        $table = $this->resolveTable( $entity );
        if ( $table === null || $id <= 0 ) return null;

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND {$this->clubScope()}",
            $id
        ) );
        if ( ! $row ) return null;

        $trashed = isset( $row->trashed_at ) && $row->trashed_at !== null;
        if ( $trashed && ! current_user_can( 'tt_manage_recycle_bin' ) ) {
            // Trashed record + caller can't manage the bin → behave as a 404.
            return null;
        }

        $state = $trashed
            ? 'trashed'
            : ( ( isset( $row->archived_at ) && $row->archived_at !== null ) ? 'archived' : 'active' );

        return [ 'row' => $row, 'state' => $state ];
    }

    /**
     * IDOR backstop for REST `permission_callback`s: does $id exist in the
     * current club at all? `SELECT id WHERE id=%d AND club_id=%d`. A 0-row
     * result is a not-found (the caller maps it to 404), NEVER a success —
     * so a forged id from another tenant can't slip a restore/purge through
     * merely because the mutation's own WHERE would have matched zero rows.
     *
     * Intentionally state-agnostic (active / archived / trashed all count
     * as "owned"): it answers ownership, not visibility. The visibility gate
     * is `findIncludingArchived()`; the cap gate is the controller.
     */
    public function ownedByCurrentClub( string $entity, int $id ): bool {
        $table = $this->resolveTable( $entity );
        if ( $table === null || $id <= 0 ) return false;
        global $wpdb;
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND {$this->clubScope()} LIMIT 1",
            $id
        ) );
        return $found !== null;
    }

    // Cross-entity bin aggregation (#2021 security #3)

    /**
     * Every trashed row across every bin-archivable entity, club-scoped on
     * EACH entity branch via one shared query builder so no branch can ever
     * omit the tenant clause. For each row returns its id, when/by-whom it
     * was trashed, the user's display name, and the computed days remaining
     * until the purge cron (#2025) removes it (retention window from
     * `tt_config` per club, default 30).
     *
     * @return array<string, list<array{
     *   id:int,
     *   trashed_at:string,
     *   trashed_by:int,
     *   trashed_by_name:string,
     *   days_until_purge:int
     * }>>  entity-key => trashed rows
     */
    public function trashedAcrossEntities(): array {
        $retention = $this->retentionDays();
        $out = [];
        foreach ( array_keys( self::TABLE_MAP ) as $entity ) {
            $rows = $this->trashedRowsFor( $entity, $retention );
            if ( ! empty( $rows ) ) {
                $out[ $entity ] = $rows;
            }
        }
        return $out;
    }

    /**
     * Trashed rows for ONE entity. The single per-entity query builder the
     * aggregation drives — every call appends `QueryHelpers::clubScopeWhere()`
     * so the tenant clause is applied centrally and can't be dropped per
     * entity. Public so the bin list view (#2022) can page one entity at a
     * time without re-implementing the club scope.
     *
     * @return list<array{
     *   id:int,
     *   trashed_at:string,
     *   trashed_by:int,
     *   trashed_by_name:string,
     *   days_until_purge:int
     * }>
     */
    public function trashedRowsFor( string $entity, ?int $retention_days = null ): array {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return [];
        $retention = $retention_days ?? $this->retentionDays();

        global $wpdb;
        $sql = "SELECT t.id, t.trashed_at, t.trashed_by, u.display_name AS trashed_by_name
                FROM {$table} t
                LEFT JOIN {$wpdb->users} u ON u.ID = t.trashed_by
                WHERE t.trashed_at IS NOT NULL AND " . QueryHelpers::clubScopeWhere( 't' ) . "
                ORDER BY t.trashed_at DESC, t.id DESC";
        $rows = $wpdb->get_results( $sql );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'               => (int) $r->id,
                'trashed_at'       => (string) $r->trashed_at,
                'trashed_by'       => (int) $r->trashed_by,
                'trashed_by_name'  => (string) ( $r->trashed_by_name ?? '' ),
                'days_until_purge' => $this->daysUntilPurge( (string) $r->trashed_at, $retention ),
            ];
        }
        return $out;
    }

    /**
     * Retention window (days) for the current club. Read from tt_config
     * (seeded by migration 0186); falls back to 30 when unset or non-positive.
     */
    public function retentionDays(): int {
        $days = $this->config->getInt( self::RETENTION_CONFIG_KEY, self::RETENTION_DEFAULT_DAYS );
        return $days > 0 ? $days : self::RETENTION_DEFAULT_DAYS;
    }

    /**
     * Days left before a row trashed at $trashed_at is purged, given the
     * retention window. Clamped at 0 (a row past its window is "0 days" —
     * due for the next purge sweep — never negative).
     */
    private function daysUntilPurge( string $trashed_at, int $retention_days ): int {
        $trashed_ts = strtotime( $trashed_at );
        if ( $trashed_ts === false ) return $retention_days;
        $purge_ts   = $trashed_ts + ( $retention_days * DAY_IN_SECONDS );
        $remaining  = (int) ceil( ( $purge_ts - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );
        return max( 0, $remaining );
    }

    /**
     * Club-scope fragment for the recycle-bin UPDATE / SELECT statements in
     * this class. Single source so every lifecycle query is tenant-bound the
     * same way.
     */
    private function clubScope(): string {
        return QueryHelpers::clubScopeWhere();
    }

    /**
     * Count active, archived, and total rows — used for the
     * "Active (N) | Archived (N) | All (N)" tab bar above list views.
     *
     * @return array{active:int, archived:int, all:int}
     */
    public function counts( string $entity ): array {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return [ 'active' => 0, 'archived' => 0, 'all' => 0 ];

        global $wpdb;
        // Scope filter keeps demo-mode isolation: when demo mode is on,
        // counts reflect only demo rows; when off, only real rows.
        $scope = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 'r', $entity );
        // #2021 — trashed rows are excluded from every list-view count so the
        // "Active | Archived | All" tab bar matches filterClause() (which now
        // hides trashed everywhere except the explicit bin view). A row in the
        // bin counts toward none of these three tabs.
        $all_sql      = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} r WHERE r.club_id = %d AND r.trashed_at IS NULL {$scope}", CurrentClub::id() );
        $archived_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} r WHERE r.club_id = %d AND r.archived_at IS NOT NULL AND r.trashed_at IS NULL {$scope}", CurrentClub::id() );
        $all      = (int) $wpdb->get_var( $all_sql );
        $archived = (int) $wpdb->get_var( $archived_sql );
        return [
            'active'   => $all - $archived,
            'archived' => $archived,
            'all'      => $all,
        ];
    }

    /**
     * SQL fragment appending the 3-state lifecycle filter (#2021). Callers
     * build their WHERE clauses; this returns one of:
     *   'active'   →  'archived_at IS NULL AND trashed_at IS NULL'
     *   'archived' →  'archived_at IS NOT NULL AND trashed_at IS NULL'
     *   'trashed'  →  'trashed_at IS NOT NULL'
     *   'all'      →  'trashed_at IS NULL'   (active + archived, NEVER trashed)
     *
     * The contract that makes the bin safe: EVERY per-entity list view
     * (active / archived / all) excludes trashed rows. A trashed minor's row
     * only ever surfaces through the explicit `trashed` view, which the
     * recycle-bin UI gates on `tt_manage_recycle_bin`. So a coach browsing
     * "all players" can never see a row that's in the bin.
     *
     * Pre-#2021 callers passing `active` / `archived` / `all` keep working —
     * the only behavioural change is that `all` and `archived` now also hide
     * trashed rows, which is the intended bin isolation.
     */
    public static function filterClause( string $view ): string {
        switch ( $view ) {
            case 'archived': return 'archived_at IS NOT NULL AND trashed_at IS NULL';
            case 'trashed':  return 'trashed_at IS NOT NULL';
            case 'all':      return 'trashed_at IS NULL';
            case 'active':
            default:         return 'archived_at IS NULL AND trashed_at IS NULL';
        }
    }

    /**
     * Normalize the ?tt_view query-string value to one of
     * active / archived / trashed / all. `trashed` is a valid vocabulary
     * member here, but surfacing it is the caller's decision: the recycle-bin
     * view passes it only after the `tt_manage_recycle_bin` cap check, and
     * per-entity list views never offer it as a tab.
     */
    public static function sanitizeView( $raw ): string {
        $v = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        return in_array( $v, [ 'active', 'archived', 'trashed', 'all' ], true ) ? $v : 'active';
    }

    // Dependency checks

    /**
     * Count active dependents of an entity — used for warnings before
     * archiving a team ("18 active players depend on this team").
     *
     * @return array<string,int>  entity => count of active dependents
     */
    public function activeDependentsFor( string $entity, int $id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [];

        if ( $entity === 'team' ) {
            $player_scope = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 'pl', 'player' );
            $sess_scope   = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 's',  'activity' );
            $out['players'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.team_id = %d AND pl.club_id = %d AND pl.archived_at IS NULL {$player_scope}",
                $id, CurrentClub::id()
            ) );
            $out['activities'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_activities s WHERE s.team_id = %d AND s.club_id = %d AND s.archived_at IS NULL {$sess_scope}",
                $id, CurrentClub::id()
            ) );
        } elseif ( $entity === 'player' ) {
            $eval_scope = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
            $goal_scope = \TT\Infrastructure\Query\QueryHelpers::apply_demo_scope( 'g', 'goal' );
            $out['evaluations'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_evaluations e WHERE e.player_id = %d AND e.club_id = %d AND e.archived_at IS NULL {$eval_scope}",
                $id, CurrentClub::id()
            ) );
            $out['goals'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_goals g WHERE g.player_id = %d AND g.club_id = %d AND g.archived_at IS NULL {$goal_scope}",
                $id, CurrentClub::id()
            ) );
            // #1274 PR2 — surface active (non-archived) PDPs so the
            // archive-confirm modal includes them in the cascade
            // count. Reuses the new PdpFilesRepository helper rather
            // than duplicating the COUNT query here.
            if ( class_exists( PdpFilesRepository::class ) ) {
                $out['pdp_files'] = ( new PdpFilesRepository() )->countActiveForPlayer( $id );
            }
        } elseif ( CascadeRegistry::has( $entity ) ) {
            // #1783 — derive dependent counts from the cascade plan so the
            // archive-confirm modal surfaces what a later hard-delete would
            // cascade or be blocked by.
            $preview = ( new GenericCascadeDeleter() )->preview( $entity, [ $id ] );
            foreach ( $preview['removals'] as $r ) {
                $label = $this->dependentLabel( (string) $r['table'] );
                $out[ $label ] = ( $out[ $label ] ?? 0 ) + (int) $r['count'];
            }
            foreach ( $preview['blockers'] as $table => $count ) {
                $label = $this->dependentLabel( (string) $table );
                $out[ $label ] = ( $out[ $label ] ?? 0 ) + (int) $count;
            }
        }

        return $out;
    }

    /** tt_eval_ratings => "eval ratings" — friendly key for the modal. */
    private function dependentLabel( string $bare_table ): string {
        return str_replace( '_', ' ', (string) preg_replace( '/^tt_/', '', $bare_table ) );
    }

    // Helpers

    private function resolveTable( string $entity ): ?string {
        if ( ! isset( self::TABLE_MAP[ $entity ] ) ) return null;
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MAP[ $entity ];
    }

    /**
     * Normalize + deduplicate an array of ids into positive ints.
     *
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
