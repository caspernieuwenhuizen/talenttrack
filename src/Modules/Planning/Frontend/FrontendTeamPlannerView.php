<?php
namespace TT\Modules\Planning\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTeamPlannerView (#0006) — team-planning calendar at
 * `?tt_view=team-planner`.
 *
 * Server-rendered grid: a stack of one or more 7-column weeks, each
 * showing the day's scheduled + completed activities pulled from
 * `tt_activities` filtered by team and date range. Coach picks the
 * range (1 / 2 / 4 / 8 weeks, or the full current season) via the
 * toolbar; navigates back/forward by the chosen range.
 *
 * Status pill comes from `activity_status_key` (the lookup-driven
 * field the user manages on the activities form), rendered through
 * `LookupPill` for visual parity with the activities list and admin
 * surface. The internal `plan_state` column stays on the row but is
 * not displayed — it duplicated the user-facing status and went stale
 * on legacy log-only rows where it defaults to `completed`.
 */
class FrontendTeamPlannerView extends FrontendViewBase {

    /**
     * Allowed range tokens. Map to a number of weeks; `season` is
     * resolved at runtime against `tt_seasons.is_current`.
     */
    private const RANGES = [
        'week'    => 1,
        '2weeks'  => 2,
        '4weeks'  => 4,
        '8weeks'  => 8,
        'season'  => 0, // resolved separately
    ];

    public static function render( int $user_id ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_plan' ) ) {
            self::renderHeader( __( 'Team planner', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to the team planner.', 'talenttrack' ) . '</p>';
            return;
        }
        self::enqueueAssets();
        wp_enqueue_style(
            'tt-team-planner',
            TT_PLUGIN_URL . 'assets/css/components/team-planner.css',
            [],
            TT_VERSION
        );
        // #1480 — soft "this is a holiday" confirm when scheduling from a
        // holiday day. Never blocks; Cancel just stops the navigation.
        wp_enqueue_script(
            'tt-planner-holiday-warning',
            TT_PLUGIN_URL . 'assets/js/planner-holiday-warning.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-planner-holiday-warning', 'TT_HOLIDAY', [
            /* translators: %s: holiday name */
            'warning' => __( 'This day is an academy holiday (%s). Schedule an activity anyway?', 'talenttrack' ),
        ] );
        FrontendBreadcrumbs::fromDashboard( __( 'Team planner', 'talenttrack' ) );
        self::renderHeader( __( 'Team planner', 'talenttrack' ) );

        $teams = self::teamsForUser( $user_id );
        if ( empty( $teams ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No teams available — assign yourself to a team first to use the planner.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : (int) $teams[0]->id;
        $team    = self::findTeam( $team_id, $teams );
        if ( $team === null ) {
            $team    = $teams[0];
            $team_id = (int) $team->id;
        }

        $range      = self::resolveRange( (string) ( $_GET['range'] ?? '' ) );
        $week_start = self::resolveWeekStart( (string) ( $_GET['week_start'] ?? '' ) );

        // Compute the rendered range. For `season`, snap the season
        // window to whole weeks (Mon–Sun) so the grid lines up.
        [ $range_start, $range_end, $weeks_count, $season ] = self::resolveRangeWindow( $range, $week_start );

        // #1480 — academy holidays overlapping the visible window, mapped
        // per day so each affected day shows a banner on the planner.
        self::loadHolidays( $range_start, $range_end );

        $activities    = self::activitiesForRange( $team_id, $range_start, $range_end );
        $activity_ids  = array_map( static fn ( $a ): int => (int) $a->id, $activities );
        $principle_map = self::principlesByActivity( $activity_ids );

        $can_manage = AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_plan' );
        // #1371 — most recent past activity per weekday, feeding the
        // "Copy last {weekday}" chips on empty day cells.
        $last_by_weekday = $can_manage ? self::lastActivityByWeekday( $team_id ) : [];

        echo self::renderToolbar( $teams, $team, $range, $range_start, $weeks_count, $season, $can_manage );
        echo self::renderExportActions( $team_id, $range_start, $range_end );
        echo self::renderRangeGrid( $range_start, $weeks_count, $activities, $principle_map, $team_id, $can_manage, $last_by_weekday );
        echo self::renderPrincipleCoverage( $team_id );
    }

    /**
     * #947 — Export PDF + XLSX buttons. Two side-by-side form POSTs
     * targeting admin-post.php (`action=tt_export`), one per format.
     * Buttons stack vertically below 480px so both stay ≥ 48px tap
     * targets on phones.
     *
     * PDF → `team_planning` exporter (new in this ship).
     * XLSX → `team_planner` exporter (#1269 / v4.20.59) — week-by-week
     * styled grid mirroring the online view. Switched from the
     * legacy `team_activities` flat exporter (which still exists for
     * direct REST callers who want raw rows). The new exporter uses
     * the `styled_sheets` payload shape shipped in v4.20.58.
     */
    private static function renderExportActions( int $team_id, string $date_from, string $date_to ): string {
        if ( $team_id <= 0 ) return '';
        $exports_url = add_query_arg( 'tt_view', 'exports', \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
        $self_url    = remove_query_arg( [ 'tt_export_error' ] );

        ob_start();
        ?>
        <div class="tt-planner-actions" style="display:flex; gap:8px; flex-wrap:wrap; margin: 4px 0 12px;">
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-export-form" style="margin:0;">
                <?php wp_nonce_field( 'tt_export', '_tt_export_nonce' ); ?>
                <input type="hidden" name="action"               value="tt_export">
                <input type="hidden" name="tt_export_key"        value="team_planning">
                <input type="hidden" name="format"               value="pdf">
                <input type="hidden" name="team_id"              value="<?php echo (int) $team_id; ?>">
                <input type="hidden" name="date_from"            value="<?php echo esc_attr( $date_from ); ?>">
                <input type="hidden" name="date_to"              value="<?php echo esc_attr( $date_to ); ?>">
                <input type="hidden" name="tt_export_return_url" value="<?php echo esc_attr( $self_url ); ?>">
                <button type="submit" class="tt-btn tt-btn-secondary" style="min-height:48px;">
                    <?php esc_html_e( 'Export PDF', 'talenttrack' ); ?>
                </button>
            </form>
            <form method="POST" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-export-form" style="margin:0;">
                <?php wp_nonce_field( 'tt_export', '_tt_export_nonce' ); ?>
                <input type="hidden" name="action"               value="tt_export">
                <input type="hidden" name="tt_export_key"        value="team_planner">
                <input type="hidden" name="format"               value="xlsx">
                <input type="hidden" name="team_id"              value="<?php echo (int) $team_id; ?>">
                <input type="hidden" name="date_from"            value="<?php echo esc_attr( $date_from ); ?>">
                <input type="hidden" name="date_to"              value="<?php echo esc_attr( $date_to ); ?>">
                <input type="hidden" name="tt_export_return_url" value="<?php echo esc_attr( $self_url ); ?>">
                <button type="submit" class="tt-btn tt-btn-secondary" style="min-height:48px;">
                    <?php esc_html_e( 'Export XLSX', 'talenttrack' ); ?>
                </button>
            </form>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderToolbar( array $teams, object $team, string $range, string $range_start, int $weeks_count, ?object $season, bool $can_manage ): string {
        // Step prev/next by the chosen range size; for `season` the
        // prev/next nav is hidden (the season picker is implicit —
        // currently always the `is_current` season).
        $step_days = max( 7, $weeks_count * 7 );
        $prev      = gmdate( 'Y-m-d', strtotime( $range_start . ' -' . $step_days . ' days' ) );
        $next      = gmdate( 'Y-m-d', strtotime( $range_start . ' +' . $step_days . ' days' ) );
        $today     = self::resolveWeekStart( '' );

        $range_options = [
            'week'    => __( 'One week', 'talenttrack' ),
            '2weeks'  => __( 'Two weeks', 'talenttrack' ),
            '4weeks'  => __( 'Four weeks', 'talenttrack' ),
            '8weeks'  => __( 'Eight weeks', 'talenttrack' ),
            'season'  => __( 'Full season', 'talenttrack' ),
        ];

        ob_start();
        ?>
        <div class="tt-planner-toolbar">
            <form method="get" class="tt-planner-team-picker">
                <input type="hidden" name="tt_view" value="team-planner" />
                <input type="hidden" name="week_start" value="<?php echo esc_attr( $range_start ); ?>" />
                <input type="hidden" name="range" value="<?php echo esc_attr( $range ); ?>" />
                <label for="tt-planner-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                <select id="tt-planner-team" name="team_id" onchange="this.form.submit()">
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo esc_attr( (string) $t->id ); ?>" <?php selected( (int) $t->id, (int) $team->id ); ?>>
                            <?php echo esc_html( (string) $t->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <form method="get" class="tt-planner-range-picker">
                <input type="hidden" name="tt_view" value="team-planner" />
                <input type="hidden" name="team_id" value="<?php echo esc_attr( (string) $team->id ); ?>" />
                <input type="hidden" name="week_start" value="<?php echo esc_attr( $range_start ); ?>" />
                <label for="tt-planner-range"><?php esc_html_e( 'Show', 'talenttrack' ); ?></label>
                <select id="tt-planner-range" name="range" onchange="this.form.submit()">
                    <?php foreach ( $range_options as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $range ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <?php if ( $range !== 'season' ) : ?>
                <div class="tt-planner-nav">
                    <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( self::buildUrl( $team->id, $prev, $range ) ); ?>">&larr; <?php echo esc_html( self::prevLabel( $weeks_count ) ); ?></a>
                    <a class="tt-btn" href="<?php echo esc_url( self::buildUrl( $team->id, $today, $range ) ); ?>"><?php esc_html_e( 'Today', 'talenttrack' ); ?></a>
                    <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( self::buildUrl( $team->id, $next, $range ) ); ?>"><?php echo esc_html( self::nextLabel( $weeks_count ) ); ?> &rarr;</a>
                </div>
            <?php elseif ( $season !== null ) : ?>
                <div class="tt-planner-season-label">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %s: season name (e.g. "2025/2026"). */
                        __( 'Season: %s', 'talenttrack' ),
                        (string) ( $season->name ?? '' )
                    ) );
                    ?>
                </div>
            <?php endif; ?>

            <?php if ( $can_manage ) : ?>
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [
                    'tt_view'      => 'activities',
                    'action'       => 'new',
                    'team_id'      => (int) $team->id,
                    'plan_state'   => 'scheduled',
                ], RecordLink::dashboardUrl() ) ) ); ?>">
                    + <?php esc_html_e( 'Schedule activity', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render `weeks_count` consecutive 7-day grids starting at
     * `range_start`. Each week sits in its own row; mobile collapses
     * to one column per day.
     *
     * @param object[]                    $activities
     * @param array<int, object[]>        $principle_map
     */
    private static function renderRangeGrid( string $range_start, int $weeks_count, array $activities, array $principle_map, int $team_id, bool $can_manage, array $last_by_weekday = [] ): string {
        $by_day = [];
        foreach ( $activities as $a ) {
            $d = (string) ( $a->session_date ?? '' );
            if ( $d === '' ) continue;
            $by_day[ $d ][] = $a;
        }

        ob_start();
        for ( $w = 0; $w < $weeks_count; $w++ ) {
            $week_start = gmdate( 'Y-m-d', strtotime( $range_start . ' +' . ( $w * 7 ) . ' days' ) );
            echo self::renderSingleWeek( $week_start, $by_day, $principle_map, $team_id, $can_manage, $weeks_count > 1, $last_by_weekday );
        }
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, object[]>     $by_day
     * @param array<int, object[]>        $principle_map
     */
    private static function renderSingleWeek( string $week_start, array $by_day, array $principle_map, int $team_id, bool $can_manage, bool $show_week_label, array $last_by_weekday = [] ): string {
        $today_str = gmdate( 'Y-m-d' );
        ob_start();
        ?>
        <?php if ( $show_week_label ) : ?>
            <h3 class="tt-planner-week-label">
                <?php
                $end_of_week = gmdate( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );
                echo esc_html( sprintf(
                    /* translators: 1: first day of the week, 2: last day. */
                    __( 'Week of %1$s — %2$s', 'talenttrack' ),
                    wp_date( 'M j', strtotime( $week_start ) ),
                    wp_date( 'M j', strtotime( $end_of_week ) )
                ) );
                ?>
            </h3>
        <?php endif; ?>
        <div class="tt-planner-week" role="list">
            <?php for ( $i = 0; $i < 7; $i++ ) :
                $day        = gmdate( 'Y-m-d', strtotime( $week_start . " +{$i} days" ) );
                $is_today   = $day === $today_str;
                $day_label  = wp_date( 'D', strtotime( $day ) );
                $date_label = wp_date( 'M j', strtotime( $day ) );
                $items      = $by_day[ $day ] ?? [];
                ?>
                <?php $holiday = self::$holidaysByDay[ $day ] ?? null; ?>
                <div class="tt-planner-day <?php echo $is_today ? 'tt-planner-day-today' : ''; ?><?php echo $holiday ? ' tt-planner-day-holiday' : ''; ?>" role="listitem"<?php echo $holiday ? ' data-tt-holiday-name="' . esc_attr( (string) $holiday->name ) . '"' : ''; ?>>
                    <div class="tt-planner-day-head">
                        <span class="tt-planner-dow"><?php echo esc_html( $day_label ); ?></span>
                        <span class="tt-planner-date"><?php echo esc_html( $date_label ); ?></span>
                    </div>
                    <?php if ( $holiday ) :
                        $h_color = (string) ( $holiday->color ?? '' );
                        if ( $h_color === '' ) $h_color = '#ff9800';
                        $h_note = (string) ( $holiday->note ?? '' );
                        ?>
                        <div class="tt-planner-holiday" style="--tt-holiday-color: <?php echo esc_attr( $h_color ); ?>;"<?php echo $h_note !== '' ? ' title="' . esc_attr( $h_note ) . '"' : ''; ?>>
                            <?php echo esc_html( (string) $holiday->name ); ?>
                        </div>
                    <?php endif; ?>
                    <div class="tt-planner-day-body">
                        <?php if ( empty( $items ) ) : ?>
                            <?php if ( $can_manage ) : ?>
                                <a class="tt-planner-empty" href="<?php echo esc_url( \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [
                                    'tt_view'      => 'activities',
                                    'action'       => 'new',
                                    'team_id'      => $team_id,
                                    'session_date' => $day,
                                    'plan_state'   => 'scheduled',
                                ], RecordLink::dashboardUrl() ) ) ); ?>">
                                    + <?php esc_html_e( 'Add', 'talenttrack' ); ?>
                                </a>
                                <?php
                                // #1371 — "Copy last {weekday}" chip:
                                // pre-fills the create form from the
                                // team's previous activity on this
                                // weekday. Future/today cells only —
                                // planning is forward.
                                $dow      = (int) gmdate( 'N', strtotime( $day ) );
                                $template = $last_by_weekday[ $dow ] ?? null;
                                if ( $template !== null && $day >= $today_str ) :
                                    $copy_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [
                                        'tt_view'        => 'activities',
                                        'action'         => 'new',
                                        'team_id'        => $team_id,
                                        'session_date'   => $day,
                                        'duplicate_from' => (int) $template->id,
                                        'plan_state'     => 'scheduled',
                                    ], RecordLink::dashboardUrl() ) );
                                    ?>
                                    <a class="tt-planner-copy-chip" href="<?php echo esc_url( $copy_url ); ?>" title="<?php echo esc_attr( (string) ( $template->title ?? '' ) ); ?>">
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: %s: localized weekday name (e.g. Tuesday) */
                                            __( 'Copy last %s', 'talenttrack' ),
                                            wp_date( 'l', strtotime( $day ) )
                                        ) );
                                        ?>
                                    </a>
                                <?php endif; ?>
                            <?php else : ?>
                                <p class="tt-planner-empty-readonly"><?php esc_html_e( '—', 'talenttrack' ); ?></p>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php foreach ( $items as $a ) : ?>
                                <?php echo self::renderActivityCard( $a, $principle_map[ (int) $a->id ] ?? [], $can_manage ); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<int, object> $principles */
    private static function renderActivityCard( object $a, array $principles, bool $can_manage = false ): string {
        // The status pill is driven by `activity_status_key` — the
        // lookup the user actually edits on the activities form. The
        // legacy `plan_state` column defaulted to `completed` on
        // every row created via the non-planner flow, which made the
        // planner show every activity as "Completed" regardless of
        // what the form said. LookupPill renders the same colour-coded
        // pill the activities list uses, so the two surfaces agree.
        $status_key  = (string) ( $a->activity_status_key ?? 'planned' );
        $state_class = 'tt-planner-state-' . sanitize_html_class( $status_key );
        // Land on the activity's display view, not the edit form —
        // display-first matches the rest of the app (player / goal /
        // evaluation / tournament list rows). Coaches with
        // `tt_edit_activities` see an Edit button on the detail view;
        // glancing at the activity no longer drops the coach into a
        // mutable form (and no longer risks accidental edits on mobile).
        // BackLink::appendTo so the detail view's own back-pill returns
        // the user to the planner page they came from.
        $url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [
            'tt_view' => 'activities',
            'id'      => (int) $a->id,
        ], RecordLink::dashboardUrl() ) );

        ob_start();
        ?>
        <a class="tt-planner-activity <?php echo esc_attr( $state_class ); ?>" href="<?php echo esc_url( $url ); ?>">
            <span class="tt-planner-activity-title"><?php echo esc_html( (string) ( $a->title ?? __( 'Activity', 'talenttrack' ) ) ); ?></span>
            <span class="tt-planner-activity-meta">
                <span class="tt-planner-activity-status">
                    <?php echo LookupPill::render( 'activity_status', $status_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </span>
                <?php
                // #1126 — optional time window. Rendered as
                // "HH:MM" or "HH:MM–HH:MM" depending on which fields
                // are set. Omitted entirely when start_time is empty.
                $st_card = (string) ( $a->start_time ?? '' );
                $et_card = (string) ( $a->end_time   ?? '' );
                if ( $st_card !== '' ) :
                    $time_text = substr( $st_card, 0, 5 ) . ( $et_card !== '' ? '–' . substr( $et_card, 0, 5 ) : '' );
                    ?>
                    <span class="tt-planner-activity-time"><?php echo esc_html( $time_text ); ?></span>
                <?php endif; ?>
                <?php if ( ! empty( $a->location ) ) : ?>
                    <span class="tt-planner-activity-loc"><?php echo esc_html( (string) $a->location ); ?></span>
                <?php endif; ?>
            </span>
            <?php if ( ! empty( $principles ) ) : ?>
                <span class="tt-planner-activity-principles">
                    <?php
                    // #1125 — show codes only (not titles), wrap at 4.
                    // Bucket colour-class derived from the code's first
                    // letter (A* = Aanvallen, V* = Verdedigen,
                    // O* = Omschakelen) matching the methodology
                    // browser's principle-bucket palette.
                    foreach ( array_slice( $principles, 0, 4 ) as $p ) :
                        $code   = (string) ( $p->code ?? '' );
                        $bucket = self::principleBucketFromCode( $code );
                        ?>
                        <span class="tt-planner-principle-chip"
                              data-bucket="<?php echo esc_attr( $bucket ); ?>"
                              title="<?php echo esc_attr( self::principleLabel( $p ) ); ?>">
                            <?php echo esc_html( $code !== '' ? $code : self::principleLabel( $p ) ); ?>
                        </span>
                    <?php endforeach; ?>
                    <?php if ( count( $principles ) > 4 ) : ?>
                        <span class="tt-planner-principle-more">+<?php echo (int) ( count( $principles ) - 4 ); ?></span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </a>
        <?php if ( $can_manage ) :
            // #1371 — duplicate this activity onto a new date (default
            // source + 7 days; the create form confirms before save).
            $dup_url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( [
                'tt_view'        => 'activities',
                'action'         => 'new',
                'duplicate_from' => (int) $a->id,
                'plan_state'     => 'scheduled',
            ], RecordLink::dashboardUrl() ) );
            ?>
            <a class="tt-planner-duplicate" href="<?php echo esc_url( $dup_url ); ?>" aria-label="<?php echo esc_attr( sprintf(
                /* translators: %s: activity title */
                __( 'Duplicate "%s" to a new date', 'talenttrack' ),
                (string) ( $a->title ?? '' )
            ) ); ?>">
                <?php esc_html_e( 'Duplicate', 'talenttrack' ); ?>
            </a>
        <?php endif; ?>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderPrincipleCoverage( int $team_id ): string {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d', strtotime( '-8 weeks' ) );
        // Coverage is what was actually trained — gate on the user-
        // facing `activity_status_key = 'completed'` rather than the
        // legacy `plan_state` column whose values were never visible
        // to the coach editing the activity.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.code, p.title_json, COUNT(DISTINCT ap.activity_id) AS hit_count
               FROM {$wpdb->prefix}tt_principles p
               JOIN {$wpdb->prefix}tt_activity_principles ap ON ap.principle_id = p.id
               JOIN {$wpdb->prefix}tt_activities a ON a.id = ap.activity_id
              WHERE a.team_id = %d
                AND a.club_id = %d
                AND a.session_date >= %s
                AND a.activity_status_key = 'completed'
              GROUP BY p.id, p.code, p.title_json
              ORDER BY hit_count DESC, p.code ASC
              LIMIT 10",
            $team_id, CurrentClub::id(), $cutoff
        ) );
        if ( empty( $rows ) ) return '';

        ob_start();
        ?>
        <section class="tt-planner-coverage">
            <h3><?php esc_html_e( 'Principles trained — last 8 weeks', 'talenttrack' ); ?></h3>
            <ul class="tt-planner-coverage-list">
                <?php foreach ( $rows as $r ) : ?>
                    <li>
                        <span class="tt-planner-principle-chip"><?php echo esc_html( self::principleLabel( $r ) ); ?></span>
                        <span class="tt-planner-coverage-count">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: count of activities touching the principle in the last 8 weeks. */
                                _n( '%d activity', '%d activities', (int) $r->hit_count, 'talenttrack' ),
                                (int) $r->hit_count
                            ) );
                            ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @return object[] teams the current user can access for the planner
     */
    private static function teamsForUser( int $user_id ): array {
        // #1124 — apply the same scope filter the teams page applies.
        // Admins + tt_edit_settings holders see every team; everyone
        // else is filtered through QueryHelpers::get_teams_for_coach()
        // which resolves both modern AuthorizationService scopes and
        // legacy tt_user_team_link rows.
        $is_admin = current_user_can( 'tt_edit_settings' ) || current_user_can( 'tt_view_all_teams' );
        if ( $is_admin ) {
            global $wpdb;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name FROM {$wpdb->prefix}tt_teams
                  WHERE club_id = %d
                    AND ( team_kind IS NULL OR team_kind = '' )
                  ORDER BY name ASC",
                CurrentClub::id()
            ) );
            return is_array( $rows ) ? $rows : [];
        }
        // Coach / AC / HC path. Filter by accessible team list and
        // drop trial / scout teams (`team_kind <> ''`) like the admin
        // query above.
        $accessible = \TT\Infrastructure\Query\QueryHelpers::get_teams_for_coach( $user_id );
        if ( ! $accessible ) return [];
        $out = [];
        foreach ( $accessible as $t ) {
            $kind = (string) ( $t->team_kind ?? '' );
            if ( $kind !== '' ) continue;
            $out[] = $t;
        }
        // Stable sort by name to match the admin path.
        usort( $out, static fn( $a, $b ): int => strcasecmp( (string) ( $a->name ?? '' ), (string) ( $b->name ?? '' ) ) );
        return $out;
    }

    /** @param object[] $teams */
    private static function findTeam( int $team_id, array $teams ): ?object {
        foreach ( $teams as $t ) {
            if ( (int) $t->id === $team_id ) return $t;
        }
        return null;
    }

    private static function resolveRange( string $raw ): string {
        return isset( self::RANGES[ $raw ] ) ? $raw : 'week';
    }

    private static function resolveWeekStart( string $raw ): string {
        if ( $raw !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            $ts = strtotime( $raw );
        } else {
            $ts = current_time( 'timestamp' );
        }
        // #1481 — snap to the academy's configured first day of the week
        // (Monday by default; Sunday when set in General settings).
        $dow = (int) gmdate( 'N', $ts ); // 1 (Mon) – 7 (Sun)
        $back = \TT\Shared\Dates\TTDate::weekStartsMonday()
            ? ( $dow - 1 )   // days back to the most recent Monday
            : ( $dow % 7 );  // days back to the most recent Sunday
        return gmdate( 'Y-m-d', $ts - $back * DAY_IN_SECONDS );
    }

    /**
     * Compute the actual rendered window for a given range.
     *
     * @return array{0:string,1:string,2:int,3:?object} [start, end, weeks_count, season|null]
     */
    private static function resolveRangeWindow( string $range, string $week_start ): array {
        if ( $range === 'season' ) {
            $season = ( new SeasonsRepository() )->current();
            if ( $season !== null && ! empty( $season->start_date ) && ! empty( $season->end_date ) ) {
                // Snap season window to whole weeks so the grid lines up.
                // Start = first-day-of-week on/before season start; end =
                // last-day-of-week on/after season end. #1481 — both ends
                // follow the academy's configured week start (Mon or Sun).
                $season_start = self::resolveWeekStart( (string) $season->start_date );
                $season_end   = gmdate( 'Y-m-d', strtotime( self::resolveWeekStart( (string) $season->end_date ) . ' +6 days' ) );
                $weeks        = (int) ceil( ( ( strtotime( $season_end ) - strtotime( $season_start ) ) / DAY_IN_SECONDS + 1 ) / 7 );
                if ( $weeks < 1 ) $weeks = 1;
                return [ $season_start, $season_end, $weeks, $season ];
            }
            // No current season configured — fall back to a single week.
            $end = gmdate( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );
            return [ $week_start, $end, 1, null ];
        }

        $weeks = self::RANGES[ $range ] ?? 1;
        if ( $weeks < 1 ) $weeks = 1;
        $end = gmdate( 'Y-m-d', strtotime( $week_start . ' +' . ( $weeks * 7 - 1 ) . ' days' ) );
        return [ $week_start, $end, $weeks, null ];
    }

    private static function prevLabel( int $weeks_count ): string {
        if ( $weeks_count <= 1 ) return __( 'Previous week', 'talenttrack' );
        return sprintf(
            /* translators: %d: number of weeks. */
            _n( 'Previous %d week', 'Previous %d weeks', $weeks_count, 'talenttrack' ),
            $weeks_count
        );
    }

    private static function nextLabel( int $weeks_count ): string {
        if ( $weeks_count <= 1 ) return __( 'Next week', 'talenttrack' );
        return sprintf(
            /* translators: %d: number of weeks. */
            _n( 'Next %d week', 'Next %d weeks', $weeks_count, 'talenttrack' ),
            $weeks_count
        );
    }

    private static function buildUrl( int $team_id, string $week_start, string $range ): string {
        return add_query_arg( [
            'tt_view'    => 'team-planner',
            'team_id'    => $team_id,
            'week_start' => $week_start,
            'range'      => $range,
        ], RecordLink::dashboardUrl() );
    }

    /**
     * #1480 — academy holidays mapped per `Y-m-d` day across the visible
     * window. Set once per render, read in the day-cell loop.
     *
     * @var array<string,object>
     */
    private static array $holidaysByDay = [];

    /**
     * Pre-fetch the holidays overlapping [$from, $to] and expand them to
     * a per-day map. Guarded so a disabled Holidays module is a no-op.
     */
    private static function loadHolidays( string $from, string $to ): void {
        self::$holidaysByDay = [];
        if ( ! class_exists( '\\TT\\Modules\\Holidays\\Repositories\\HolidaysRepository' ) ) {
            return;
        }
        $holidays = ( new \TT\Modules\Holidays\Repositories\HolidaysRepository() )->list( $from, $to );
        foreach ( $holidays as $h ) {
            $start = max( $from, (string) $h->start_date );
            $end   = min( $to,   (string) $h->end_date );
            $ts    = strtotime( $start );
            $endTs = strtotime( $end );
            while ( $ts !== false && $endTs !== false && $ts <= $endTs ) {
                self::$holidaysByDay[ gmdate( 'Y-m-d', $ts ) ] = $h;
                $ts += DAY_IN_SECONDS;
            }
        }
    }

    /** @return object[] */
    private static function activitiesForRange( int $team_id, string $from, string $to ): array {
        global $wpdb;
        // Filter by `activity_status_key` (the user-facing lookup).
        // `plan_state` was the legacy filter; on rows created via the
        // non-planner flow it always defaults to `completed`, so it
        // cannot be relied on as a "is this cancelled" signal.
        // #1127 — exclude soft-deleted activities. Without this filter
        // the planner kept rendering cards for activities the operator
        // archived via Spond sync / activity admin; clicking them
        // routed to the activity-detail "no longer exists" notice.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, start_time, end_time, location, activity_status_key, plan_state
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND session_date BETWEEN %s AND %s
                AND activity_status_key <> 'cancelled'
                AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY session_date ASC, start_time ASC, id ASC",
            $team_id, CurrentClub::id(), $from, $to
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #1371 — the team's most recent activity per weekday (Mon=1 …
     * Sun=7), today or earlier. Feeds the "Copy last {weekday}" chips:
     * an empty Tuesday cell offers a copy of the last Tuesday session
     * when one exists. Cancelled + archived rows never qualify as
     * templates.
     *
     * @return array<int, object> ISO weekday → {id, session_date, title}
     */
    private static function lastActivityByWeekday( int $team_id ): array {
        global $wpdb;
        // One bounded scan: newest-first within the last 26 weeks, then
        // first-seen-per-weekday in PHP (no window functions — MySQL
        // 5.6 floor).
        $cutoff = gmdate( 'Y-m-d', strtotime( '-26 weeks' ) );
        $rows   = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_date, title
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND session_date BETWEEN %s AND %s
                AND activity_status_key <> 'cancelled'
                AND ( archived_at IS NULL OR archived_at = '' )
              ORDER BY session_date DESC, id DESC
              LIMIT 200",
            $team_id, CurrentClub::id(), $cutoff, gmdate( 'Y-m-d' )
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $dow = (int) gmdate( 'N', strtotime( (string) $r->session_date ) );
            if ( ! isset( $out[ $dow ] ) ) $out[ $dow ] = $r;
            if ( count( $out ) === 7 ) break;
        }
        return $out;
    }

    /**
     * @param int[] $activity_ids
     * @return array<int, object[]>  activity_id → list of principle rows
     */
    private static function principlesByActivity( array $activity_ids ): array {
        if ( empty( $activity_ids ) ) return [];
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ap.activity_id, p.id, p.code, p.title_json
               FROM {$wpdb->prefix}tt_activity_principles ap
               JOIN {$wpdb->prefix}tt_principles p ON p.id = ap.principle_id
              WHERE ap.activity_id IN ($placeholders)
              ORDER BY ap.sort_order ASC, ap.id ASC",
            ...$activity_ids
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $aid = (int) $r->activity_id;
            $out[ $aid ][] = $r;
        }
        return $out;
    }

    /**
     * #1125 — derive the methodology bucket from a principle's code
     * prefix. A* = aanvallen, V* = verdedigen, O* = omschakelen.
     * Used to colour-code planner chips without needing a SELECT
     * extension for `team_function_key`.
     */
    private static function principleBucketFromCode( string $code ): string {
        if ( $code === '' ) return '';
        $first = strtoupper( $code[0] );
        switch ( $first ) {
            case 'A': return 'aanvallen';
            case 'V': return 'verdedigen';
            case 'O': return 'omschakelen';
            default:  return '';
        }
    }

    private static function principleLabel( object $p ): string {
        $title_json = (string) ( $p->title_json ?? '' );
        if ( $title_json !== '' ) {
            $decoded = json_decode( $title_json, true );
            if ( is_array( $decoded ) ) {
                $locale = get_locale();
                if ( ! empty( $decoded[ $locale ] ) ) return (string) $decoded[ $locale ];
                $short = substr( $locale, 0, 2 );
                if ( ! empty( $decoded[ $short ] ) ) return (string) $decoded[ $short ];
                if ( ! empty( $decoded['en'] ) ) return (string) $decoded['en'];
                $first = reset( $decoded );
                if ( $first ) return (string) $first;
            }
        }
        return (string) ( $p->code ?? '' );
    }
}
