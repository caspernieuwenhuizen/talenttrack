<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;
use TT\Shared\Frontend\Components\TeamPickerComponent;

/**
 * CoachForms — shared form-rendering helpers for coaching actions.
 *
 * v3.0.0 slice 4. Extracted from the legacy CoachDashboardView so
 * the new focused FrontendEvaluationsView could share the eval form
 * markup. The session form helper was retired in Sprint 2 session
 * 2.3 (FrontendSessionsManageView renders its own form); the goals
 * form helper was retired in Sprint 2 session 2.4
 * (FrontendGoalsManageView renders its own form). Only renderEvalForm
 * has a live caller now.
 *
 * Form submissions go through the TalentTrack REST API; each form
 * declares its endpoint via `data-rest-path` (relative to
 * `/wp-json/talenttrack/v1/`). See `assets/js/public.js` for the
 * submit handler. The legacy `tt_fe_*` admin-ajax actions were retired
 * in #0019 Sprint 1 session 2.
 */
class CoachForms {

    /**
     * @param object[] $teams
     */
    public static function renderEvalForm( array $teams, bool $is_admin ): void {
        $categories = QueryHelpers::get_categories();
        $types      = QueryHelpers::get_eval_types();
        $rmin  = QueryHelpers::get_config( 'rating_min', '1' );
        $rmax  = QueryHelpers::get_config( 'rating_max', '5' );
        $rstep = QueryHelpers::get_config( 'rating_step', '0.5' );

        $type_meta = [];
        foreach ( $types as $t ) {
            $m = QueryHelpers::lookup_meta( $t );
            $type_meta[ (int) $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0;
        }

        // F6 — low-rating comment policy. Threshold and mode come
        // from tt_config; the JS at the bottom of this form watches
        // every rating input and surfaces a warning (or a hard block
        // when configured) if any rating is ≤ threshold and the
        // notes textarea is empty.
        $low_threshold = (float) QueryHelpers::get_config( 'eval_low_rating_threshold', '3' );
        $low_mode      = (string) QueryHelpers::get_config( 'eval_low_rating_require_comment', 'soft' );

        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) {
            foreach ( $teams as $t ) {
                $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
            }
        }
        ?>
        <h3><?php esc_html_e( 'Submit Evaluation', 'talenttrack' ); ?></h3>
        <form id="tt-eval-form" class="tt-ajax-form" data-rest-path="evaluations" data-rest-method="POST" data-draft-key="eval-form" data-redirect-after-save="1">
            <?php // F1 — team-first eval flow. Coach picks team first; player picker is filtered to that team. ?>
            <div class="tt-field" data-tt-eval-team-wrap>
                <?php echo TeamPickerComponent::render( [
                    'name'     => 'eval_team_id',
                    'label'    => __( 'Team', 'talenttrack' ),
                    'required' => true,
                    'teams'    => $teams,
                    'placeholder' => __( '— Select team first —', 'talenttrack' ),
                ] ); ?>
            </div>
            <div class="tt-field" data-tt-eval-player-wrap>
                <?php echo PlayerSearchPickerComponent::render( [
                    'name'     => 'player_id',
                    'label'    => __( 'Player', 'talenttrack' ),
                    'required' => true,
                    'players'  => $players,
                    'placeholder' => __( 'Type a name to search…', 'talenttrack' ),
                ] ); ?>
            </div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</label><select name="eval_type_id" id="tt_fe_eval_type" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $types as $t ) : ?>
                    <option value="<?php echo (int) $t->id; ?>" data-match="<?php echo (int) $type_meta[ (int) $t->id ]; ?>"><?php echo esc_html( (string) $t->name ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" name="eval_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
            <div id="tt-fe-match-fields" style="display:none;">
                <div class="tt-form-row"><label><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></label><input type="text" name="opponent" /></div>
                <div class="tt-form-row">
                    <label><?php esc_html_e( 'Competition', 'talenttrack' ); ?></label>
                    <select name="competition">
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'competition_type' ) as $tt_ct ) : ?>
                            <option value="<?php echo esc_attr( (string) $tt_ct->name ); ?>"><?php echo esc_html( __( (string) $tt_ct->name, 'talenttrack' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Result', 'talenttrack' ); ?></label><input type="text" name="match_result" placeholder="2-1" style="width:80px" /></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></label><select name="home_away"><option value="">—</option><option value="home"><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away"><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></label><input type="number" name="minutes_played" min="0" max="120" /></div>
            </div>
            <h4><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h4>
            <?php foreach ( $categories as $cat ) : ?>
                <div class="tt-form-row"><label><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat->name ) ); ?></label>
                    <input type="number" name="ratings[<?php echo (int) $cat->id; ?>]" min="<?php echo esc_attr( $rmin ); ?>" max="<?php echo esc_attr( $rmax ); ?>" step="<?php echo esc_attr( $rstep ); ?>" required style="width:80px" />
                    <span class="tt-range-hint">(<?php echo esc_html( $rmin ); ?>–<?php echo esc_html( $rmax ); ?>)</span></div>
            <?php endforeach; ?>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="3" data-tt-low-rating-notes></textarea></div>
            <div class="tt-form-row tt-low-rating-warning" data-tt-low-rating-warning hidden>
                <span style="color:var(--tt-warning, #c9962a); font-size:13px;">
                    <?php
                    printf(
                        /* translators: %s is the threshold value */
                        esc_html__( '⚠ One or more ratings are at or below %s — please add a comment explaining the low score.', 'talenttrack' ),
                        esc_html( rtrim( rtrim( number_format( $low_threshold, 1 ), '0' ), '.' ) )
                    );
                    ?>
                </span>
            </div>
            <?php echo FormSaveButton::render( [ 'label' => __( 'Save Evaluation', 'talenttrack' ) ] ); ?>
            <div class="tt-form-msg"></div>
        </form>
        <script>
        (function(){
            var typeMeta = <?php echo wp_json_encode( $type_meta ); ?>;
            var sel = document.getElementById('tt_fe_eval_type');
            if (sel) sel.addEventListener('change', function(){
                document.getElementById('tt-fe-match-fields').style.display = (typeMeta[this.value] == 1) ? 'block' : 'none';
            });
            // F1 — when the team changes, refresh the player picker
            // to show only players on that team.
            var form = document.getElementById('tt-eval-form');
            if (form) {
                var teamSel = form.querySelector('select[name="eval_team_id"]');
                var playerWrap = form.querySelector('[data-tt-eval-player-wrap] [data-tt-psp]');
                if (teamSel && playerWrap) {
                    teamSel.addEventListener('change', function(){
                        var teamId = parseInt(teamSel.value, 10) || 0;
                        playerWrap.dispatchEvent(new CustomEvent('tt-psp:set-team', { detail: { team_id: teamId } }));
                    });
                }
            }
            // F6 — low-rating comment policy.
            var lowThreshold = <?php echo wp_json_encode( $low_threshold ); ?>;
            var lowMode      = <?php echo wp_json_encode( $low_mode ); ?>;
            if (form) {
                var notesEl   = form.querySelector('[data-tt-low-rating-notes]');
                var warningEl = form.querySelector('[data-tt-low-rating-warning]');
                function evaluate() {
                    var inputs = form.querySelectorAll('input[type="number"][name^="ratings["]');
                    var triggered = false;
                    inputs.forEach(function(inp){
                        var v = parseFloat(inp.value);
                        if (!isNaN(v) && v <= lowThreshold) triggered = true;
                    });
                    var notesEmpty = !notesEl || notesEl.value.trim() === '';
                    if (warningEl) warningEl.hidden = !( triggered && notesEmpty );
                    return { triggered: triggered, notesEmpty: notesEmpty };
                }
                form.addEventListener('input', evaluate);
                if (lowMode === 'hard') {
                    form.addEventListener('submit', function(e){
                        var s = evaluate();
                        if (s.triggered && s.notesEmpty) {
                            e.preventDefault();
                            if (notesEl) notesEl.focus();
                        }
                    }, true);
                }
            }
        })();
        </script>
        <?php
    }

    /** @param object[] $teams */
    public static function renderSessionForm( array $teams ): void {
        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        $all_players = [];
        foreach ( $teams as $t ) {
            foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) {
                $all_players[ (int) $pl->id ] = $pl;
            }
        }
        ?>
        <h3><?php esc_html_e( 'Record Training Session', 'talenttrack' ); ?></h3>
        <form id="tt-session-form" class="tt-ajax-form" data-rest-path="sessions" data-rest-method="POST" data-draft-key="session-form">
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" name="session_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Team', 'talenttrack' ); ?></label><select name="team_id">
                <?php foreach ( $teams as $t ) : ?>
                    <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( (string) $t->name ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Location', 'talenttrack' ); ?></label><input type="text" name="location" /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="2"></textarea></div>
            <h4><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h4>
            <?php if ( empty( $all_players ) ) : ?>
                <p><em><?php esc_html_e( 'No players on your teams yet.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="tt-table"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th></tr></thead><tbody>
                <?php foreach ( $all_players as $pl ) : ?>
                    <tr><td><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></td>
                        <td><select name="att[<?php echo (int) $pl->id; ?>][status]">
                            <?php foreach ( $statuses as $s ) : ?>
                                <option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( LabelTranslator::attendanceStatus( $s ) ); ?></option>
                            <?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" style="width:150px" /></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
            <?php echo FormSaveButton::render( [ 'label' => __( 'Save Session', 'talenttrack' ) ] ); ?>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /** @param object[] $teams */
    public static function renderGoalsForm( array $teams, bool $is_admin ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) {
            foreach ( $teams as $t ) {
                $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
            }
        }
        $statuses   = QueryHelpers::get_lookup_names( 'goal_status' );
        $priorities = QueryHelpers::get_lookup_names( 'goal_priority' );
        $pids = wp_list_pluck( $players, 'id' );
        $goals = [];
        if ( $pids ) {
            $ph = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
            $goals = $wpdb->get_results( $wpdb->prepare(
                "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name
                 FROM {$p}tt_goals g
                 LEFT JOIN {$p}tt_players pl ON g.player_id = pl.id
                 WHERE g.player_id IN ($ph) AND g.archived_at IS NULL
                 ORDER BY g.created_at DESC
                 LIMIT 30",
                ...$pids
            ) );
        }
        ?>
        <h3><?php esc_html_e( 'Add Goal', 'talenttrack' ); ?></h3>
        <form id="tt-goal-form" class="tt-ajax-form" data-rest-path="goals" data-rest-method="POST" data-draft-key="goal-form">
            <div class="tt-form-row"><label><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</label><select name="player_id" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $players as $pl ) : ?>
                    <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Description', 'talenttrack' ); ?></label><textarea name="description" rows="2"></textarea></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Priority', 'talenttrack' ); ?></label><select name="priority">
                <?php foreach ( $priorities as $pr ) : ?>
                    <option value="<?php echo esc_attr( strtolower( $pr ) ); ?>"><?php echo esc_html( LabelTranslator::goalPriority( $pr ) ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Due Date', 'talenttrack' ); ?></label><input type="date" name="due_date" /></div>
            <?php echo FormSaveButton::render( [ 'label' => __( 'Add Goal', 'talenttrack' ) ] ); ?>
            <div class="tt-form-msg"></div>
        </form>
        <h3 style="margin-top:20px;"><?php esc_html_e( 'Current Goals', 'talenttrack' ); ?></h3>
        <?php if ( empty( $goals ) ) : ?>
            <p><?php esc_html_e( 'No goals yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <table class="tt-table"><thead><tr>
                <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Goal', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Priority', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Due', 'talenttrack' ); ?></th><th></th>
            </tr></thead><tbody>
            <?php foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( (string) $g->player_name ); ?></td><td><?php echo esc_html( (string) $g->title ); ?></td>
                    <td><?php echo esc_html( LabelTranslator::goalPriority( (string) $g->priority ) ); ?></td>
                    <td><select class="tt-goal-status-select" data-goal-id="<?php echo (int) $g->id; ?>">
                        <?php foreach ( $statuses as $st ) :
                            $v = strtolower( str_replace( ' ', '_', $st ) );
                            ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $g->status, $v ); ?>><?php echo esc_html( LabelTranslator::goalStatus( $v ) ); ?></option>
                        <?php endforeach; ?>
                    </select></td>
                    <td><?php echo esc_html( $g->due_date ?: '—' ); ?></td>
                    <td><button class="tt-btn-sm tt-goal-delete" data-goal-id="<?php echo (int) $g->id; ?>">✕</button></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php
    }
}
