<?php
namespace TT\Modules\Comms\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * CommsLogRepository (#2605, Gate D) — the read side of `tt_comms_log`.
 *
 * The table has been written since v3.106.0 and read by nothing, which is
 * why "did the parents actually get the cancellation message?" still
 * required SQL. This is the query layer both the REST controller and the
 * Gate C staff surface call, so the two cannot drift into answering the
 * same question differently.
 *
 * What it deliberately does not expose: the message body. The audit row
 * stores a SHA-256 of it and nothing more, so a reader can see who was
 * told what kind of thing and when, and cannot read a coach's words about
 * a child out of the log. That is a limit on the surface, not a gap in it.
 *
 * Every query is club-scoped.
 */
final class CommsLogRepository {

    /** Hard ceiling on a page, whatever the caller asks for. */
    public const MAX_PER_PAGE = 200;

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';
    }

    /**
     * One page of the log, newest first.
     *
     * @param array<string,mixed> $filters player_id, user_id, template_key,
     *                                     message_type, status, channel,
     *                                     date_from, date_to
     * @return list<object>
     */
    public function search( array $filters, int $page = 1, int $per_page = 50 ): array {
        global $wpdb;
        [ $where, $args ] = $this->buildWhere( $filters );

        $per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
        $offset   = ( max( 1, $page ) - 1 ) * $per_page;

        $sql = "SELECT id, uuid, created_at, template_key, message_type, channel,
                       sender_user_id, recipient_user_id, recipient_player_id,
                       recipient_kind, address_blob, subject, status, error_code, attempt
                  FROM {$this->table}{$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $per_page, $offset ] ) ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * How many rows the same filters match, for the pagination headers.
     *
     * @param array<string,mixed> $filters
     */
    public function count( array $filters ): int {
        global $wpdb;
        [ $where, $args ] = $this->buildWhere( $filters );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table}{$where}", $args ) );
    }

    /**
     * The distinct statuses present in this club's log.
     *
     * A reader surface that offers every status the vocabulary defines
     * offers mostly empty filters; offering the ones that occurred is the
     * more useful list and costs one indexed query.
     *
     * @return list<string>
     */
    public function statusesInUse(): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT status FROM {$this->table} WHERE club_id = %d ORDER BY status ASC",
            CurrentClub::id()
        ) );
        return array_values( array_map( 'strval', is_array( $rows ) ? $rows : [] ) );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildWhere( array $filters ): array {
        // Club scoping is not a filter — it is the first clause of every
        // query here, so a second tenant on this install cannot read the
        // first one's log by omitting a parameter.
        $clauses = [ 'club_id = %d' ];
        $args    = [ CurrentClub::id() ];

        $exact = [
            'player_id'    => [ 'recipient_player_id', '%d' ],
            'user_id'      => [ 'recipient_user_id',   '%d' ],
            'template_key' => [ 'template_key',        '%s' ],
            'message_type' => [ 'message_type',        '%s' ],
            'status'       => [ 'status',              '%s' ],
            'channel'      => [ 'channel',             '%s' ],
        ];
        foreach ( $exact as $key => [ $column, $placeholder ] ) {
            $value = $filters[ $key ] ?? null;
            if ( $value === null || $value === '' || $value === 0 ) continue;
            $clauses[] = "{$column} = {$placeholder}";
            $args[]    = $placeholder === '%d' ? (int) $value : (string) $value;
        }

        if ( ! empty( $filters['date_from'] ) ) {
            $clauses[] = 'created_at >= %s';
            $args[]    = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $clauses[] = 'created_at <= %s';
            $args[]    = (string) $filters['date_to'];
        }

        return [ ' WHERE ' . implode( ' AND ', $clauses ), $args ];
    }
}
