<?php
namespace TT\Modules\MatchPrep\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendMatchPrepView (#838) — main editing surface for a match
 * preparation. Renders the lineup-per-half grid + bench columns +
 * per-player attention notes alongside the team's roster. All edits
 * are wired through the REST controller; the wizard's AvailabilityStep
 * is the entry point that ensures the prep row exists.
 *
 * Desktop-only per spec; the v1 form is best-effort below 1024px.
 */
class FrontendMatchPrepView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match prep is restricted to coaches and admins.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Open Match prep from a match activity\'s detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity = self::loadActivity( $activity_id );
        if ( ! $activity ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>';
            return;
        }
        if ( ( $activity->activity_type_key ?? '' ) !== 'game' && ( $activity->activity_type_key ?? '' ) !== 'match' ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match prep is only available for match-type activities.', 'talenttrack' ) . '</p>';
            return;
        }

        $repo = new MatchPrepRepository();
        $prep = $repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            // Send back to the wizard so AvailabilityStep can collect
            // the roster first.
            $wizard_url = WizardEntryPoint::buildUrl( 'match-prep', [ 'activity_id' => $activity_id ] );
            wp_safe_redirect( $wizard_url );
            exit;
        }
        $prep_id      = (int) $prep->id;
        $availability = $repo->listAvailability( $prep_id );
        $lineup_rows  = $repo->listLineup( $prep_id );
        $player_goals = $repo->listPlayerGoals( $prep_id );

        // Build helper structures.
        $players_by_id = self::loadTeamRosterById( (int) $activity->team_id );
        $available_ids = [];
        foreach ( $availability as $a ) {
            if ( strcasecmp( (string) $a->status, 'Present' ) === 0 ) {
                $available_ids[] = (int) $a->player_id;
            }
        }
        $lineup_by_half = [ 1 => [], 2 => [] ];
        foreach ( $lineup_rows as $l ) {
            $lineup_by_half[ (int) $l->half ][ (int) $l->slot_number ] = (int) $l->player_id;
        }
        $pgoals_by_pid = [];
        foreach ( $player_goals as $g ) {
            $pgoals_by_pid[ (int) $g->player_id ] = $g;
        }

        $title = sprintf(
            /* translators: 1: activity title, 2: session date */
            __( 'Match prep — %1$s · %2$s', 'talenttrack' ),
            (string) ( $activity->title ?? '—' ),
            (string) ( $activity->session_date ?? '' )
        );

        $back_to_activity = add_query_arg( [
            'tt_view' => 'activities',
            'id'      => $activity_id,
        ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) );

        FrontendBreadcrumbs::fromDashboard( __( 'Match prep', 'talenttrack' ), [
            [ 'label' => __( 'Activities', 'talenttrack' ), 'href' => add_query_arg( [ 'tt_view' => 'activities' ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) ) ],
        ] );

        parent::enqueueAssets();
        self::enqueueViewAssets();

        ?>
        <h1 class="tt-fview-title" style="margin: 6px 0 18px; font-size: 22px;"><?php echo esc_html( $title ); ?></h1>

        <form id="tt-match-prep-form"
              class="tt-match-prep"
              data-activity-id="<?php echo (int) $activity_id; ?>"
              data-prep-id="<?php echo (int) $prep_id; ?>"
              data-half-length="<?php echo (int) $prep->half_length_minutes; ?>"
              novalidate>

            <div class="tt-match-prep-header">
                <div class="tt-match-prep-header-left">
                    <label>
                        <span><?php esc_html_e( 'Formation', 'talenttrack' ); ?></span>
                        <input type="text" name="formation_template_id" value="<?php echo esc_attr( (string) ( $prep->formation_template_id ?? '' ) ); ?>" placeholder="<?php esc_attr_e( '1-4-3-3', 'talenttrack' ); ?>" />
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Half length (min)', 'talenttrack' ); ?></span>
                        <input type="number" name="half_length_minutes" min="1" max="60" inputmode="numeric" value="<?php echo (int) $prep->half_length_minutes; ?>" />
                    </label>
                </div>
                <div class="tt-match-prep-header-right">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-mp-manage-availability>
                        <?php esc_html_e( 'Manage availability', 'talenttrack' ); ?>
                    </button>
                </div>
            </div>

            <table class="tt-match-prep-table" data-tt-mp-grid>
                <thead>
                    <tr>
                        <th rowspan="2" class="tt-mp-col-player"><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                        <th rowspan="2" class="tt-mp-col-min"><?php esc_html_e( 'min 1e', 'talenttrack' ); ?></th>
                        <th rowspan="2" class="tt-mp-col-min"><?php esc_html_e( 'min 2e', 'talenttrack' ); ?></th>
                        <th rowspan="2" class="tt-mp-col-min"><?php esc_html_e( 'tot', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( '1e half', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( '2e half', 'talenttrack' ); ?></th>
                        <th rowspan="2" class="tt-mp-col-attention"><?php esc_html_e( 'Attention', 'talenttrack' ); ?></th>
                        <th rowspan="2" class="tt-mp-col-flag" title="<?php esc_attr_e( 'Specific goal', 'talenttrack' ); ?>">!</th>
                        <th rowspan="2" class="tt-mp-col-flag" title="<?php esc_attr_e( 'Video analyst appointed', 'talenttrack' ); ?>">🎥</th>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Slot · Player', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Slot · Player', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $sorted_available = self::sortedAvailablePlayers( $available_ids, $players_by_id );
                foreach ( $sorted_available as $pl ) :
                    $pid       = (int) $pl->id;
                    $name      = QueryHelpers::player_display_name( $pl );
                    $in_half1  = in_array( $pid, $lineup_by_half[1], true );
                    $in_half2  = in_array( $pid, $lineup_by_half[2], true );
                    $g         = $pgoals_by_pid[ $pid ] ?? null;
                    $slot_1    = self::slotFor( $pid, $lineup_by_half[1] );
                    $slot_2    = self::slotFor( $pid, $lineup_by_half[2] );
                    ?>
                    <tr data-player-id="<?php echo $pid; ?>">
                        <td class="tt-mp-col-player"><?php echo esc_html( $name ); ?></td>
                        <td class="tt-mp-col-min" data-tt-mp-min="1"><?php echo $in_half1 ? (int) $prep->half_length_minutes : 0; ?></td>
                        <td class="tt-mp-col-min" data-tt-mp-min="2"><?php echo $in_half2 ? (int) $prep->half_length_minutes : 0; ?></td>
                        <td class="tt-mp-col-min" data-tt-mp-min="tot"><?php echo ( $in_half1 ? (int) $prep->half_length_minutes : 0 ) + ( $in_half2 ? (int) $prep->half_length_minutes : 0 ); ?></td>
                        <td>
                            <select name="lineup[1][<?php echo $pid; ?>]" data-tt-mp-slot="1" class="tt-mp-slot-select">
                                <option value=""><?php esc_html_e( '— Bench —', 'talenttrack' ); ?></option>
                                <?php for ( $s = 1; $s <= 11; $s++ ) : ?>
                                    <option value="<?php echo $s; ?>" <?php selected( $slot_1, $s ); ?>><?php echo (int) $s; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td>
                            <select name="lineup[2][<?php echo $pid; ?>]" data-tt-mp-slot="2" class="tt-mp-slot-select">
                                <option value=""><?php esc_html_e( '— Bench —', 'talenttrack' ); ?></option>
                                <?php for ( $s = 1; $s <= 11; $s++ ) : ?>
                                    <option value="<?php echo $s; ?>" <?php selected( $slot_2, $s ); ?>><?php echo (int) $s; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td class="tt-mp-col-attention">
                            <input type="text" name="player_goals[<?php echo $pid; ?>][attention_text]" value="<?php echo esc_attr( (string) ( $g->attention_text ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Coach notes…', 'talenttrack' ); ?>" />
                        </td>
                        <td class="tt-mp-col-flag">
                            <input type="checkbox" name="player_goals[<?php echo $pid; ?>][is_specific_goal]" value="1" <?php checked( ! empty( $g->is_specific_goal ) ); ?> />
                        </td>
                        <td class="tt-mp-col-flag">
                            <input type="checkbox" name="player_goals[<?php echo $pid; ?>][analyst_appointed]" value="1" <?php checked( ! empty( $g->analyst_appointed ) ); ?> />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><?php esc_html_e( 'Totals', 'talenttrack' ); ?></td>
                        <td data-tt-mp-total="1">—</td>
                        <td data-tt-mp-total="2">—</td>
                        <td data-tt-mp-total="tot">—</td>
                        <td colspan="2"><span data-tt-mp-validity></span></td>
                        <td colspan="3">
                            <button type="button" class="tt-btn tt-btn-secondary" data-tt-mp-copy-half><?php esc_html_e( '→ Copy 1e to 2e', 'talenttrack' ); ?></button>
                        </td>
                    </tr>
                </tfoot>
            </table>

            <fieldset class="tt-match-prep-goals">
                <legend><?php esc_html_e( 'Match goals', 'talenttrack' ); ?></legend>
                <label class="tt-match-prep-goals-full">
                    <span><?php esc_html_e( 'General', 'talenttrack' ); ?></span>
                    <textarea name="goals_general" rows="2"><?php echo esc_textarea( (string) ( $prep->goals_general ?? '' ) ); ?></textarea>
                </label>
                <div class="tt-match-prep-goals-grid">
                    <label>
                        <span><?php esc_html_e( 'Attacking', 'talenttrack' ); ?></span>
                        <textarea name="goals_attack" rows="2"><?php echo esc_textarea( (string) ( $prep->goals_attack ?? '' ) ); ?></textarea>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Defending', 'talenttrack' ); ?></span>
                        <textarea name="goals_defend" rows="2"><?php echo esc_textarea( (string) ( $prep->goals_defend ?? '' ) ); ?></textarea>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Set pieces (attack)', 'talenttrack' ); ?></span>
                        <textarea name="goals_attack_setpiece" rows="2"><?php echo esc_textarea( (string) ( $prep->goals_attack_setpiece ?? '' ) ); ?></textarea>
                    </label>
                    <label>
                        <span><?php esc_html_e( 'Set pieces (defend)', 'talenttrack' ); ?></span>
                        <textarea name="goals_defend_setpiece" rows="2"><?php echo esc_textarea( (string) ( $prep->goals_defend_setpiece ?? '' ) ); ?></textarea>
                    </label>
                </div>
            </fieldset>

            <?php
            $pdf_url = add_query_arg( [
                'tt_view'     => 'exports',
                'exporter'    => 'match_prep_pdf',
                'activity_id' => $activity_id,
            ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) );
            ?>
            <p class="tt-match-prep-pdf-link">
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener">
                    <?php esc_html_e( '📄 Open print-ready PDF', 'talenttrack' ); ?>
                </a>
            </p>

            <?php
            echo FormSaveButton::render( [
                'label'        => __( 'Save match prep', 'talenttrack' ),
                'label_saving' => __( 'Saving…', 'talenttrack' ),
                'label_saved'  => __( 'Saved', 'talenttrack' ),
                'cancel_url'   => $back_to_activity,
                'cancel_label' => __( 'Cancel', 'talenttrack' ),
            ] );
            ?>
            <div class="tt-form-msg" data-tt-mp-msg></div>
        </form>
        <?php
    }

    /**
     * Enqueue the form's scoped CSS + JS. Stable handle names so the
     * page can also unenqueue them on cleanup.
     */
    private static function enqueueViewAssets(): void {
        wp_enqueue_style(
            'tt-match-prep',
            TT_PLUGIN_URL . 'assets/css/frontend-match-prep.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-match-prep',
            TT_PLUGIN_URL . 'assets/js/frontend-match-prep.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-prep', 'TT_MATCH_PREP', [
            'rest_url'  => esc_url_raw( rest_url( 'talenttrack/v1/match-prep/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'      => [
                'saving'           => __( 'Saving…', 'talenttrack' ),
                'saved'            => __( 'Match prep saved.', 'talenttrack' ),
                'error'            => __( 'Save failed. Try again.', 'talenttrack' ),
                'eleven_required'  => __( '11 players required on each half.', 'talenttrack' ),
                'slot_in_use'      => __( 'That slot is taken — pick a different number.', 'talenttrack' ),
            ],
        ] );
    }

    private static function loadActivity( int $activity_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, title, session_date, activity_type_key
               FROM {$wpdb->prefix}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** @return array<int, object> id => player */
    private static function loadTeamRosterById( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.*
               FROM {$wpdb->prefix}tt_team_players tp
               JOIN {$wpdb->prefix}tt_players pl ON pl.id = tp.player_id
              WHERE tp.team_id = %d AND tp.club_id = %d AND pl.club_id = %d",
            $team_id, CurrentClub::id(), CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->id ] = $r;
        }
        return $out;
    }

    /**
     * @param list<int> $available_ids
     * @param array<int, object> $players_by_id
     * @return list<object>
     */
    private static function sortedAvailablePlayers( array $available_ids, array $players_by_id ): array {
        $out = [];
        foreach ( $available_ids as $pid ) {
            if ( isset( $players_by_id[ $pid ] ) ) $out[] = $players_by_id[ $pid ];
        }
        usort( $out, function( $a, $b ) {
            $la = strtolower( (string) ( $a->last_name ?? '' ) );
            $lb = strtolower( (string) ( $b->last_name ?? '' ) );
            if ( $la === $lb ) {
                return strcmp(
                    strtolower( (string) ( $a->first_name ?? '' ) ),
                    strtolower( (string) ( $b->first_name ?? '' ) )
                );
            }
            return strcmp( $la, $lb );
        } );
        return $out;
    }

    /** @param array<int,int> $half_slots slot=>player_id */
    private static function slotFor( int $player_id, array $half_slots ): int {
        foreach ( $half_slots as $slot => $pid ) {
            if ( (int) $pid === $player_id ) return (int) $slot;
        }
        return 0;
    }
}
