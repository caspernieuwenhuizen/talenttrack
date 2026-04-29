<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\AuthorizationRepository;
use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FunctionalRolesPage — admin UI at TalentTrack → Functional Roles.
 *
 * Sprint 1G (v2.10.0). Displays the catalogue of jobs people can hold on
 * a team (head_coach, physio, manager, etc.) and lets admins configure
 * which authorization roles each functional role grants.
 *
 * Routes under admin.php?page=tt-functional-roles:
 *   - default              → list of functional roles
 *   - action=view&id=N     → detail with auth-role mapping editor
 *
 * Also handles:
 *   - tt_save_functional_role_mapping (POST, the mapping toggle form)
 *
 * Sprint 1G limitations (intentional):
 *   - Only system functional roles exist. Custom functional role creation
 *     is deferred; the 5 seeded roles cover the in-scope domain.
 *   - role_key / label / description of system functional roles are
 *     read-only. The mapping to auth roles IS editable.
 */
class FunctionalRolesPage {

    private const CAP = 'tt_view_settings';

    // Router

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

    // Views

    private static function renderList(): void {
        $repo  = new FunctionalRolesRepository();
        $roles = $repo->listRoles();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Functional Roles', 'talenttrack' ); ?></h1>

            <?php self::renderMessages(); ?>

            <p class="description">
                <?php esc_html_e( 'Per-team staff assignments. A user can hold many functional roles at once — head coach of one team, assistant of another. Each functional role is mapped to one or more authorization roles, which determine what that person is allowed to do on the team they\'re assigned to. Edit a functional role below to change its mapping.', 'talenttrack' ); ?>
            </p>
            <p class="description">
                <?php
                printf(
                    /* translators: %s: link to roles & rights page */
                    esc_html__( 'For academy-wide roles (Head of Development, Club Admin, Coach, Player, Parent, Scout, Read-only Observer), see %s.', 'talenttrack' ),
                    '<a href="' . esc_url( admin_url( 'admin.php?page=tt-roles' ) ) . '">' . esc_html__( 'Roles & rights', 'talenttrack' ) . '</a>'
                );
                ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Functional role', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Key', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Maps to authorization roles', 'talenttrack' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Assignments', 'talenttrack' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'System', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $roles ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No functional roles configured.', 'talenttrack' ); ?></td></tr>
                <?php else : foreach ( $roles as $r ) :
                    $detail_url = admin_url( 'admin.php?page=tt-functional-roles&action=view&id=' . (int) $r->id );
                    $mapped     = $repo->getAuthRolesForFunctionalRole( (int) $r->id );
                    ?>
                    <tr>
                        <td><strong><a href="<?php echo esc_url( $detail_url ); ?>"><?php echo esc_html( self::roleLabel( (string) $r->role_key ) ); ?></a></strong></td>
                        <td><code><?php echo esc_html( (string) $r->role_key ); ?></code></td>
                        <td><?php echo esc_html( self::roleDescription( (string) $r->role_key ) ); ?></td>
                        <td>
                            <?php if ( empty( $mapped ) ) : ?>
                                <em style="color:#b32d2e;"><?php esc_html_e( '(none — grants no permissions)', 'talenttrack' ); ?></em>
                            <?php else :
                                $labels = [];
                                foreach ( $mapped as $auth_role ) {
                                    $labels[] = RolesPage::roleLabel( (string) $auth_role->role_key );
                                }
                                echo esc_html( implode( ', ', $labels ) );
                            endif; ?>
                        </td>
                        <td><?php echo (int) $r->assignment_count; ?></td>
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

    private static function renderDetail( int $functional_role_id ): void {
        $repo = new FunctionalRolesRepository();
        $role = $repo->findRole( $functional_role_id );
        if ( ! $role ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Functional role not found.', 'talenttrack' ) . '</p></div>';
            return;
        }

        $auth_repo  = new AuthorizationRepository();
        $all_roles  = $auth_repo->listRoles();
        $mapped_ids = $repo->getAuthRoleIdsForFunctionalRole( $functional_role_id );

        // Filter to roles that make sense to map to a team-scoped functional
        // role. Global-only roles (club_admin, head_of_development) and the
        // derived `player` role aren't sensible targets for a team job.
        $grantable = [];
        foreach ( $all_roles as $ar ) {
            $allowed = RolesPage::allowedScopesForRole( (string) $ar->role_key );
            if ( in_array( 'team', $allowed, true ) ) {
                $grantable[] = $ar;
            }
        }
        ?>
        <div class="wrap">
            <h1>
                <?php echo esc_html( self::roleLabel( (string) $role->role_key ) ); ?>
                <code style="font-size:14px;color:#666;font-weight:normal;"><?php echo esc_html( (string) $role->role_key ); ?></code>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-functional-roles' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( '← Back to all functional roles', 'talenttrack' ); ?>
                </a>
            </h1>

            <?php self::renderMessages(); ?>

            <p class="description"><?php echo esc_html( self::roleDescription( (string) $role->role_key ) ); ?></p>

            <h2><?php esc_html_e( 'Authorization roles this functional role grants', 'talenttrack' ); ?></h2>

            <p class="description">
                <?php esc_html_e( 'When someone is assigned to a team with this functional role, they receive every authorization role ticked below, scoped to that team. Tick multiple to combine permission sets — for example, a head coach who should also see physio-level information.', 'talenttrack' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:900px;">
                <?php wp_nonce_field( 'tt_save_functional_role_mapping_' . $functional_role_id, 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_functional_role_mapping" />
                <input type="hidden" name="functional_role_id" value="<?php echo (int) $functional_role_id; ?>" />

                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th style="width:40px;"></th>
                            <th><?php esc_html_e( 'Authorization role', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                            <th style="width:100px;"><?php esc_html_e( 'Permissions', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty( $grantable ) ) : ?>
                        <tr><td colspan="4"><?php esc_html_e( 'No team-scoped authorization roles available.', 'talenttrack' ); ?></td></tr>
                    <?php else : foreach ( $grantable as $ar ) :
                        $is_mapped = in_array( (int) $ar->id, $mapped_ids, true );
                        $input_id  = 'tt_fr_map_' . (int) $ar->id;
                        ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="auth_role_ids[]"
                                       id="<?php echo esc_attr( $input_id ); ?>"
                                       value="<?php echo (int) $ar->id; ?>"
                                       <?php checked( $is_mapped ); ?> />
                            </td>
                            <td>
                                <label for="<?php echo esc_attr( $input_id ); ?>">
                                    <strong><?php echo esc_html( RolesPage::roleLabel( (string) $ar->role_key ) ); ?></strong>
                                </label>
                                <br>
                                <code style="font-size:11px;color:#666;"><?php echo esc_html( (string) $ar->role_key ); ?></code>
                            </td>
                            <td><?php echo esc_html( RolesPage::roleDescription( (string) $ar->role_key ) ); ?></td>
                            <td><?php echo (int) $ar->permission_count; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <p style="margin-top:16px;">
                    <?php submit_button( __( 'Save mapping', 'talenttrack' ), 'primary', 'submit', false ); ?>
                </p>
            </form>

            <h2 style="margin-top:36px;"><?php esc_html_e( 'Current assignments using this functional role', 'talenttrack' ); ?></h2>
            <?php self::renderAssignmentsTable( $functional_role_id ); ?>
        </div>
        <?php
    }

    private static function renderAssignmentsTable( int $functional_role_id ): void {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.id, tp.start_date, tp.end_date,
                    p.id AS person_id, p.first_name, p.last_name, p.email,
                    t.id AS team_id, t.name AS team_name
             FROM {$wpdb->prefix}tt_team_people tp
             INNER JOIN {$wpdb->prefix}tt_people p ON p.id = tp.person_id AND p.club_id = tp.club_id
             INNER JOIN {$wpdb->prefix}tt_teams  t ON t.id = tp.team_id   AND t.club_id = tp.club_id
             WHERE tp.functional_role_id = %d AND tp.club_id = %d
             ORDER BY p.last_name ASC, p.first_name ASC, t.name ASC",
            $functional_role_id, CurrentClub::id()
        ) );

        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No one is currently assigned to a team with this functional role.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Person', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) :
                    $person_url = admin_url( 'admin.php?page=tt-people&action=edit&id=' . (int) $r->person_id );
                    $team_url   = admin_url( 'admin.php?page=tt-teams&action=edit&id=' . (int) $r->team_id );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( $person_url ); ?>">
                                <?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ); ?>
                            </a>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $team_url ); ?>"><?php echo esc_html( (string) $r->team_name ); ?></a>
                        </td>
                        <td><?php echo esc_html( $r->start_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $r->end_date ?: '—' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // Handlers

    public static function handleSaveMapping(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );

        $functional_role_id = isset( $_POST['functional_role_id'] ) ? absint( wp_unslash( (string) $_POST['functional_role_id'] ) ) : 0;
        check_admin_referer( 'tt_save_functional_role_mapping_' . $functional_role_id, 'tt_nonce' );

        $raw_ids = isset( $_POST['auth_role_ids'] ) && is_array( $_POST['auth_role_ids'] )
            ? $_POST['auth_role_ids']
            : [];
        $auth_role_ids = array_map( 'absint', array_map( 'wp_unslash', $raw_ids ) );

        $repo = new FunctionalRolesRepository();
        $repo->setAuthRoleMapping( $functional_role_id, $auth_role_ids );

        $redirect = admin_url( 'admin.php?page=tt-functional-roles&action=view&id=' . $functional_role_id . '&tt_msg=saved' );
        wp_safe_redirect( $redirect );
        exit;
    }

    // Localization helpers

    /**
     * Translatable label for a functional role key. tt_functional_roles.label
     * is seeded in English as a stable identifier; UI uses this method for
     * localized display. Matches the pattern from RolesPage::roleLabel().
     */
    public static function roleLabel( string $role_key ): string {
        switch ( $role_key ) {
            case 'head_coach':      return __( 'Head Coach', 'talenttrack' );
            case 'assistant_coach': return __( 'Assistant Coach', 'talenttrack' );
            case 'manager':         return __( 'Manager', 'talenttrack' );
            case 'physio':          return __( 'Physio', 'talenttrack' );
            case 'other':           return __( 'Other', 'talenttrack' );
        }
        // Fallback for any custom functional roles added via future UI.
        return ucwords( str_replace( '_', ' ', $role_key ) );
    }

    public static function roleDescription( string $role_key ): string {
        switch ( $role_key ) {
            case 'head_coach':
                return __( 'Lead coach for a team. Owns methodology, selection, and session planning.', 'talenttrack' );
            case 'assistant_coach':
                return __( 'Supports the head coach with training and evaluations within a team.', 'talenttrack' );
            case 'manager':
                return __( 'Handles logistics, roster, sessions, and team settings. Not an evaluator.', 'talenttrack' );
            case 'physio':
                return __( 'Medical / physical support staff attached to a team.', 'talenttrack' );
            case 'other':
                return __( 'Anything that does not fit the other categories. Minimal read-only access by default.', 'talenttrack' );
        }
        return '';
    }

    private static function renderMessages(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) ) : '';
        if ( $msg === '' ) return;

        if ( $msg === 'saved' ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'talenttrack' ) . '</p></div>';
        } elseif ( $msg === 'error' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Something went wrong.', 'talenttrack' ) . '</p></div>';
        }
    }
}
