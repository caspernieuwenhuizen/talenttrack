<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrialCaseChecklistRepository (#4008) — the rows of a trial case's
 * follow-up checklist, and nothing else.
 *
 * Which items a decision gets, when an item ticks itself and who may
 * change one are `TrialCaseChecklistService`'s questions. This class only
 * reads and writes `tt_trial_case_checklist`, always inside the current
 * club.
 */
class TrialCaseChecklistRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_case_checklist';
    }

    /**
     * The case's rows, oldest first.
     *
     * @return list<object>
     */
    public function listForCase( int $case_id ): array {
        if ( $case_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE trial_case_id = %d AND club_id = %d ORDER BY id ASC",
            $case_id,
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? array_values( $rows ) : [];
    }

    /**
     * The ids of a case's rows, for a caller that tags or cascades them.
     *
     * @return list<int>
     */
    public function idsForCase( int $case_id ): array {
        $ids = [];
        foreach ( $this->listForCase( $case_id ) as $row ) {
            $ids[] = (int) ( ( (array) $row )['id'] ?? 0 );
        }
        return array_values( array_filter( $ids, static fn( int $id ): bool => $id > 0 ) );
    }

    /**
     * Add an item to a case unless it is already there. Returns true when a
     * row was written.
     */
    public function ensureItem( int $case_id, string $item_key ): bool {
        if ( $case_id <= 0 || $item_key === '' ) return false;
        $written = $this->wpdb->query( $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->table} (club_id, uuid, trial_case_id, item_key, created_at, updated_at)
             VALUES (%d, %s, %d, %s, %s, %s)",
            CurrentClub::id(),
            wp_generate_uuid4(),
            $case_id,
            $item_key,
            current_time( 'mysql', true ),
            current_time( 'mysql', true )
        ) );
        return (int) $written > 0;
    }

    /**
     * Change one item. Only the columns in `$patch` are written.
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $case_id, string $item_key, array $patch ): bool {
        $allowed = [ 'assignee_user_id', 'done_at', 'done_by', 'done_auto' ];
        $data    = array_intersect_key( $patch, array_flip( $allowed ) );
        if ( $data === [] ) return false;
        $data['updated_at'] = current_time( 'mysql', true );

        $ok = $this->wpdb->update(
            $this->table,
            $data,
            [ 'trial_case_id' => $case_id, 'item_key' => $item_key, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }
}
