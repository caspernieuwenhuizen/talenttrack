<?php
namespace TT\Modules\Prospects\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ProspectsRepository (#0081 child 1) — read/write `tt_prospects`.
 *
 * Tenancy via `CurrentClub::id()` on every query. No `status` column;
 * lifecycle is workflow-task-driven. Soft-delete via `archived_at`
 * for terminal outcomes; hard-delete only via the retention cron.
 */
class ProspectsRepository {

    public const ARCHIVE_REASON_DECLINED       = 'declined';
    public const ARCHIVE_REASON_PARENT_WITHDREW = 'parent_withdrew';
    public const ARCHIVE_REASON_NO_SHOW        = 'no_show';
    public const ARCHIVE_REASON_PROMOTED       = 'promoted';
    public const ARCHIVE_REASON_GDPR_PURGE     = 'gdpr_purge';

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_prospects';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @param array{
     *   discovered_by_user_id?: int,
     *   include_archived?: bool,
     *   age_group_lookup_id?: int,
     *   status?: string,
     *   name_like?: string,
     *   orderby?: string,
     *   order?: string,
     *   limit?: int,
     *   offset?: int
     * } $filters
     * @return object[]
     */
    public function search( array $filters = [] ): array {
        [ $where, $params ] = $this->buildWhere( $filters );

        $orderby_key = (string) ( $filters['orderby'] ?? 'discovered_at' );
        $order_dir   = strtolower( (string) ( $filters['order'] ?? 'desc' ) ) === 'asc' ? 'ASC' : 'DESC';
        $orderby_sql = $this->orderByClause( $orderby_key, $order_dir );

        $sql = "SELECT * FROM {$this->table}
                WHERE " . implode( ' AND ', $where ) . "
                {$orderby_sql}";

        if ( ! empty( $filters['limit'] ) ) {
            $sql     .= ' LIMIT %d OFFSET %d';
            $params[] = (int) $filters['limit'];
            $params[] = (int) ( $filters['offset'] ?? 0 );
        }

        /** @var string $prepared */
        $prepared = $this->wpdb->prepare( $sql, ...$params );
        $rows = $this->wpdb->get_results( $prepared );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Same filters as `search()`, returns the matching count for
     * pagination. Skips orderby / limit / offset by design.
     *
     * @param array<string,mixed> $filters
     */
    public function count( array $filters = [] ): int {
        [ $where, $params ] = $this->buildWhere( $filters );
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE " . implode( ' AND ', $where );
        /** @var string $prepared */
        $prepared = $this->wpdb->prepare( $sql, ...$params );
        return (int) $this->wpdb->get_var( $prepared );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:list<string>,1:list<mixed>}
     */
    private function buildWhere( array $filters ): array {
        $where  = [ 'club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['discovered_by_user_id'] ) ) {
            $where[]  = 'discovered_by_user_id = %d';
            $params[] = (int) $filters['discovered_by_user_id'];
        }
        if ( ! empty( $filters['age_group_lookup_id'] ) ) {
            $where[]  = 'age_group_lookup_id = %d';
            $params[] = (int) $filters['age_group_lookup_id'];
        }
        // Computed-status filter. Mirrors the kanban classifier's terminal
        // states (Active / In trial / Joined / Archived) without joining
        // tt_workflow_tasks — the cheap "where is this prospect right
        // now" projection.
        if ( ! empty( $filters['status'] ) ) {
            switch ( (string) $filters['status'] ) {
                case 'archived':
                    $where[] = 'archived_at IS NOT NULL';
                    break;
                case 'joined':
                    $where[] = 'archived_at IS NULL';
                    $where[] = 'promoted_to_player_id IS NOT NULL';
                    $where[] = '( promoted_to_trial_case_id IS NULL )';
                    break;
                case 'trial':
                    $where[] = 'archived_at IS NULL';
                    $where[] = 'promoted_to_trial_case_id IS NOT NULL';
                    break;
                case 'active':
                    $where[] = 'archived_at IS NULL';
                    $where[] = 'promoted_to_player_id IS NULL';
                    $where[] = 'promoted_to_trial_case_id IS NULL';
                    break;
            }
        } elseif ( empty( $filters['include_archived'] ) ) {
            $where[] = 'archived_at IS NULL';
        }
        if ( ! empty( $filters['name_like'] ) ) {
            $like     = '%' . $this->wpdb->esc_like( (string) $filters['name_like'] ) . '%';
            $where[]  = '( first_name LIKE %s OR last_name LIKE %s )';
            $params[] = $like;
            $params[] = $like;
        }

        return [ $where, $params ];
    }

    private function orderByClause( string $key, string $dir ): string {
        $allowed = [
            'last_name'     => 'last_name',
            'first_name'    => 'first_name',
            'discovered_at' => 'discovered_at',
            'current_club'  => 'current_club',
            'date_of_birth' => 'date_of_birth',
        ];
        $col = $allowed[ $key ] ?? 'discovered_at';
        return "ORDER BY {$col} {$dir}, id DESC";
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $insert = [
            'club_id'                       => CurrentClub::id(),
            'uuid'                          => wp_generate_uuid4(),
            'first_name'                    => (string) ( $data['first_name'] ?? '' ),
            'last_name'                     => (string) ( $data['last_name']  ?? '' ),
            'date_of_birth'                 => $data['date_of_birth'] ?? null,
            'age_group_lookup_id'           => isset( $data['age_group_lookup_id'] ) ? (int) $data['age_group_lookup_id'] : null,
            'discovered_at'                 => (string) ( $data['discovered_at'] ?? gmdate( 'Y-m-d' ) ),
            'discovered_by_user_id'         => (int) ( $data['discovered_by_user_id'] ?? get_current_user_id() ),
            'discovered_at_event'           => $data['discovered_at_event'] ?? null,
            'current_club'                  => $data['current_club'] ?? null,
            'preferred_position_lookup_id'  => isset( $data['preferred_position_lookup_id'] ) ? (int) $data['preferred_position_lookup_id'] : null,
            'scouting_notes'                => $data['scouting_notes'] ?? null,
            'parent_name'                   => $data['parent_name']  ?? null,
            'parent_email'                  => $data['parent_email'] ?? null,
            'parent_phone'                  => $data['parent_phone'] ?? null,
            'consent_given_at'              => $data['consent_given_at'] ?? null,
        ];
        $ok = $this->wpdb->insert( $this->table, $insert );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    /**
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 || ! $patch ) return false;
        $allowed = [
            'first_name', 'last_name', 'date_of_birth', 'age_group_lookup_id',
            'discovered_at_event', 'current_club', 'preferred_position_lookup_id',
            'scouting_notes',
            'parent_name', 'parent_email', 'parent_phone', 'consent_given_at',
            'promoted_to_player_id', 'promoted_to_trial_case_id',
            'archived_at', 'archived_by', 'archive_reason',
        ];
        $clean = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( ! $clean ) return false;
        return (bool) $this->wpdb->update( $this->table, $clean, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
    }

    public function archive( int $id, string $reason, ?int $by = null ): bool {
        return $this->update( $id, [
            'archived_at'    => current_time( 'mysql', true ),
            'archived_by'    => (int) ( $by ?? get_current_user_id() ),
            'archive_reason' => $reason,
        ] );
    }

    /**
     * Hard-delete used only by the retention cron. Does NOT scope to
     * the current club via `CurrentClub::id()` because the cron may
     * run before any tenant context is established. Caller (the cron)
     * is responsible for passing the club_id explicitly.
     */
    public function hardDelete( int $id, int $club_id ): bool {
        if ( $id <= 0 ) return false;
        $deleted = $this->wpdb->delete( $this->table, [ 'id' => $id, 'club_id' => $club_id ] );
        return $deleted !== false && $deleted > 0;
    }

    /**
     * Fuzzy duplicate-detection at log time. Match on first/last name +
     * age group + current club. Levenshtein with threshold 85% is
     * applied client-side; this method returns candidates matching on
     * the structured fields. The caller decides whether to surface
     * the prompt.
     *
     * @return object[]
     */
    public function findDuplicateCandidates(
        string $first_name,
        string $last_name,
        ?int $age_group_lookup_id = null,
        ?string $current_club = null
    ): array {
        $where  = [ 'club_id = %d', 'archived_at IS NULL' ];
        $params = [ CurrentClub::id() ];

        $where[]  = 'LOWER(first_name) = %s';
        $params[] = strtolower( $first_name );
        $where[]  = 'LOWER(last_name) = %s';
        $params[] = strtolower( $last_name );

        if ( $age_group_lookup_id ) {
            $where[]  = 'age_group_lookup_id = %d';
            $params[] = (int) $age_group_lookup_id;
        }
        if ( $current_club ) {
            $where[]  = 'LOWER(current_club) = %s';
            $params[] = strtolower( $current_club );
        }

        $sql = "SELECT * FROM {$this->table}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY id DESC LIMIT 5";

        /** @var string $prepared */
        $prepared = $this->wpdb->prepare( $sql, ...$params );
        $rows = $this->wpdb->get_results( $prepared );
        return is_array( $rows ) ? $rows : [];
    }
}
