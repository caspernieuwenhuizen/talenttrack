<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\AuthorizationRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Security\AuthorizationService;

/**
 * RolesPage — admin UI at TalentTrack → Roles & Permissions.
 *
 * Routes under admin.php?page=tt-roles:
 *   - default       → roles list
 *   - action=view&id=N  → role detail (permissions + assignments)
 *
 * Also provides static handlers for:
 *   - tt_grant_role   (POST from Person edit page or role detail page)
 *   - tt_revoke_role  (POST from role detail page or Person edit page)
 *
 * Sprint 1F limitations (intentional):
 *   - Permissions per role are display-only. Editing the matrix lands in
 *     Sprint 1G.
 *   - Role creation/deletion is not exposed. All 9 seeded roles are
 *     is_system=1 and cannot be modified.
 */
class RolesPage {

    private const CAP = 'tt_manage_settings';

    /* ═══════════════ Router ═══════════════ */

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['action'] ) ) : 'list';
        $id     = isset( $_GET['id'] ) ? absint( wp_unslash( (string) $_GET['id'] ) ) : 0;

        if ( $action === 'view' && $id > 0 ) {
            self::renderDetail( $id );
            return;
        }

        self::renderList();
    }

    /* ═══════════════ Views ═══════════════ */

    private static function renderList(): void {
        $repo  = new AuthorizationRepository();
        $roles = $repo->listRoles();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Roles & Permissions', 'talenttrack' ); ?></h1>

            <?php self::renderMessages(); ?>

            <p class="description">
                <?php esc_html_e( 'TalentTrack uses a role-based access system. Each role grants a set of permissions that can be scoped globally, to a team, or to a specific player. System roles are predefined and their permission sets are read-only.', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-roles-debug' ) ); ?>">
                    <?php esc_html_e( 'Open permission debugger', 'talenttrack' ); ?>
                </a>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Permissions', 'talenttrack' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'System', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $roles ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No roles configured.', 'talenttrack' ); ?></td></tr>
                <?php else : foreach ( $roles as $r ) :
                    $detail_url = admin_url( 'admin.php?page=tt-roles&action=view&id=' . (int) $r->id );
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( self::roleLabel( (string) $r->role_key ) ); ?></a></strong></td>
                        <td><code><?php echo esc_html( (string) $r->role_key ); ?></code></td>
                        <td><?php echo esc_html( self::roleDescription( (string) $r->role_key ) ); ?></td>
                        <td><?php echo (int) $r->permission_count; ?></td>
                        <td>
                            <?php if ( (int) $r->is_system === 1 ) : ?>
                                <span style="color:#00a32a;">✓</span>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderDetail( int $role_id ): void {
        $repo = new AuthorizationRepository();
        $role = $repo->findRole( $role_id );
        if ( ! $role ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Role not found.', 'talenttrack' ) . '</p></div>';
            return;
        }

        $permissions = $repo->getPermissionsForRole( $role_id );
        $assignments = $repo->getAssignmentsForRole( $role_id );
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( self::roleLabel( (string) $role->role_key ) ); ?>
                <code style="font-size:14px;color:#666;font-weight:normal;"><?php echo esc_html( (string) $role->role_key ); ?></code>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-roles' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( '← Back to all roles', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <p class="description"><?php echo esc_html( self::roleDescription( (string) $role->role_key ) ); ?></p>

            <h2><?php esc_html_e( 'Permissions granted by this role', 'talenttrack' ); ?></h2>

            <?php if ( (int) $role->is_system === 1 ) : ?>
                <p class="description">
                    <em><?php esc_html_e( 'This is a system role. Its permission set is read-only. Custom role editing will be available in a future release.', 'talenttrack' ); ?></em>
                </p>
            <?php endif; ?>

            <?php if ( empty( $permissions ) ) : ?>
                <p><?php esc_html_e( 'No permissions configured for this role.', 'talenttrack' ); ?></p>
            <?php else : ?>
                <?php $grouped = self::groupPermissionsByDomain( $permissions ); ?>
                <table class="widefat striped" style="max-width:600px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Domain', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Actions granted', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $grouped as $domain => $actions ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( self::domainLabel( (string) $domain ) ); ?></strong></td>
                                <td>
                                    <?php foreach ( $actions as $act ) : ?>
                                        <code style="margin-right:8px;"><?php echo esc_html( $act ); ?></code>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2 style="margin-top:30px;"><?php esc_html_e( 'Current assignments', 'talenttrack' ); ?></h2>
            <?php self::renderAssignmentsTable( $assignments, $role_id ); ?>
        </div>
        <?php
    }

    private static function renderAssignmentsTable( array $assignments, int $role_id = 0 ): void {
        $fn_repo = new \TT\Infrastructure\Authorization\FunctionalRolesRepository();
        $indirect = $role_id > 0 ? $fn_repo->getIndirectAssignmentsForAuthRole( $role_id ) : [];

        if ( empty( $assignments ) && empty( $indirect ) ) {
            echo '<p>' . esc_html__( 'This role is not currently assigned to anyone.', 'talenttrack' ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1000px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Person', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $a ) :
                    $scope_label = self::formatScope(
                        (string) $a->scope_type,
                        $a->scope_id !== null ? (int) $a->scope_id : null
                    );
                    ?>
                    <tr>
                        <td><?php echo esc_html( trim( $a->first_name . ' ' . $a->last_name ) ); ?></td>
                        <td><?php echo esc_html( $scope_label ); ?></td>
                        <td><?php esc_html_e( 'Direct', 'talenttrack' ); ?></td>
                        <td><?php echo esc_html( $a->start_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $a->end_date ?: '—' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'tt_revoke_role_' . (int) $a->id, 'tt_nonce' ); ?>
                                <input type="hidden" name="action" value="tt_revoke_role" />
                                <input type="hidden" name="scope_id_pk" value="<?php echo (int) $a->id; ?>" />
                                <input type="hidden" name="redirect_to" value="role_detail" />
                                <input type="hidden" name="role_id" value="<?php echo (int) $a->role_id; ?>" />
                                <button type="submit" class="button-link" style="color:#b32d2e;"
                                    onclick="return confirm('<?php echo esc_js( __( 'Revoke this role assignment?', 'talenttrack' ) ); ?>');">
                                    <?php esc_html_e( 'Revoke', 'talenttrack' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php foreach ( $indirect as $ia ) :
                    $team_scope_label = sprintf(
                        '%s: %s',
                        __( 'Team', 'talenttrack' ),
                        (string) $ia->team_name
                    );
                    $fn_role_label = \TT\Modules\Authorization\Admin\FunctionalRolesPage::roleLabel( (string) $ia->functional_role_key );
                    $fn_role_url   = admin_url( 'admin.php?page=tt-functional-roles&action=view&id=' . (int) $ia->functional_role_id );
                    ?>
                    <tr>
                        <td><?php echo esc_html( trim( $ia->first_name . ' ' . $ia->last_name ) ); ?></td>
                        <td><?php echo esc_html( $team_scope_label ); ?></td>
                        <td>
                            <?php
                            /* translators: %s is a functional role label, e.g. "Head Coach". */
                            printf(
                                esc_html__( 'via %s', 'talenttrack' ),
                                '<a href="' . esc_url( $fn_role_url ) . '">' . esc_html( $fn_role_label ) . '</a>'
                            );
                            ?>
                        </td>
                        <td><?php echo esc_html( $ia->start_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $ia->end_date ?: '—' ); ?></td>
                        <td>
                            <span class="description" style="color:#999;">
                                <?php esc_html_e( '—', 'talenttrack' ); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( ! empty( $indirect ) ) : ?>
            <p class="description" style="margin-top:8px;">
                <?php esc_html_e( 'Indirect grants come from a functional role mapping. To remove one, either unassign the person from the team or change the functional role\'s mapping on the Functional Roles admin page.', 'talenttrack' ); ?>
            </p>
        <?php endif; ?>
        <?php
    }

    /* ═══════════════ Handlers ═══════════════ */

    public static function handleGrant(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $person_id  = isset( $_POST['person_id'] ) ? absint( wp_unslash( (string) $_POST['person_id'] ) ) : 0;
        $role_id    = isset( $_POST['role_id'] ) ? absint( wp_unslash( (string) $_POST['role_id'] ) ) : 0;
        $scope_type = isset( $_POST['scope_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['scope_type'] ) ) : 'global';
        $scope_id   = isset( $_POST['scope_id'] ) ? absint( wp_unslash( (string) $_POST['scope_id'] ) ) : 0;
        $start      = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_date'] ) ) : '';
        $end        = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['end_date'] ) ) : '';

        check_admin_referer( 'tt_grant_role_' . $person_id, 'tt_nonce' );

        $granted_by = AuthorizationService::getCurrentPersonId();

        $repo = new AuthorizationRepository();
        $new_id = $repo->grant(
            $person_id,
            $role_id,
            $scope_type,
            $scope_type === 'global' ? null : ( $scope_id > 0 ? $scope_id : null ),
            $start ?: null,
            $end ?: null,
            $granted_by
        );

        $ok = $new_id > 0;

        // Redirect back to the person edit page where the grant form lives.
        $redirect = admin_url( 'admin.php?page=tt-people&action=edit&id=' . $person_id . '&tt_msg=' . ( $ok ? 'saved' : 'error' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    public static function handleRevoke(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $scope_id_pk = isset( $_POST['scope_id_pk'] ) ? absint( wp_unslash( (string) $_POST['scope_id_pk'] ) ) : 0;
        check_admin_referer( 'tt_revoke_role_' . $scope_id_pk, 'tt_nonce' );

        $person_id = isset( $_POST['person_id'] ) ? absint( wp_unslash( (string) $_POST['person_id'] ) ) : 0;
        $role_id   = isset( $_POST['role_id'] ) ? absint( wp_unslash( (string) $_POST['role_id'] ) ) : 0;
        $redirect_to = isset( $_POST['redirect_to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['redirect_to'] ) ) : 'person_edit';

        $repo = new AuthorizationRepository();
        $ok = $repo->revoke( $scope_id_pk );

        if ( $redirect_to === 'role_detail' && $role_id > 0 ) {
            $redirect = admin_url( 'admin.php?page=tt-roles&action=view&id=' . $role_id . '&tt_msg=' . ( $ok ? 'deleted' : 'error' ) );
        } elseif ( $person_id > 0 ) {
            $redirect = admin_url( 'admin.php?page=tt-people&action=edit&id=' . $person_id . '&tt_msg=' . ( $ok ? 'saved' : 'error' ) );
        } else {
            $redirect = admin_url( 'admin.php?page=tt-roles&tt_msg=' . ( $ok ? 'deleted' : 'error' ) );
        }

        wp_safe_redirect( $redirect );
        exit;
    }

    /* ═══════════════ Helpers ═══════════════ */

    /**
     * Produce a readable scope label, e.g. "Team: U14 A", "Player: Jan Jansen",
     * "Global".
     */
    public static function formatScope( string $scope_type, ?int $scope_id ): string {
        if ( $scope_type === 'global' || $scope_id === null ) {
            return __( 'Global', 'talenttrack' );
        }

        global $wpdb;
        switch ( $scope_type ) {
            case 'team':
                $name = $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d",
                    $scope_id
                ) );
                return sprintf( '%s: %s', __( 'Team', 'talenttrack' ), $name ?: '#' . $scope_id );
            case 'player':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d",
                    $scope_id
                ) );
                $name = $row ? trim( $row->first_name . ' ' . $row->last_name ) : '';
                return sprintf( '%s: %s', __( 'Player', 'talenttrack' ), $name ?: '#' . $scope_id );
            case 'person':
                $row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT first_name, last_name FROM {$wpdb->prefix}tt_people WHERE id = %d",
                    $scope_id
                ) );
                $name = $row ? trim( $row->first_name . ' ' . $row->last_name ) : '';
                return sprintf( '%s: %s', __( 'Person', 'talenttrack' ), $name ?: '#' . $scope_id );
        }
        return $scope_type . ' #' . $scope_id;
    }

    /**
     * Group a flat list of permission strings (e.g. "players.view") into a
     * map keyed by domain: [ 'players' => ['view', 'edit'], ... ]
     *
     * @param string[] $permissions
     * @return array<string, array<int, string>>
     */
    public static function groupPermissionsByDomain( array $permissions ): array {
        $grouped = [];
        foreach ( $permissions as $perm ) {
            $parts = explode( '.', $perm, 2 );
            $domain = $parts[0] ?? '(misc)';
            $action = $parts[1] ?? '(?)';
            $grouped[ $domain ][] = $action;
        }
        ksort( $grouped );
        foreach ( $grouped as &$acts ) sort( $acts );
        return $grouped;
    }

    /**
     * Allowed scope_types per system role. UI uses this to restrict the
     * scope picker. Unknown roles default to allowing all scope types.
     *
     * @return array<int, string>
     */
    public static function allowedScopesForRole( string $role_key ): array {
        $map = [
            'club_admin'          => [ 'global' ],
            'head_of_development' => [ 'global' ],
            'head_coach'          => [ 'team' ],
            'assistant_coach'     => [ 'team' ],
            'manager'             => [ 'team' ],
            'physio'              => [ 'team' ],
            'team_member'         => [ 'team' ],
            'scout'               => [ 'global', 'team' ],
            'parent'              => [ 'player' ],
            'player'              => [], // not manually grantable
        ];
        return $map[ $role_key ] ?? [ 'global', 'team', 'player', 'person' ];
    }

    /**
     * Translatable label for a role_key. Role labels seeded in tt_roles.label
     * are in English (for programmatic stability); this method returns the
     * localized display string.
     */
    public static function roleLabel( string $role_key ): string {
        switch ( $role_key ) {
            case 'club_admin':          return __( 'Club Admin', 'talenttrack' );
            case 'head_of_development': return __( 'Head of Development', 'talenttrack' );
            case 'head_coach':          return __( 'Head Coach', 'talenttrack' );
            case 'assistant_coach':     return __( 'Assistant Coach', 'talenttrack' );
            case 'manager':             return __( 'Manager', 'talenttrack' );
            case 'physio':              return __( 'Physio', 'talenttrack' );
            case 'team_member':         return __( 'Team Member', 'talenttrack' );
            case 'scout':               return __( 'Scout', 'talenttrack' );
            case 'parent':              return __( 'Parent', 'talenttrack' );
            case 'player':              return __( 'Player', 'talenttrack' );
        }
        // Fallback for any custom roles added via future UI.
        return ucwords( str_replace( '_', ' ', $role_key ) );
    }

    /**
     * Translatable description for a role_key. Same reason as roleLabel().
     */
    public static function roleDescription( string $role_key ): string {
        switch ( $role_key ) {
            case 'club_admin':
                return __( 'Full access across the academy. Can manage all entities, assign staff, and configure the system.', 'talenttrack' );
            case 'head_of_development':
                return __( 'Shapes methodology and reviews output. Read-all across the academy plus evaluations management. No player-data editing, no staff reassignment.', 'talenttrack' );
            case 'head_coach':
                return __( 'Full control within assigned teams — players, evaluations, sessions, goals, and team settings. Scoped to team.', 'talenttrack' );
            case 'assistant_coach':
                return __( "Evaluate and observe within assigned teams. Can create and edit own evaluations; cannot edit other coaches' evaluations. Scoped to team.", 'talenttrack' );
            case 'manager':
                return __( 'Runs logistics within assigned teams — roster, sessions, team settings. No evaluation permissions. Scoped to team.', 'talenttrack' );
            case 'physio':
                return __( 'Read-only access to players and sessions within assigned teams.', 'talenttrack' );
            case 'team_member':
                return __( 'Minimal read-only access within assigned teams. Default authorization for the "Other" functional role — see only players and sessions of the teams you are assigned to, nothing more.', 'talenttrack' );
            case 'scout':
                return __( 'View any player and create scouting evaluations. Can be assigned globally or to specific teams.', 'talenttrack' );
            case 'parent':
                return __( "Read-only access to linked children's records. Scoped to specific players.", 'talenttrack' );
            case 'player':
                return __( 'Read-only access to own profile. Auto-derived from tt_players.wp_user_id — not manually grantable.', 'talenttrack' );
        }
        return '';
    }

    /**
     * Translatable label for a permission domain (the left side of
     * `<domain>.<action>` permission strings). Used in the role detail
     * page to group permissions.
     */
    public static function domainLabel( string $domain_key ): string {
        switch ( $domain_key ) {
            case 'players':     return __( 'Players', 'talenttrack' );
            case 'teams':       return __( 'Teams', 'talenttrack' );
            case 'team':        return __( 'Team', 'talenttrack' );
            case 'evaluations': return __( 'Evaluations', 'talenttrack' );
            case 'sessions':    return __( 'Sessions', 'talenttrack' );
            case 'goals':       return __( 'Goals', 'talenttrack' );
            case 'reports':     return __( 'Reports', 'talenttrack' );
            case 'config':      return __( 'Configuration', 'talenttrack' );
            case 'people':      return __( 'People', 'talenttrack' );
            case 'audit':       return __( 'Audit', 'talenttrack' );
            case '*':           return __( 'All domains', 'talenttrack' );
        }
        return ucfirst( $domain_key );
    }

    private static function renderMessages(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === '' ) return;

        if ( $msg === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'deleted' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Something went wrong.', 'talenttrack' ) . '</p></div>';
        }
    }
}
