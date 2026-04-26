<?php
namespace TT\Modules\Methodology\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\Repositories\FootballActionsRepository;

/**
 * FootballActionsPage — admin browser for the football actions
 * (voetbalhandelingen) catalogue. Lists all actions grouped by
 * category. Edit happens via the dedicated FootballActionEditPage.
 */
final class FootballActionsPage {

    public const SLUG     = 'tt-football-actions';
    public const CAP_VIEW = 'tt_view_methodology';
    public const CAP_EDIT = 'tt_edit_methodology';

    public static function init(): void {
        add_action( 'admin_post_tt_football_action_archive', [ self::class, 'handleArchive' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP_VIEW ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $repo = new FootballActionsRepository();
        $rows = $repo->listAll();
        $categories = FootballActionsRepository::categories();

        $by_cat = [];
        foreach ( $rows as $row ) $by_cat[ (string) $row->category_key ][] = $row;

        ?>
        <div class="wrap">
            <h1>
                <?php esc_html_e( 'Voetbalhandelingen', 'talenttrack' ); ?>
                <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . FootballActionEditPage::SLUG . '&action=new' ) ); ?>" class="page-title-action">
                        <?php esc_html_e( 'Add action', 'talenttrack' ); ?>
                    </a>
                <?php endif; ?>
            </h1>
            <?php self::renderNotices(); ?>

            <p class="description" style="max-width:740px;">
                <?php esc_html_e( 'De voetbalhandelingen vormen de harde kern van het voetbalmodel: concrete handelingen die spelers uitvoeren binnen elke teamtaak. Doelen kunnen worden gekoppeld aan één voetbalhandeling om verbeterde uitvoering meetbaar te maken.', 'talenttrack' ); ?>
            </p>

            <?php if ( empty( $rows ) ) : ?>
                <p><em><?php esc_html_e( 'No football actions yet.', 'talenttrack' ); ?></em></p>
            <?php else : foreach ( $categories as $cat_key => $cat_label ) :
                if ( empty( $by_cat[ $cat_key ] ) ) continue; ?>
                <h2 style="margin-top:24px;"><?php echo esc_html( $cat_label ); ?></h2>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php esc_html_e( 'Slug', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $by_cat[ $cat_key ] as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $row->slug ); ?></code></td>
                            <td><strong><?php echo esc_html( MultilingualField::string( $row->name_json ) ?: $row->slug ); ?></strong></td>
                            <td><?php echo esc_html( MultilingualField::string( $row->description_json ) ?: '—' ); ?></td>
                            <td><?php echo $row->is_shipped ? esc_html__( 'Shipped', 'talenttrack' ) : esc_html__( 'Club', 'talenttrack' ); ?></td>
                            <td>
                                <?php if ( current_user_can( self::CAP_EDIT ) ) : ?>
                                    <?php if ( $row->is_shipped ) : ?>
                                        <span style="color:#5b6470; font-size:12px;"><?php esc_html_e( 'Read-only', 'talenttrack' ); ?></span>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . FootballActionEditPage::SLUG . '&action=edit&id=' . (int) $row->id ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a>
                                        | <a href="<?php echo esc_url( self::archiveActionUrl( (int) $row->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Archive this action?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Archive', 'talenttrack' ); ?></a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; endif; ?>
        </div>
        <?php
    }

    public static function handleArchive(): void {
        if ( ! current_user_can( self::CAP_EDIT ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_football_action_archive', 'tt_football_action_nonce' );
        $id = absint( $_GET['id'] ?? 0 );
        if ( $id > 0 ) ( new FootballActionsRepository() )->archive( $id );
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'tt_msg' => 'archived' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public static function archiveActionUrl( int $id ): string {
        return wp_nonce_url(
            add_query_arg(
                [ 'action' => 'tt_football_action_archive', 'id' => $id ],
                admin_url( 'admin-post.php' )
            ),
            'tt_football_action_archive',
            'tt_football_action_nonce'
        );
    }

    private static function renderNotices(): void {
        if ( ! isset( $_GET['tt_msg'] ) ) return;
        $msg = sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) );
        $map = [
            'saved'    => __( 'Saved.',    'talenttrack' ),
            'archived' => __( 'Archived.', 'talenttrack' ),
        ];
        if ( ! isset( $map[ $msg ] ) ) return;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $map[ $msg ] ) . '</p></div>';
    }
}
