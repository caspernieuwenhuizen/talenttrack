<?php
namespace TT\Modules\Trials\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

class TrialExtensionsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_trial_extensions';
    }

    /**
     * @return object[]
     */
    public function listForCase( int $case_id ): array {
        if ( $case_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE case_id = %d ORDER BY extended_at DESC",
            $case_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function record( int $case_id, string $previous_end_date, string $new_end_date, string $justification, int $user_id ): int {
        if ( $case_id <= 0 || trim( $justification ) === '' ) return 0;
        $ok = $this->wpdb->insert( $this->table, [
            'case_id'           => $case_id,
            'previous_end_date' => $previous_end_date,
            'new_end_date'      => $new_end_date,
            'justification'     => $justification,
            'extended_by'       => $user_id,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }
}
