<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\RolesService;

/**
 * FrontendRolesView — frontend admin-tier surface for the
 * TalentTrack role + capability reference.
 *
 * #0019 Sprint 5. Per the shaping decision (Q5): ship a read-only
 * reference panel as the primary surface — short description per
 * role, collapsible cap list, "users with this role" count linking
 * to the wp-admin Users list filtered by role, and "How to assign"
 * inline note. Cap-edit checkboxes live in wp-admin's `RolesPage`
 * where the existing grant/revoke UI works correctly; the deep-link
 * surfaces it.
 *
 * The Read-Only Observer role gets a prominent card per the spec —
 * it's the role most admins miss.
 */
class FrontendRolesView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Roles & capabilities', 'talenttrack' ) );

        $roles_admin_url = admin_url( 'admin.php?page=tt-roles' );

        ?>
        <p style="color:var(--tt-muted); max-width:760px; margin:0 0 var(--tt-sp-3);">
            <?php esc_html_e( 'Reference for the eight TalentTrack roles. Editing individual capabilities is done in wp-admin where the existing grant/revoke UI lives.', 'talenttrack' ); ?>
        </p>
        <p style="margin:0 0 var(--tt-sp-4);">
            <a class="tt-btn tt-btn-secondary" href="<?php echo esc_url( $roles_admin_url ); ?>">
                <?php esc_html_e( 'Edit capabilities in wp-admin', 'talenttrack' ); ?>
            </a>
        </p>

        <?php
        // The Read-Only Observer card first (most-missed role).
        self::renderRoleCard(
            'tt_readonly_observer',
            __( 'Read-Only Observer', 'talenttrack' ),
            __( 'Sees every team, player, evaluation, activity, goal, and report. Cannot edit anything. Use this for board members, parents who want a peek at progress, or external scouts. The role most admins forget exists.', 'talenttrack' ),
            true
        );

        $other = [
            'tt_head_dev' => [
                __( 'Head of Development', 'talenttrack' ),
                __( 'Oversees the whole academy. Can see and edit everything: teams, players, evaluations, activities, goals, settings. The default fallback for "this person needs to do everything."', 'talenttrack' ),
            ],
            'tt_club_admin' => [
                __( 'Club Admin', 'talenttrack' ),
                __( 'Manages teams, players, people, activities, goals, and settings. Cannot record evaluations themselves. Use for office-side admins who handle the roster + administration but don\'t coach.', 'talenttrack' ),
            ],
            'tt_coach' => [
                __( 'Coach', 'talenttrack' ),
                __( 'Records evaluations, activities, and goals for the teams they head-coach. Cannot edit the player roster, the teams structure, or settings. The daily-use role for trainers.', 'talenttrack' ),
            ],
            'tt_scout' => [
                __( 'Scout', 'talenttrack' ),
                __( 'Read-only across players + teams + reports. Used for external scouts evaluating prospects without write access to the academy\'s own data.', 'talenttrack' ),
            ],
            'tt_staff' => [
                __( 'Staff', 'talenttrack' ),
                __( 'Generic non-coach staff (physio, manager, kit). Read-only on most surfaces. Specific functional roles on specific teams are assigned via Functional Roles, separate from this WP role.', 'talenttrack' ),
            ],
            'tt_player' => [
                __( 'Player', 'talenttrack' ),
                __( 'A player\'s own login. Sees their own evaluations, activities, goals, and rate card. Cannot see other players. Linked to a `tt_players` row via the player\'s WP user id.', 'talenttrack' ),
            ],
            'tt_parent' => [
                __( 'Parent', 'talenttrack' ),
                __( 'A parent\'s login, linked to one or more children. Sees what those children see — evaluations, activities, goals — read-only.', 'talenttrack' ),
            ],
        ];

        foreach ( $other as $slug => [ $label, $desc ] ) {
            self::renderRoleCard( $slug, $label, $desc, false );
        }
        ?>
        <?php
    }

    private static function renderRoleCard( string $slug, string $label, string $description, bool $highlight ): void {
        $role = get_role( $slug );
        $count = self::countUsersWithRole( $slug );
        $caps = $role && is_array( $role->capabilities ?? null )
            ? array_keys( array_filter( $role->capabilities, function ( $v ) { return (bool) $v; } ) )
            : [];
        sort( $caps );
        $users_url = admin_url( 'users.php?role=' . rawurlencode( $slug ) );
        $border = $highlight ? '2px solid var(--tt-secondary)' : '1px solid var(--tt-line)';

        ?>
        <div class="tt-panel" style="border:<?php echo esc_attr( $border ); ?>;">
            <div style="display:flex; gap:var(--tt-sp-3); align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:220px;">
                    <h3 class="tt-panel-title" style="margin:0 0 var(--tt-sp-2);">
                        <?php echo esc_html( $label ); ?>
                        <code style="font-size:var(--tt-fs-xs); color:var(--tt-muted); margin-left:6px;"><?php echo esc_html( $slug ); ?></code>
                        <?php if ( $highlight ) : ?>
                            <span class="tt-badge" style="margin-left:6px; padding:2px 8px; background:var(--tt-warning-soft); border:1px solid var(--tt-warning); border-radius:999px; font-size:var(--tt-fs-xs); color:#7c5a00;"><?php esc_html_e( 'often-missed', 'talenttrack' ); ?></span>
                        <?php endif; ?>
                    </h3>
                    <p style="margin:0; color:var(--tt-ink);"><?php echo esc_html( $description ); ?></p>
                </div>
                <div style="text-align:right; min-width:160px;">
                    <a href="<?php echo esc_url( $users_url ); ?>" style="text-decoration:none;">
                        <div style="font-size:var(--tt-fs-xl); font-weight:700; color:var(--tt-primary);"><?php echo (int) $count; ?></div>
                        <div style="font-size:var(--tt-fs-xs); color:var(--tt-muted); text-transform:uppercase; letter-spacing:0.04em;"><?php esc_html_e( 'users', 'talenttrack' ); ?></div>
                    </a>
                </div>
            </div>

            <details style="margin-top:var(--tt-sp-3);">
                <summary style="cursor:pointer; color:var(--tt-muted); font-size:var(--tt-fs-sm);">
                    <?php
                    /* translators: %d: number of capabilities */
                    echo esc_html( sprintf( __( 'Capabilities (%d)', 'talenttrack' ), count( $caps ) ) );
                    ?>
                </summary>
                <?php if ( $caps ) : ?>
                    <div style="margin-top:var(--tt-sp-2); display:flex; gap:6px; flex-wrap:wrap;">
                        <?php foreach ( $caps as $cap ) : ?>
                            <code style="padding:2px 8px; background:var(--tt-bg-soft); border:1px solid var(--tt-line); border-radius:4px; font-size:var(--tt-fs-xs);"><?php echo esc_html( $cap ); ?></code>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p style="margin-top:var(--tt-sp-2); color:var(--tt-muted); font-style:italic;">
                        <?php esc_html_e( 'No explicit capabilities — uses WP defaults only.', 'talenttrack' ); ?>
                    </p>
                <?php endif; ?>
            </details>

            <p style="margin:var(--tt-sp-3) 0 0; font-size:var(--tt-fs-sm); color:var(--tt-muted);">
                <strong><?php esc_html_e( 'How to assign:', 'talenttrack' ); ?></strong>
                <?php esc_html_e( 'WordPress → Users → Edit user → Role.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    private static function countUsersWithRole( string $slug ): int {
        $count = count_users();
        if ( ! is_array( $count ) || ! is_array( $count['avail_roles'] ?? null ) ) return 0;
        return (int) ( $count['avail_roles'][ $slug ] ?? 0 );
    }
}
