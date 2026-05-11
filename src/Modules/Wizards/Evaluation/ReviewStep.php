<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * ReviewStep (#0072) — final step. Lists what's about to be created
 * and submits N evaluations on activity-first OR a single evaluation
 * on player-first.
 *
 * Submit semantics: writes `tt_evaluations` rows directly (one per
 * rated player on activity-first; one on player-first), with
 * `tt_eval_ratings` rows per non-zero category rating. Errors cause
 * the wizard to surface a soft warn at the top instead of failing
 * the whole batch.
 */
final class ReviewStep implements WizardStepInterface {

    public function slug(): string  { return 'review'; }
    public function label(): string { return __( 'Review', 'talenttrack' ); }

    public function render( array $state ): void {
        if ( ( $state['_path'] ?? '' ) === 'activity-first' ) {
            $this->renderActivityReview( $state );
        } else {
            $this->renderPlayerReview( $state );
        }
    }

    private function renderActivityReview( array $state ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $aid     = (int) ( $state['activity_id'] ?? 0 );
        $ratings = (array) ( $state['ratings'] ?? [] );
        $notes   = (array) ( $state['notes'] ?? [] );
        $skip    = (array) ( $state['skip'] ?? [] );
        $present_players = RateActorsStep::ratablePlayersForActivity( $aid );

        $rated_count = 0;
        $unrated     = [];
        $rows        = [];
        $activity_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_date FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );
        $eval_date = $activity_row && $activity_row->session_date ? (string) $activity_row->session_date : current_time( 'Y-m-d' );
        foreach ( $present_players as $pl ) {
            $pid = (int) $pl->id;
            if ( ! empty( $skip[ $pid ] ) ) continue;
            $r = (array) ( $ratings[ $pid ] ?? [] );
            $any = false;
            foreach ( $r as $v ) { if ( (int) $v > 0 ) { $any = true; break; } }
            if ( $any ) {
                $rated_count++;
                $rows[] = [
                    'activity_id' => $aid,
                    'player_id'   => $pid,
                    'eval_date'   => $eval_date,
                    'ratings'     => array_map( 'intval', $r ),
                    'notes'       => (string) ( $notes[ $pid ] ?? '' ),
                ];
            } else {
                $unrated[] = trim( (string) $pl->first_name . ' ' . (string) $pl->last_name );
            }
        }

        $redirect_url = add_query_arg( [
            'tt_view'     => 'evaluations',
            'activity_id' => $aid,
        ], WizardEntryPoint::dashboardBaseUrl() );

        $payload = [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/wizards/new-evaluation/insert-row' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'rows'       => $rows,
            'redirect_url' => esc_url_raw( $redirect_url ),
            'i18n_writing' => __( 'Writing evaluation %1$d of %2$d…', 'talenttrack' ),
            'i18n_done'    => __( 'Done — redirecting…', 'talenttrack' ),
            'i18n_failed'  => __( 'One or more rows failed. Try again or go back to fix the input.', 'talenttrack' ),
        ];
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php
            printf(
                /* translators: %d: rated player count */
                esc_html( _n( '%d evaluation will be created.', '%d evaluations will be created.', $rated_count, 'talenttrack' ) ),
                $rated_count
            );
            ?>
        </p>
        <?php if ( ! empty( $unrated ) ) : ?>
            <div class="tt-notice tt-notice-warning" style="background:#fef3c7;border:1px solid #d97706;padding:8px 12px;border-radius:6px;margin:8px 0;">
                <strong><?php esc_html_e( 'Heads up:', 'talenttrack' ); ?></strong>
                <?php
                printf(
                    /* translators: %d: unrated player count */
                    esc_html( _n( '%d player was present but not rated. Submit anyway, or go back?', '%d players were present but not rated. Submit anyway, or go back?', count( $unrated ), 'talenttrack' ) ),
                    count( $unrated )
                );
                ?>
                <span style="color:var(--tt-muted);"><?php echo esc_html( implode( ', ', $unrated ) ); ?></span>
            </div>
        <?php endif; ?>
        <p style="color:var(--tt-muted);font-size:13px;"><?php esc_html_e( 'Click Submit to write the evaluations.', 'talenttrack' ); ?></p>
        <div class="tt-pd-eval-progress" data-tt-eval-progress hidden>
            <progress class="tt-pd-eval-progress-bar" max="100" value="0"></progress>
            <p class="tt-pd-eval-progress-status" data-tt-eval-progress-status></p>
        </div>
        <script type="application/json" id="tt-eval-review-payload"><?php echo wp_json_encode( $payload ); ?></script>
        <?php
        wp_enqueue_script(
            'tt-eval-review-progress',
            TT_PLUGIN_URL . 'assets/js/wizard-eval-review.js',
            [],
            TT_VERSION,
            true
        );
    }

    private function renderPlayerReview( array $state ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $pid = (int) ( $state['player_id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$p}tt_players WHERE id = %d AND club_id = %d",
            $pid, CurrentClub::id()
        ) );
        $name = $row ? trim( (string) $row->first_name . ' ' . (string) $row->last_name ) : '';

        $ratings = (array) ( $state['ratings_self'] ?? [] );
        $non_zero = array_filter( $ratings, static fn( $v ) => (int) $v > 0 );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php
            /* translators: %s: player display name */
            printf( esc_html__( 'One evaluation will be created for %s.', 'talenttrack' ), '<strong>' . esc_html( $name ) . '</strong>' );
            ?>
        </p>
        <p><?php
            printf(
                /* translators: %d: number of categories rated */
                esc_html( _n( '%d category rated.', '%d categories rated.', count( $non_zero ), 'talenttrack' ) ),
                count( $non_zero )
            );
        ?></p>
        <p style="color:var(--tt-muted);font-size:13px;"><?php esc_html_e( 'Click Submit to write the evaluation.', 'talenttrack' ); ?></p>
        <?php
    }

    public function validate( array $post, array $state ) { return []; }
    public function nextStep( array $state ): ?string { return null; } // last step
    public function submit( array $state ) {
        if ( ( $state['_path'] ?? '' ) === 'activity-first' ) {
            return $this->submitActivityFirst( $state );
        }
        return $this->submitPlayerFirst( $state );
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    private function submitActivityFirst( array $state ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $aid     = (int) ( $state['activity_id'] ?? 0 );
        $ratings = (array) ( $state['ratings'] ?? [] );
        $notes   = (array) ( $state['notes'] ?? [] );
        $skip    = (array) ( $state['skip'] ?? [] );

        $activity_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_date FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $aid, CurrentClub::id()
        ) );
        $eval_date = $activity_row && $activity_row->session_date ? (string) $activity_row->session_date : current_time( 'Y-m-d' );

        $created = 0;
        foreach ( $ratings as $player_id => $cats ) {
            $player_id = (int) $player_id;
            if ( $player_id <= 0 ) continue;
            if ( ! empty( $skip[ $player_id ] ) ) continue;
            if ( ! is_array( $cats ) || empty( $cats ) ) continue;

            $any = false;
            foreach ( $cats as $v ) { if ( (int) $v > 0 ) { $any = true; break; } }
            if ( ! $any ) continue;

            $result = EvaluationInserter::insert( [
                'activity_id' => $aid,
                'player_id'   => $player_id,
                'eval_date'   => $eval_date,
                'ratings'     => $cats,
                'notes'       => (string) ( $notes[ $player_id ] ?? '' ),
            ] );
            if ( ! is_wp_error( $result ) ) $created++;
        }

        // v3.110.73 — wizards can override the post-submit redirect via
        // `_done_redirect`. MarkAttendanceWizard sets the dashboard URL
        // so the coach returns to where they started the flow; the
        // new-evaluation wizard leaves it unset and keeps landing on
        // the evaluations list scoped to the activity.
        $override = isset( $state['_done_redirect'] ) ? (string) $state['_done_redirect'] : '';
        $redirect = $override !== ''
            ? $override
            : add_query_arg( [
                'tt_view'     => 'evaluations',
                'activity_id' => $aid,
            ], WizardEntryPoint::dashboardBaseUrl() );

        return [ 'redirect_url' => $redirect, 'created' => $created ];
    }

    /**
     * @return array<string,mixed>|\WP_Error
     */
    private function submitPlayerFirst( array $state ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $pid     = (int) ( $state['player_id'] ?? 0 );
        $ratings = (array) ( $state['ratings_self'] ?? [] );
        $date    = (string) ( $state['eval_date'] ?? current_time( 'Y-m-d' ) );
        $reason  = (string) ( $state['eval_reason'] ?? '' );
        // v3.110.67 — persist `eval_type_id` (FK into tt_lookups,
        // lookup_type='eval_type'). HybridDeepRateStep used to write
        // a slug to `eval_setting` in wizard state but never carried
        // it through to the row, so the wizard's type pick was
        // discarded silently and reopening for edit showed a default
        // type. Now the wizard captures `eval_type_id` and the
        // insert below stores it on the row.
        $type_id = (int) ( $state['eval_type_id'] ?? 0 );

        if ( $pid <= 0 ) {
            return new \WP_Error( 'no_player', __( 'No player selected.', 'talenttrack' ) );
        }

        $insert_payload = [
            'club_id'   => CurrentClub::id(),
            'player_id' => $pid,
            'coach_id'  => get_current_user_id(),
            'eval_date' => $date,
            'notes'     => $reason,
        ];
        if ( $type_id > 0 ) $insert_payload['eval_type_id'] = $type_id;
        $wpdb->insert( "{$p}tt_evaluations", $insert_payload );
        $eval_id = (int) $wpdb->insert_id;

        if ( $eval_id > 0 ) {
            // v3.110.x — every rating row carries `club_id` (see same
            // fix in EvaluationsRestController::write_ratings +
            // EvaluationInserter::insert).
            $club_id = CurrentClub::id();
            foreach ( $ratings as $cat_id => $val ) {
                $val = (int) $val;
                if ( $val <= 0 ) continue;
                $wpdb->insert( "{$p}tt_eval_ratings", [
                    'club_id'       => $club_id,
                    'evaluation_id' => $eval_id,
                    'category_id'   => (int) $cat_id,
                    'rating'        => $val,
                ] );
            }
        }

        $redirect = add_query_arg( [
            'tt_view' => 'evaluations',
            'player_id' => $pid,
        ], WizardEntryPoint::dashboardBaseUrl() );

        return [ 'redirect_url' => $redirect, 'created' => 1 ];
    }
}
