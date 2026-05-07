<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Wizards\WizardStepInterface;

/**
 * RateActorsStep (#0072) — the heart of the activity-first wizard.
 *
 * Renders the present/late players for the picked activity and lets
 * the coach quick-rate (one row per `meta.quick_rate`-flagged
 * category) or deep-rate (full sub-criteria + notes) each player in
 * one submission.
 *
 * #0080 Wave B4 — markup is now mobile-first. Each player renders as
 * a `<details>` card containing a `.tt-rate-grid`; per-category rows
 * stack vertically on phones (`<720px`) and switch to a 180px-label
 * + control two-column grid on `≥720px`. Inputs hit the v3.50.0 48px
 * touch-target floor; numeric inputs keep `inputmode="numeric"`.
 */
final class RateActorsStep implements WizardStepInterface {

    public function slug(): string  { return 'rate-actors'; }
    public function label(): string { return __( 'Rate players', 'talenttrack' ); }

    public function notApplicableFor( array $state ): bool {
        return ( $state['_path'] ?? '' ) !== 'activity-first';
    }

    public function render( array $state ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $aid = (int) ( $state['activity_id'] ?? 0 );
        $players = self::ratablePlayersForActivity( $aid );

        $quick_cats = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label FROM {$p}tt_eval_categories
              WHERE parent_id IS NULL AND is_active = 1
                AND meta IS NOT NULL AND meta LIKE %s
              ORDER BY display_order, label",
            '%"quick_rate":true%'
        ) );

        // v3.108.4 — A3: pull every active subcategory keyed by its
        // parent so each main row can render its detail children
        // beneath. Sub-categories already exist in the schema (parent_id
        // points to a main row) and the seed ships ~21 of them across
        // the 4 main categories; the wizard just never surfaced them.
        // Quick-rate stays on the main category; the subcategory inputs
        // are nested under each main row inside a `<details>` so the
        // coach can drill into a detailed rating without losing the
        // top-line ergonomic.
        $sub_cats_raw = $wpdb->get_results(
            "SELECT id, parent_id, label FROM {$p}tt_eval_categories
              WHERE parent_id IS NOT NULL AND is_active = 1
              ORDER BY parent_id, display_order, label"
        );
        $sub_cats_by_parent = [];
        foreach ( (array) $sub_cats_raw as $sc ) {
            $pid = (int) $sc->parent_id;
            $sub_cats_by_parent[ $pid ][] = $sc;
        }

        $rating = $wpdb->get_var( "SELECT rating_max FROM {$p}tt_eval_categories LIMIT 0" );
        $max = (int) ( get_option( 'tt_rating_scale_max', 5 ) ?: 5 );

        if ( empty( $players ) ) :
            ?><p class="tt-notice"><?php esc_html_e( 'No players to rate. Mark attendance first or pick another activity.', 'talenttrack' ); ?></p><?php
            return;
        endif;
        ?>
        <p style="color:var(--tt-muted);max-width:60ch;">
            <?php
            printf(
                /* translators: %d: player count */
                esc_html( _n( '%d player ready to rate. Quick-rate the categories below; deep-rate any player by expanding their row.', '%d players ready to rate. Quick-rate the categories below; deep-rate any player by expanding their row.', count( $players ), 'talenttrack' ) ),
                count( $players )
            );
            ?>
        </p>

        <?php foreach ( $players as $pl ) :
            $name = trim( (string) $pl->first_name . ' ' . (string) $pl->last_name );
            $pid  = (int) $pl->id;
            ?>
            <details class="tt-rate-player" open>
                <summary class="tt-rate-player-name"><?php echo esc_html( $name ); ?></summary>

                <div class="tt-rate-grid">
                    <?php foreach ( (array) $quick_cats as $cat ) :
                        $cid    = (int) $cat->id;
                        $val    = (int) ( $state['ratings'][ $pid ][ $cid ] ?? 0 );
                        $iid    = 'tt-rate-' . $pid . '-' . $cid;
                        $label  = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $cat->label );
                        $subs   = $sub_cats_by_parent[ $cid ] ?? [];
                    ?>
                        <div class="tt-rate-row">
                            <label class="tt-rate-label" for="<?php echo esc_attr( $iid ); ?>"><?php echo esc_html( $label ); ?></label>
                            <div class="tt-rate-control">
                                <input type="number" min="0" max="<?php echo (int) $max; ?>"
                                       step="1"
                                       inputmode="numeric"
                                       id="<?php echo esc_attr( $iid ); ?>"
                                       class="tt-rate-input"
                                       name="ratings[<?php echo $pid; ?>][<?php echo $cid; ?>]"
                                       value="<?php echo $val > 0 ? (int) $val : ''; ?>" />
                                <span class="tt-rate-max">
                                    / <?php echo (int) $max; ?>
                                </span>
                            </div>
                        </div>
                        <?php if ( ! empty( $subs ) ) : ?>
                            <details class="tt-rate-subs">
                                <summary class="tt-rate-subs-toggle">
                                    <?php
                                    /* translators: %s: main category label (Technical / Tactical / Physical / Mental) */
                                    echo esc_html( sprintf( __( 'Detailed %s', 'talenttrack' ), $label ) );
                                    ?>
                                </summary>
                                <?php foreach ( $subs as $sub ) :
                                    $scid = (int) $sub->id;
                                    $sval = (int) ( $state['ratings'][ $pid ][ $scid ] ?? 0 );
                                    $siid = 'tt-rate-' . $pid . '-' . $scid;
                                    $slabel = \TT\Infrastructure\Evaluations\EvalCategoriesRepository::displayLabel( (string) $sub->label );
                                ?>
                                    <div class="tt-rate-row tt-rate-row--sub">
                                        <label class="tt-rate-label" for="<?php echo esc_attr( $siid ); ?>">↳ <?php echo esc_html( $slabel ); ?></label>
                                        <div class="tt-rate-control">
                                            <input type="number" min="0" max="<?php echo (int) $max; ?>"
                                                   step="1"
                                                   inputmode="numeric"
                                                   id="<?php echo esc_attr( $siid ); ?>"
                                                   class="tt-rate-input"
                                                   name="ratings[<?php echo $pid; ?>][<?php echo $scid; ?>]"
                                                   value="<?php echo $sval > 0 ? (int) $sval : ''; ?>" />
                                            <span class="tt-rate-max">
                                                / <?php echo (int) $max; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </details>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="tt-rate-row">
                        <label class="tt-rate-label" for="tt-rate-notes-<?php echo $pid; ?>"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label>
                        <div class="tt-rate-control">
                            <textarea rows="2"
                                      id="tt-rate-notes-<?php echo $pid; ?>"
                                      class="tt-rate-notes"
                                      name="notes[<?php echo $pid; ?>]"><?php
                                echo esc_textarea( (string) ( $state['notes'][ $pid ] ?? '' ) );
                            ?></textarea>
                        </div>
                    </div>
                    <div class="tt-rate-row tt-rate-skip-row">
                        <label class="tt-rate-skip">
                            <input type="checkbox" name="skip[<?php echo $pid; ?>]" value="1" <?php checked( ! empty( $state['skip'][ $pid ] ) ); ?> />
                            <?php esc_html_e( 'Skip this player (no evaluation written)', 'talenttrack' ); ?>
                        </label>
                    </div>
                </div>
            </details>
        <?php endforeach;
    }

    public function validate( array $post, array $state ) {
        $ratings = isset( $post['ratings'] ) && is_array( $post['ratings'] ) ? $post['ratings'] : [];
        $clean = [];
        foreach ( $ratings as $pid => $cats ) {
            if ( ! is_array( $cats ) ) continue;
            foreach ( $cats as $cid => $v ) {
                $v = (int) $v;
                if ( $v <= 0 ) continue;
                $clean[ (int) $pid ][ (int) $cid ] = $v;
            }
        }
        $notes = isset( $post['notes'] ) && is_array( $post['notes'] )
            ? array_map( 'sanitize_textarea_field', wp_unslash( $post['notes'] ) )
            : [];
        $skip  = isset( $post['skip'] ) && is_array( $post['skip'] )
            ? array_map( 'absint', $post['skip'] )
            : [];

        return [ 'ratings' => $clean, 'notes' => $notes, 'skip' => $skip ];
    }

    public function nextStep( array $state ): ?string { return 'review'; }
    public function submit( array $state ) { return null; }

    /**
     * Players present/late on the activity. The previous fallback to
     * the team's full roster (when no attendance was recorded) is
     * gone — the user surfaced that as confusing: a coach who skipped
     * the attendance step would see the entire roster as rateable,
     * misrepresenting who actually participated. Without the fallback
     * the rate step refuses to start until someone has been marked
     * present/late on the activity.
     *
     * @return list<object>
     */
    public static function ratablePlayersForActivity( int $activity_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $team_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( $team_id <= 0 ) return [];

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name
               FROM {$p}tt_attendance att
               INNER JOIN {$p}tt_players pl ON pl.id = att.player_id AND pl.club_id = att.club_id
              WHERE att.activity_id = %d AND att.club_id = %d
                AND att.status IN ( 'present', 'late' )
              ORDER BY pl.last_name, pl.first_name",
            $activity_id, CurrentClub::id()
        ) );
    }
}
