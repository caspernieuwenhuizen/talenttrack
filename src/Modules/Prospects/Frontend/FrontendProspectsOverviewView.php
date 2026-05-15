<?php
namespace TT\Modules\Prospects\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendProspectsOverviewView (v3.110.99) — `?tt_view=prospects-overview`.
 *
 * Standard rich list of every prospect on the club, backed by the new
 * `GET /talenttrack/v1/prospects` REST endpoint via the shared
 * `FrontendListTable` component (search, status filter, discovered-by
 * filter, include-archived toggle, per-page selector 10/25/50/100
 * default 25, sortable columns).
 *
 * Destination of the "See all" link on the scout dashboard's
 * `my_recent_prospects` table (previously routed to the kanban, which
 * was less useful as a "give me a real prospect list" answer).
 *
 * Scout-scoping is enforced at the REST layer — scouts auto-filter to
 * `discovered_by_user_id = $current_user_id` regardless of any
 * operator-supplied filter.
 */
final class FrontendProspectsOverviewView extends FrontendViewBase {

    public static function render( int $user_id ): void {
        self::enqueueAssets();

        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( __( 'Prospects', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the prospects list.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Prospects', 'talenttrack' ) );
        self::renderHeader( __( 'Prospects', 'talenttrack' ) );

        echo FrontendListTable::render( [
            'rest_path' => 'prospects',
            'search'    => [
                'placeholder' => __( 'Search by name…', 'talenttrack' ),
            ],
            'filters' => [
                'status' => [
                    'type'    => 'select',
                    'label'   => __( 'Status', 'talenttrack' ),
                    'options' => [
                        ''         => __( 'All',      'talenttrack' ),
                        'active'   => __( 'Active',   'talenttrack' ),
                        'trial'    => __( 'In trial', 'talenttrack' ),
                        'joined'   => __( 'Joined',   'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
                'discovered_by_user_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Discovered by', 'talenttrack' ),
                    'options' => self::discovererOptions(),
                ],
            ],
            'columns' => [
                'last_name'     => [ 'label' => __( 'Last name',     'talenttrack' ), 'sortable' => true ],
                'first_name'    => [ 'label' => __( 'First name',    'talenttrack' ), 'sortable' => true ],
                'birth_year'    => [ 'label' => __( 'Born',          'talenttrack' ) ],
                'current_club'  => [ 'label' => __( 'Club',          'talenttrack' ), 'sortable' => true ],
                'discovered_at' => [ 'label' => __( 'Discovered',    'talenttrack' ), 'sortable' => true, 'render' => 'date' ],
                'discovered_by' => [ 'label' => __( 'Discovered by', 'talenttrack' ) ],
                'status_label'  => [ 'label' => __( 'Status',        'talenttrack' ) ],
            ],
            'default_sort' => [ 'orderby' => 'last_name', 'order' => 'asc' ],
            'empty_state'  => __( 'No prospects match those filters yet.', 'talenttrack' ),
        ] );
    }

    /**
     * Dropdown options for the "Discovered by" filter. Returns
     * `[ '' => 'Anyone', uid => display_name, … ]` listing every user
     * who has discovered ≥1 non-archived prospect on this club.
     *
     * @return array<int|string,string>
     */
    private static function discovererOptions(): array {
        global $wpdb;
        $uids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT discovered_by_user_id
               FROM {$wpdb->prefix}tt_prospects
              WHERE club_id = %d
                AND archived_at IS NULL
                AND discovered_by_user_id IS NOT NULL
                AND discovered_by_user_id > 0",
            CurrentClub::id()
        ) );
        $opts = [ '' => __( 'Anyone', 'talenttrack' ) ];
        foreach ( (array) $uids as $uid_str ) {
            $uid = (int) $uid_str;
            if ( $uid <= 0 ) continue;
            $u = get_userdata( $uid );
            if ( $u ) $opts[ $uid ] = (string) $u->display_name;
        }
        return $opts;
    }
}
