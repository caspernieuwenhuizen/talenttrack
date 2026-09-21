<?php
namespace TT\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ScheduledReportFrequency;
use TT\Domain\Vocabularies\Lookups\ScheduledReportStatus;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ScheduledReportsRepository — CRUD on `tt_scheduled_reports` (#0083 Child 6).
 *
 * Hydrates rows into a uniform shape:
 *
 *   [
 *     'id' => int, 'club_id' => int, 'uuid' => string,
 *     'name' => string, 'kpi_key' => string|null,
 *     'report_key' => string,            // 'kpi' | 'team_monthly' (#3462)
 *     'composition' => array|null,       // the schedule's own copy of a report composition
 *     'last_error' => string|null,       // why the last run did not send
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

    public const FREQUENCY_WEEKLY_MONDAY  = ScheduledReportFrequency::WEEKLY_MONDAY;
    public const FREQUENCY_MONTHLY_FIRST  = ScheduledReportFrequency::MONTHLY_FIRST;
    public const FREQUENCY_SEASON_END     = ScheduledReportFrequency::SEASON_END;

    public const STATUS_ACTIVE   = ScheduledReportStatus::ACTIVE;
    public const STATUS_PAUSED   = ScheduledReportStatus::PAUSED;
    public const STATUS_ARCHIVED = ScheduledReportStatus::ARCHIVED;

    /** A KPI rendered as CSV — every schedule before #3462. */
    public const REPORT_KPI = 'kpi';

    /** The team monthly report rendered as PDF (#3462). */
    public const REPORT_TEAM_MONTHLY = 'team_monthly';

    /** #3891 — the player report, for one player or a whole squad. */
    public const REPORT_PLAYER = 'player_report';

    /**
     * Operator-editable label for a stored frequency value. Resolves
     * through `tt_translations` via `LookupTranslator::byTypeAndName(
     * 'scheduled_report_frequency', $value)`; pre-migration installs
     * fall back to the canonical English label.
     */
    public static function frequencyLabel( string $frequency ): string {
        if ( $frequency === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'scheduled_report_frequency', $frequency );
            if ( $label !== '' && $label !== $frequency ) return $label;
        }
        switch ( $frequency ) {
            case self::FREQUENCY_WEEKLY_MONDAY: return __( 'Weekly (Monday)', 'talenttrack' );
            case self::FREQUENCY_MONTHLY_FIRST: return __( 'Monthly (1st)',   'talenttrack' );
            case self::FREQUENCY_SEASON_END:    return __( 'Season end',      'talenttrack' );
        }
        return $frequency;
    }

    /**
     * Operator-editable label for a stored status value.
     */
    public static function statusLabel( string $status ): string {
        if ( $status === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'scheduled_report_status', $status );
            if ( $label !== '' && $label !== $status ) return $label;
        }
        switch ( $status ) {
            case self::STATUS_ACTIVE:   return __( 'Active',   'talenttrack' );
            case self::STATUS_PAUSED:   return __( 'Paused',   'talenttrack' );
            case self::STATUS_ARCHIVED: return __( 'Archived', 'talenttrack' );
        }
        return $status;
    }

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
     * `report_key` defaults to a KPI schedule. A team-monthly schedule passes
     * its `composition`, which is stored as the schedule's own copy.
     *
     * @param array{name:string, kpi_key?:string, frequency:string, recipients:string[], format?:string, report_key?:string, composition?:array<string,mixed>} $data
     */
    public function create( array $data, int $created_by ): int {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $next_run = self::computeNextRun( (string) $data['frequency'], $now );

        $row = [
            'club_id'     => CurrentClub::id(),
            'uuid'        => wp_generate_uuid4(),
            'name'        => (string) $data['name'],
            'report_key'  => (string) ( $data['report_key'] ?? self::REPORT_KPI ),
            'kpi_key'     => (string) ( $data['kpi_key'] ?? '' ),
            'frequency'   => (string) $data['frequency'],
            'recipients'  => (string) wp_json_encode( $data['recipients'] ),
            'format'      => $data['format'] ?? 'csv',
            'next_run_at' => $next_run,
            'status'      => self::STATUS_ACTIVE,
            'created_by'  => $created_by,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];
        if ( isset( $data['composition'] ) ) {
            $row['composition_json'] = (string) wp_json_encode( $data['composition'] );
        }

        $ok = $wpdb->insert( $this->table(), $row );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Record why a run did not send, so the schedules screen can say so.
     * Cleared by the next run that does send.
     */
    public function recordError( int $id, string $message ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'last_error' => mb_substr( $message, 0, 255 ), 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    public function clearError( int $id ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'last_error' => null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
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
        $composition = null;
        $comp_json   = (string) ( $row['composition_json'] ?? '' );
        if ( $comp_json !== '' ) {
            $decoded = json_decode( $comp_json, true );
            if ( is_array( $decoded ) ) $composition = $decoded;
        }
        return [
            'id'             => (int) $row['id'],
            'club_id'        => (int) $row['club_id'],
            'uuid'           => (string) ( $row['uuid'] ?? '' ),
            'name'           => (string) ( $row['name'] ?? '' ),
            'report_key'     => (string) ( $row['report_key'] ?? '' ) ?: self::REPORT_KPI,
            'composition'    => $composition,
            'last_error'     => isset( $row['last_error'] ) && $row['last_error'] !== '' ? (string) $row['last_error'] : null,
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
