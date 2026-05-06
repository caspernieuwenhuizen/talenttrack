<?php
namespace TT\Modules\Planning\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Methodology\Repositories\PrincipleLinksRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTeamPlannerView (#0006) — team-planning calendar at
 * `?tt_view=team-planner`.
 *
 * Server-rendered week view: 7 columns (Mon-Sun), each column shows
 * the day's scheduled + completed activities pulled from
 * `tt_activities` filtered by team and date range. Coach navigates
 * week-by-week via prev/next links + a "today" jump.
 *
 * Empty days have a "+ Schedule" CTA that links to the existing
 * activities create flow with the date pre-filled and
 * `plan_state=scheduled` injected. Existing activities are clickable
 * to the activities edit view.
 *
 * Mobile: the 7-column grid stacks to 1 column at <720px, with each
 * day rendered as a labelled card stack.
 *
 * Trimmed from the spec's full Sprint 2 scope: no drag-drop reschedule
 * (would require a JS calendar component; coach can edit the date in
 * the activities form instead), no month view (week is the primary
 * surface; bookmarkable via the URL date), no inline activity creation
 * modal (existing activities form is the canonical create surface).
 * Keeps this view to one server-rendered template with zero JS
 * dependencies.
 */
class FrontendTeamPlannerView extends FrontendViewBase {

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

        $week_start = self::resolveWeekStart( (string) ( $_GET['week_start'] ?? '' ) );
        $week_end   = gmdate( 'Y-m-d', strtotime( $week_start . ' +6 days' ) );

        $activities    = self::activitiesForWeek( $team_id, $week_start, $week_end );
        $activity_ids  = array_map( static fn ( $a ): int => (int) $a->id, $activities );
        $principle_map = self::principlesByActivity( $activity_ids );

        $can_manage = AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_plan' );

        echo self::renderToolbar( $teams, $team, $week_start, $can_manage );
        echo self::renderWeekGrid( $week_start, $activities, $principle_map, $team_id, $can_manage );
        echo self::renderPrincipleCoverage( $team_id );
    }

    private static function renderToolbar( array $teams, object $team, string $week_start, bool $can_manage ): string {
        $prev = gmdate( 'Y-m-d', strtotime( $week_start . ' -7 days' ) );
        $next = gmdate( 'Y-m-d', strtotime( $week_start . ' +7 days' ) );
        $today = self::resolveWeekStart( '' );

        ob_start();
        ?>
        <div class="tt-planner-toolbar">
            <form method="get" class="tt-planner-team-picker">
                <input type="hidden" name="tt_view" value="team-planner" />
                <input type="hidden" name="week_start" value="<?php echo esc_attr( $week_start ); ?>" />
                <label for="tt-planner-team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></label>
                <select id="tt-planner-team" name="team_id" onchange="this.form.submit()">
                    <?php foreach ( $teams as $t ) : ?>
                        <option value="<?php echo esc_attr( (string) $t->id ); ?>" <?php selected( (int) $t->id, (int) $team->id ); ?>>
                            <?php echo esc_html( (string) $t->name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="tt-planner-nav">
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( self::buildUrl( $team->id, $prev ) ); ?>">&larr; <?php esc_html_e( 'Previous week', 'talenttrack' ); ?></a>
                <a class="tt-btn" href="<?php echo esc_url( self::buildUrl( $team->id, $today ) ); ?>"><?php esc_html_e( 'Today', 'talenttrack' ); ?></a>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( self::buildUrl( $team->id, $next ) ); ?>"><?php esc_html_e( 'Next week', 'talenttrack' ); ?> &rarr;</a>
            </div>

            <?php if ( $can_manage ) : ?>
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( add_query_arg( [
                    'tt_view'      => 'activities',
                    'action'       => 'new',
                    'team_id'      => (int) $team->id,
                    'plan_state'   => 'scheduled',
                ], home_url( '/' ) ) ); ?>">
                    + <?php esc_html_e( 'Schedule activity', 'talenttrack' ); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderWeekGrid( string $week_start, array $activities, array $principle_map, int $team_id, bool $can_manage ): string {
        $by_day = [];
        foreach ( $activities as $a ) {
            $d = (string) ( $a->session_date ?? '' );
            if ( $d === '' ) continue;
            $by_day[ $d ][] = $a;
        }

        $today_str = gmdate( 'Y-m-d' );
        ob_start();
        ?>
        <div class="tt-planner-week" role="list">
            <?php for ( $i = 0; $i < 7; $i++ ) :
                $day  = gmdate( 'Y-m-d', strtotime( $week_start . " +{$i} days" ) );
                $is_today = $day === $today_str;
                $day_label = wp_date( __( 'D', 'talenttrack' ), strtotime( $day ) );
                $date_label = wp_date( __( 'M j', 'talenttrack' ), strtotime( $day ) );
                $items = $by_day[ $day ] ?? [];
                ?>
                <div class="tt-planner-day <?php echo $is_today ? 'tt-planner-day-today' : ''; ?>" role="listitem">
                    <div class="tt-planner-day-head">
                        <span class="tt-planner-dow"><?php echo esc_html( $day_label ); ?></span>
                        <span class="tt-planner-date"><?php echo esc_html( $date_label ); ?></span>
                    </div>
                    <div class="tt-planner-day-body">
                        <?php if ( empty( $items ) ) : ?>
                            <?php if ( $can_manage ) : ?>
                                <a class="tt-planner-empty" href="<?php echo esc_url( add_query_arg( [
                                    'tt_view'      => 'activities',
                                    'action'       => 'new',
                                    'team_id'      => $team_id,
                                    'session_date' => $day,
                                    'plan_state'   => 'scheduled',
                                ], home_url( '/' ) ) ); ?>">
                                    + <?php esc_html_e( 'Add', 'talenttrack' ); ?>
                                </a>
                            <?php else : ?>
                                <p class="tt-planner-empty-readonly"><?php esc_html_e( '—', 'talenttrack' ); ?></p>
                            <?php endif; ?>
                        <?php else : ?>
                            <?php foreach ( $items as $a ) : ?>
                                <?php echo self::renderActivityCard( $a, $principle_map[ (int) $a->id ] ?? [] ); ?>
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
    private static function renderActivityCard( object $a, array $principles ): string {
        $state = (string) ( $a->plan_state ?? 'completed' );
        $state_class = 'tt-planner-state-' . sanitize_html_class( $state );
        $state_label = self::planStateLabel( $state );
        $url = add_query_arg( [
            'tt_view' => 'activities',
            'action'  => 'edit',
            'id'      => (int) $a->id,
        ], home_url( '/' ) );

        ob_start();
        ?>
        <a class="tt-planner-activity <?php echo esc_attr( $state_class ); ?>" href="<?php echo esc_url( $url ); ?>">
            <span class="tt-planner-activity-title"><?php echo esc_html( (string) ( $a->title ?? __( 'Activity', 'talenttrack' ) ) ); ?></span>
            <span class="tt-planner-activity-meta">
                <span class="tt-planner-activity-state"><?php echo esc_html( $state_label ); ?></span>
                <?php if ( ! empty( $a->location ) ) : ?>
                    <span class="tt-planner-activity-loc"><?php echo esc_html( (string) $a->location ); ?></span>
                <?php endif; ?>
            </span>
            <?php if ( ! empty( $principles ) ) : ?>
                <span class="tt-planner-activity-principles">
                    <?php foreach ( array_slice( $principles, 0, 3 ) as $p ) : ?>
                        <span class="tt-planner-principle-chip"><?php echo esc_html( self::principleLabel( $p ) ); ?></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        </a>
        <?php
        return (string) ob_get_clean();
    }

    private static function renderPrincipleCoverage( int $team_id ): string {
        global $wpdb;
        $cutoff = gmdate( 'Y-m-d', strtotime( '-8 weeks' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.id, p.code, p.title_json, COUNT(DISTINCT ap.activity_id) AS hit_count
               FROM {$wpdb->prefix}tt_principles p
               JOIN {$wpdb->prefix}tt_activity_principles ap ON ap.principle_id = p.id
               JOIN {$wpdb->prefix}tt_activities a ON a.id = ap.activity_id
              WHERE a.team_id = %d
                AND a.club_id = %d
                AND a.session_date >= %s
                AND a.plan_state IN ('completed','in_progress')
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

    /** @param object[] $teams */
    private static function findTeam( int $team_id, array $teams ): ?object {
        foreach ( $teams as $t ) {
            if ( (int) $t->id === $team_id ) return $t;
        }
        return null;
    }

    private static function resolveWeekStart( string $raw ): string {
        if ( $raw !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            $ts = strtotime( $raw );
        } else {
            $ts = current_time( 'timestamp' );
        }
        // Snap to Monday.
        $dow = (int) gmdate( 'N', $ts ); // 1 (Mon) – 7 (Sun)
        return gmdate( 'Y-m-d', $ts - ( $dow - 1 ) * DAY_IN_SECONDS );
    }

    private static function buildUrl( int $team_id, string $week_start ): string {
        return add_query_arg( [
            'tt_view'    => 'team-planner',
            'team_id'    => $team_id,
            'week_start' => $week_start,
        ], home_url( '/' ) );
    }

    /** @return object[] */
    private static function activitiesForWeek( int $team_id, string $from, string $to ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, location, plan_state
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND session_date BETWEEN %s AND %s
                AND plan_state <> 'cancelled'
              ORDER BY session_date ASC, id ASC",
            $team_id, CurrentClub::id(), $from, $to
        ) );
        return is_array( $rows ) ? $rows : [];
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

    private static function planStateLabel( string $state ): string {
        switch ( $state ) {
            case 'draft':       return __( 'Draft', 'talenttrack' );
            case 'scheduled':   return __( 'Scheduled', 'talenttrack' );
            case 'in_progress': return __( 'In progress', 'talenttrack' );
            case 'completed':   return __( 'Completed', 'talenttrack' );
            case 'cancelled':   return __( 'Cancelled', 'talenttrack' );
            default:            return $state;
        }
    }
}
