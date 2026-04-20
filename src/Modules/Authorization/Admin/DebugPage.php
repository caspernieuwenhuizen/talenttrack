<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;

/**
 * DebugPage — TalentTrack → Permission Debug.
 *
 * Pick any WP user and see every scope they resolve to, plus the concrete
 * permission set. Each scope shows its source (role_scope, legacy bridge,
 * etc.) so "why can User X do Y?" is always answerable.
 *
 * This is a read-only diagnostic. No state-changing actions.
 */
class DebugPage {

    private const CAP = 'tt_manage_settings';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $selected_user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( (string) $_GET['user_id'] ) ) : 0;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Permission Debug', 'talenttrack' ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Choose a WordPress user to see every role-scope they resolve to, the source of each scope (data-driven role assignment, legacy bridge, or derived), and the concrete permissions each scope grants.', 'talenttrack' ); ?>
            </p>

            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin:16px 0;">
                <input type="hidden" name="page" value="tt-roles-debug" />
                <label for="tt_debug_user">
                    <strong><?php esc_html_e( 'WordPress user:', 'talenttrack' ); ?></strong>
                </label>
                <?php wp_dropdown_users( [
                    'name'              => 'user_id',
                    'id'                => 'tt_debug_user',
                    'selected'          => $selected_user_id,
                    'show_option_none'  => __( '— Select —', 'talenttrack' ),
                    'option_none_value' => 0,
                ] ); ?>
                <?php submit_button( __( 'Check', 'talenttrack' ), 'secondary', 'submit', false ); ?>
            </form>

            <?php if ( $selected_user_id > 0 ) : ?>
                <?php self::renderTrace( $selected_user_id ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderTrace( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            echo '<p>' . esc_html__( 'User not found.', 'talenttrack' ) . '</p>';
            return;
        }

        $person_id = AuthorizationService::getPersonIdByUserId( $user_id );
        $scopes    = AuthorizationService::getResolvedScopesForUser( $user_id );
        $is_wp_admin = user_can( $user_id, 'administrator' );

        ?>
        <h2 style="margin-top:24px;">
            <?php echo esc_html( $user->display_name ); ?>
            <code style="font-size:13px;font-weight:normal;color:#666;"><?php echo esc_html( $user->user_email ); ?></code>
        </h2>

        <table class="form-table" style="max-width:700px;">
            <tr>
                <th><?php esc_html_e( 'WordPress user ID', 'talenttrack' ); ?></th>
                <td><code><?php echo (int) $user_id; ?></code></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'WordPress roles', 'talenttrack' ); ?></th>
                <td>
                    <?php if ( ! empty( $user->roles ) ) :
                        foreach ( $user->roles as $r ) : ?>
                            <code style="margin-right:8px;"><?php echo esc_html( $r ); ?></code>
                        <?php endforeach;
                    else : ?>
                        <em><?php esc_html_e( '(none)', 'talenttrack' ); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Linked tt_people record', 'talenttrack' ); ?></th>
                <td>
                    <?php if ( $person_id ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-people&action=edit&id=' . $person_id ) ); ?>">
                            <?php esc_html_e( 'Person', 'talenttrack' ); ?> #<?php echo (int) $person_id; ?>
                        </a>
                    <?php else : ?>
                        <em><?php esc_html_e( 'Not linked — this user has no tt_people record.', 'talenttrack' ); ?></em>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ( $is_wp_admin ) : ?>
                <tr>
                    <th><?php esc_html_e( 'Administrator override', 'talenttrack' ); ?></th>
                    <td>
                        <span style="color:#c9a227;">⚠</span>
                        <?php esc_html_e( 'This user has the WordPress administrator role. AuthorizationService grants all permissions unconditionally, bypassing the role-scope lookup.', 'talenttrack' ); ?>
                    </td>
                </tr>
            <?php endif; ?>
        </table>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Resolved scopes', 'talenttrack' ); ?></h3>

        <?php if ( empty( $scopes ) ) : ?>
            <p>
                <?php if ( $is_wp_admin ) : ?>
                    <?php esc_html_e( 'No explicit role-scopes. (Administrator override grants everything anyway.)', 'talenttrack' ); ?>
                <?php else : ?>
                    <?php esc_html_e( 'This user has no active role-scopes. They have no TalentTrack permissions beyond what WordPress capabilities they directly hold.', 'talenttrack' ); ?>
                <?php endif; ?>
            </p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:1100px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Permissions granted', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $scopes as $scope ) :
                        $scope_label = RolesPage::formatScope(
                            (string) $scope['scope_type'],
                            $scope['scope_id'] !== null ? (int) $scope['scope_id'] : null
                        );
                        $source_label = self::sourceLabel( (string) $scope['source'] );
                        ?>
                        <tr>
                            <td><code><?php echo esc_html( (string) $scope['role_key'] ); ?></code></td>
                            <td><?php echo esc_html( $scope_label ); ?></td>
                            <td><?php echo esc_html( $source_label ); ?></td>
                            <td>
                                <?php if ( empty( $scope['permissions'] ) ) : ?>
                                    <em><?php esc_html_e( '(none)', 'talenttrack' ); ?></em>
                                <?php else :
                                    foreach ( $scope['permissions'] as $perm ) : ?>
                                        <code style="margin-right:6px;display:inline-block;margin-bottom:2px;"><?php echo esc_html( $perm ); ?></code>
                                    <?php endforeach;
                                endif; ?>
                            </td>
                            <td><?php echo esc_html( $scope['start_date'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $scope['end_date'] ?: '—' ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3 style="margin-top:24px;"><?php esc_html_e( 'Flattened permissions', 'talenttrack' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'The union of all permissions above, grouped by domain. This is what AuthorizationService::userHasPermission() checks against when deciding access.', 'talenttrack' ); ?>
        </p>
        <?php self::renderFlatPermissions( $scopes ); ?>
        <?php
    }

    private static function renderFlatPermissions( array $scopes ): void {
        $global = [];
        $scoped = []; // [ "scope_label" => [perms] ]

        foreach ( $scopes as $s ) {
            if ( $s['scope_type'] === 'global' ) {
                foreach ( $s['permissions'] as $p ) $global[ $p ] = true;
            } else {
                $label = RolesPage::formatScope( (string) $s['scope_type'], $s['scope_id'] !== null ? (int) $s['scope_id'] : null );
                foreach ( $s['permissions'] as $p ) $scoped[ $label ][ $p ] = true;
            }
        }

        if ( empty( $global ) && empty( $scoped ) ) {
            echo '<p><em>' . esc_html__( '(none)', 'talenttrack' ) . '</em></p>';
            return;
        }

        if ( ! empty( $global ) ) {
            echo '<h4>' . esc_html__( 'Global', 'talenttrack' ) . '</h4>';
            $perms = array_keys( $global );
            sort( $perms );
            echo '<p>';
            foreach ( $perms as $p ) {
                echo '<code style="margin-right:6px;">' . esc_html( $p ) . '</code>';
            }
            echo '</p>';
        }

        foreach ( $scoped as $label => $perms_map ) {
            echo '<h4>' . esc_html( $label ) . '</h4>';
            $perms = array_keys( $perms_map );
            sort( $perms );
            echo '<p>';
            foreach ( $perms as $p ) {
                echo '<code style="margin-right:6px;">' . esc_html( $p ) . '</code>';
            }
            echo '</p>';
        }
    }

    private static function sourceLabel( string $source ): string {
        switch ( $source ) {
            case 'role_scope':           return __( 'Role assignment (data-driven)', 'talenttrack' );
            case 'legacy_team_people':   return __( 'Legacy bridge: tt_team_people', 'talenttrack' );
            case 'legacy_head_coach_id': return __( 'Legacy bridge: head_coach_id', 'talenttrack' );
            case 'derived_player_link':  return __( 'Derived: linked player record', 'talenttrack' );
            default: return $source;
        }
    }
}
