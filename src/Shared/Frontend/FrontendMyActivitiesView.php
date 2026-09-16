<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Activities\ActivitiesRepository;

/**
 * FrontendMyActivitiesView — the "My activities" tile destination.
 *
 * Two halves (#3390): a short **Coming up** block of the team's next
 * activities, then the history — what the player actually turned up to,
 * most-recent first, bounded to today so the surface stops opening on
 * next month. Filterable by date range and free-text search. Rendered via
 * the shared FrontendListTable, whose filter chrome is the shared 2026
 * FilterBar (component-level migration, #2082 / epic #2017), so this
 * player surface matches the staff Activities list one-for-one — the
 * inline row on tablet+, the bottom sheet on phones.
 *
 * #2074 (epic #2017 Phase 2) decisions:
 *   - Q1 filter altitude: path (A), component-level — FrontendListTable
 *     already renders its filters through FilterBar, so no per-view
 *     filter code is needed here.
 *   - Q2 status column: keep "Your status" (the player's own attendance:
 *     Present / Absent / …). Player-centric — the activity-status lens
 *     (Planned / Completed) is the staff view's concern, not the
 *     player's.
 *   - Q3 table head/rows: the 2026 restyle (small-caps head + row hover)
 *     lives in assets/css/frontend-my-activities.css, scoped to
 *     `.tt-myact-list`, mirroring frontend-players-list.css.
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
        //
        // #2074 — the filter row this renders is the shared 2026 FilterBar
        // (FrontendListTable routes its filters through it since #2082), so
        // no per-view filter markup is needed. The static `player_id` scope
        // below keeps the list to the player's OWN activities; the REST
        // contract is unchanged.
        // #3390 — what is coming up, above what already happened.
        //
        // The list below is a history: the tile promises "activities you have
        // attended" and it sorts newest-first. On any install with a season
        // planned ahead that meant the whole first page was next month, so the
        // surface opened on the future and buried the thing it was for.
        self::renderComingUp( $player );

        echo '<div class="tt-myact-list">';
        echo \TT\Shared\Frontend\Components\FrontendListTable::render( [
            'rest_path' => 'activities',
            // #3390 — `date_to` bounds the list to what has happened, so the
            // history stops opening on the future. A static filter yields to
            // the player's own date-range control (the JS hydrator only fills
            // a key the user left empty), which is the behaviour we want: the
            // default is history, and a player who deliberately widens the
            // range sees what they asked for — with no status pill on it,
            // because the column now reads recorded attendance only.
            'static_filters' => [
                'player_id' => (int) $player->id,
                'date_to'   => current_time( 'Y-m-d' ),
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
     * #3390 — the forward-looking half of this surface.
     *
     * Deliberately not a second `FrontendListTable`: there is nothing to
     * filter, sort or page through in the next five activities, and it
     * carries **no status column** — a squad the coach has planned is not
     * something the player has attended, and putting any pill on it would
     * publish a selection decision the coach has not announced.
     *
     * Reads `ActivitiesRepository::upcomingForTeam()`, the same source the
     * development home and the profile's Upcoming card use, so the three
     * agree about what "next" means (from today, excluding completed and
     * cancelled, soonest first).
     *
     * Renders nothing at all when there is nothing to show — an empty
     * "Coming up" card on a player's screen in the off-season is noise.
     */
    private static function renderComingUp( object $player ): void {
        $team_id = (int) ( $player->team_id ?? 0 );
        if ( $team_id <= 0 ) return;

        $rows = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )
            ->upcomingForTeam( $team_id, 5 );
        if ( empty( $rows ) ) return;

        ?>
        <section class="tt-myact-upcoming">
            <h3 class="tt-myact-upcoming__title"><?php esc_html_e( 'Coming up', 'talenttrack' ); ?></h3>
            <ul class="tt-myact-upcoming__list">
                <?php foreach ( $rows as $row ) :
                    $activity_id = (int) ( $row->id ?? 0 );
                    $title       = trim( (string) ( $row->title ?? '' ) );
                    if ( $title === '' ) $title = __( 'Activity', 'talenttrack' );
                    $location = trim( (string) ( $row->location ?? '' ) );
                    $date     = \TT\Shared\Dates\TTDate::dateWithDay( (string) ( $row->session_date ?? '' ) );
                    $url      = add_query_arg(
                        [ 'tt_view' => 'my-activities', 'id' => $activity_id ],
                        \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                    );
                    ?>
                    <li class="tt-myact-upcoming__item">
                        <a class="tt-myact-upcoming__link" href="<?php echo esc_url( $url ); ?>">
                            <span class="tt-myact-upcoming__date"><?php echo esc_html( $date ); ?></span>
                            <span class="tt-myact-upcoming__label"><?php echo esc_html( $title ); ?></span>
                            <?php if ( $location !== '' ) : ?>
                                <span class="tt-myact-upcoming__loc"><?php echo esc_html( $location ); ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
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
        // #2909 — compare against the canonical member, not a lowercased copy.
        // This used to `strtolower()` and compare to a constant that was also
        // lowercase; once AttendanceStatus became Title Case that comparison
        // would have been false for every row, silently dropping the status
        // colour rather than erroring.
        $att_canonical    = AttendanceStatus::normalise( $att_status );
        $att_status_class = $att_canonical === AttendanceStatus::PRESENT
            ? 'tt-status-completed'
            : ( $att_canonical === AttendanceStatus::ABSENT ? 'tt-status-pending' : '' );
        ?>
        <article class="tt-activity-detail">
            <p class="tt-activity-detail-meta">
                <?php if ( $session_date !== '' ) : ?>
                    <span class="tt-due"><?php esc_html_e( 'Date:', 'talenttrack' ); ?> <?php echo esc_html( \TT\Shared\Dates\TTDate::dateWithDay( $session_date ) ); ?></span>
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
