<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\PlayerSearchPickerComponent;

/**
 * CoachForms — shared form-rendering helpers for coaching actions.
 *
 * v3.0.0 slice 4. Extracted from the legacy CoachDashboardView so
 * the new focused FrontendEvaluationsView could share the eval form
 * markup. The session form helper was retired in Sprint 2 session
 * 2.3 (FrontendActivitiesManageView renders its own form); the goals
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
     * @param object[]    $teams
     * @param int         $preset_player_id  v3.110.3 — when set, the form
     *                                       pre-fills the team + player
     *                                       pickers and hides them; the
     *                                       operator only sees the rating
     *                                       inputs. Used by the "Add
     *                                       evaluation" CTA on the player
     *                                       profile's empty Evaluations
     *                                       tab.
     * @param object|null $existing_eval     v3.110.55 — when set, render
     *                                       in EDIT mode: form posts PUT
     *                                       to /evaluations/{id}, every
     *                                       field is pre-filled from the
     *                                       row, every existing rating is
     *                                       pre-populated.
     */
    public static function renderEvalForm( array $teams, bool $is_admin, int $preset_player_id = 0, ?object $existing_eval = null ): void {
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

        // v3.110.3 — preset path: the "Add evaluation" CTA on a player
        // profile passes ?player_id=N. Look up the player's team_id and
        // pre-fill both pickers; render hidden inputs in place of the
        // dropdowns.
        $preset_player = $preset_player_id > 0 ? QueryHelpers::get_player( $preset_player_id ) : null;
        $preset_team_id = $preset_player ? (int) ( $preset_player->team_id ?? 0 ) : 0;
        $hide_pickers = $preset_player !== null && $preset_team_id > 0;

        // v3.110.55 — edit mode: the form switches to PUT against the
        // existing eval, every field pre-fills from `$existing_eval`,
        // and the player picker is replaced with a hidden input (a
        // player swap mid-edit would invalidate ratings against the
        // wrong player). Existing ratings are fetched once and merged
        // into the inputs as `value="..."`.
        $is_edit          = $existing_eval !== null;
        $rest_path        = $is_edit ? 'evaluations/' . (int) $existing_eval->id : 'evaluations';
        $rest_method      = $is_edit ? 'PUT' : 'POST';
        $title_text       = $is_edit ? __( 'Edit evaluation', 'talenttrack' ) : __( 'Submit Evaluation', 'talenttrack' );
        $submit_label     = $is_edit ? __( 'Save changes', 'talenttrack' ) : __( 'Save Evaluation', 'talenttrack' );
        $existing_player  = $is_edit ? (int) $existing_eval->player_id : 0;
        $existing_ratings = [];
        if ( $is_edit ) {
            $rating_rows = ( new \TT\Infrastructure\Evaluations\EvalRatingsRepository() )->getForEvaluation( (int) $existing_eval->id );
            foreach ( $rating_rows as $rr ) {
                $existing_ratings[ (int) $rr->category_id ] = (float) $rr->rating;
            }
        }
        if ( $is_edit ) {
            $hide_pickers = true;
        }
        ?>
        <h3><?php echo esc_html( $title_text ); ?></h3>
        <form id="tt-eval-form" class="tt-ajax-form" data-rest-path="<?php echo esc_attr( $rest_path ); ?>" data-rest-method="<?php echo esc_attr( $rest_method ); ?>" data-draft-key="<?php echo esc_attr( $is_edit ? 'eval-form-edit-' . (int) $existing_eval->id : 'eval-form' ); ?>" data-redirect-after-save="1">
            <?php if ( $hide_pickers ) :
                $hidden_player_id = $is_edit ? $existing_player : $preset_player_id;
                $hidden_player    = $is_edit ? QueryHelpers::get_player( $existing_player ) : $preset_player;
                $hidden_player_label = $hidden_player ? QueryHelpers::player_display_name( $hidden_player ) : '#' . (int) $hidden_player_id;
                ?>
                <input type="hidden" name="player_id" value="<?php echo esc_attr( (string) $hidden_player_id ); ?>" />
                <p class="tt-muted" style="margin: 0 0 12px;">
                    <?php
                    if ( $is_edit ) {
                        /* translators: %s = player display name */
                        printf(
                            esc_html__( 'Editing evaluation of %s.', 'talenttrack' ),
                            '<strong>' . esc_html( $hidden_player_label ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wrapper escapes the name
                        );
                    } else {
                        /* translators: %s = player display name */
                        printf(
                            esc_html__( 'Recording evaluation for %s.', 'talenttrack' ),
                            '<strong>' . esc_html( $hidden_player_label ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — wrapper escapes the name
                        );
                    }
                    ?>
                </p>
            <?php else : ?>
                <?php
                // v3.110.4 — single picker with embedded team filter.
                // Replaces the previous team-first two-picker flow:
                // PlayerSearchPickerComponent renders a built-in "All
                // teams" team filter ABOVE the search input; selecting
                // a team filters the player list, leaving "All teams"
                // shows every player in the user's context. The
                // separate `eval_team_id` picker is gone — it was only
                // used as a player-list filter (REST never read it),
                // and the embedded version handles that without the
                // dead-end "select team first" placeholder.
                ?>
                <div class="tt-field" data-tt-eval-player-wrap>
                    <?php echo PlayerSearchPickerComponent::render( [
                        'name'             => 'player_id',
                        'label'            => __( 'Player', 'talenttrack' ),
                        'required'         => true,
                        'players'          => $players,
                        'placeholder'      => __( 'Type a name to search…', 'talenttrack' ),
                        'show_team_filter' => true,
                    ] ); ?>
                </div>
            <?php endif; ?>
            <?php
            $cur_type_id     = $is_edit ? (int) ( $existing_eval->eval_type_id ?? 0 ) : 0;
            $cur_eval_date   = $is_edit ? (string) ( $existing_eval->eval_date ?? '' ) : current_time( 'Y-m-d' );
            $cur_opponent    = $is_edit ? (string) ( $existing_eval->opponent ?? '' ) : '';
            $cur_competition = $is_edit ? (string) ( $existing_eval->competition ?? '' ) : '';
            $cur_result      = $is_edit ? (string) ( $existing_eval->game_result ?? '' ) : '';
            $cur_home_away   = $is_edit ? (string) ( $existing_eval->home_away ?? '' ) : '';
            $cur_minutes     = $is_edit ? (string) ( $existing_eval->minutes_played ?? '' ) : '';
            $cur_notes       = $is_edit ? (string) ( $existing_eval->notes ?? '' ) : '';
            $match_open      = $is_edit && $cur_type_id > 0 && ! empty( $type_meta[ $cur_type_id ] );
            ?>
            <div class="tt-form-row"><label><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</label><select name="eval_type_id" id="tt_fe_eval_type" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $types as $t ) : ?>
                    <option value="<?php echo (int) $t->id; ?>" data-match="<?php echo (int) $type_meta[ (int) $t->id ]; ?>" <?php selected( $cur_type_id, (int) $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option>
                <?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" name="eval_date" value="<?php echo esc_attr( $cur_eval_date ); ?>" required /></div>
            <div id="tt-fe-match-fields" style="display:<?php echo $match_open ? 'block' : 'none'; ?>;">
                <div class="tt-form-row"><label><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></label><input type="text" name="opponent" value="<?php echo esc_attr( $cur_opponent ); ?>" /></div>
                <div class="tt-form-row">
                    <label><?php esc_html_e( 'Competition', 'talenttrack' ); ?></label>
                    <select name="competition">
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'game_subtype' ) as $tt_ct ) : ?>
                            <option value="<?php echo esc_attr( (string) $tt_ct->name ); ?>" <?php selected( $cur_competition, (string) $tt_ct->name ); ?>><?php echo esc_html( __( (string) $tt_ct->name, 'talenttrack' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Result', 'talenttrack' ); ?></label><input type="text" name="game_result" placeholder="2-1" style="width:80px" value="<?php echo esc_attr( $cur_result ); ?>" /></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></label><select name="home_away"><option value="">—</option><option value="home" <?php selected( $cur_home_away, 'home' ); ?>><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away" <?php selected( $cur_home_away, 'away' ); ?>><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></label><input type="number" name="minutes_played" min="0" max="120" value="<?php echo esc_attr( $cur_minutes ); ?>" /></div>
            </div>
            <h4><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h4>
            <?php foreach ( $categories as $cat ) :
                $cid = (int) $cat->id;
                $cur_rating = isset( $existing_ratings[ $cid ] ) ? (string) $existing_ratings[ $cid ] : '';
                ?>
                <div class="tt-form-row"><label><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat->name ) ); ?></label>
                    <input type="number" name="ratings[<?php echo $cid; ?>]" min="<?php echo esc_attr( $rmin ); ?>" max="<?php echo esc_attr( $rmax ); ?>" step="<?php echo esc_attr( $rstep ); ?>" required style="width:80px" value="<?php echo esc_attr( $cur_rating ); ?>" />
                    <span class="tt-range-hint">(<?php echo esc_html( $rmin ); ?>–<?php echo esc_html( $rmax ); ?>)</span></div>
            <?php endforeach; ?>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="3" data-tt-low-rating-notes><?php echo esc_textarea( $cur_notes ); ?></textarea></div>
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
            <?php
            // v3.110.58 — CLAUDE.md § 6: Save + Cancel on every
            // record-mutating form. tt_back wins when the entry URL
            // captured one; otherwise fall back to the eval detail in
            // edit mode and the evaluations list in create mode.
            $dash_url   = \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
            $list_url   = add_query_arg( [ 'tt_view' => 'evaluations' ], $dash_url );
            $detail_url = $is_edit
                ? add_query_arg( [ 'tt_view' => 'evaluations', 'id' => (int) $existing_eval->id ], $dash_url )
                : $list_url;
            $back       = \TT\Shared\Frontend\Components\BackLink::resolve();
            $cancel_url = $back !== null ? $back['url'] : ( $is_edit ? $detail_url : $list_url );
            echo FormSaveButton::render( [ 'label' => $submit_label, 'cancel_url' => $cancel_url ] );
            ?>
            <div class="tt-form-msg"></div>
        </form>
        <script>
        (function(){
            var typeMeta = <?php echo wp_json_encode( $type_meta ); ?>;
            var sel = document.getElementById('tt_fe_eval_type');
            if (sel) sel.addEventListener('change', function(){
                document.getElementById('tt-fe-match-fields').style.display = (typeMeta[this.value] == 1) ? 'block' : 'none';
            });
            // v3.110.4 — the F1 team→player wiring is gone now that
            // the player picker carries its own embedded team filter
            // (`show_team_filter=true`). The picker hydrator handles
            // the cross-filter natively.
            var form = document.getElementById('tt-eval-form');
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
        <h3><?php esc_html_e( 'Record Training Activity', 'talenttrack' ); ?></h3>
        <form id="tt-activity-form" class="tt-ajax-form" data-rest-path="activities" data-rest-method="POST" data-draft-key="activity-form">
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
            <?php echo FormSaveButton::render( [ 'label' => __( 'Save Activity', 'talenttrack' ) ] ); ?>
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
