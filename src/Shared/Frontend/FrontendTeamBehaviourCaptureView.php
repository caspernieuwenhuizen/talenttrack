<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;

/**
 * FrontendTeamBehaviourCaptureView (#872) — bulk behaviour-rating grid
 * for a single team.
 *
 * Sub-ship C of epic #867. The natural coach workflow is "I just
 * finished training; rate the players who showed up." This view
 * renders the full roster in one form so the coach submits N behaviour
 * rows in one shot rather than N detours through the per-player surface.
 *
 * Slug: `team-behaviour-capture` (requires `team_id`).
 * Cap:  `tt_rate_player_behaviour` (route-level).
 * Team scope: coaches see only teams they're assigned to via
 * `QueryHelpers::get_teams_for_coach`; HoDs + admins see all teams.
 *
 * Layout: one form-level activity picker at the top (defaults to the
 * team's most recent completed activity), one row per active player
 * below with a rating dropdown + notes input. Submit writes one
 * `tt_player_behaviour_ratings` row per non-blank rating, all sharing
 * the chosen `related_activity_id`. Blank rows write nothing.
 *
 * Out of scope: per-row save (single-shot form POST is enough for v1),
 * REST `POST /teams/{id}/behaviour-ratings/bulk` (future SaaS port),
 * potential bulk grid (quarterly cadence doesn't fit this surface).
 */
