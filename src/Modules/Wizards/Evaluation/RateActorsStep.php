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
 * v1 ships a desktop-style vertical list; the spec's full mobile-vs-
 * desktop responsive split is deferred — the layout collapses onto
 * mobile via the existing `@media (max-width: 720px)` rules. The
 * Skip affordance, autosave indicator, and the soft-warn at Review
 * are also v1 follow-ups; the wizard is functional without them and
 * the data model already supports them.
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
            ?>
            <details class="tt-rate-player" style="margin: var(--tt-sp-3) 0;border:1px solid var(--tt-line);border-radius:8px;padding:var(--tt-sp-3);" open>
                <summary style="font-weight:600;cursor:pointer;"><?php echo esc_html( $name ); ?></summary>

                <table style="margin-top:var(--tt-sp-2);width:100%;">
                    <tbody>
                    <?php foreach ( (array) $quick_cats as $cat ) :
                        $val = (int) ( $state['ratings'][ (int) $pl->id ][ (int) $cat->id ] ?? 0 );
                        ?>
                        <tr>
                            <th style="text-align:left;font-weight:normal;width:160px;"><?php echo esc_html( (string) $cat->label ); ?></th>
                            <td>
                                <input type="number" min="0" max="<?php echo (int) $max; ?>"
                                       step="1"
                                       inputmode="numeric"
                                       name="ratings[<?php echo (int) $pl->id; ?>][<?php echo (int) $cat->id; ?>]"
                                       value="<?php echo $val > 0 ? (int) $val : ''; ?>"
                                       style="width:60px;" />
                                <span style="color:var(--tt-muted);font-size:13px;">
                                    / <?php echo (int) $max; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        <tr>
                            <th style="text-align:left;font-weight:normal;"><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                            <td>
                                <textarea rows="2" name="notes[<?php echo (int) $pl->id; ?>]" style="width:100%;"><?php
                                    echo esc_textarea( (string) ( $state['notes'][ (int) $pl->id ] ?? '' ) );
                                ?></textarea>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2">
                                <label>
                                    <input type="checkbox" name="skip[<?php echo (int) $pl->id; ?>]" value="1" <?php checked( ! empty( $state['skip'][ (int) $pl->id ] ) ); ?> />
                                    <?php esc_html_e( 'Skip this player (no evaluation written)', 'talenttrack' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
     * Players present/late on the activity, falling back to the team's
     * full active roster if no attendance was recorded.
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

        $with_att = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name
               FROM {$p}tt_attendance att
               INNER JOIN {$p}tt_players pl ON pl.id = att.player_id AND pl.club_id = att.club_id
              WHERE att.activity_id = %d AND att.club_id = %d
                AND att.status IN ( 'present', 'late' )
              ORDER BY pl.last_name, pl.first_name",
            $activity_id, CurrentClub::id()
        ) );
        if ( ! empty( $with_att ) ) return (array) $with_att;

        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$p}tt_players
              WHERE team_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY last_name, first_name",
            $team_id, CurrentClub::id()
        ) );
    }
}
