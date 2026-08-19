<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Tiles\TileRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * SearchRestController (#2458) — cross-entity lookup behind the command
 * palette.
 *
 * The sidebar (#2456) solves navigation for the ~30 grouped destinations.
 * It does not solve "jump straight to Sem de Vries", and it does not make
 * the long setup / report tail reachable without scanning a list. This
 * endpoint is what does.
 *
 * ## Privacy is the whole design
 *
 * These are minors (CLAUDE.md §1). A search box is the easiest place in a
 * product to leak a record someone may not see, so filtering happens
 * **server-side, per row, through the same authorization service the
 * detail views use** — never by trimming a client list, and never by
 * assuming a capability check on the endpoint is enough. A user with
 * `tt_view_players` may still only see the players in their own scope.
 *
 * Results are hard-capped. An uncapped search is both a performance
 * problem and an enumeration surface.
 */
final class SearchRestController extends BaseController {

    /** Maximum rows returned across all types, after filtering. */
    private const LIMIT = 10;

    /**
     * How many section results may take slots while records are also
     * matching (#2508).
     *
     * Sections were merged before records and the combined list truncated to
     * LIMIT, so any query matching LIMIT-or-more sections discarded every
     * record. With ~59 registered tiles that is most two-letter fragments:
     * typing `er` matched 19 players and showed none of them. Capping
     * sections guarantees records a share; leftover section slots are
     * handed back when there are too few records to use them.
     */
    private const VIEW_QUOTA = 3;

    /** Minimum query length. Below this the palette shows views only. */
    private const MIN_CHARS = 2;

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'search' ],
            // Any logged-in user may search; what they get back is scoped
            // per row below. The cap gate lives with the data, not here —
            // a parent and a head of development both search, they just
            // reach different records.
            'permission_callback' => [ __CLASS__, 'permLoggedIn' ],
            'args'                => [
                'q'     => [ 'type' => 'string', 'required' => false ],
                'types' => [ 'type' => 'string', 'required' => false ],
            ],
        ] );
    }

    /**
     * @return WP_REST_Response Shape: { results: [ {type,id,label,sublabel,url} ] }
     */
    public static function search( WP_REST_Request $request ): WP_REST_Response {
        $q     = trim( (string) $request->get_param( 'q' ) );
        $types = (string) $request->get_param( 'types' );
        $types = $types !== '' ? array_map( 'sanitize_key', explode( ',', $types ) ) : [ 'view', 'player', 'team', 'activity' ];

        $user_id = get_current_user_id();

        // Views come from TileRegistry, so they are already capability- and
        // persona-filtered and honour the __hidden__ marker. Free.
        $views = in_array( 'view', $types, true ) ? self::views( $user_id, $q ) : [];

        $records = [];
        if ( mb_strlen( $q ) >= self::MIN_CHARS ) {
            if ( in_array( 'player', $types, true ) ) {
                $records = array_merge( $records, self::players( $user_id, $q ) );
            }
            if ( in_array( 'team', $types, true ) ) {
                $records = array_merge( $records, self::teams( $q ) );
            }
            if ( in_array( 'activity', $types, true ) ) {
                $records = array_merge( $records, self::activities( $q ) );
            }
        }

        return new WP_REST_Response( [ 'results' => self::merge( $views, $records ) ], 200 );
    }

    /**
     * Combine sections and records into one capped list (#2508).
     *
     * Sections stay first — for a short query they are usually what the user
     * means — but they are capped at VIEW_QUOTA whenever records also match,
     * so a name search can never be crowded out. Whichever side under-uses
     * its share hands the remainder to the other, so the list is still full
     * when only one kind matched.
     *
     * @param list<array<string,mixed>> $views
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private static function merge( array $views, array $records ): array {
        if ( $records === [] ) {
            return array_slice( $views, 0, self::LIMIT );
        }
        if ( $views === [] ) {
            return array_slice( $records, 0, self::LIMIT );
        }

        // Sections take their quota; records take the rest. If records are
        // too few to fill it, the leftover goes back to sections.
        $view_take   = min( count( $views ), self::VIEW_QUOTA );
        $record_take = min( count( $records ), self::LIMIT - $view_take );
        $view_take   = min( count( $views ), self::LIMIT - $record_take );

        return array_merge(
            array_slice( $views, 0, $view_take ),
            array_slice( $records, 0, $record_take )
        );
    }

    /** @return list<array<string,mixed>> */
    private static function views( int $user_id, string $q ): array {
        $out = [];
        foreach ( TileRegistry::tilesForUserGrouped( $user_id ) as $group ) {
            foreach ( $group['tiles'] as $tile ) {
                $label = (string) ( $tile['label'] ?? '' );
                if ( $label === '' ) continue;
                if ( $q !== '' && stripos( $label, $q ) === false ) continue;
                $out[] = [
                    'type'     => 'view',
                    'id'       => 0,
                    'label'    => $label,
                    'sublabel' => (string) ( $group['label'] ?? '' ),
                    'url'      => (string) ( $tile['url'] ?? '' ),
                ];
            }
        }
        return $out;
    }

    /**
     * Players the user may actually see.
     *
     * The SQL is a cheap prefilter; `canViewPlayer()` is the gate. Over-
     * fetching a few rows and dropping the ones the viewer may not see is
     * what keeps this honest without duplicating the scope rules in a
     * second query that could drift from the detail view's.
     *
     * @return list<array<string,mixed>>
     */
    private static function players( int $user_id, string $q ): array {
        global $wpdb;

        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.first_name, p.last_name, t.name AS team_name
               FROM {$wpdb->prefix}tt_players p
          LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = p.team_id
              WHERE p.club_id = %d
                AND p.archived_at IS NULL
                AND CONCAT_WS(' ', p.first_name, p.last_name) LIKE %s
           ORDER BY p.last_name ASC, p.first_name ASC
              LIMIT %d",
            CurrentClub::id(),
            $like,
            self::LIMIT * 4
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $player_id = (int) $row->id;
            if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) continue;

            $out[] = [
                'type'     => 'player',
                'id'       => $player_id,
                'label'    => trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
                'sublabel' => (string) ( $row->team_name ?? '' ),
                'url'      => \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'players', $player_id ),
            ];
            if ( count( $out ) >= self::LIMIT ) break;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function teams( string $q ): array {
        if ( ! current_user_can( 'tt_view_teams' ) ) return [];

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, age_group
               FROM {$wpdb->prefix}tt_teams
              WHERE club_id = %d AND archived_at IS NULL AND name LIKE %s
           ORDER BY name ASC
              LIMIT %d",
            CurrentClub::id(),
            $like,
            self::LIMIT
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'type'     => 'team',
                'id'       => (int) $row->id,
                'label'    => (string) $row->name,
                'sublabel' => (string) ( $row->age_group ?? '' ),
                'url'      => \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'teams', (int) $row->id ),
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function activities( string $q ): array {
        if ( ! current_user_can( 'tt_view_activities' ) ) return [];

        global $wpdb;
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date
               FROM {$wpdb->prefix}tt_activities
              WHERE club_id = %d AND archived_at IS NULL AND title LIKE %s
           ORDER BY session_date DESC
              LIMIT %d",
            CurrentClub::id(),
            $like,
            self::LIMIT
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = [
                'type'     => 'activity',
                'id'       => (int) $row->id,
                'label'    => (string) $row->title,
                'sublabel' => ! empty( $row->session_date )
                    ? \TT\Shared\Dates\TTDate::date( (string) $row->session_date )
                    : '',
                'url'      => \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( 'activities', (int) $row->id ),
            ];
        }
        return $out;
    }
}
