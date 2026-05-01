<?php
namespace TT\Modules\Pdp\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * SeasonsPage — wp-admin CRUD for `tt_seasons`.
 *
 * Tiny: list, create, set-current. Edit (renaming a season / nudging
 * dates) is intentionally out-of-scope; if a season needs editing,
 * delete + re-add is fine because PDP files reference seasons by id
 * and the join survives a name change anyway.
 *
 * Mutations route through `admin-post.php` (server-side form posts)
 * rather than the REST endpoints because the wp-admin context isn't
 * the place to bring REST + JS in.
 */
class SeasonsPage {

    public static function init(): void {
        add_action( 'admin_post_tt_save_season', [ __CLASS__, 'handleSave' ] );
        add_action( 'admin_post_tt_set_current_season', [ __CLASS__, 'handleSetCurrent' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'tt_edit_seasons' ) ) {
            wp_die( esc_html__( 'You do not have permission to manage seasons.', 'talenttrack' ) );
        }

        $repo = new SeasonsRepository();
        $rows = $repo->all();
        $current = $repo->current();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Seasons', 'talenttrack' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['tt_err'] ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( (string) wp_unslash( $_GET['tt_err'] ) ); ?></p></div>
            <?php endif; ?>

            <p style="color:#5b6e75;"><?php esc_html_e( 'Exactly one season can be marked current. PDP files are scoped to a season, and the carryover job runs whenever you change the current season.', 'talenttrack' ); ?></p>

            <table class="widefat striped" style="max-width:900px;">
                <thead><tr>
                    <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Start', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'End', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Current?', 'talenttrack' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="5"><em><?php esc_html_e( 'No seasons yet — add the first below.', 'talenttrack' ); ?></em></td></tr>
                    <?php else : foreach ( $rows as $r ) :
                        $is_current = ( (int) $r->is_current ) === 1;
                        ?>
                        <tr<?php echo $is_current ? ' style="background:#f0f7f6;"' : ''; ?>>
                            <td><strong><?php echo esc_html( (string) $r->name ); ?></strong></td>
                            <td><?php echo esc_html( (string) $r->start_date ); ?></td>
                            <td><?php echo esc_html( (string) $r->end_date ); ?></td>
                            <td><?php echo $is_current ? esc_html__( 'Yes', 'talenttrack' ) : '—'; ?></td>
                            <td>
                                <?php if ( ! $is_current ) : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'tt_set_current_season_' . (int) $r->id, 'tt_nonce' ); ?>
                                        <input type="hidden" name="action" value="tt_set_current_season" />
                                        <input type="hidden" name="id" value="<?php echo (int) $r->id; ?>" />
                                        <button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Make this the current season? Carryover will run for any open PDP file from the previous season.', 'talenttrack' ) ); ?>')"><?php esc_html_e( 'Set as current', 'talenttrack' ); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>

            <h2 style="margin-top:24px;"><?php esc_html_e( 'Add a season', 'talenttrack' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:600px;">
                <?php wp_nonce_field( 'tt_save_season', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_season" />
                <table class="form-table">
                    <tr>
                        <th><label for="tt-season-name"><?php esc_html_e( 'Name', 'talenttrack' ); ?> *</label></th>
                        <td><input type="text" id="tt-season-name" name="name" class="regular-text" required placeholder="<?php esc_attr_e( '2026/2027', 'talenttrack' ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="tt-season-start"><?php esc_html_e( 'Start date', 'talenttrack' ); ?> *</label></th>
                        <td><input type="date" id="tt-season-start" name="start_date" required /></td>
                    </tr>
                    <tr>
                        <th><label for="tt-season-end"><?php esc_html_e( 'End date', 'talenttrack' ); ?> *</label></th>
                        <td><input type="date" id="tt-season-end" name="end_date" required /></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add season', 'talenttrack' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'tt_edit_seasons' ) ) wp_die( esc_html__( 'Forbidden.', 'talenttrack' ) );
        check_admin_referer( 'tt_save_season', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'season.save' );

        $name  = sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) );
        $start = sanitize_text_field( wp_unslash( (string) ( $_POST['start_date'] ?? '' ) ) );
        $end   = sanitize_text_field( wp_unslash( (string) ( $_POST['end_date'] ?? '' ) ) );

        $back = admin_url( 'admin.php?page=tt-seasons' );
        if ( $name === '' || $start === '' || $end === '' || strtotime( $end ) <= strtotime( $start ) ) {
            wp_safe_redirect( add_query_arg( 'tt_err', urlencode( __( 'Invalid season data — name, start date, and end date are required, and end date must follow start date.', 'talenttrack' ) ), $back ) );
            exit;
        }

        $id = ( new SeasonsRepository() )->create( [
            'name'       => $name,
            'start_date' => $start,
            'end_date'   => $end,
        ] );
        $url = $id > 0 ? add_query_arg( 'tt_msg', '1', $back )
                       : add_query_arg( 'tt_err', urlencode( __( 'Database refused the save.', 'talenttrack' ) ), $back );
        wp_safe_redirect( $url );
        exit;
    }

    public static function handleSetCurrent(): void {
        if ( ! current_user_can( 'tt_edit_seasons' ) ) wp_die( esc_html__( 'Forbidden.', 'talenttrack' ) );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        check_admin_referer( 'tt_set_current_season_' . $id, 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'season.set_current' );

        $back = admin_url( 'admin.php?page=tt-seasons' );
        if ( $id <= 0 ) {
            wp_safe_redirect( add_query_arg( 'tt_err', urlencode( __( 'Invalid season id.', 'talenttrack' ) ), $back ) );
            exit;
        }

        $ok = ( new SeasonsRepository() )->setCurrent( $id );
        if ( $ok ) {
            // Trigger the carryover hook — Sprint 2's one-shot job.
            do_action( 'tt_pdp_season_set_current', $id );
        }
        $url = $ok ? add_query_arg( 'tt_msg', '1', $back )
                   : add_query_arg( 'tt_err', urlencode( __( 'Could not set current season.', 'talenttrack' ) ), $back );
        wp_safe_redirect( $url );
        exit;
    }
}
