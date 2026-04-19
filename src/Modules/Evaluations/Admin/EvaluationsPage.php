<?php
namespace TT\Modules\Evaluations\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * EvaluationsPage — admin CRUD for evaluations.
 *
 * v2.6.2: fail-loud on insert/update failures. If the database rejects the
 * save (e.g. missing schema column), the user is redirected back with an
 * error notice instead of silently redirecting to the list showing "Saved."
 */
class EvaluationsPage {

    private const TRANSIENT_PREFIX = 'tt_eval_form_state_';

    public static function init(): void {
        add_action( 'admin_post_tt_save_evaluation', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_tt_delete_evaluation', [ __CLASS__, 'handle_delete' ] );
    }

    public static function render_page(): void {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $action === 'new' || $action === 'edit' ) { self::render_form( $action === 'edit' ? $id : 0 ); return; }
        if ( $action === 'view' && $id ) { self::render_view( $id ); return; }
        self::render_list();
    }

    private static function render_list(): void {
        global $wpdb; $p = $wpdb->prefix;
        $evals = $wpdb->get_results(
            "SELECT e.*, lt.name AS type_name, CONCAT(pl.first_name,' ',pl.last_name) AS player_name, u.display_name AS coach_name
             FROM {$p}tt_evaluations e LEFT JOIN {$p}tt_lookups lt ON e.eval_type_id=lt.id AND lt.lookup_type='eval_type'
             LEFT JOIN {$p}tt_players pl ON e.player_id=pl.id LEFT JOIN {$wpdb->users} u ON e.coach_id=u.ID
             ORDER BY e.eval_date DESC LIMIT 50"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Evaluations', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div><?php endif; ?>
            <table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Coach', 'talenttrack' ); ?></th><th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th></tr></thead><tbody>
            <?php if ( empty( $evals ) ) : ?><tr><td colspan="5"><?php esc_html_e( 'No evaluations.', 'talenttrack' ); ?></td></tr>
            <?php else : foreach ( $evals as $ev ) : ?>
                <tr><td><?php echo esc_html( (string) $ev->eval_date ); ?></td><td><?php echo esc_html( $ev->player_name ?: '—' ); ?></td>
                    <td><?php echo esc_html( $ev->type_name ?: '—' ); ?></td><td><?php echo esc_html( (string) $ev->coach_name ); ?></td>
                    <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=view&id={$ev->id}" ) ); ?>"><?php esc_html_e( 'View', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-evaluations&action=edit&id={$ev->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_evaluation&id={$ev->id}" ), 'tt_del_eval_' . $ev->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td></tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
        <?php
    }

    private static function render_form( int $eval_id ): void {
        $eval = $eval_id ? QueryHelpers::get_evaluation( $eval_id ) : null;
        $players    = QueryHelpers::get_players();
        $categories = QueryHelpers::get_categories();
        $types      = QueryHelpers::get_eval_types();
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $rstep = (float) QueryHelpers::get_config( 'rating_step', '0.5' );
        $existing = [];
        if ( $eval && ! empty( $eval->ratings ) ) foreach ( $eval->ratings as $r ) $existing[ (int) $r->category_id ] = (float) $r->rating;
        $type_meta = [];
        foreach ( $types as $t ) { $m = QueryHelpers::lookup_meta( $t ); $type_meta[ (int) $t->id ] = ! empty( $m['requires_match_details'] ) ? 1 : 0; }

        $state = self::popFormState();
        ?>
        <div class="wrap">
            <h1><?php echo $eval ? esc_html__( 'Edit Evaluation', 'talenttrack' ) : esc_html__( 'New Evaluation', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h1>

            <?php if ( $state && ! empty( $state['db_error'] ) ) : ?>
                <div class="notice notice-error">
                    <p><strong><?php esc_html_e( 'The database rejected the save. No evaluation was created.', 'talenttrack' ); ?></strong></p>
                    <p style="font-family:monospace;font-size:12px;"><?php echo esc_html( (string) $state['db_error'] ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_save_evaluation', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_evaluation" />
                <?php if ( $eval ) : ?><input type="hidden" name="id" value="<?php echo (int) $eval->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Player', 'talenttrack' ); ?> *</th><td><select name="player_id" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $players as $pl ) : ?><option value="<?php echo (int) $pl->id; ?>" <?php selected( $eval->player_id ?? 0, $pl->id ); ?>><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Type', 'talenttrack' ); ?> *</th><td><select name="eval_type_id" id="tt_eval_type" required>
                        <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                        <?php foreach ( $types as $t ) : ?><option value="<?php echo (int) $t->id; ?>" data-match="<?php echo (int) $type_meta[ (int) $t->id ]; ?>" <?php selected( $eval->eval_type_id ?? 0, $t->id ); ?>><?php echo esc_html( (string) $t->name ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?> *</th><td><input type="date" name="eval_date" value="<?php echo esc_attr( $eval->eval_date ?? current_time( 'Y-m-d' ) ); ?>" required /></td></tr>
                </table>
                <table class="form-table" id="tt-match-fields" style="display:none;">
                    <tr><th><?php esc_html_e( 'Opponent', 'talenttrack' ); ?></th><td><input type="text" name="opponent" value="<?php echo esc_attr( $eval->opponent ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Competition', 'talenttrack' ); ?></th><td><input type="text" name="competition" value="<?php echo esc_attr( $eval->competition ?? '' ); ?>" class="regular-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Result', 'talenttrack' ); ?></th><td><input type="text" name="match_result" value="<?php echo esc_attr( $eval->match_result ?? '' ); ?>" style="width:80px" /></td></tr>
                    <tr><th><?php esc_html_e( 'Home/Away', 'talenttrack' ); ?></th><td><select name="home_away"><option value="">—</option><option value="home" <?php selected( $eval->home_away ?? '', 'home' ); ?>><?php esc_html_e( 'Home', 'talenttrack' ); ?></option><option value="away" <?php selected( $eval->home_away ?? '', 'away' ); ?>><?php esc_html_e( 'Away', 'talenttrack' ); ?></option></select></td></tr>
                    <tr><th><?php esc_html_e( 'Minutes Played', 'talenttrack' ); ?></th><td><input type="number" name="minutes_played" value="<?php echo esc_attr( $eval->minutes_played ?? '' ); ?>" min="0" max="120" /></td></tr>
                </table>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Ratings', 'talenttrack' ); ?></th><td>
                        <?php if ( empty( $categories ) ) : ?>
                            <p style="color:#b32d2e;"><strong><?php esc_html_e( 'No evaluation categories configured.', 'talenttrack' ); ?></strong> <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=eval_categories' ) ); ?>"><?php esc_html_e( 'Add some', 'talenttrack' ); ?></a>.</p>
                        <?php else : foreach ( $categories as $cat ) : ?>
                            <p><label><strong><?php echo esc_html( (string) $cat->name ); ?></strong></label><br/>
                            <input type="number" name="ratings[<?php echo (int) $cat->id; ?>]" min="<?php echo esc_attr( (string) $rmin ); ?>" max="<?php echo esc_attr( (string) $rmax ); ?>" step="<?php echo esc_attr( (string) $rstep ); ?>" value="<?php echo esc_attr( (string) ( $existing[ (int) $cat->id ] ?? '' ) ); ?>" style="width:80px" required />
                            <span class="description">(<?php echo esc_html( (string) $rmin ); ?>–<?php echo esc_html( (string) $rmax ); ?>)</span></p>
                        <?php endforeach; endif; ?>
                    </td></tr>
                    <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><textarea name="notes" rows="4" class="large-text"><?php echo esc_textarea( $eval->notes ?? '' ); ?></textarea></td></tr>
                </table>
                <?php submit_button( $eval ? __( 'Update', 'talenttrack' ) : __( 'Save', 'talenttrack' ) ); ?>
            </form>
        </div>
        <script>
        jQuery(function($){
            var m = <?php echo wp_json_encode( $type_meta ); ?>;
            function t(){ var v = $('#tt_eval_type').val(); $('#tt-match-fields').toggle(v && m[v]==1); }
            $('#tt_eval_type').on('change', t); t();
        });
        </script>
        <?php
    }

    private static function render_view( int $id ): void {
        $eval = QueryHelpers::get_evaluation( $id );
        if ( ! $eval ) { echo '<div class="wrap"><p>' . esc_html__( 'Not found.', 'talenttrack' ) . '</p></div>'; return; }
        $player = QueryHelpers::get_player( (int) $eval->player_id );
        $max = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $labels = []; $values = [];
        if ( ! empty( $eval->ratings ) ) foreach ( $eval->ratings as $r ) { $labels[] = (string) $r->category_name; $values[] = (float) $r->rating; }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Evaluation', 'talenttrack' ); ?> — <?php echo esc_html( $player ? QueryHelpers::player_display_name( $player ) : '' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-evaluations' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h1>
            <div style="display:flex;gap:30px;flex-wrap:wrap;margin-top:20px;">
                <div style="flex:1;min-width:260px;">
                    <table class="form-table">
                        <tr><th><?php esc_html_e( 'Date', 'talenttrack' ); ?></th><td><?php echo esc_html( (string) $eval->eval_date ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Type', 'talenttrack' ); ?></th><td><?php echo esc_html( $eval->type_name ?: '—' ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th><td><?php echo nl2br( esc_html( $eval->notes ?: '—' ) ); ?></td></tr>
                    </table>
                </div>
                <div style="flex:1;min-width:320px;">
                    <h3><?php esc_html_e( 'Radar Chart', 'talenttrack' ); ?></h3>
                    <?php echo ! empty( $labels ) ? QueryHelpers::radar_chart_svg( $labels, [ [ 'label' => (string) $eval->eval_date, 'values' => $values ] ], $max ) : ''; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function handle_save(): void {
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_evaluation', 'tt_nonce' );
        global $wpdb; $p = $wpdb->prefix;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $header = [
            'player_id' => isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0,
            'coach_id' => get_current_user_id(),
            'eval_type_id' => isset( $_POST['eval_type_id'] ) ? absint( $_POST['eval_type_id'] ) : 0,
            'eval_date' => isset( $_POST['eval_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['eval_date'] ) ) : '',
            'notes' => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['notes'] ) ) : '',
            'opponent' => isset( $_POST['opponent'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['opponent'] ) ) : '',
            'competition' => isset( $_POST['competition'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['competition'] ) ) : '',
            'match_result' => isset( $_POST['match_result'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['match_result'] ) ) : '',
            'home_away' => isset( $_POST['home_away'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['home_away'] ) ) : '',
            'minutes_played' => ! empty( $_POST['minutes_played'] ) ? absint( $_POST['minutes_played'] ) : null,
        ];

        if ( $id ) {
            $ok = $wpdb->update( "{$p}tt_evaluations", $header, [ 'id' => $id ] );
            if ( $ok !== false ) $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        } else {
            do_action( 'tt_before_save_evaluation', $header['player_id'], 0, 0 );
            $ok = $wpdb->insert( "{$p}tt_evaluations", $header );
            if ( $ok !== false ) $id = (int) $wpdb->insert_id;
        }

        if ( $ok === false ) {
            Logger::error( 'admin.evaluation.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'is_update' => (bool) $id ] );
            self::saveFormState( [ 'db_error' => $wpdb->last_error ?: __( 'Unknown database error.', 'talenttrack' ) ] );
            $back = add_query_arg(
                [ 'page' => 'tt-evaluations', 'action' => $id ? 'edit' : 'new', 'id' => $id ],
                admin_url( 'admin.php' )
            );
            wp_safe_redirect( $back );
            exit;
        }

        $rmin = (float) QueryHelpers::get_config( 'rating_min', '1' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '5' );
        $ratings = isset( $_POST['ratings'] ) && is_array( $_POST['ratings'] ) ? $_POST['ratings'] : [];
        foreach ( $ratings as $cid => $val ) {
            $r = max( $rmin, min( $rmax, floatval( $val ) ) );
            $ok_rating = $wpdb->insert( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id, 'category_id' => absint( $cid ), 'rating' => $r ] );
            if ( $ok_rating === false ) {
                Logger::error( 'admin.evaluation.rating.save.failed', [ 'db_error' => (string) $wpdb->last_error, 'evaluation_id' => $id, 'category_id' => absint( $cid ) ] );
            }
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tt-evaluations&tt_msg=saved' ) );
        exit;
    }

    public static function handle_delete(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        check_admin_referer( 'tt_del_eval_' . $id );
        if ( ! current_user_can( 'tt_evaluate_players' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb; $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_eval_ratings", [ 'evaluation_id' => $id ] );
        $wpdb->delete( "{$p}tt_evaluations", [ 'id' => $id ] );
        wp_safe_redirect( admin_url( 'admin.php?page=tt-evaluations&tt_msg=deleted' ) );
        exit;
    }

    private static function saveFormState( array $state ): void {
        set_transient( self::TRANSIENT_PREFIX . get_current_user_id(), $state, 60 );
    }

    private static function popFormState(): ?array {
        $key   = self::TRANSIENT_PREFIX . get_current_user_id();
        $state = get_transient( $key );
        if ( $state === false ) return null;
        delete_transient( $key );
        return is_array( $state ) ? $state : null;
    }
}
