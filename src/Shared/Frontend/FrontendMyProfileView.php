<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\Stats\Admin\PlayerCardView;

/**
 * FrontendMyProfileView — the "My profile" tile destination.
 *
 * #0014 Sprint 2 rebuild. Six sections: hero (photo + identity + FIFA
 * card), playing details, recent performance (rolling avg + sparkline),
 * active goals, upcoming activities, account. Read-only — players don't
 * edit their own playing fields; that stays a coach action.
 */
class FrontendMyProfileView extends FrontendViewBase {

    public static function render( object $player ): void {
        self::enqueueAssets();
        self::enqueueProfileStyles();
        self::renderHeader( __( 'My profile', 'talenttrack' ) );

        $user = wp_get_current_user();
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        $stats = new PlayerStatsService();
        $headline = $stats->getHeadlineNumbers( (int) $player->id, [], 5 );
        $sparkline = self::sparklineForPlayer( (int) $player->id, 10 );
        $active_goals = self::activeGoalsForPlayer( (int) $player->id, 3 );
        $active_goals_total = self::countActiveGoalsForPlayer( (int) $player->id );
        $upcoming = $team ? self::upcomingForTeam( (int) $team->id, 3 ) : [];

        ?>
        <div class="tt-profile">
            <?php self::renderHero( $player, $team, $user ); ?>
            <div class="tt-profile-grid">
                <?php self::renderPlayingDetails( $player, $team ); ?>
                <?php self::renderRecentPerformance( $headline, $sparkline ); ?>
                <?php self::renderActiveGoals( $active_goals, $active_goals_total ); ?>
                <?php self::renderUpcoming( $upcoming, $team ); ?>
                <?php self::renderAccount( $user ); ?>
            </div>
        </div>
        <?php
    }

    private static function enqueueProfileStyles(): void {
        wp_enqueue_style(
            'tt-frontend-profile',
            TT_PLUGIN_URL . 'assets/css/frontend-profile.css',
            [],
            TT_VERSION
        );
    }

    private static function renderHero( object $player, ?object $team, \WP_User $user ): void {
        $name        = QueryHelpers::player_display_name( $player );
        $team_name   = $team ? (string) $team->name : '';
        $age_group   = $team ? (string) ( $team->age_group ?? '' ) : '';
        $jersey      = $player->jersey_number ? '#' . (int) $player->jersey_number : '';
        $photo       = (string) ( $player->photo_url ?? '' );
        $initials    = self::initialsFor( $name );
        ?>
        <section class="tt-profile-hero">
            <div class="tt-profile-hero-identity">
                <?php if ( $photo !== '' ) : ?>
                    <img class="tt-profile-photo" src="<?php echo esc_url( $photo ); ?>" alt="" />
                <?php else : ?>
                    <span class="tt-profile-photo tt-profile-photo--placeholder" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
                <?php endif; ?>
                <div class="tt-profile-hero-text">
                    <h2 class="tt-profile-name"><?php echo esc_html( $name ); ?></h2>
                    <p class="tt-profile-meta">
                        <?php
                        $bits = array_filter( [ $team_name, $age_group, $jersey ], static fn( $v ) => $v !== '' );
                        echo esc_html( implode( ' · ', $bits ) );
                        ?>
                    </p>
                </div>
            </div>
            <div class="tt-profile-hero-card">
                <?php PlayerCardView::renderCard( (int) $player->id, 'sm', true ); ?>
            </div>
        </section>
        <?php
    }

