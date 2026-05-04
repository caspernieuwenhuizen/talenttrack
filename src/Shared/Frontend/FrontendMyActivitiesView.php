<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;

/**
 * FrontendMyActivitiesView — the "My sessions" tile destination.
 *
 * Lists activities attended by the logged-in player, most-recent
 * first. Filterable by status, date range, and free-text search
 * (matches activity title + notes). Filter pattern matches the
 * coach-side evaluation list so the UX is consistent.
 */
class FrontendMyActivitiesView extends FrontendViewBase {

    public static function render( object $player ): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id > 0 ) {
            // v3.92.1 — breadcrumb on the detail sub-view; renderDetail
            // doesn't add its own.
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
                __( 'Activity detail', 'talenttrack' ),
                [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'my-activities', __( 'My activities', 'talenttrack' ) ) ]
            );
            self::renderDetail( $player, $id );
            return;
        }

        self::enqueueAssets();
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My activities', 'talenttrack' ) );
        self::renderHeader( __( 'My activities', 'talenttrack' ) );

        // v3.92.7 — full migration to `FrontendListTable::render`. The
        // surface previously ran a custom $wpdb query (joined attendance
        // → activities, scoped to the player) and rendered a plain
        // `<table>` with an HTML form for filters. Switched to the
        // shared FrontendListTable component so the player's view picks
        // up the same chrome / sortable columns / pagination / search /
        // date-range / team filter / per-page chooser the admin lists
        // have. Server-side, `ActivitiesRestController::list_sessions`
        // accepts `filter[player_id]` (added in this release) and the
        // `can_view` permission gate allows the player or their parent
        // to read their own attendance via this filter.
        echo \TT\Shared\Frontend\Components\FrontendListTable::render( [
            'rest_path' => 'activities',
            'static_filters' => [
                'player_id' => (int) $player->id,
            ],
            'columns' => [
                'session_date'        => [ 'label' => __( 'Date',   'talenttrack' ), 'sortable' => true ],
                'title'               => [ 'label' => __( 'Title',  'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'title_link_html' ],
                'activity_type_key'   => [ 'label' => __( 'Type',   'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'activity_type_pill_html' ],
                'team_name'           => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true, 'render' => 'html', 'value_key' => 'team_link_html' ],
                'your_attendance_status' => [ 'label' => __( 'Your status', 'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'your_attendance_pill_html' ],
            ],
            'filters' => [
                'date' => [
                    'type'       => 'date_range',
                    'param_from' => 'date_from',
                    'param_to'   => 'date_to',
                    'label_from' => __( 'From', 'talenttrack' ),
                    'label_to'   => __( 'To',   'talenttrack' ),
                ],
            ],
            'search'       => [ 'placeholder' => __( 'Search title, location, team…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'session_date', 'order' => 'desc' ],
            'empty_state'  => __( 'No activities recorded for you yet.', 'talenttrack' ),
        ] );
    }

    /**
     * Single-activity detail reachable via `?tt_view=my-activities&id=N`.
     * Shows the activity (date, title, opponent, type), the player's
     * attendance for it, and any notes — closing the "see more"
     * gap from the profile + activities list (#0061).
     */
    private static function renderDetail( object $player, int $activity_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*, t.name AS team_name
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_teams t ON a.team_id = t.id
              WHERE a.id = %d
              LIMIT 1",
            $activity_id
        ) );

        if ( ! $row ) {
            self::renderHeader( __( 'Activity not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That activity is no longer available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        $att = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_attendance
              WHERE activity_id = %d AND ( player_id = %d OR guest_player_id = %d )
              LIMIT 1",
            $activity_id, (int) $player->id, (int) $player->id
        ) );

        self::enqueueAssets();
        $title = (string) \TT\Modules\Translations\TranslationLayer::render( (string) ( $row->title ?: '' ) );
        if ( $title === '' ) $title = __( 'Activity', 'talenttrack' );
        self::renderHeader( $title );

        // v3.92.5 — was a flat `<dl class="tt-profile-dl">` with no card
        // chrome, no badge for attendance status, and no visual grouping.
        // Pilot operator: "the display page of an activity is visually
        // not very appealing." Mirroring the goal-detail pattern
        // (`tt-goal-detail` wrapper + meta row with badges + body) so the
        // two surfaces feel consistent.
        $session_date = (string) ( $row->session_date ?: '' );
        $opponent     = (string) ( $row->opponent ?? '' );
        $location     = (string) ( $row->location ?? '' );
        $team_name    = (string) ( $row->team_name ?? '' );
        $att_status   = $att ? (string) ( $att->status ?? '' ) : '';
        $att_notes    = $att && ! empty( $att->notes ) ? (string) $att->notes : '';
        $type_key     = (string) ( $row->activity_type_key ?? '' );
        $att_status_lower = strtolower( $att_status );
        $att_status_class = $att_status_lower === 'present'
            ? 'tt-status-completed'
            : ( $att_status_lower === 'absent' ? 'tt-status-pending' : '' );
        ?>
        <article class="tt-activity-detail">
            <p class="tt-activity-detail-meta">
                <?php if ( $session_date !== '' ) : ?>
                    <span class="tt-due"><?php esc_html_e( 'Date:', 'talenttrack' ); ?> <?php echo esc_html( $session_date ); ?></span>
                <?php endif; ?>
                <?php if ( $team_name !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Team:', 'talenttrack' ); ?> <strong><?php echo esc_html( $team_name ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $opponent !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Opponent:', 'talenttrack' ); ?> <strong><?php echo esc_html( $opponent ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $location !== '' ) : ?>
                    <span class="tt-meta-chip"><?php esc_html_e( 'Location:', 'talenttrack' ); ?> <strong><?php echo esc_html( $location ); ?></strong></span>
                <?php endif; ?>
                <?php if ( $type_key !== '' ) :
                    // v3.92.7 — `LabelTranslator::activityType()` covers the
                    // seeded activity-type lookup rows. Falls back to a
                    // humanised key for custom types the operator added
                    // post-seed.
                    $type_label = LabelTranslator::activityType( $type_key );
                    if ( $type_label === null ) {
                        $type_label = ucfirst( str_replace( '_', ' ', $type_key ) );
                    }
                    ?>
                    <span class="tt-status-badge"><?php echo esc_html( $type_label ); ?></span>
                <?php endif; ?>
                <?php if ( $att_status !== '' ) : ?>
                    <span class="tt-status-badge <?php echo esc_attr( $att_status_class ); ?>">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: %s = attendance status label (Present / Absent / Late / etc.) */
                            __( 'Your attendance: %s', 'talenttrack' ),
                            LabelTranslator::attendanceStatus( $att_status )
                        ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ( $att_notes !== '' ) : ?>
                <section class="tt-activity-detail-body">
                    <h3 style="margin:0 0 var(--tt-sp-2); font-size:1rem;"><?php esc_html_e( 'Notes from your coach', 'talenttrack' ); ?></h3>
                    <p><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( $att_notes ) ); ?></p>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }

}
