<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Activities\ActivitiesRepository;

/**
 * FrontendMyActivitiesView — the "My sessions" tile destination.
 *
 * Lists activities attended by the logged-in player, most-recent
 * first. Filterable by status, date range, and free-text search
 * (matches activity title + notes). Filter pattern matches the
 * coach-side evaluation list so the UX is consistent.
 */
class FrontendMyActivitiesView extends FrontendViewBase {

    /**
     * #1901 — 2026 chrome for the activity detail + the list's mobile
     * cards, on top of the shared frontend assets. Scoped to this view.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-my-activities',
            TT_PLUGIN_URL . 'assets/css/frontend-my-activities.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( object $player ): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $id > 0 ) {
            // v3.110.46 — migrated from fromDashboardWithBack() (referer-
            // based back crumb) to plain fromDashboard(). The
            // tt_back-borne pill is now the canonical "back to where I
            // came from" affordance per docs/back-navigation.md, and
            // FrontendBreadcrumbs::render() auto-renders it above the
            // chain when the entry URL captured a back-target.
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
        echo '<div class="tt-myact-list">';
        echo \TT\Shared\Frontend\Components\FrontendListTable::render( [
            'rest_path' => 'activities',
            'static_filters' => [
                'player_id' => (int) $player->id,
            ],
            // #1986 — player surface: rows are NOT clickable (the only detail
            // link pointed at the staff `?tt_view=activities` view, which a
            // player isn't authorised for). All player-allowed information is
            // surfaced inline instead, so there's nothing to click through to.
            'columns' => [
                'session_date'        => [ 'label' => __( 'Date',   'talenttrack' ), 'sortable' => true ],
                'title'               => [ 'label' => __( 'Title',  'talenttrack' ), 'sortable' => true ],
                'activity_type_key'   => [ 'label' => __( 'Type',   'talenttrack' ), 'sortable' => false, 'render' => 'html', 'value_key' => 'activity_type_pill_html' ],
                'team_name'           => [ 'label' => __( 'Team',   'talenttrack' ), 'sortable' => true ],
                'location'            => [ 'label' => __( 'Location', 'talenttrack' ), 'sortable' => false ],
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
            // #1362 — guided fresh empty state. Player-self surface:
            // activities are planned at team level by the coach, so
            // there's no CTA — the explainer sets the expectation.
            'empty_state_card' => [
                'icon'      => 'activities',
                'headline'  => __( 'No activities recorded for you yet', 'talenttrack' ),
                'explainer' => __( 'When your coach plans trainings or matches for your team, they show up here together with your attendance.', 'talenttrack' ),
            ],
        ] );
        echo '</div>';
    }

    /**
     * Single-activity detail reachable via `?tt_view=my-activities&id=N`.
     * Shows the activity (date, title, opponent, type), the player's
     * attendance for it, and any notes — closing the "see more"
     * gap from the profile + activities list (#0061).
     */
    private static function renderDetail( object $player, int $activity_id ): void {
        // #1078 — was inline activity SQL + separate attendance fetch
        // + inline LabelTranslator calls below. ActivitiesRepository
        // centralises both queries into one JOIN, hydrates the row
        // with activity_type_localised + attendance_status_localised,
        // so this view echoes the localised fields by construction.
        // Same shape as #1077 GoalsRepository / #1081 worked example.
        $row = ( new ActivitiesRepository() )->findForPlayer( $activity_id, (int) $player->id );

        if ( ! $row ) {
            self::renderHeader( __( 'Activity not found', 'talenttrack' ) );
            echo '<p><em>' . esc_html__( 'That activity is no longer available.', 'talenttrack' ) . '</em></p>';
            return;
        }

        // Back-compat with the rest of this method which referenced a
        // separate `$att` row. The repository joined attendance fields
        // onto the activity row as `attendance_*`, so expose them via
        // the same shape.
        $att = null;
        if ( ! empty( $row->attendance_status ) ) {
            $att = (object) [
                'status' => (string) $row->attendance_status,
                'notes'  => (string) ( $row->attendance_notes ?? '' ),
            ];
        }

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
        $att_status_class = $att_status_lower === AttendanceStatus::PRESENT
            ? 'tt-status-completed'
            : ( $att_status_lower === AttendanceStatus::ABSENT ? 'tt-status-pending' : '' );
        ?>
        <article class="tt-activity-detail">
            <p class="tt-activity-detail-meta">
                <?php if ( $session_date !== '' ) : ?>
                    <span class="tt-due"><?php esc_html_e( 'Date:', 'talenttrack' ); ?> <?php echo esc_html( \TT\Shared\Dates\TTDate::date( $session_date ) ); ?></span>
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
                    // #1078 — repository pre-localises into
                    // `activity_type_localised` (humanise-fallback
                    // included), so the view just echoes.
                    ?>
                    <span class="tt-status-badge"><?php echo esc_html( (string) $row->activity_type_localised ); ?></span>
                <?php endif; ?>
                <?php if ( $att_status !== '' ) : ?>
                    <span class="tt-status-badge <?php echo esc_attr( $att_status_class ); ?>">
                        <?php
                        // #1078 — pre-localised attendance status from
                        // the repository's hydrate() pass.
                        echo esc_html( sprintf(
                            /* translators: %s = attendance status label (Present / Absent / Late / etc.) */
                            __( 'Your attendance: %s', 'talenttrack' ),
                            (string) ( $row->attendance_status_localised ?? '' )
                        ) );
                        ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ( $att_notes !== '' ) : ?>
                <section class="tt-activity-detail-body">
                    <h3 class="tt-activity-detail-body__h"><?php esc_html_e( 'Notes from your coach', 'talenttrack' ); ?></h3>
                    <p><?php echo esc_html( \TT\Modules\Translations\TranslationLayer::render( $att_notes ) ); ?></p>
                </section>
            <?php endif; ?>
        </article>
        <?php
    }

}
