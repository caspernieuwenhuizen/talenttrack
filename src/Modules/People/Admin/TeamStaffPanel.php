<?php
namespace TT\Modules\People\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\People\PeopleRepository;
use TT\Modules\Authorization\Admin\FunctionalRolesPage;

/**
 * TeamStaffPanel — renders the Staff section for the team edit page.
 *
 * v2.10.0 (Sprint 1G): The role dropdown now reads from
 * tt_functional_roles rather than a hardcoded PHP constant. Assignment
 * writes go through PeopleRepository::assignToTeam( int $functional_role_id ).
 *
 * One row per assignment — a head coach who (via the functional→auth role
 * mapping) also has physio permissions still appears once under their
 * functional role. The physio auth role is visible on the Roles &
 * Permissions detail page, not here.
 *
 * Designed to be called from TeamsPage::renderForm() as a self-contained
 * include. Keeps all staff-related UI logic in one place so the Teams
 * module doesn't grow People-specific concerns.
 *
 * Usage inside team edit form:
 *   TeamStaffPanel::render( (int) $team->id );
 *   TeamStaffPanel::renderAddForm( (int) $team->id );
 */
class TeamStaffPanel {

    /**
     * Render the current staff list for a team, grouped by functional role.
     * Each assignment row has an Unassign button.
     */
    public static function render( int $team_id ): void {
        $repo    = new PeopleRepository();
        $grouped = $repo->getTeamStaff( $team_id );
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Staff', 'talenttrack' ); ?></h2>

        <?php if ( empty( $grouped ) ) : ?>
            <p><?php esc_html_e( 'No staff assigned to this team yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <?php foreach ( self::displayOrder( $grouped ) as $fn_role_key ) :
                if ( empty( $grouped[ $fn_role_key ] ) ) continue; ?>
                <h3 style="margin-top:18px;"><?php echo esc_html( FunctionalRolesPage::roleLabel( $fn_role_key ) ); ?></h3>
                <table class="widefat striped" style="max-width:800px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $grouped[ $fn_role_key ] as $entry ) :
                        $person  = $entry['person']  ?? null;
                        $wp_user = $entry['wp_user'] ?? null;

                        if ( $person ) {
                            $name  = trim( $person->first_name . ' ' . $person->last_name );
                            $email = $person->email ?: '—';
                        } elseif ( $wp_user ) {
                            $name  = $wp_user->display_name;
                            $email = $wp_user->user_email ?: '—';
                        } else {
                            $name  = '—';
                            $email = '—';
                        }
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html( $name ); ?></strong></td>
                            <td><?php echo esc_html( $email ); ?></td>
                            <td><?php echo esc_html( $entry['start_date'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $entry['end_date'] ?: '—' ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                    <?php wp_nonce_field( 'tt_unassign_staff_' . (int) $entry['assignment_id'], 'tt_nonce' ); ?>
                                    <input type="hidden" name="action"        value="tt_unassign_staff" />
                                    <input type="hidden" name="assignment_id" value="<?php echo (int) $entry['assignment_id']; ?>" />
                                    <input type="hidden" name="team_id"       value="<?php echo (int) $team_id; ?>" />
                                    <button type="submit" class="button-link" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Remove this staff assignment?', 'talenttrack' ) ); ?>');">
                                        <?php esc_html_e( 'Unassign', 'talenttrack' ); ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif;
    }

    /**
     * Render the "add staff to team" form. Submits to admin-post.php with
     * action=tt_assign_staff, handled by PeopleModule::handleAssignStaff.
     */
    public static function renderAddForm( int $team_id ): void {
        $repo = new PeopleRepository();
        $active_people = $repo->list( [ 'status' => 'active' ] );

        $fn_repo = new FunctionalRolesRepository();
        $functional_roles = $fn_repo->listRoles();
        ?>
        <h3 style="margin-top:24px;"><?php esc_html_e( 'Assign staff to this team', 'talenttrack' ); ?></h3>

        <?php if ( empty( $active_people ) ) : ?>
            <p>
                <?php esc_html_e( 'No active people exist yet.', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-people&action=new' ) ); ?>" class="button">
                    <?php esc_html_e( 'Add a person first', 'talenttrack' ); ?>
                </a>
            </p>
        <?php elseif ( empty( $functional_roles ) ) : ?>
            <p>
                <?php esc_html_e( 'No functional roles are defined. Check the Functional Roles admin page.', 'talenttrack' ); ?>
            </p>
        <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
                <?php wp_nonce_field( 'tt_assign_staff_' . $team_id, 'tt_nonce' ); ?>
                <input type="hidden" name="action"  value="tt_assign_staff" />
                <input type="hidden" name="team_id" value="<?php echo (int) $team_id; ?>" />

                <table class="form-table" style="max-width:800px;">
                    <tr>
                        <th><label for="tt_assign_person_id"><?php esc_html_e( 'Person', 'talenttrack' ); ?></label> *</th>
                        <td>
                            <select name="person_id" id="tt_assign_person_id" required>
                                <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                                <?php foreach ( $active_people as $p ) :
                                    $label = trim( $p->first_name . ' ' . $p->last_name );
                                    if ( $p->email ) $label .= ' <' . $p->email . '>'; ?>
                                    <option value="<?php echo (int) $p->id; ?>"><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_assign_functional_role_id"><?php esc_html_e( 'Functional role', 'talenttrack' ); ?></label> *</th>
                        <td>
                            <select name="functional_role_id" id="tt_assign_functional_role_id" required>
                                <?php foreach ( $functional_roles as $fr ) : ?>
                                    <option value="<?php echo (int) $fr->id; ?>">
                                        <?php echo esc_html( FunctionalRolesPage::roleLabel( (string) $fr->role_key ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'What is this person\'s job on the team? Functional roles are mapped to authorization roles (permissions) on the Functional Roles admin page.', 'talenttrack' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="tt_assign_start"><?php esc_html_e( 'Start date', 'talenttrack' ); ?></label></th>
                        <td><input type="date" name="start_date" id="tt_assign_start" /></td>
                    </tr>
                    <tr>
                        <th><label for="tt_assign_end"><?php esc_html_e( 'End date', 'talenttrack' ); ?></label></th>
                        <td><input type="date" name="end_date" id="tt_assign_end" /></td>
                    </tr>
                </table>

                <?php submit_button( __( 'Assign', 'talenttrack' ), 'secondary', 'submit', false ); ?>
            </form>
        <?php endif;
    }

    /**
     * Display order: explicit sort for the common roles, then any others
     * discovered in the grouped data, then 'other' last.
     *
     * @param array<string, mixed> $grouped
     * @return string[]
     */
    private static function displayOrder( array $grouped ): array {
        $preferred = [ 'head_coach', 'assistant_coach', 'manager', 'physio' ];
        $out = [];
        foreach ( $preferred as $k ) {
            if ( array_key_exists( $k, $grouped ) ) $out[] = $k;
        }
        foreach ( array_keys( $grouped ) as $k ) {
            if ( $k === 'other' ) continue;
            if ( ! in_array( $k, $out, true ) ) $out[] = $k;
        }
        if ( array_key_exists( 'other', $grouped ) ) $out[] = 'other';
        return $out;
    }
}