    private static function renderPlayingDetails( object $player, ?object $team ): void {
        $pos = json_decode( (string) $player->preferred_positions, true );
        $rows = [];
        if ( $team ) {
            $rows[] = [ __( 'Team', 'talenttrack' ),       (string) $team->name ];
            if ( ! empty( $team->age_group ) ) {
                $rows[] = [ __( 'Age group', 'talenttrack' ), (string) $team->age_group ];
            }
        }
        if ( is_array( $pos ) && $pos ) {
            $rows[] = [ __( 'Positions', 'talenttrack' ), implode( ', ', $pos ) ];
        }
        if ( ! empty( $player->preferred_foot ) ) {
            $rows[] = [
                __( 'Preferred foot', 'talenttrack' ),
                LookupTranslator::byTypeAndName( 'foot_option', (string) $player->preferred_foot ),
            ];
        }
        if ( ! empty( $player->jersey_number ) ) {
            $rows[] = [ __( 'Jersey #', 'talenttrack' ), '#' . (int) $player->jersey_number ];
        }
        if ( ! empty( $player->height_cm ) ) {
            $rows[] = [ __( 'Height', 'talenttrack' ), $player->height_cm . ' cm' ];
        }
        if ( ! empty( $player->weight_kg ) ) {
            $rows[] = [ __( 'Weight', 'talenttrack' ), $player->weight_kg . ' kg' ];
        }
        if ( ! empty( $player->date_of_birth ) ) {
            $rows[] = [ __( 'Date of birth', 'talenttrack' ), (string) $player->date_of_birth ];
        }
        ?>
        <section class="tt-profile-card">
            <h3 class="tt-profile-card-title"><?php esc_html_e( 'Playing details', 'talenttrack' ); ?></h3>
            <dl class="tt-profile-dl">
                <?php foreach ( $rows as [ $label, $value ] ) : ?>
                    <dt><?php echo esc_html( $label ); ?></dt>
                    <dd><?php echo esc_html( $value ); ?></dd>
                <?php endforeach; ?>
            </dl>
            <p class="tt-profile-note">
                <?php esc_html_e( 'Playing details are maintained by your coach. Ask them if anything is wrong.', 'talenttrack' ); ?>
            </p>
        </section>
        <?php
    }

