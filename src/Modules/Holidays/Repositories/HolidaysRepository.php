<?php
namespace TT\Modules\Holidays\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * HolidaysRepository (#1480) — data access for `tt_holidays`.
 *
 * Club-scoped, soft-archive aware, stamps uuid + audit columns on write.
 * Holidays are one-off date ranges; no recurrence.
 */
final class HolidaysRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_holidays';
    }

    /** @param array<string,mixed> $data name, start_date, end_date, note, color */
    public function create( array $data ): int {
        $uid = (int) get_current_user_id();
        $ok  = $this->wpdb->insert( $this->table, [
            'uuid'       => wp_generate_uuid4(),
            'club_id'    => CurrentClub::id(),
            'name'       => (string) ( $data['name'] ?? '' ),
            'start_date' => (string) ( $data['start_date'] ?? '' ),
            'end_date'   => (string) ( $data['end_date'] ?? '' ),
            'note'       => isset( $data['note'] ) && $data['note'] !== '' ? (string) $data['note'] : null,
            'color'      => isset( $data['color'] ) && $data['color'] !== '' ? (string) $data['color'] : null,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
            'created_by' => $uid > 0 ? $uid : null,
            'updated_by' => $uid > 0 ? $uid : null,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function findById( int $id ): ?object {
        if ( $id <= 0 ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d AND archived_at IS NULL",
            $id, CurrentClub::id()
        ) ) ?: null;
    }

    /**
     * All non-archived holidays for the club, soonest first. Optionally
     * restricted to those overlapping [$from, $to].
     *
     * @return object[]
     */
    public function list( string $from = '', string $to = '', string $status = 'active' ): array {
        // #1784 — `status` (active|archived|all) drives the archive filter
        // so the list view can show an Archived tab + Restore action.
        $where = [ 'club_id = %d', \TT\Infrastructure\Archive\ArchiveRepository::filterClause( $status ) ];
        $args  = [ CurrentClub::id() ];
        if ( $from !== '' && $to !== '' ) {
            // Overlap: the holiday's range intersects the window.
            $where[] = 'start_date <= %s AND end_date >= %s';
            $args[]  = $to;
            $args[]  = $from;
        }
        $sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where )
             . ' ORDER BY start_date ASC, id ASC';
        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param array<string,mixed> $patch name, start_date, end_date, note, color */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 ) return false;
        $allowed = [ 'name', 'start_date', 'end_date', 'note', 'color' ];
        $clean   = [];
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $patch ) ) {
                $v = $patch[ $k ];
                $clean[ $k ] = ( $k === 'note' || $k === 'color' ) && ( $v === '' || $v === null )
                    ? null
                    : (string) $v;
            }
        }
        if ( $clean === [] ) return false;
        $clean['updated_at'] = current_time( 'mysql' );
        $uid = (int) get_current_user_id();
        if ( $uid > 0 ) $clean['updated_by'] = $uid;

        return false !== $this->wpdb->update(
            $this->table,
            $clean,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * #1997 — inclusive day span of a holiday: the number of calendar
     * days the period covers counting BOTH the start and the end date.
     * A single-day holiday (start === end) is 1; "21 dec – 4 jan" is 15.
     *
     * Business logic lives here (not the view) so the REST serializer
     * and the PHP detail view derive the same number — SaaS §4. Returns
     * 0 when either date is missing or malformed, or when the range is
     * inverted.
     */
    public static function dayCount( string $start_date, string $end_date ): int {
        $start = strtotime( $start_date . ' 00:00:00' );
        $end   = strtotime( $end_date . ' 00:00:00' );
        if ( $start === false || $end === false || $end < $start ) {
            return 0;
        }
        $days = (int) floor( ( $end - $start ) / DAY_IN_SECONDS );
        return $days + 1; // inclusive of both endpoints.
    }

    public function archive( int $id ): bool {
        if ( $id <= 0 ) return false;
        return false !== $this->wpdb->update(
            $this->table,
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id(), 'archived_at' => null ]
        );
    }
}
