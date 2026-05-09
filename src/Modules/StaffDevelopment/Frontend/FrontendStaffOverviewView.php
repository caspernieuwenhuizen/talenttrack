<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\StaffDevelopment\Repositories\StaffCertificationsRepository;
use TT\Modules\StaffDevelopment\Repositories\StaffGoalsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendStaffOverviewView — HoD / academy_admin roll-up.
 *
 * Three cards: open staff goals (count + top 10), pending evaluations
 * (people whose latest top-down review is older than 365 days), and
 * certifications expiring in 90 days (with traffic-light pill on each
 * row). Each row links to the relevant detail surface.
 */
class FrontendStaffOverviewView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'Staff overview', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_staff_certifications_expiry' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        global $wpdb;
        $club = CurrentClub::id();

        $open_goals = $wpdb->get_results( $wpdb->prepare(
            "SELECT g.id, g.title, g.priority, g.due_date, p.first_name, p.last_name, p.id AS person_id
               FROM {$wpdb->prefix}tt_staff_goals g
               JOIN {$wpdb->prefix}tt_people p ON p.id = g.person_id
              WHERE g.club_id = %d AND g.archived_at IS NULL AND g.status != %s
              ORDER BY g.priority DESC, g.due_date ASC
              LIMIT 10",
            $club, StaffGoalsRepository::STATUS_COMPLETED
        ) ) ?: [];

        // #0063 — restrict the top-down-review roll-up to people who
        // are actually staff. The previous query iterated every active
        // tt_people row, including parents and 'other' roles, so the
        // column read like it was leaking unrelated people. Staff
        // overview is a staff page; non-staff roles don't belong here.
        $pending_evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id AS person_id, p.first_name, p.last_name,
                    (SELECT MAX(eval_date) FROM {$wpdb->prefix}tt_staff_evaluations e
                       WHERE e.person_id = p.id AND e.review_kind = 'top_down' AND e.archived_at IS NULL
                    ) AS last_top_down
               FROM {$wpdb->prefix}tt_people p
              WHERE p.club_id = %d
                AND p.status = 'active'
                AND p.role_type IN ('coach', 'assistant_coach', 'manager', 'staff', 'physio', 'scout')
              ORDER BY p.last_name, p.first_name
              LIMIT 50",
            $club
        ) ) ?: [];

        $cutoff      = gmdate( 'Y-m-d', strtotime( '-365 days' ) ?: time() - 365 * 86400 );
        $pending_due = array_values( array_filter( $pending_evals, static function ( $row ) use ( $cutoff ) {
            return $row->last_top_down === null || (string) $row->last_top_down < $cutoff;
        } ) );

        $cert_repo  = new StaffCertificationsRepository();
        $expiring   = $cert_repo->listExpiringWithin( 90 );
        $cert_types = QueryHelpers::get_lookups( 'cert_type' );
        $cert_by_id = [];
        foreach ( $cert_types as $t ) { $cert_by_id[ (int) $t->id ] = (string) $t->name; }

        echo '<div class="tt-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-top: 16px;">';

        echo '<div class="tt-panel"><h3 class="tt-panel-title">' . esc_html__( 'Open staff goals', 'talenttrack' ) . ' (' . (int) count( $open_goals ) . ')</h3>';
        if ( ! $open_goals ) {
            echo '<p>' . esc_html__( 'No open goals.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul style="margin:0; padding-left:18px;">';
            foreach ( $open_goals as $g ) {
                $name = trim( ( $g->first_name ?? '' ) . ' ' . ( $g->last_name ?? '' ) ) ?: '#' . (int) $g->person_id;
                echo '<li>' . esc_html( $name ) . ' — ' . esc_html( (string) $g->title );
                if ( $g->due_date ) echo ' <em>' . esc_html( (string) $g->due_date ) . '</em>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        echo '<div class="tt-panel"><h3 class="tt-panel-title">' . esc_html__( 'Top-down review overdue', 'talenttrack' ) . ' (' . (int) count( $pending_due ) . ')</h3>';
        if ( ! $pending_due ) {
            echo '<p>' . esc_html__( 'Everyone reviewed within the last year.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul style="margin:0; padding-left:18px;">';
            foreach ( $pending_due as $row ) {
                $name = trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) ) ?: '#' . (int) $row->person_id;
                $last = $row->last_top_down ? esc_html( (string) $row->last_top_down ) : esc_html__( 'never', 'talenttrack' );
                echo '<li>' . esc_html( $name ) . ' — ' . __( 'last reviewed:', 'talenttrack' ) . ' ' . $last . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        echo '<div class="tt-panel"><h3 class="tt-panel-title">' . esc_html__( 'Certifications expiring in 90 days', 'talenttrack' ) . ' (' . (int) count( $expiring ) . ')</h3>';
        if ( ! $expiring ) {
            echo '<p>' . esc_html__( 'No certifications expiring soon.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul style="margin:0; padding-left:18px;">';
            foreach ( $expiring as $c ) {
                $type   = (string) ( $cert_by_id[ (int) $c->cert_type_lookup_id ] ?? '#' . (int) $c->cert_type_lookup_id );
                $person = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d",
                    (int) $c->person_id
                ) );
                $name = $person ? trim( (string) $person->first_name . ' ' . (string) $person->last_name ) : '#' . (int) $c->person_id;
                echo '<li>' . esc_html( $name ) . ' — ' . esc_html( $type ) . ' (' . esc_html( (string) $c->expires_on ) . ')</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        echo '</div>';
    }
}
