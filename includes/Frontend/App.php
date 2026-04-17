<?php
namespace TT\Frontend;

use TT\Helpers;

if ( ! defined( 'ABSPATH' ) ) exit;

class App {

    public static function init() {
        add_shortcode( 'talenttrack_dashboard', [ __CLASS__, 'render' ] );
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tt-public', TT_PLUGIN_URL . 'assets/css/public.css', [], TT_VERSION );
        wp_enqueue_script( 'tt-public', TT_PLUGIN_URL . 'assets/js/public.js', [ 'jquery' ], TT_VERSION, true );
        wp_localize_script( 'tt-public', 'TT', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'tt_frontend' ),
        ]);

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return '<div class="tt-dashboard tt-notice">Please log in to access the dashboard.</div>';
        }

        ob_start();
        echo '<div class="tt-dashboard">';
        self::render_header();

        $is_admin = current_user_can( 'tt_manage_settings' );
        $is_coach = current_user_can( 'tt_evaluate_players' );
        $player   = Helpers::get_player_for_user( $user_id );

        if ( $player && ! $is_coach && ! $is_admin ) {
            self::render_player_dashboard( $player );
        } elseif ( $is_coach || $is_admin ) {
            self::render_coach_dashboard( $user_id, $is_admin );
        } else {
            echo '<p class="tt-notice">No player profile linked to your account. Contact your administrator.</p>';
        }

        echo '</div>';
        return apply_filters( 'tt_dashboard_data', ob_get_clean(), $user_id );
    }

    private static function render_header() {
        $logo = Helpers::get_config( 'logo_url', '' );
        $name = Helpers::get_config( 'academy_name', 'TalentTrack' );
        echo '<div class="tt-dash-header">';
        if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt=""/>';
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';
    }

    /* ═══ Player Dashboard ═══════════════════════════════ */

    private static function render_player_dashboard( $player ) {
        global $wpdb; $p = $wpdb->prefix;
        $max  = (float) Helpers::get_config( 'rating_max', 5 );
        $view = sanitize_text_field( $_GET['tt_view'] ?? 'overview' );

        echo '<div class="tt-tabs">';
        foreach ( [ 'overview' => 'Overview', 'evaluations' => 'Evaluations', 'goals' => 'Goals', 'attendance' => 'Attendance', 'progress' => 'Progress', 'help' => 'Help' ] as $k => $l ) {
            echo '<button class="tt-tab' . ( $view === $k ? ' tt-tab-active' : '' ) . '" data-tab="' . $k . '">' . $l . '</button>';
        }
        echo '</div>';

        // Overview
        echo '<div class="tt-tab-content' . ( $view === 'overview' ? ' tt-tab-content-active' : '' ) . '" data-tab="overview">';
        self::render_player_card( $player );
        $radar = Helpers::player_radar_datasets( $player->id, 3 );
        if ( ! empty( $radar['datasets'] ) ) echo '<div class="tt-radar-wrap">' . Helpers::radar_chart_svg( $radar['labels'], $radar['datasets'], $max ) . '</div>';
        echo '</div>';

        // Evaluations
        echo '<div class="tt-tab-content' . ( $view === 'evaluations' ? ' tt-tab-content-active' : '' ) . '" data-tab="evaluations">';
        $evals = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, lt.name AS type_name, u.display_name AS coach_name FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             WHERE e.player_id=%d ORDER BY e.eval_date DESC", $player->id
        ));
        if ( empty( $evals ) ) echo '<p>No evaluations yet.</p>';
        else {
            echo '<table class="tt-table"><thead><tr><th>Date</th><th>Type</th><th>Coach</th><th>Ratings</th></tr></thead><tbody>';
            foreach ( $evals as $ev ) {
                $full = Helpers::get_evaluation( $ev->id );
                echo '<tr><td>' . esc_html( $ev->eval_date ) . '</td><td>' . esc_html( $ev->type_name ?: '—' ) . '</td><td>' . esc_html( $ev->coach_name ) . '</td><td>';
                if ( $ev->opponent ) echo '<small>vs ' . esc_html( $ev->opponent ) . ' (' . esc_html( $ev->match_result ?: '—' ) . ')</small><br/>';
                if ( $full && ! empty( $full->ratings ) ) foreach ( $full->ratings as $r ) echo '<span class="tt-rating-pill">' . esc_html( $r->category_name ) . ': ' . $r->rating . '</span> ';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Goals
        echo '<div class="tt-tab-content' . ( $view === 'goals' ? ' tt-tab-content-active' : '' ) . '" data-tab="goals">';
        $goals = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}tt_goals WHERE player_id=%d ORDER BY created_at DESC", $player->id ) );
        if ( empty( $goals ) ) echo '<p>No goals assigned.</p>';
        else {
            echo '<div class="tt-goals-list">';
            foreach ( $goals as $g ) {
                echo '<div class="tt-goal-item tt-status-' . esc_attr( $g->status ) . '"><h4>' . esc_html( $g->title ) . '</h4>';
                if ( $g->description ) echo '<p>' . esc_html( $g->description ) . '</p>';
                echo '<span class="tt-status-badge">' . esc_html( ucwords( str_replace( '_', ' ', $g->status ) ) ) . '</span>';
                if ( $g->due_date ) echo ' <small>Due: ' . esc_html( $g->due_date ) . '</small>';
                echo '</div>';
            }
            echo '</div>';
        }
        echo '</div>';

        // Attendance
        echo '<div class="tt-tab-content' . ( $view === 'attendance' ? ' tt-tab-content-active' : '' ) . '" data-tab="attendance">';
        $att = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.*, s.title AS session_title, s.session_date FROM {$p}tt_attendance a
             LEFT JOIN {$p}tt_sessions s ON a.session_id=s.id WHERE a.player_id=%d ORDER BY s.session_date DESC", $player->id
        ));
        if ( empty( $att ) ) echo '<p>No attendance records.</p>';
        else {
            echo '<table class="tt-table"><thead><tr><th>Date</th><th>Session</th><th>Status</th><th>Notes</th></tr></thead><tbody>';
            foreach ( $att as $a ) {
                $cls = strtolower( $a->status ) === 'present' ? 'tt-att-present' : ( strtolower( $a->status ) === 'absent' ? 'tt-att-absent' : 'tt-att-other' );
                echo '<tr class="' . $cls . '"><td>' . esc_html( $a->session_date ) . '</td><td>' . esc_html( $a->session_title ) . '</td><td>' . esc_html( $a->status ) . '</td><td>' . esc_html( $a->notes ?: '—' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Progress
        echo '<div class="tt-tab-content' . ( $view === 'progress' ? ' tt-tab-content-active' : '' ) . '" data-tab="progress">';
        $r5 = Helpers::player_radar_datasets( $player->id, 5 );
        echo ! empty( $r5['datasets'] ) ? '<h3>Development Over Time</h3><div class="tt-radar-wrap">' . Helpers::radar_chart_svg( $r5['labels'], $r5['datasets'], $max ) . '</div>' : '<p>Not enough data yet.</p>';
        echo '</div>';

        // Help
        echo '<div class="tt-tab-content' . ( $view === 'help' ? ' tt-tab-content-active' : '' ) . '" data-tab="help">';
        echo '<h3>How to use your dashboard</h3>';
        echo '<p><strong>Overview</strong> shows your profile and latest radar chart.<br/><strong>Evaluations</strong> lists everything your coaches have recorded.<br/><strong>Goals</strong> shows your development goals.<br/><strong>Attendance</strong> tracks your sessions.<br/><strong>Progress</strong> shows your trajectory.</p>';
        echo '</div>';
    }

    /* ═══ Coach / Admin Dashboard ════════════════════════ */

    private static function render_coach_dashboard( $user_id, $is_admin ) {
        global $wpdb; $p = $wpdb->prefix;
        $view = sanitize_text_field( $_GET['tt_view'] ?? 'roster' );
        $max  = (float) Helpers::get_config( 'rating_max', 5 );
        $teams = $is_admin ? Helpers::get_teams() : Helpers::get_teams_for_coach( $user_id );

        $tabs = [ 'roster' => 'My Team', 'evaluate' => 'New Evaluation', 'session' => 'New Session', 'goals' => 'Manage Goals', 'player' => 'Player Detail', 'help' => 'Help' ];
        echo '<div class="tt-tabs">';
        foreach ( $tabs as $k => $l ) echo '<button class="tt-tab' . ( $view === $k ? ' tt-tab-active' : '' ) . '" data-tab="' . $k . '">' . $l . '</button>';
        echo '</div>';

        // Roster
        echo '<div class="tt-tab-content' . ( $view === 'roster' ? ' tt-tab-content-active' : '' ) . '" data-tab="roster">';
        if ( empty( $teams ) ) echo '<p>No teams assigned.</p>';
        else foreach ( $teams as $team ) {
            echo '<h3>' . esc_html( $team->name ) . ' <small>(' . esc_html( $team->age_group ) . ')</small></h3>';
            $players = Helpers::get_players( $team->id );
            if ( empty( $players ) ) { echo '<p>No players.</p>'; continue; }
            echo '<div class="tt-grid">';
            foreach ( $players as $pl ) self::render_player_card( $pl, true );
            echo '</div>';
        }
        echo '</div>';

        // Evaluate
        echo '<div class="tt-tab-content' . ( $view === 'evaluate' ? ' tt-tab-content-active' : '' ) . '" data-tab="evaluate">';
        self::render_coach_eval_form( $teams, $is_admin );
        echo '</div>';

        // Session
        echo '<div class="tt-tab-content' . ( $view === 'session' ? ' tt-tab-content-active' : '' ) . '" data-tab="session">';
        self::render_coach_session_form( $teams );
        echo '</div>';

        // Goals
        echo '<div class="tt-tab-content' . ( $view === 'goals' ? ' tt-tab-content-active' : '' ) . '" data-tab="goals">';
        self::render_coach_goals_form( $teams, $is_admin );
        echo '</div>';

        // Player detail
        $pid = absint( $_GET['player_id'] ?? 0 );
        echo '<div class="tt-tab-content' . ( $view === 'player' ? ' tt-tab-content-active' : '' ) . '" data-tab="player">';
        if ( $pid && ( $pl = Helpers::get_player( $pid ) ) ) {
            self::render_player_card( $pl );
            $r = Helpers::player_radar_datasets( $pid, 3 );
            if ( ! empty( $r['datasets'] ) ) echo '<div class="tt-radar-wrap">' . Helpers::radar_chart_svg( $r['labels'], $r['datasets'], $max ) . '</div>';
        } else echo '<p>Select a player from the roster.</p>';
        echo '</div>';

        // Help
        echo '<div class="tt-tab-content' . ( $view === 'help' ? ' tt-tab-content-active' : '' ) . '" data-tab="help">';
        echo '<h3>Coach Quick Guide</h3><p><strong>My Team</strong> — view your players.<br/><strong>New Evaluation</strong> — submit training or match evaluations.<br/><strong>New Session</strong> — record sessions with attendance.<br/><strong>Manage Goals</strong> — assign and track goals.</p>';
        echo '</div>';
    }

    private static function render_player_card( $player, $link = false ) {
        $pos  = json_decode( $player->preferred_positions, true );
        $team = $player->team_id ? Helpers::get_team( $player->team_id ) : null;
        echo '<div class="tt-card">';
        if ( $player->photo_url ) echo '<div class="tt-card-thumb"><img src="' . esc_url( $player->photo_url ) . '" alt=""/></div>';
        echo '<div class="tt-card-body">';
        $name = Helpers::player_display_name( $player );
        if ( $link ) {
            $url = add_query_arg( [ 'tt_view' => 'player', 'player_id' => $player->id ] );
            echo '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></h3>';
        } else echo '<h3>' . esc_html( $name ) . '</h3>';
        if ( $team ) echo '<p><strong>Team:</strong> ' . esc_html( $team->name ) . '</p>';
        if ( is_array( $pos ) && $pos ) echo '<p><strong>Pos:</strong> ' . esc_html( implode( ', ', $pos ) ) . '</p>';
        if ( $player->preferred_foot ) echo '<p><strong>Foot:</strong> ' . esc_html( $player->preferred_foot ) . '</p>';
        if ( $player->jersey_number ) echo '<p><strong>#</strong>' . esc_html( $player->jersey_number ) . '</p>';
        echo '</div></div>';
    }

    private static function render_coach_eval_form( $teams, $is_admin ) {
        $categories = Helpers::get_categories();
        $types      = Helpers::get_eval_types();
        $rmin = Helpers::get_config( 'rating_min', 1 );
        $rmax = Helpers::get_config( 'rating_max', 5 );
        $rstep = Helpers::get_config( 'rating_step', '0.5' );

        $type_meta = [];
        foreach ( $types as $t ) {
            $m = Helpers::lookup_meta( $t );
            $type_meta[ $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0;
        }

        $players = $is_admin ? Helpers::get_players() : [];
        if ( ! $is_admin ) foreach ( $teams as $t ) $players = array_merge( $players, Helpers::get_players( $t->id ) );
        ?>
        <h3>Submit Evaluation</h3>
        <form id="tt-eval-form" class="tt-ajax-form">
            <input type="hidden" name="action" value="tt_fe_save_evaluation" />
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'tt_frontend' ); ?>" />
            <div class="tt-form-row"><label>Player *</label><select name="player_id" required>
                <option value="">— Select —</option>
                <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label>Type *</label><select name="eval_type_id" id="tt_fe_eval_type" required>
                <option value="">— Select —</option>
                <?php foreach ( $types as $t ) : ?><option value="<?php echo (int) $t->id; ?>" data-match="<?php echo $type_meta[ $t->id ]; ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label>Date *</label><input type="date" name="eval_date" value="<?php echo current_time( 'Y-m-d' ); ?>" required /></div>
            <div id="tt-fe-match-fields" style="display:none;">
                <div class="tt-form-row"><label>Opponent</label><input type="text" name="opponent" /></div>
                <div class="tt-form-row"><label>Competition</label><input type="text" name="competition" /></div>
                <div class="tt-form-row"><label>Result</label><input type="text" name="match_result" placeholder="e.g. 2-1" style="width:80px"/></div>
                <div class="tt-form-row"><label>Home/Away</label><select name="home_away"><option value="">—</option><option value="home">Home</option><option value="away">Away</option></select></div>
                <div class="tt-form-row"><label>Minutes Played</label><input type="number" name="minutes_played" min="0" max="120" /></div>
            </div>
            <h4>Ratings</h4>
            <?php foreach ( $categories as $cat ) : ?>
                <div class="tt-form-row"><label><?php echo esc_html( $cat->name ); ?></label>
                    <input type="number" name="ratings[<?php echo (int) $cat->id; ?>]" min="<?php echo $rmin; ?>" max="<?php echo $rmax; ?>" step="<?php echo $rstep; ?>" required style="width:80px" />
                    <span class="tt-range-hint">(<?php echo $rmin; ?>–<?php echo $rmax; ?>)</span></div>
            <?php endforeach; ?>
            <div class="tt-form-row"><label>Notes</label><textarea name="notes" rows="3"></textarea></div>
            <button type="submit" class="tt-btn tt-btn-primary">Save Evaluation</button>
            <div class="tt-form-msg"></div>
        </form>
        <script>
        (function(){
            var typeMeta = <?php echo wp_json_encode( $type_meta ); ?>;
            document.getElementById('tt_fe_eval_type').addEventListener('change', function(){
                var show = typeMeta[this.value] == 1;
                document.getElementById('tt-fe-match-fields').style.display = show ? 'block' : 'none';
            });
        })();
        </script>
        <?php
    }

    private static function render_coach_session_form( $teams ) {
        $statuses = Helpers::get_lookup_names( 'attendance_status' );
        $all_players = [];
        foreach ( $teams as $t ) foreach ( Helpers::get_players( $t->id ) as $pl ) $all_players[ $pl->id ] = $pl;
        ?>
        <h3>Record Training Session</h3>
        <form id="tt-session-form" class="tt-ajax-form">
            <input type="hidden" name="action" value="tt_fe_save_session" />
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'tt_frontend' ); ?>" />
            <div class="tt-form-row"><label>Title *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label>Date *</label><input type="date" name="session_date" value="<?php echo current_time( 'Y-m-d' ); ?>" required /></div>
            <div class="tt-form-row"><label>Team</label><select name="team_id">
                <?php foreach ( $teams as $t ) : ?><option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label>Location</label><input type="text" name="location" /></div>
            <div class="tt-form-row"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
            <h4>Attendance</h4>
            <table class="tt-table"><thead><tr><th>Player</th><th>Status</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ( $all_players as $pl ) : ?>
                <tr><td><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></td>
                    <td><select name="att[<?php echo (int) $pl->id; ?>][status]">
                        <?php foreach ( $statuses as $s ) : ?><option value="<?php echo esc_attr( $s ); ?>"><?php echo esc_html( $s ); ?></option><?php endforeach; ?>
                    </select></td>
                    <td><input type="text" name="att[<?php echo (int) $pl->id; ?>][notes]" style="width:150px" /></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <button type="submit" class="tt-btn tt-btn-primary" style="margin-top:10px;">Save Session</button>
            <div class="tt-form-msg"></div>
        </form>
        <?php
    }

    private static function render_coach_goals_form( $teams, $is_admin ) {
        global $wpdb; $p = $wpdb->prefix;
        $players = $is_admin ? Helpers::get_players() : [];
        if ( ! $is_admin ) foreach ( $teams as $t ) $players = array_merge( $players, Helpers::get_players( $t->id ) );
        $statuses   = Helpers::get_lookup_names( 'goal_status' );
        $priorities = Helpers::get_lookup_names( 'goal_priority' );
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
        <h3>Add Goal</h3>
        <form id="tt-goal-form" class="tt-ajax-form">
            <input type="hidden" name="action" value="tt_fe_save_goal" />
            <input type="hidden" name="nonce" value="<?php echo wp_create_nonce( 'tt_frontend' ); ?>" />
            <div class="tt-form-row"><label>Player *</label><select name="player_id" required>
                <option value="">— Select —</option>
                <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>"><?php echo esc_html( Helpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label>Title *</label><input type="text" name="title" required /></div>
            <div class="tt-form-row"><label>Description</label><textarea name="description" rows="2"></textarea></div>
            <div class="tt-form-row"><label>Priority</label><select name="priority">
                <?php foreach ( $priorities as $pr ) : ?><option value="<?php echo esc_attr( strtolower( $pr ) ); ?>"><?php echo esc_html( $pr ); ?></option><?php endforeach; ?>
            </select></div>
            <div class="tt-form-row"><label>Due Date</label><input type="date" name="due_date" /></div>
            <button type="submit" class="tt-btn tt-btn-primary">Add Goal</button>
            <div class="tt-form-msg"></div>
        </form>
        <h3 style="margin-top:20px;">Current Goals</h3>
        <?php if ( empty( $goals ) ) : ?><p>No goals yet.</p>
        <?php else : ?>
            <table class="tt-table"><thead><tr><th>Player</th><th>Goal</th><th>Priority</th><th>Status</th><th>Due</th><th></th></tr></thead><tbody>
            <?php foreach ( $goals as $g ) : ?>
                <tr><td><?php echo esc_html( $g->player_name ); ?></td><td><?php echo esc_html( $g->title ); ?></td>
                    <td><?php echo esc_html( ucfirst( $g->priority ) ); ?></td>
                    <td><select class="tt-goal-status-select" data-goal-id="<?php echo (int) $g->id; ?>">
                        <?php foreach ( $statuses as $st ) : $v = strtolower( str_replace( ' ', '_', $st ) ); ?>
                            <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $g->status, $v ); ?>><?php echo esc_html( $st ); ?></option><?php endforeach; ?>
                    </select></td>
                    <td><?php echo esc_html( $g->due_date ?: '—' ); ?></td>
                    <td><button class="tt-btn-sm tt-goal-delete" data-goal-id="<?php echo (int) $g->id; ?>">✕</button></td></tr>
            <?php endforeach; ?></tbody></table>
        <?php endif; ?>
        <?php
    }
}
