<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;

/**
 * FrontendMigrationsView — read-only migration status.
 *
 * #0019 Sprint 5. Per Q6 in shaping: simple table view, no
 * `FrontendListTable` (finite migration count, no need for
 * filter/sort/paginate). Running migrations stays wp-admin-only —
 * forced friction on irreversible operations is the right design.
 *
 * Pending migrations surface a warning banner with a deep-link to
 * the wp-admin MigrationsPage where execution lives.
 */
class FrontendMigrationsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-admin-lists',
            TT_PLUGIN_URL . 'assets/css/frontend-admin-lists.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Migrations', 'talenttrack' ) );
        self::renderHeader( __( 'Migrations', 'talenttrack' ) );

        $info = ( new MigrationRunner() )->inspect();
        $applied = $info['applied'] ?? [];
        $pending = $info['pending'] ?? [];
        $missing = $info['missing_files'] ?? [];

        $admin_url = admin_url( 'admin.php?page=tt-migrations' );

        if ( $pending ) :
            ?>
            <div class="tt-flash tt-flash-warning tt-admin-flash">
                <span class="tt-admin-flash-text">
                    <strong><?php echo esc_html( sprintf( _n( '%d pending migration.', '%d pending migrations.', count( $pending ), 'talenttrack' ), count( $pending ) ) ); ?></strong>
                    <?php esc_html_e( 'Migrations are run from wp-admin to add a deliberate friction point on irreversible operations.', 'talenttrack' ); ?>
                </span>
                <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $admin_url ); ?>">
                    <?php esc_html_e( 'Open wp-admin to run them', 'talenttrack' ); ?>
                </a>
            </div>
            <?php
        endif;

        if ( $missing ) :
            ?>
            <div class="tt-flash tt-flash-error tt-admin-flash">
                <span class="tt-admin-flash-text">
                    <strong><?php esc_html_e( 'Missing migration files', 'talenttrack' ); ?>:</strong>
                    <?php echo esc_html( implode( ', ', $missing ) ); ?>.
                    <?php esc_html_e( 'These migrations were applied previously but their source files are no longer on disk.', 'talenttrack' ); ?>
                </span>
            </div>
            <?php
        endif;

        ?>
        <div class="tt-admin-card tt-admin-card-pad">
            <h3 class="tt-admin-section-title tt-admin-section-title--flush"><?php esc_html_e( 'Status', 'talenttrack' ); ?></h3>
            <p>
                <?php
                $applied_count = count( $applied );
                $pending_count = count( $pending );
                /* translators: 1: applied count, 2: pending count */
                echo esc_html( sprintf( __( '%1$d applied, %2$d pending.', 'talenttrack' ), $applied_count, $pending_count ) );
                ?>
            </p>
        </div>

        <h3 class="tt-admin-section-title"><?php esc_html_e( 'Applied migrations', 'talenttrack' ); ?></h3>
        <?php if ( ! $applied ) : ?>
            <p><em><?php esc_html_e( 'None applied yet.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <div class="tt-admin-card"><div class="tt-table-wrap">
            <table class="tt-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Applied at', 'talenttrack' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $applied as $row ) :
                    $name      = is_array( $row ) ? ( $row['name'] ?? '' ) : ( is_object( $row ) ? ( $row->name ?? '' ) : (string) $row );
                    $applied_at = is_array( $row ) ? ( $row['applied_at'] ?? '' ) : ( is_object( $row ) ? ( $row->applied_at ?? '' ) : '' );
                    ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $name ); ?></code></td>
                        <td><?php echo esc_html( (string) $applied_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div></div>
        <?php endif; ?>

        <h3 class="tt-admin-section-title"><?php esc_html_e( 'Pending migrations', 'talenttrack' ); ?></h3>
        <?php if ( ! $pending ) : ?>
            <p><em><?php esc_html_e( 'None pending.', 'talenttrack' ); ?></em></p>
        <?php else : ?>
            <div class="tt-admin-card"><div class="tt-table-wrap">
            <table class="tt-table">
                <thead><tr><th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $pending as $name ) : ?>
                    <tr><td><code><?php echo esc_html( (string) $name ); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div></div>
            <p class="tt-migrations-run">
                <a class="tt-btn tt-btn-primary" href="<?php echo esc_url( $admin_url ); ?>">
                    <?php esc_html_e( 'Open wp-admin to run pending migrations', 'talenttrack' ); ?>
                </a>
            </p>
        <?php endif; ?>
        <?php
    }
}
