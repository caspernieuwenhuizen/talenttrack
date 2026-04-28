<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpVerdictsRepository — at-most-one verdict per PDP file.
 *
 * The unique key on pdp_file_id enforces the cardinality at the
 * database level; upsertForFile() handles the insert-or-update
 * semantics so callers don't have to care.
 */
class PdpVerdictsRepository {

    private const ALLOWED_DECISIONS = [ 'promote', 'retain', 'release', 'transfer' ];

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_pdp_verdicts';
    }

    public function findForFile( int $file_id ): ?object {
        if ( $file_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE pdp_file_id = %d",
            $file_id
        ) );
        return $row ?: null;
    }

    /**
     * @param array{
     *   decision: string, summary?: string,
     *   coach_id?: int, head_of_academy_id?: int,
     *   signed_off_at?: ?string,
     * } $data
     */
    public function upsertForFile( int $file_id, array $data ): bool {
        if ( $file_id <= 0 ) return false;
        $decision = (string) ( $data['decision'] ?? '' );
        if ( ! in_array( $decision, self::ALLOWED_DECISIONS, true ) ) return false;

        $payload = [
            'pdp_file_id'        => $file_id,
            'decision'           => $decision,
            'summary'            => isset( $data['summary'] ) ? (string) $data['summary'] : null,
            'coach_id'           => isset( $data['coach_id'] ) ? (int) $data['coach_id'] : null,
            'head_of_academy_id' => isset( $data['head_of_academy_id'] ) ? (int) $data['head_of_academy_id'] : null,
            'signed_off_at'      => $data['signed_off_at'] ?? null,
        ];

        $existing = $this->findForFile( $file_id );
        $was_signed_off = $existing && ! empty( $existing->signed_off_at );
        $now_signed_off = ! empty( $payload['signed_off_at'] );

        if ( $existing ) {
            $ok = $this->wpdb->update( $this->table, $payload, [ 'id' => (int) $existing->id ] );
            if ( $ok !== false && ! $was_signed_off && $now_signed_off ) {
                do_action( 'tt_pdp_verdict_signed_off', (int) $existing->id, $file_id );
            }
            return $ok !== false;
        }
        $ok = $this->wpdb->insert( $this->table, $payload );
        if ( $ok !== false && $now_signed_off ) {
            do_action( 'tt_pdp_verdict_signed_off', (int) $this->wpdb->insert_id, $file_id );
        }
        return $ok !== false;
    }
}
