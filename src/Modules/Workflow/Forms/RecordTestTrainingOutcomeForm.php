<?php
namespace TT\Modules\Workflow\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Workflow\Contracts\FormInterface;

/**
 * RecordTestTrainingOutcomeForm (#0081 child 2b) — coach observation +
 * HoD recommendation captured in one form per attendee.
 *
 * The spec calls for a per-attendee batch form (multiple prospects in
 * one HoD pass). Child 2b ships the single-attendee form — one task
 * per confirmed prospect, the HoD reviews each one. Multi-attendee
 * batch UX lives as a follow-up in child 3 (the pipeline widget),
 * which already aggregates per-stage tasks; the widget is a more
 * natural place for that batching.
 *
 * On `admit_to_trial`, the form creates the trial case directly
 * (mirrors the LogProspect → tt_prospects pattern) so the chain step
 * can read `trial_case_id` from the response payload.
 */
class RecordTestTrainingOutcomeForm implements FormInterface {

    public function render( array $task ): string {
        $existing = self::decodeResponse( $task );
        $disabled = self::completedAttr( $task );
        $prospect = self::prospectSummary( (int) ( $task['prospect_id'] ?? 0 ) );

        ob_start();
        ?>
        <div style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">
            <?php if ( $prospect !== '' ) : ?>
                <p style="margin: 0 0 14px; font-weight: 600;">
                    <?php echo esc_html( sprintf( __( 'Prospect: %s', 'talenttrack' ), $prospect ) ); ?>
                </p>
            <?php endif; ?>

            <p style="margin: 0 0 6px;">
                <label for="tt-rtt-obs"><?php esc_html_e( 'Coach observations', 'talenttrack' ); ?></label>
            </p>
            <p>
                <textarea id="tt-rtt-obs" name="observations" rows="4" style="width:100%;"
                          <?php echo $disabled; ?>><?php
                    echo esc_textarea( (string) ( $existing['observations'] ?? '' ) );
                ?></textarea>
            </p>

            <p style="margin: 16px 0 6px; font-weight:600;">
                <?php esc_html_e( 'Recommendation', 'talenttrack' ); ?>
            </p>
            <p>
                <label>
                    <input type="radio" name="recommendation" value="admit_to_trial"
                           <?php checked( (string) ( $existing['recommendation'] ?? '' ), 'admit_to_trial' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Admit to trial group', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="recommendation" value="decline"
                           <?php checked( (string) ( $existing['recommendation'] ?? '' ), 'decline' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Decline (with encouragement letter)', 'talenttrack' ); ?>
                </label>
            </p>
            <p>
                <label>
                    <input type="radio" name="recommendation" value="request_second_session"
                           <?php checked( (string) ( $existing['recommendation'] ?? '' ), 'request_second_session' ); ?>
                           <?php echo $disabled; ?> />
                    <?php esc_html_e( 'Invite to a second test training', 'talenttrack' ); ?>
                </label>
            </p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public function validate( array $raw, array $task ): array {
        $errors = [];
        $recommendation = (string) ( $raw['recommendation'] ?? '' );
        if ( ! in_array( $recommendation, [ 'admit_to_trial', 'decline', 'request_second_session' ], true ) ) {
            $errors['recommendation'] = __( 'Pick one recommendation.', 'talenttrack' );
        }
        return $errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        $recommendation = (string) ( $raw['recommendation'] ?? '' );
        $observations   = sanitize_textarea_field( (string) ( $raw['observations'] ?? '' ) );

        $payload = [
            'recommendation' => $recommendation,
            'observations'   => $observations,
        ];

        if ( $recommendation === 'admit_to_trial' ) {
            // Promote prospect to a player on admit (matches the
            // existing trial_case → player flow). Without a player_id
            // the trial case can't be created.
            $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
            $player_id   = self::ensurePromotedPlayer( $prospect_id );
            $track_id    = self::defaultTrialTrackId();
            if ( $player_id > 0 && $track_id > 0 ) {
                $repo = new TrialCasesRepository();
                $trial_case_id = $repo->create( [
                    'player_id'  => $player_id,
                    'track_id'   => $track_id,
                    'start_date' => gmdate( 'Y-m-d' ),
                    'end_date'   => gmdate( 'Y-m-d', strtotime( '+90 days' ) ),
                    'created_by' => (int) ( $task['assignee_user_id'] ?? get_current_user_id() ),
                ] );
                $payload['player_id']     = $player_id;
                $payload['trial_case_id'] = $trial_case_id;

                // Stamp the prospect with the promotion link so future
                // queries (PlayerDataMap, retention cron, pipeline
                // widget) can navigate prospect → player.
                ( new ProspectsRepository() )->update( $prospect_id, [
                    'promoted_to_player_id'    => $player_id,
                    'promoted_to_trial_case_id' => $trial_case_id,
                ] );
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $task */
    private static function decodeResponse( array $task ): array {
        $raw = (string) ( $task['response_json'] ?? '' );
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /** @param array<string,mixed> $task */
    private static function completedAttr( array $task ): string {
        return ( (string) ( $task['status'] ?? '' ) ) === 'completed' ? 'disabled' : '';
    }

    private static function prospectSummary( int $prospect_id ): string {
        if ( $prospect_id <= 0 ) return '';
        $repo = new ProspectsRepository();
        $row  = $repo->find( $prospect_id );
        if ( ! $row ) return '';
        return trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
    }

    /**
     * Promote a prospect into a tt_players row if not already promoted.
     * Status is `trial` so the player surfaces in the right cohort
     * across the dashboard. Returns the player id (existing or new).
     */
    private static function ensurePromotedPlayer( int $prospect_id ): int {
        if ( $prospect_id <= 0 ) return 0;
        $repo     = new ProspectsRepository();
        $prospect = $repo->find( $prospect_id );
        if ( ! $prospect ) return 0;
        if ( ! empty( $prospect->promoted_to_player_id ) ) {
            return (int) $prospect->promoted_to_player_id;
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => CurrentClub::id(),
            'first_name'    => (string) $prospect->first_name,
            'last_name'     => (string) $prospect->last_name,
            'date_of_birth' => $prospect->date_of_birth,
            'date_joined'   => gmdate( 'Y-m-d' ),
            'status'        => 'trial',
            'uuid'          => wp_generate_uuid4(),
        ] );
        $player_id = (int) $wpdb->insert_id;

        // Auto-tag demo-on rows — mirror of FrontendTrialsManageView's
        // inline-create path. Without this, a prospect admitted from a
        // demo-mode pipeline produces a real (untagged) tt_players row
        // that apply_demo_scope filters out, so ?tt_view=players&id=N
        // returns "Player not found" from the same demo session that
        // just promoted the prospect.
        if ( $player_id > 0 && class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            \TT\Modules\DemoData\DemoMode::tagIfActive( 'player', $player_id );
        }

        return $player_id;
    }

    private static function defaultTrialTrackId(): int {
        global $wpdb;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_trial_tracks
              WHERE club_id = %d AND archived_at IS NULL
              ORDER BY sort_order ASC, id ASC LIMIT 1",
            CurrentClub::id()
        ) );
        return (int) ( $id ?: 0 );
    }
}