    /**
     * @param array{latest:?float, rolling:?float, alltime:?float, eval_count:int, rolling_count:int} $headline
     * @param array{points: float[], min: float, max: float, trend: string}                              $sparkline
     */
    private static function renderRecentPerformance( array $headline, array $sparkline ): void {
        $rolling     = $headline['rolling'];
        $count       = (int) $headline['eval_count'];
        $rolling_n   = (int) $headline['rolling_count'];
        $trend       = (string) $sparkline['trend'];
        ?>
        <section class="tt-profile-card">
            <h3 class="tt-profile-card-title"><?php esc_html_e( 'Recent performance', 'talenttrack' ); ?></h3>
            <?php if ( $count === 0 || $rolling === null ) : ?>
                <p class="tt-profile-empty">
                    <?php esc_html_e( "No evaluations yet — your first review will appear here once your coach completes one.", 'talenttrack' ); ?>
                </p>
            <?php else : ?>
                <div class="tt-profile-perf-headline">
                    <span class="tt-profile-perf-rating"><?php echo esc_html( number_format_i18n( $rolling, 1 ) ); ?></span>
                    <span class="tt-profile-perf-trend tt-profile-perf-trend--<?php echo esc_attr( $trend ); ?>" aria-hidden="true">
                        <?php echo esc_html( self::trendArrow( $trend ) ); ?>
                    </span>
                </div>
                <p class="tt-profile-perf-meta">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: 1: rolling-window count, 2: total evaluation count */
                        _n(
                            'Rolling average over your last %1$d evaluation. %2$d total recorded.',
                            'Rolling average over your last %1$d evaluations. %2$d total recorded.',
                            $rolling_n,
                            'talenttrack'
                        ),
                        $rolling_n,
                        $count
                    ) );
                    ?>
                </p>
                <?php echo self::renderSparkline( $sparkline ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pre-built SVG. ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<int, object> $goals
     */
    private static function renderActiveGoals( array $goals, int $total ): void {
        ?>
        <section class="tt-profile-card">
            <h3 class="tt-profile-card-title"><?php esc_html_e( 'Active goals', 'talenttrack' ); ?></h3>
            <?php if ( empty( $goals ) ) : ?>
                <p class="tt-profile-empty">
                    <?php esc_html_e( 'No active goals right now. Your coach will set new ones during the next review.', 'talenttrack' ); ?>
                </p>
            <?php else : ?>
                <ul class="tt-profile-goals">
                    <?php foreach ( $goals as $goal ) :
                        $title    = (string) ( $goal->title ?? '' );
                        $due      = (string) ( $goal->due_date ?? '' );
                        $priority = (string) ( $goal->priority ?? '' );
                        $detail   = self::dashboardLink( 'my-goals', (int) ( $goal->id ?? 0 ) );
                        ?>
                        <li class="tt-profile-goal">
                            <a class="tt-profile-goal-link" href="<?php echo esc_url( $detail ); ?>">
                                <span class="tt-profile-goal-title"><?php echo esc_html( $title ); ?></span>
                                <?php if ( $due !== '' ) : ?>
                                    <span class="tt-profile-goal-due">
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: %s: due date in YYYY-MM-DD */
                                            __( 'Due %s', 'talenttrack' ),
                                            $due
                                        ) );
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $priority !== '' ) : ?>
                                    <span class="tt-profile-goal-priority tt-profile-goal-priority--<?php echo esc_attr( strtolower( $priority ) ); ?>">
                                        <?php echo esc_html( \TT\Infrastructure\Query\LabelTranslator::goalPriority( $priority ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $total > count( $goals ) ) : ?>
                    <p class="tt-profile-card-link">
                        <a href="<?php echo esc_url( self::dashboardLink( 'my-goals' ) ); ?>">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: total active-goal count */
                                __( 'See all %d goals', 'talenttrack' ),
                                $total
                            ) );
                            ?>
                        </a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * @param array<int, object> $sessions
     */
    private static function renderUpcoming( array $sessions, ?object $team ): void {
        ?>
        <section class="tt-profile-card">
            <h3 class="tt-profile-card-title"><?php esc_html_e( 'Upcoming', 'talenttrack' ); ?></h3>
            <?php if ( ! $team ) : ?>
                <p class="tt-profile-empty">
                    <?php esc_html_e( "You're not on a team yet, so there's nothing scheduled.", 'talenttrack' ); ?>
                </p>
            <?php elseif ( empty( $sessions ) ) : ?>
                <p class="tt-profile-empty">
                    <?php esc_html_e( 'Nothing on the calendar in the next few weeks.', 'talenttrack' ); ?>
                </p>
            <?php else : ?>
                <ul class="tt-profile-upcoming">
                    <?php foreach ( $sessions as $session ) :
                        $title    = (string) ( $session->title ?? '' );
                        $date     = (string) ( $session->session_date ?? '' );
                        $location = (string) ( $session->location ?? '' );
                        $detail   = self::dashboardLink( 'my-activities', (int) ( $session->id ?? 0 ) );
                        ?>
                        <li class="tt-profile-upcoming-row">
                            <a class="tt-profile-upcoming-link" href="<?php echo esc_url( $detail ); ?>">
                                <span class="tt-profile-upcoming-date"><?php echo esc_html( $date ); ?></span>
                                <span class="tt-profile-upcoming-title"><?php echo esc_html( $title ); ?></span>
                                <?php if ( $location !== '' ) : ?>
                                    <span class="tt-profile-upcoming-loc"><?php echo esc_html( $location ); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="tt-profile-card-link">
                    <a href="<?php echo esc_url( self::dashboardLink( 'my-activities' ) ); ?>">
                        <?php esc_html_e( 'See all sessions', 'talenttrack' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    private static function renderAccount( \WP_User $user ): void {
        $wp_profile_url = get_edit_profile_url( (int) $user->ID );
        ?>
        <section class="tt-profile-card">
            <h3 class="tt-profile-card-title"><?php esc_html_e( 'Account', 'talenttrack' ); ?></h3>
            <dl class="tt-profile-dl">
                <dt><?php esc_html_e( 'Display name', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( (string) $user->display_name ); ?></dd>
                <dt><?php esc_html_e( 'Email', 'talenttrack' ); ?></dt>
                <dd><?php echo esc_html( (string) $user->user_email ); ?></dd>
            </dl>
            <p>
                <a class="tt-profile-account-edit" href="<?php echo esc_url( $wp_profile_url ); ?>">
                    <?php esc_html_e( 'Edit account settings', 'talenttrack' ); ?>
                </a>
            </p>
        </section>
        <?php
    }

    /**
     * Sparkline of the last N overall ratings, oldest-left.
     *
     * @return array{points: float[], min: float, max: float, trend: 'up'|'down'|'flat'|'insufficient'}
     */
    private static function sparklineForPlayer( int $player_id, int $n ): array {
        $stats = new PlayerStatsService();
        $evals = $stats->getEvaluationsForPlayer( $player_id, [] );
        $evals = array_slice( $evals, - max( 1, $n ) );
        if ( empty( $evals ) ) {
            return [ 'points' => [], 'min' => 0.0, 'max' => 0.0, 'trend' => 'insufficient' ];
        }

        $eval_ids = array_map( static fn( $e ) => (int) $e->id, $evals );
        $overalls = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( $eval_ids );

        $values = [];
        foreach ( $evals as $ev ) {
            $eid = (int) $ev->id;
            $v   = $overalls[ $eid ]['value'] ?? null;
            if ( $v !== null ) {
                $values[] = (float) $v;
            }
        }
        if ( count( $values ) < 2 ) {
            return [
                'points' => $values,
                'min'    => $values ? min( $values ) : 0.0,
                'max'    => $values ? max( $values ) : 0.0,
                'trend'  => 'insufficient',
            ];
        }

        // Trend: split half/half, compare means with a 0.15 dead-zone
        // matching PlayerStatsService::getMainCategoryBreakdown so the
        // arrow direction agrees with the rest of the rate-card UI.
        $mid_idx = intdiv( count( $values ), 2 );
        $older   = array_slice( $values, 0, $mid_idx );
        $newer   = array_slice( $values, $mid_idx );
        $om      = array_sum( $older ) / max( 1, count( $older ) );
        $nm      = array_sum( $newer ) / max( 1, count( $newer ) );
        $delta   = $nm - $om;
        $trend   = abs( $delta ) < 0.15 ? 'flat' : ( $delta > 0 ? 'up' : 'down' );

        return [
            'points' => $values,
            'min'    => min( $values ),
            'max'    => max( $values ),
            'trend'  => $trend,
        ];
    }

    /**
     * @param array{points: float[], min: float, max: float, trend: string} $sparkline
     */
    private static function renderSparkline( array $sparkline ): string {
        $points = $sparkline['points'];
        if ( count( $points ) < 2 ) return '';

        $w   = 240;
        $h   = 56;
        $pad = 4;
        $count = count( $points );
        $min = $sparkline['min'];
        $max = $sparkline['max'];
        // Avoid a divide-by-zero when every point is identical.
        $range = max( 0.001, $max - $min );

        $coords = [];
        foreach ( $points as $i => $v ) {
            $x = $pad + ( $i / ( $count - 1 ) ) * ( $w - 2 * $pad );
            $y = $pad + ( 1 - ( ( $v - $min ) / $range ) ) * ( $h - 2 * $pad );
            $coords[] = round( $x, 2 ) . ',' . round( $y, 2 );
        }
        $polyline = implode( ' ', $coords );
        $last_xy  = end( $coords );
        [ $lx, $ly ] = array_map( 'floatval', explode( ',', $last_xy ) );

        return '<svg class="tt-profile-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true">'
            . '<polyline points="' . esc_attr( $polyline ) . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />'
            . '<circle cx="' . $lx . '" cy="' . $ly . '" r="3" fill="currentColor" />'
            . '</svg>';
    }

    private static function trendArrow( string $trend ): string {
        switch ( $trend ) {
            case 'up':   return '↗';
            case 'down': return '↘';
            case 'flat': return '→';
            default:     return '·';
        }
    }

    /**
     * @return array<int, object>
     */
    private static function activeGoalsForPlayer( int $player_id, int $limit ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, status, priority, due_date
               FROM {$p}tt_goals
              WHERE player_id = %d
                AND archived_at IS NULL
                AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )
              ORDER BY ( due_date IS NULL ), due_date ASC, id DESC
              LIMIT %d",
            $player_id,
            $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function countActiveGoalsForPlayer( int $player_id ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals
              WHERE player_id = %d
                AND archived_at IS NULL
                AND ( status IS NULL OR status NOT IN ( 'completed', 'cancelled' ) )",
            $player_id
        ) );
    }

    /**
     * @return array<int, object>
     */
    private static function upcomingForTeam( int $team_id, int $limit ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $today = current_time( 'Y-m-d' );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, location
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND archived_at IS NULL
                AND session_date >= %s
              ORDER BY session_date ASC, id ASC
              LIMIT %d",
            $team_id,
            $today,
            $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function dashboardLink( string $view, int $id = 0 ): string {
        $base = remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab', 'action', 'id' ]
        );
        $url = add_query_arg( 'tt_view', $view, $base ?: home_url( '/' ) );
        if ( $id > 0 ) $url = add_query_arg( 'id', $id, $url );
        return $url;
    }

    private static function initialsFor( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $initials = '';
        foreach ( $parts as $part ) {
            if ( $part === '' ) continue;
            $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
            if ( mb_strlen( $initials ) >= 2 ) break;
        }
        return $initials !== '' ? $initials : '–';
    }
}
