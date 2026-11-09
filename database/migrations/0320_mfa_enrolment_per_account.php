<?php
/**
 * Migration 0320 — a second factor belongs to the account (#4798).
 *
 * `tt_user_mfa` was unique on (`wp_user_id`, `club_id`) since 0073, so an
 * account in two academies could hold two enrolments, and set up two-step
 * login twice. The key becomes `wp_user_id` alone. Before it changes, an
 * account with more than one row is merged into one:
 *
 *   - the row that most recently verified a code survives, so the
 *     authenticator the person uses today keeps working;
 *   - when none has verified, the most recently set-up one survives
 *     (`enrolled_at`, then `created_at`, then the highest id);
 *   - its recovery codes and remembered devices are on that row and stay
 *     with it; the merged-away rows are deleted;
 *   - each merge writes one audit row, `mfa.enrolments_merged`, in every
 *     academy whose enrolment took part: the
 *     account, the row kept and the rows removed, by id and academy. No
 *     secret, code or token goes into it.
 *
 * Product owner decision, 3 November 2026. An account with one enrolment
 * is not touched.
 *
 * Idempotent: once the key is `wp_user_id` alone there is at most one row
 * per account, and a second run finds nothing to merge and the key already
 * changed. Every write goes through `exec()`. Schema change: runs alone.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const KEY = 'uk_user';

    public function getName(): string {
        return '0320_mfa_enrolment_per_account';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_user_mfa';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            $this->report( 0, 'tt_user_mfa missing' );
            return;
        }

        $merged = $this->mergeDuplicates( $table );
        $keyed  = $this->rekey( $table );

        $this->report(
            $merged + $keyed,
            sprintf( '%d account(s) merged to one enrolment; %s', $merged, $keyed > 0 ? 'unique key is now wp_user_id' : 'unique key was already wp_user_id' )
        );
    }

    /** @return int accounts merged */
    private function mergeDuplicates( string $table ): int {
        global $wpdb;
        $accounts = $wpdb->get_col( "SELECT wp_user_id FROM {$table} GROUP BY wp_user_id HAVING COUNT(*) > 1" );
        $merged   = 0;
        foreach ( (array) $accounts as $account ) {
            $account = (int) $account;
            if ( $account <= 0 ) continue;
            // Survivor first: the latest verification, then the latest set-up.
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, club_id FROM {$table}
                  WHERE wp_user_id = %d
                  ORDER BY ( last_verified_at IS NULL ) ASC, last_verified_at DESC,
                           ( enrolled_at IS NULL ) ASC, enrolled_at DESC,
                           created_at DESC, id DESC",
                $account
            ), ARRAY_A );
            $rows = is_array( $rows ) ? $rows : [];
            if ( count( $rows ) < 2 ) continue;

            $kept    = array_shift( $rows );
            $removed = array_map( static fn( array $r ): int => (int) $r['id'], $rows );
            $in      = implode( ',', array_fill( 0, count( $removed ), '%d' ) );
            $this->exec( $wpdb->prepare( "DELETE FROM {$table} WHERE wp_user_id = %d AND id IN ({$in})", $account, ...$removed ) );
            $this->audit( $account, $kept, $rows );
            $merged++;
        }
        return $merged;
    }

    /**
     * One audit row per merge in every academy whose enrolment took part, so
     * each academy's log shows the merge. Ids and academies only.
     *
     * @param array<string,mixed>       $kept
     * @param list<array<string,mixed>> $removed
     */
    private function audit( int $account, array $kept, array $removed ): void {
        global $wpdb;
        $log = $wpdb->prefix . 'tt_audit_log';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log ) ) !== $log ) return;

        $payload = (string) wp_json_encode( [
            'kept'    => [ 'id' => (int) $kept['id'], 'club_id' => (int) $kept['club_id'] ],
            'removed' => array_map( static fn( array $r ): array => [ 'id' => (int) $r['id'], 'club_id' => (int) $r['club_id'] ], $removed ),
            'rule'    => 'most recently verified, else most recently set up',
        ] );
        $clubs = array_values( array_unique( array_map( static fn( array $r ): int => (int) $r['club_id'], array_merge( [ $kept ], $removed ) ) ) );
        sort( $clubs );
        foreach ( $clubs as $club_id ) {
            $this->exec( $wpdb->prepare(
                "INSERT INTO {$log} ( club_id, user_id, action, entity_type, entity_id, payload, ip_address, created_at )
                 VALUES ( %d, %d, %s, %s, %d, %s, %s, %s )",
                $club_id, 0, 'mfa.enrolments_merged', 'user', $account, $payload, '', current_time( 'mysql' )
            ) );
        }
    }

    /** @return int 1 when the key was changed, 0 when it already was */
    private function rekey( string $table ): int {
        global $wpdb;
        $columns = $wpdb->get_col( $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX',
            $table, self::KEY
        ) );
        $columns = array_map( 'strval', is_array( $columns ) ? $columns : [] );
        if ( $columns === [ 'wp_user_id' ] ) return 0;

        $drop = $columns === [] ? '' : 'DROP INDEX ' . self::KEY . ', ';
        $this->exec( "ALTER TABLE {$table} {$drop}ADD UNIQUE KEY " . self::KEY . ' (wp_user_id)' );
        return 1;
    }
};
