<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * BehaviourStep (#869) — optional "Behaviour today" step in the
 * activity-first new-evaluation flow.
 *
 * Sits between RateActorsStep and ReviewStep on the activity-first
 * path. Renders the rateable roster for the activity with a per-player
 * rating dropdown + notes input. Step is fully skippable — submitting
 * with all blank values writes zero behaviour rows.
 *
 * Auto-skipped (`notApplicableFor`):
 *   - When the wizard is on the player-first path (`_path !== 'activity-first'`).
 *   - When the current user lacks `tt_rate_player_behaviour`.
 *
 * Writing the rows is delegated to ReviewStep::submitActivityFirst()
 * which iterates `behaviour_ratings` from accumulated state and calls
 * `PlayerBehaviourRatingsRepository::create()` once per non-null rating.
 * The behaviour row's `related_activity_id` is the wizard's selected
 * activity so the player-status calculator can attribute the rating
 * to the same session as the evaluation it was captured alongside.
 *
 * Per parent epic #867: ties behaviour capture to the same flow most
 * coaches already use for player data entry, eliminating the most
 * common reason coaches never record behaviour (the context-switch tax).
 */
final class BehaviourStep implements WizardStepInterface {

    public function slug(): string  { return 'behaviour'; }
    public function label(): string { return __( 'Behaviour today', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        if ( ( $state['_path'] ?? '' ) !== 'activity-first' ) return true;
        if ( ! current_user_can( 'tt_rate_player_behaviour' ) ) return true;
        return false;
    }

    public function render( array $state ): void {
        $aid = (int) ( $state['activity_id'] ?? 0 );
        $players = RateActorsStep::ratablePlayersForActivity( $aid );

        $min = (int) round( (float) QueryHelpers::get_config( 'rating_min', '5' ) );
        $max = (int) round( (float) QueryHelpers::get_config( 'rating_max', '10' ) );

        if ( empty( $players ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No players to rate. Skip to continue.', 'talenttrack' ) . '</p>';
            return;
        }

        $existing_ratings = (array) ( $state['behaviour_ratings'] ?? [] );
        $existing_notes   = (array) ( $state['behaviour_notes'] ?? [] );
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php esc_html_e( 'Optional — record a quick behaviour rating for this activity. Skip any player you do not want to rate today. Players left blank get no behaviour row.', 'talenttrack' ); ?>
        </p>

        <div class="tt-rate-roster">
            <?php foreach ( $players as $pl ) :
                $pid   = (int) $pl->id;
                $name  = trim( (string) $pl->first_name . ' ' . (string) $pl->last_name );
                $rval  = (int) ( $existing_ratings[ $pid ] ?? 0 );
                $nval  = (string) ( $existing_notes[ $pid ] ?? '' );
                $rid   = 'tt-behav-rating-' . $pid;
                $nid   = 'tt-behav-notes-' . $pid;
            ?>
                <details class="tt-rate-player" data-pid="<?php echo $pid; ?>">
                    <summary class="tt-rate-player-summary">
                        <span class="tt-rate-player-name"><?php echo esc_html( $name ); ?></span>
                        <span class="tt-rate-player-status tt-rate-player-status--<?php echo $rval > 0 ? 'complete' : 'empty'; ?>">
                            <?php echo $rval > 0 ? esc_html( (string) $rval ) : esc_html__( 'Not rated', 'talenttrack' ); ?>
                        </span>
                    </summary>
                    <div class="tt-rate-grid">
                        <div class="tt-rate-row">
                            <label class="tt-rate-label" for="<?php echo esc_attr( $rid ); ?>"><?php esc_html_e( 'Behaviour rating', 'talenttrack' ); ?></label>
                            <div class="tt-rate-control">
                                <select id="<?php echo esc_attr( $rid ); ?>" class="tt-input" name="behaviour_ratings[<?php echo $pid; ?>]">
                                    <option value=""><?php esc_html_e( '— skip —', 'talenttrack' ); ?></option>
                                    <?php for ( $v = $min; $v <= $max; $v++ ) : ?>
                                        <option value="<?php echo (int) $v; ?>" <?php selected( $rval, $v ); ?>><?php echo (int) $v; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="tt-rate-row">
                            <label class="tt-rate-label" for="<?php echo esc_attr( $nid ); ?>"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                            <div class="tt-rate-control">
                                <input type="text" id="<?php echo esc_attr( $nid ); ?>" class="tt-input"
                                       name="behaviour_notes[<?php echo $pid; ?>]"
                                       value="<?php echo esc_attr( $nval ); ?>"
                                       placeholder="<?php esc_attr_e( 'Optional one-liner', 'talenttrack' ); ?>" />
                            </div>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function validate( array $post, array $state ) {
        $raw_r = isset( $post['behaviour_ratings'] ) && is_array( $post['behaviour_ratings'] )
            ? $post['behaviour_ratings']
            : [];
        $raw_n = isset( $post['behaviour_notes'] ) && is_array( $post['behaviour_notes'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $post['behaviour_notes'] ) )
            : [];

        $rmin = (int) round( (float) QueryHelpers::get_config( 'rating_min', '5' ) );
        $rmax = (int) round( (float) QueryHelpers::get_config( 'rating_max', '10' ) );

        $ratings = [];
        foreach ( $raw_r as $pid => $v ) {
            $pid = (int) $pid;
            $v   = (int) $v;
            if ( $pid <= 0 || $v <= 0 ) continue;
            // Out-of-range ratings are silently dropped — matches the
            // RateActorsStep behaviour: a bad value shouldn't fail the
            // whole step.
            if ( $v < $rmin || $v > $rmax ) continue;
            $ratings[ $pid ] = $v;
        }

        $notes = [];
        foreach ( $raw_n as $pid => $n ) {
            $pid = (int) $pid;
            if ( $pid <= 0 ) continue;
            $n = (string) $n;
            if ( $n === '' ) continue;
            $notes[ $pid ] = $n;
        }

        return [
            'behaviour_ratings' => $ratings,
            'behaviour_notes'   => $notes,
        ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }
}
