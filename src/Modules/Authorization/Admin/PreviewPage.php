<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Config\ConfigService;
use TT\Modules\Authorization\LegacyCapMapper;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * PreviewPage — Authorization → Migration preview (#0033 Sprint 8).
 *
 * Compares each user's old (cap-based) and new (matrix-based) effective
 * permissions. Output: an in-page table + downloadable CSV. The "Apply"
 * button flips `tt_authorization_active = 1`; from then the user_has_cap
 * filter routes through MatrixGate. "Rollback" flips it back to 0.
 *
 * Cap gate: `administrator` (sharper than `tt_edit_settings`).
 *
 * The preview computes "Gained" (matrix grants something legacy didn't)
 * and "Revoked" (matrix denies something legacy granted) per user. The
 * "Revoked" column is the dangerous one — admins should review carefully
 * before clicking Apply.
 */
class PreviewPage {

    public static function init(): void {
        add_action( 'admin_post_tt_matrix_apply',    [ __CLASS__, 'handleApply' ] );
        add_action( 'admin_post_tt_matrix_rollback', [ __CLASS__, 'handleRollback' ] );
        add_action( 'admin_post_tt_matrix_csv',      [ __CLASS__, 'handleCsv' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $config = new ConfigService();
        $is_active = $config->getBool( 'tt_authorization_active', false );
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';

        $rows = self::computeDiff();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Migration preview — Authorization Matrix', 'talenttrack' ); ?></h1>
            <p style="color:#5b6e75; max-width:760px;">
                <?php esc_html_e( 'Compare each user\'s legacy (capability-based) and new (matrix-based) effective permissions. "Gained" means the matrix grants something the old caps didn\'t. "Revoked" means the opposite — review carefully before applying.', 'talenttrack' ); ?>
            </p>

            <?php if ( $msg === 'applied' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Authorization matrix is now active. The user_has_cap filter routes legacy capability checks through MatrixGate.', 'talenttrack' ); ?></p></div>
            <?php elseif ( $msg === 'rolled_back' ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Authorization matrix rollback complete. The user_has_cap filter is dormant; native WP capability checks decide again.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <div style="background:#fff; border:1px solid <?php echo $is_active ? '#196a32' : '#d0d2d6'; ?>; border-radius:6px; padding:14px; margin:14px 0;">
                <p style="margin:0;">
                    <strong><?php esc_html_e( 'Current state:', 'talenttrack' ); ?></strong>
                    <?php if ( $is_active ) : ?>
                        <span style="color:#196a32;">●</span>
                        <?php esc_html_e( 'Matrix-driven authorization is ACTIVE. Legacy cap checks route through MatrixGate.', 'talenttrack' ); ?>
                    <?php else : ?>
                        <span style="color:#888;">○</span>
                        <?php esc_html_e( 'Matrix is in shadow mode. Legacy cap checks decide; the matrix is data-only.', 'talenttrack' ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ( ! $is_active ) : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      style="display:inline-block;"
                      onsubmit="return confirm('<?php echo esc_js( __( 'Activate matrix-driven authorization? Every legacy tt_* capability check will route through MatrixGate. You can roll back at any time.', 'talenttrack' ) ); ?>');">
                    <?php wp_nonce_field( 'tt_matrix_apply', 'tt_nonce' ); ?>
                    <input type="hidden" name="action" value="tt_matrix_apply" />
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Apply matrix', 'talenttrack' ); ?></button>
                </form>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                      style="display:inline-block;"
                      onsubmit="return confirm('<?php echo esc_js( __( 'Roll back to legacy capability checks? Matrix data is preserved; only the routing flag flips.', 'talenttrack' ) ); ?>');">
                    <?php wp_nonce_field( 'tt_matrix_rollback', 'tt_nonce' ); ?>
                    <input type="hidden" name="action" value="tt_matrix_rollback" />
                    <button type="submit" class="button"><?php esc_html_e( 'Rollback to legacy', 'talenttrack' ); ?></button>
                </form>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left:8px;">
                <?php wp_nonce_field( 'tt_matrix_csv', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_csv" />
                <button type="submit" class="button"><?php esc_html_e( 'Download diff (CSV)', 'talenttrack' ); ?></button>
            </form>

            <h2 style="margin-top:24px;"><?php esc_html_e( 'Per-user diff', 'talenttrack' ); ?></h2>
            <?php if ( empty( $rows ) ) : ?>
                <p><em><?php esc_html_e( 'No TT-role users found.', 'talenttrack' ); ?></em></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Personas', 'talenttrack' ); ?></th>
                            <th style="color:#196a32;"><?php esc_html_e( 'Gained (matrix grants new)', 'talenttrack' ); ?></th>
                            <th style="color:#b32d2e;"><?php esc_html_e( 'Revoked (matrix denies)', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $r['user_login'] ); ?></strong>
                                <small style="color:#888;"><?php echo esc_html( $r['user_email'] ); ?></small></td>
                            <td><?php echo esc_html( implode( ', ', $r['personas'] ) ?: '—' ); ?></td>
                            <td style="font-family:monospace; font-size:11px; color:#196a32;">
                                <?php echo esc_html( implode( ', ', $r['gained'] ) ?: '—' ); ?>
                            </td>
                            <td style="font-family:monospace; font-size:11px; color:#b32d2e;">
                                <?php echo esc_html( implode( ', ', $r['revoked'] ) ?: '—' ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handleApply(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_apply', 'tt_nonce' );
        ( new ConfigService() )->set( 'tt_authorization_active', '1' );
        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix-preview', 'tt_msg' => 'applied' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleRollback(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_rollback', 'tt_nonce' );
        ( new ConfigService() )->set( 'tt_authorization_active', '0' );
        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix-preview', 'tt_msg' => 'rolled_back' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleCsv(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_csv', 'tt_nonce' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="authorization-matrix-diff-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'user_id', 'user_login', 'user_email', 'personas', 'gained', 'revoked' ] );
        foreach ( self::computeDiff() as $r ) {
            fputcsv( $out, [
                $r['user_id'],
                $r['user_login'],
                $r['user_email'],
                implode( '|', $r['personas'] ),
                implode( '|', $r['gained'] ),
                implode( '|', $r['revoked'] ),
            ] );
        }
        fclose( $out );
        exit;
    }

    /**
     * @return list<array{user_id:int, user_login:string, user_email:string,
     *                    personas:array<int,string>,
     *                    gained:array<int,string>, revoked:array<int,string>}>
     */
    private static function computeDiff(): array {
        $caps = LegacyCapMapper::knownCaps();
        $users = function_exists( 'get_users' )
            ? get_users( [
                'number'   => -1,
                'role__in' => [
                    'tt_player', 'tt_parent', 'tt_coach', 'tt_scout', 'tt_staff',
                    'tt_head_dev', 'tt_club_admin', 'tt_readonly_observer', 'tt_team_manager',
                    'administrator',
                ],
                'fields'   => [ 'ID', 'user_login', 'user_email' ],
            ] )
            : [];

        $rows = [];
        foreach ( (array) $users as $u ) {
            $user_id = (int) $u->ID;
            $personas = PersonaResolver::personasFor( $user_id );
            $wp_user  = get_user_by( 'id', $user_id );
            if ( ! $wp_user instanceof \WP_User ) continue;

            $gained = [];
            $revoked = [];
            foreach ( $caps as $cap ) {
                $tuple = LegacyCapMapper::tupleFor( $cap );
                if ( $tuple === null ) continue;
                [ $entity, $activity ] = $tuple;
                $legacy = isset( $wp_user->allcaps[ $cap ] ) && (bool) $wp_user->allcaps[ $cap ];
                $matrix = MatrixGate::can( $user_id, $entity, $activity, MatrixGate::SCOPE_GLOBAL );
                if ( $matrix && ! $legacy ) $gained[]  = $cap;
                if ( ! $matrix && $legacy ) $revoked[] = $cap;
            }
            $rows[] = [
                'user_id'    => $user_id,
                'user_login' => (string) ( $u->user_login ?? '' ),
                'user_email' => (string) ( $u->user_email ?? '' ),
                'personas'   => $personas,
                'gained'     => $gained,
                'revoked'    => $revoked,
            ];
        }
        return $rows;
    }
}
