<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ScheduledReportsRepository — CRUD on `tt_scheduled_reports` (#0083 Child 6).
 *
 * Hydrates rows into a uniform shape:
 *
 *   [
 *     'id' => int, 'club_id' => int, 'uuid' => string,
 *     'name' => string, 'kpi_key' => string|null,
 *     'explorer_state' => array|null,    // decoded
 *     'frequency' => string,             // 'weekly_monday' | 'monthly_first' | 'season_end'
 *     'recipients' => string[],          // decoded JSON list
 *     'format' => string,                // 'csv' (v1)
 *     'last_run_at' => string|null, 'next_run_at' => string,
 *     'status' => string,                // 'active' | 'paused' | 'archived'
 *     'created_by' => int, 'created_at' => string, 'updated_at' => string|null,
 *   ]
 */
final class ScheduledReportsRepository {

    public const FREQUENCY_WEEKLY_MONDAY  = 'weekly_monday';
    public const FREQUENCY_MONTHLY_FIRST  = 'monthly_first';
    public const FREQUENCY_SEASON_END     = 'season_end';

    public const STATUS_ACTIVE   = 'active';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_ARCHIVED = 'archived';

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_scheduled_reports';
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listForCurrentClub(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE club_id = %d AND status <> %s
              ORDER BY name ASC",
            CurrentClub::id(),
            self::STATUS_ARCHIVED
        ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], (array) $rows );
    }

    /**
     * Active schedules whose next-run is due (cron consumer).
     *
     * @return array<int, array<string,mixed>>
     */
    public function dueForRun(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE status = %s AND next_run_at <= UTC_TIMESTAMP()
              ORDER BY next_run_at ASC",
            self::STATUS_ACTIVE
        ), ARRAY_A );
        return array_map( [ $this, 'hydrate' ], (array) $rows );
    }

    public function findById( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $this->hydrate( $row ) : null;
    }

    /**
     * @param array{name:string, kpi_key:string, frequency:string, recipients:string[], format:string} $data
     */
    public function create( array $data, int $created_by ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $next_run = self::computeNextRun( (string) $data['frequency'], $now );

        $ok = $wpdb->insert( $this->table(), [
            'club_id'     => CurrentClub::id(),
            'uuid'        => wp_generate_uuid4(),
            'name'        => (string) $data['name'],
            'kpi_key'     => (string) ( $data['kpi_key'] ?? '' ),
            'frequency'   => (string) $data['frequency'],
            'recipients'  => (string) wp_json_encode( (array) ( $data['recipients'] ?? [] ) ),
            'format'      => (string) ( $data['format'] ?? 'csv' ),
            'next_run_at' => $next_run,
            'status'      => self::STATUS_ACTIVE,
            'created_by'  => $created_by,
            'created_at'  => $now,
            'updated_at'  => $now,
        ] );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    public function setStatus( int $id, string $status ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Stamp the last/next run after the cron processes a schedule.
     */
    public function markRun( int $id, string $now_utc ): bool {
        $existing = $this->findById( $id );
        if ( $existing === null ) return false;
        $next_run = self::computeNextRun( (string) $existing['frequency'], $now_utc );
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [
                'last_run_at' => $now_utc,
                'next_run_at' => $next_run,
                'updated_at'  => $now_utc,
            ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Compute the next-run timestamp from a frequency string + the
     * current UTC time. The window is loose by design — operators
     * care about "this came in on Monday morning", not minute-level
     * precision.
     */
    public static function computeNextRun( string $frequency, string $base_utc ): string {
        $base = strtotime( $base_utc . ' UTC' );
        if ( $base === false ) $base = time();
        switch ( $frequency ) {
            case self::FREQUENCY_WEEKLY_MONDAY:
                $next = strtotime( 'next Monday 06:00 UTC', $base );
                break;
            case self::FREQUENCY_MONTHLY_FIRST:
                $next = strtotime( 'first day of next month 06:00 UTC', $base );
                break;
            case self::FREQUENCY_SEASON_END:
                // Season convention: 1 July (Northern Hemisphere). If we're
                // already past it this year, schedule for next July.
                $year = (int) gmdate( 'Y', $base );
                $candidate = strtotime( $year . '-07-01 06:00 UTC' );
                if ( $candidate === false || $candidate <= $base ) {
                    $candidate = strtotime( ( $year + 1 ) . '-07-01 06:00 UTC' );
                }
                $next = $candidate;
                break;
            default:
                // Defensive fallback — unknown frequency runs in 24 hours.
                $next = $base + DAY_IN_SECONDS;
        }
        return gmdate( 'Y-m-d H:i:s', (int) $next );
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate( array $row ): array {
        $recipients = [];
        $rec_json = (string) ( $row['recipients'] ?? '' );
        if ( $rec_json !== '' ) {
            $decoded = json_decode( $rec_json, true );
            if ( is_array( $decoded ) ) $recipients = array_values( array_map( 'strval', $decoded ) );
        }
        $explorer = null;
        $exp_json = (string) ( $row['explorer_state_json'] ?? '' );
        if ( $exp_json !== '' ) {
            $decoded = json_decode( $exp_json, true );
            if ( is_array( $decoded ) ) $explorer = $decoded;
        }
        return [
            'id'             => (int) $row['id'],
            'club_id'        => (int) $row['club_id'],
            'uuid'           => (string) ( $row['uuid'] ?? '' ),
            'name'           => (string) ( $row['name'] ?? '' ),
            'kpi_key'        => (string) ( $row['kpi_key'] ?? '' ) ?: null,
            'explorer_state' => $explorer,
            'frequency'      => (string) ( $row['frequency'] ?? '' ),
            'recipients'     => $recipients,
            'format'         => (string) ( $row['format'] ?? 'csv' ),
            'last_run_at'    => $row['last_run_at'] ?? null,
            'next_run_at'    => (string) ( $row['next_run_at'] ?? '' ),
            'status'         => (string) ( $row['status'] ?? 'active' ),
            'created_by'     => (int) ( $row['created_by'] ?? 0 ),
            'created_at'     => (string) ( $row['created_at'] ?? '' ),
            'updated_at'     => $row['updated_at'] ?? null,
        ];
    }
}