final class FrontendTeamBehaviourCaptureView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_team_behaviour_capture';
    public const NONCE_FIELD  = '_tt_team_behaviour_nonce';

    public static function render( int $user_id, bool $is_admin ): void {
        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Bulk-record behaviour', 'talenttrack' ),
            [
                \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'teams', __( 'Teams', 'talenttrack' ) ),
            ]
        );

        if ( ! current_user_can( 'tt_rate_player_behaviour' ) ) {
            self::renderHeader( __( 'Bulk-record behaviour', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to record behaviour ratings.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( $team_id <= 0 ) {
            self::renderHeader( __( 'Bulk-record behaviour', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'A team is required. Click "Bulk-record behaviour" from a team detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        // Team scope gate — coaches only see their own teams; HoDs +
        // admins see all.
        $see_all_global = current_user_can( 'tt_manage_players' );
        if ( ! $see_all_global ) {
            $teams = QueryHelpers::get_teams_for_coach( $user_id );
            $allowed = array_map( static fn( $t ) => (int) $t->id, (array) $teams );
            if ( ! in_array( $team_id, $allowed, true ) ) {
                self::renderHeader( __( 'Bulk-record behaviour', 'talenttrack' ) );
                echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this team.', 'talenttrack' ) . '</p>';
                return;
            }
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            $team_id, CurrentClub::id()
        ) );
        if ( ! $team ) {
            self::renderHeader( __( 'Team not found', 'talenttrack' ) );
            return;
        }

        $flash = '';
        $row_errors = [];

        if ( $_SERVER['REQUEST_METHOD'] === 'POST'
             && isset( $_POST[ self::NONCE_FIELD ] )
             && wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
            [ $flash, $row_errors ] = self::handlePost( $team_id );
        }

        $players = QueryHelpers::get_players( $team_id );
        $recent_activities = self::loadRecentActivitiesForTeam( $team_id, 20 );

        $rmin = (int) round( (float) QueryHelpers::get_config( 'rating_min', '5' ) );
        $rmax = (int) round( (float) QueryHelpers::get_config( 'rating_max', '10' ) );

        self::enqueueAssets();
        self::enqueueViewCss();
        self::renderHeader( sprintf(
            /* translators: %s = team name */
            __( 'Bulk-record behaviour — %s', 'talenttrack' ),
            (string) $team->name
        ) );

        if ( $flash !== '' ) {
            echo '<div class="tt-notice tt-notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                . esc_html( $flash ) . '</div>';
        }

        if ( empty( $players ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No active players on this team yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_url = add_query_arg(
            [ 'tt_view' => 'teams', 'id' => $team_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );
        ?>
        <p style="color:#5b6e75; max-width:60ch;">
            <?php esc_html_e( 'Tie behaviour ratings to a single activity. Leave a player blank to skip — only filled-in rows are saved.', 'talenttrack' ); ?>
        </p>
        <form method="post" class="tt-tbc-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
            <div class="tt-tbc-activity-row">
                <label class="tt-tbc-activity-label" for="tt-tbc-activity">
                    <?php esc_html_e( 'Related activity', 'talenttrack' ); ?>
                </label>
                <select id="tt-tbc-activity" name="related_activity_id" class="tt-input tt-tbc-activity-select">
                    <option value="0"><?php esc_html_e( '— none —', 'talenttrack' ); ?></option>
                    <?php foreach ( $recent_activities as $i => $act ) :
                        $selected = $i === 0 ? ' selected' : '';
                    ?>
                        <option value="<?php echo (int) $act->id; ?>"<?php echo $selected; ?>>
                            <?php echo esc_html( sprintf( '%s · %s', (string) $act->session_date, (string) $act->title ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tt-tbc-roster">
                <?php foreach ( $players as $pl ) :
                    $pid    = (int) $pl->id;
                    $name   = QueryHelpers::player_display_name( $pl );
                    $jersey = isset( $pl->jersey_number ) && (int) $pl->jersey_number > 0 ? (int) $pl->jersey_number : null;
                    $err    = $row_errors[ $pid ] ?? '';
                    $rid    = 'tt-tbc-rating-' . $pid;
                    $nid    = 'tt-tbc-notes-' . $pid;
                ?>
                    <div class="tt-tbc-row">
                        <div class="tt-tbc-name">
                            <?php if ( $jersey !== null ) : ?>
                                <span class="tt-tbc-jersey">#<?php echo (int) $jersey; ?></span>
                            <?php endif; ?>
                            <?php echo esc_html( $name ); ?>
                        </div>
                        <div class="tt-tbc-controls">
                            <label class="tt-tbc-control-label" for="<?php echo esc_attr( $rid ); ?>">
                                <span class="tt-tbc-control-text"><?php esc_html_e( 'Rating', 'talenttrack' ); ?></span>
                                <select id="<?php echo esc_attr( $rid ); ?>" class="tt-input" name="behaviour[<?php echo $pid; ?>][rating]">
                                    <option value=""><?php esc_html_e( '— skip —', 'talenttrack' ); ?></option>
                                    <?php for ( $v = $rmin; $v <= $rmax; $v++ ) : ?>
                                        <option value="<?php echo (int) $v; ?>"><?php echo (int) $v; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </label>
                            <label class="tt-tbc-control-label" for="<?php echo esc_attr( $nid ); ?>">
                                <span class="tt-tbc-control-text"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></span>
                                <input type="text" id="<?php echo esc_attr( $nid ); ?>" class="tt-input"
                                       name="behaviour[<?php echo $pid; ?>][notes]"
                                       placeholder="<?php esc_attr_e( 'Optional one-liner', 'talenttrack' ); ?>" />
                            </label>
                        </div>
                        <?php if ( $err !== '' ) : ?>
                            <div class="tt-tbc-row-error"><?php echo esc_html( $err ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="tt-tbc-footer">
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $team_url ); ?>">
                    <?php esc_html_e( 'Cancel', 'talenttrack' ); ?>
                </a>
                <button type="submit" class="tt-btn tt-btn-primary">
                    <?php esc_html_e( 'Save all ratings', 'talenttrack' ); ?>
                </button>
            </div>
        </form>
        <?php
    }

    /**
     * Handle the bulk submit. Returns [ flash, row_errors ].
     *
     * @return array{0:string, 1:array<int,string>}
     */
    private static function handlePost( int $team_id ): array {
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '5' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '10' );

        $related_activity_id = isset( $_POST['related_activity_id'] ) ? absint( $_POST['related_activity_id'] ) : 0;
        $entries = isset( $_POST['behaviour'] ) && is_array( $_POST['behaviour'] )
            ? wp_unslash( $_POST['behaviour'] )
            : [];

        $repo = new PlayerBehaviourRatingsRepository();
        $created = 0;
        $row_errors = [];

        foreach ( $entries as $pid => $row ) {
            $pid = (int) $pid;
            if ( $pid <= 0 || ! is_array( $row ) ) continue;
            $raw_rating = isset( $row['rating'] ) ? (string) $row['rating'] : '';
            if ( $raw_rating === '' ) continue; // skipped — no error.
            $rating = (float) $raw_rating;
            if ( $rating < $rmin || $rating > $rmax ) {
                $row_errors[ $pid ] = sprintf(
                    /* translators: 1: min, 2: max */
                    __( 'Rating must be between %1$s and %2$s.', 'talenttrack' ),
                    (string) $rmin, (string) $rmax
                );
                continue;
            }
            $notes = isset( $row['notes'] ) ? sanitize_text_field( (string) $row['notes'] ) : '';
            $repo->create( [
                'player_id'           => $pid,
                'rating'              => $rating,
                'rated_at'            => current_time( 'mysql' ),
                'rated_by'            => get_current_user_id(),
                'related_activity_id' => $related_activity_id > 0 ? $related_activity_id : null,
                'notes'               => $notes !== '' ? $notes : null,
            ] );
            $created++;
        }

        if ( $created === 0 && $row_errors === [] ) {
            return [ __( 'No ratings entered. Fill in at least one row before saving.', 'talenttrack' ), [] ];
        }

        $flash = sprintf(
            /* translators: %d: number of ratings recorded */
            _n( '%d behaviour rating recorded.', '%d behaviour ratings recorded.', $created, 'talenttrack' ),
            $created
        );
        return [ $flash, $row_errors ];
    }

    /**
     * 20 most-recent completed activities for the team. Mirrors the
     * pattern used by `FrontendPlayerStatusCaptureView`. Most-recent
     * first so the dropdown defaults to the activity the coach
     * probably just finished.
     *
     * @return list<object>
     */
    private static function loadRecentActivitiesForTeam( int $team_id, int $limit = 20 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_date, title
               FROM {$p}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND activity_status_key = %s
                AND archived_at IS NULL
              ORDER BY session_date DESC, id DESC
              LIMIT %d",
            $team_id, CurrentClub::id(), 'completed', $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function enqueueViewCss(): void {
        wp_enqueue_style(
            'tt-frontend-team-behaviour-capture',
            TT_PLUGIN_URL . 'assets/css/frontend-team-behaviour-capture.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
    }
}
