<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\CustomFields\CustomFieldsRepository;
use TT\Infrastructure\CustomFields\CustomValuesRepository;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;

class CoachDashboardView {

    public function render( int $user_id, bool $is_admin ): void {
        global $wpdb; $p = $wpdb->prefix;
        $view = isset( $_GET['tt_view'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_view'] ) ) : 'roster';
        $max  = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $teams = $is_admin ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $user_id );

        // v2.15.0: enqueue card stylesheet for the podium + player detail card.
        \TT\Modules\Stats\Admin\PlayerCardView::enqueueStyles();

        // v2.16.0: enqueue the frontend mobile responsive layer.
        wp_enqueue_style(
            'tt-frontend-mobile',
            TT_PLUGIN_URL . 'assets/css/frontend-mobile.css',
            [],
            TT_VERSION
        );

        $tabs = [
            'roster'   => __( 'My Team', 'talenttrack' ),
            'evaluate' => __( 'New Evaluation', 'talenttrack' ),
            'activity'  => __( 'New Activity', 'talenttrack' ),
            'goals'    => __( 'Manage Goals', 'talenttrack' ),
            'player'   => __( 'Player Detail', 'talenttrack' ),
            'help'     => __( 'Help', 'talenttrack' ),
        ];
        echo '<div class="tt-tabs">';
        foreach ( $tabs as $k => $l ) {
            echo '<button class="tt-tab' . ( $view === $k ? ' tt-tab-active' : '' ) . '" data-tab="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</button>';
        }
        echo '</div>';

        // Roster
        echo '<div class="tt-tab-content' . ( $view === 'roster' ? ' tt-tab-content-active' : '' ) . '" data-tab="roster">';
        if ( empty( $teams ) ) {
            echo '<p>' . esc_html__( 'No teams assigned.', 'talenttrack' ) . '</p>';
        } else {
            $team_svc = new \TT\Infrastructure\Stats\TeamStatsService();
            foreach ( $teams as $team ) {
                echo '<h3>' . esc_html( (string) $team->name ) . ' <small>(' . esc_html( (string) $team->age_group ) . ')</small></h3>';
                // v2.15.0: top-3 podium before the roster grid.
                $top = $team_svc->getTopPlayersForTeam( (int) $team->id, 3, 5 );
                if ( ! empty( $top ) ) {
                    \TT\Modules\Stats\Admin\PlayerCardView::renderPodium( $top );
                }
                $players = QueryHelpers::get_players( (int) $team->id );
                if ( empty( $players ) ) { echo '<p>' . esc_html__( 'No players.', 'talenttrack' ) . '</p>'; continue; }
                echo '<div class="tt-grid">';
                foreach ( $players as $pl ) $this->renderPlayerCard( $pl, true );
                echo '</div>';
            }
        }
        echo '</div>';

        // Evaluate
        echo '<div class="tt-tab-content' . ( $view === 'evaluate' ? ' tt-tab-content-active' : '' ) . '" data-tab="evaluate">';
        $this->renderEvalForm( $teams, $is_admin );
        echo '</div>';

        // Session
        echo '<div class="tt-tab-content' . ( $view === 'activity' ? ' tt-tab-content-active' : '' ) . '" data-tab="activity">';
        $this->renderSessionForm( $teams );
        echo '</div>';

        // Goals
        echo '<div class="tt-tab-content' . ( $view === 'goals' ? ' tt-tab-content-active' : '' ) . '" data-tab="goals">';
        $this->renderGoalsForm( $teams, $is_admin );
        echo '</div>';

        // Player detail
        $pid = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
        echo '<div class="tt-tab-content' . ( $view === 'player' ? ' tt-tab-content-active' : '' ) . '" data-tab="player">';
        if ( $pid && ( $pl = QueryHelpers::get_player( $pid ) ) ) {
            // v2.16.0: print-report button, preserves current context.
            $_print_url = esc_url( add_query_arg( [ 'tt_print' => $pid ], remove_query_arg( [ 'tt_view', 'player_id' ] ) ) );
            echo '<div style="margin-bottom:10px;">';
            echo '<a href="' . $_print_url . '" target="_blank" rel="noopener" style="display:inline-block;padding:6px 12px;border:1px solid #c3c4c7;border-radius:4px;background:#fff;color:#1a1d21;font-size:13px;text-decoration:none;">';
            echo esc_html__( '🖨 Print report', 'talenttrack' );
            echo '</a>';
            echo '</div>';

            // v2.15.0: FIFA-style card + the classic info block side by side.
            echo '<div style="display:flex;gap:30px;flex-wrap:wrap;align-items:flex-start;">';
            echo '<div>';
            \TT\Modules\Stats\Admin\PlayerCardView::renderCard( $pid, 'md', true );
            echo '</div>';
            echo '<div style="flex:1;min-width:280px;">';
            $this->renderPlayerCard( $pl );
            $this->renderCustomFieldsBlock( $pid );
            $r = QueryHelpers::player_radar_datasets( $pid, 3 );
            if ( ! empty( $r['datasets'] ) ) echo '<div class="tt-radar-wrap">' . QueryHelpers::radar_chart_svg( $r['labels'], $r['datasets'], $max ) . '</div>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<p>' . esc_html__( 'Select a player from the roster.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';

        // Help
        echo '<div class="tt-tab-content' . ( $view === 'help' ? ' tt-tab-content-active' : '' ) . '" data-tab="help">';
        echo '<h3>' . esc_html__( 'Coach Quick Guide', 'talenttrack' ) . '</h3>';
        echo '<p>' . esc_html__( 'My Team shows your players. New Evaluation submits training or match evaluations. New Activity records attendance. Manage Goals assigns and tracks goals.', 'talenttrack' ) . '</p>';
        echo '</div>';
    }

    private function renderPlayerCard( object $player, bool $link = false ): void {
        $pos  = json_decode( (string) $player->preferred_positions, true );
        $team = $player->team_id ? QueryHelpers::get_team( (int) $player->team_id ) : null;
        echo '<div class="tt-card">';
        if ( $player->photo_url ) echo '<div class="tt-card-thumb"><img src="' . esc_url( (string) $player->photo_url ) . '" alt="" /></div>';
        echo '<div class="tt-card-body">';
        $name = QueryHelpers::player_display_name( $player );
        if ( $link ) {
            $url = add_query_arg( [ 'tt_view' => 'player', 'player_id' => $player->id ] );
            echo '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></h3>';
        } else {
            echo '<h3>' . esc_html( $name ) . '</h3>';
        }
        if ( $team ) echo '<p><strong>' . esc_html__( 'Team:', 'talenttrack' ) . '</strong> ' . esc_html( (string) $team->name ) . '</p>';
        if ( is_array( $pos ) && $pos ) echo '<p><strong>' . esc_html__( 'Pos:', 'talenttrack' ) . '</strong> ' . esc_html( implode( ', ', $pos ) ) . '</p>';
        if ( $player->preferred_foot ) echo '<p><strong>' . esc_html__( 'Foot:', 'talenttrack' ) . '</strong> ' . \TT\Infrastructure\Query\LookupPill::render( 'foot_options', (string) $player->preferred_foot ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — pill returns escaped html
        if ( $player->jersey_number ) echo '<p><strong>#</strong>' . esc_html( (string) $player->jersey_number ) . '</p>';
        echo '</div></div>';
    }

    private function renderCustomFieldsBlock( int $player_id ): void {
        $fields = ( new CustomFieldsRepository() )->getActive( CustomFieldsRepository::ENTITY_PLAYER );
        if ( empty( $fields ) ) return;
        $values = ( new CustomValuesRepository() )->getByEntityKeyed( CustomFieldsRepository::ENTITY_PLAYER, $player_id );

        $has_any = false;
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v !== null && $v !== '' ) { $has_any = true; break; }
        }
        if ( ! $has_any ) return;

        echo '<div class="tt-custom-fields">';
        echo '<h4>' . esc_html__( 'Additional Information', 'talenttrack' ) . '</h4>';
        echo '<dl class="tt-custom-fields-list">';
        foreach ( $fields as $f ) {
            $v = $values[ (string) $f->field_key ] ?? null;
            if ( $v === null || $v === '' ) continue;
            echo '<dt>' . esc_html( (string) $f->label ) . '</dt>';
            echo '<dd>' . CustomFieldRenderer::display( $f, $v ) . '</dd>';
        }
        echo '</dl>';
        echo '</div>';
    }

    /** @param object[] $teams */
    private function renderEvalForm( array $teams, bool $is_admin ): void {
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

        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) foreach ( $teams as $t ) $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
        ?>
        <h3><?php esc_html_e( 'Submit Evaluation', 'talenttrack' ); ?></h3>
        <form id="tt-eval-form" class="tt-ajax-form" data-rest-path="evaluations" data-rest-method="POST">
            <div class="tt-form-row"><label><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</label><select name="player_id" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $players as $pl ) : ?>
                    <option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option>
                <?php endforeach; ?>
            </select></div>
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
                        <?php foreach ( \TT\Infrastructure\Query\QueryHelpers::get_lookups( 'game_subtype' ) as $tt_ct ) : ?>
                            <option value="<?php echo esc_attr( (string) $tt_ct->name ); ?>"><?php echo esc_html( __( (string) $tt_ct->name, 'talenttrack' ) ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Result', 'talenttrack' ); ?></label><input type="text" name="game_result" placeholder="2-1" style="width:80px" /></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></label><select name="home_away"><option value="">—</option><option value="home"><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away"><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></div>
                <div class="tt-form-row"><label><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></label><input type="number" name="minutes_played" min="0" max="120" /></div>
            </div>
            <h4><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></h4>
            <?php foreach ( $categories as $cat ) : ?>
                <div class="tt-form-row"><label><?php echo esc_html( EvalCategoriesRepository::displayLabel( (string) $cat->name ) ); ?></label>
                    <input type="number" name="ratings[<?php echo (int) $cat->id; ?>]" min="<?php echo esc_attr( $rmin ); ?>" max="<?php echo esc_attr( $rmax ); ?>" step="<?php echo esc_attr( $rstep ); ?>" required style="width:80px" />
                    <span class="tt-range-hint">(<?php echo esc_html( $rmin ); ?>–<?php echo esc_html( $rmax ); ?>)</span></div>
            <?php endforeach; ?>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="3"></textarea></div>
            <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Save Evaluation', 'talenttrack' ); ?></button>
            <div class="tt-form-msg"></div>
        </form>
        <script>
        (function(){
            var typeMeta = <?php echo wp_json_encode( $type_meta ); ?>;
            var sel = document.getElementById('tt_fe_eval_type');
            if (sel) sel.addEventListener('change', function(){
                document.getElementById('tt-fe-match-fields').style.display = (typeMeta[this.value] == 1) ? 'block' : 'none';
            });
        })();
        </script>
        <?php
    }

    /** @param object[] $teams */
    private function renderSessionForm( array $teams ): void {
        $statuses = QueryHelpers::get_lookup_names( 'attendance_status' );
        $all_players = [];
        foreach ( $teams as $t ) foreach ( QueryHelpers::get_players( (int) $t->id ) as $pl ) $all_players[ (int) $pl->id ] = $pl;
        ?>
        <h3><?php esc_html_e( 'Record Training Activity', 'talenttrack' ); ?></h3>
        <form id="tt-activity-form" class="tt-ajax-form" data-rest-path="activities" data-rest-method="POST">
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</label><input type="date" name="session_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Team', 'talenttrack' ); ?></label><select name="team_id">
                <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Location', 'talenttrack' ); ?></label><input type="text" name="location" /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Notes', 'talenttrack' ); ?></label><textarea name="notes" rows="2"></textarea></div>
            <h4><?php esc_html_e( 'Attendance', 'talenttrack' ); ?></h4>
            <table class="tt-table"><thead><tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php foreach ( $all_players as $pl ) : ?>
                <tr><td><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></td>
                    <td><select name="att[<?php echo (int) $pl->id; ?>][status]">
                        <?php foreach ( $statuses as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( LabelTranslator::attendanceStatus( $s ) ); ?></option><?php endforeach; ?>
                    </select></td>
                    <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" style="width:150px" /></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <button type="submit" class="tt-btn tt-btn-primary" style="margin-top:10px;"><?php esc_html_e( 'Save Activity', 'talenttrack' ); ?></button>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    /** @param object[] $teams */
    private function renderGoalsForm( array $teams, bool $is_admin ): void {
        global $wpdb; $p = $wpdb->prefix;
        $players = $is_admin ? QueryHelpers::get_players() : [];
        if ( ! $is_admin ) foreach ( $teams as $t ) $players = array_merge( $players, QueryHelpers::get_players( (int) $t->id ) );
        $statuses   = QueryHelpers::get_lookup_names( 'goal_status' );
        $priorities = QueryHelpers::get_lookup_names( 'goal_priority' );
        $pids = wp_list_pluck( $players, 'id' );
        $goals = [];
        if ( $pids ) {
            $ph = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
            $goals = $wpdb->get_results( $wpdb->prepare(
                "SELECT g.*, CONCAT(pl.first_name,' ',pl.last_name) AS player_name FROM {$p}tt_goals g
                 LEFT JOIN {$p}tt_players pl ON g.player_id=pl.id WHERE g.player_id IN ($ph) ORDER BY g.created_at DESC LIMIT 30",
                ...$pids
            ));
        }
        ?>
        <h3><?php esc_html_e( 'Add Goal', 'talenttrack' ); ?></h3>
        <form id="tt-goal-form" class="tt-ajax-form" data-rest-path="goals" data-rest-method="POST">
            <div class="tt-form-row"><label><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</label><select name="player_id" required>
                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Title', 'talenttrack' ); ?> *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Description', 'talenttrack' ); ?></label><textarea name="description" rows="2"></textarea></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Priority', 'talenttrack' ); ?></label><select name="priority">
                <?php foreach ( $priorities as $pr ) : ?><option value="<?php echo esc_attr( strtolower( $pr ) ); ?>"><?php echo esc_html( LabelTranslator::goalPriority( $pr ) ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label><?php esc_html_e( 'Due Date', 'talenttrack' ); ?></label><input type="date" name="due_date" /></div>
            <button type="submit" class="tt-btn tt-btn-primary"><?php esc_html_e( 'Add Goal', 'talenttrack' ); ?></button>
            <div class="tt-form-msg"></div>
        </form>
        <h3 style="margin-top:20px;"><?php esc_html_e( 'Current Goals', 'talenttrack' ); ?></h3>
        <?php if ( empty( $goals ) ) : ?><p><?php esc_html_e( 'No goals yet.', 'talenttrack' ); ?></p>
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
                        <?php foreach ( $statuses as $st ) : $v = strtolower( str_replace( ' ', '_', $st ) ); ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( (string) $g->status, $v ); ?>><?php echo esc_html( LabelTranslator::goalStatus( $v ) ); ?></option><?php endforeach; ?>
                    </select></td>
                    <td><?php echo esc_html( $g->due_date ?: '—' ); ?></td>
                    <td><button class="tt-btn-sm tt-goal-delete" data-goal-id="<?php echo (int) $g->id; ?>">✕</button></td></tr>
            <?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <?php
    }
}
