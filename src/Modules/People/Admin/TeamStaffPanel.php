<?php
namespace TT\Modules\People\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\People\PeopleRepository;

/**
 * TeamStaffPanel — renders the Staff section for the team edit page.
 *
 * Designed to be called from TeamsPage::renderForm() (or equivalent) as a
 * self-contained include. Keeps all staff-related UI logic in one place
 * so the Teams module doesn't grow People-specific concerns.
 *
 * Usage inside team edit form:
 *   TeamStaffPanel::render( (int) $team->id );
 *   TeamStaffPanel::renderAddForm( (int) $team->id );
 */
class TeamStaffPanel {

    /**
     * Render the current staff list for a team, grouped by role.
     * Each assignment row has an Unassign button.
     * Legacy head_coach_id rows (source=legacy) are shown as read-only
     * with a note explaining how to migrate.
     */
    public static function render( int $team_id ): void {
        $repo    = new PeopleRepository();
        $grouped = $repo->getTeamStaff( $team_id );
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Staff', 'talenttrack' ); ?></h2>

        <?php if ( empty( $grouped ) ) : ?>
            <p><?php esc_html_e( 'No staff assigned to this team yet.', 'talenttrack' ); ?></p>
        <?php else : ?>
            <?php foreach ( self::displayOrder() as $role ) :
                if ( empty( $grouped[ $role ] ) ) continue; ?>
                <h3 style="margin-top:18px;"><?php echo esc_html( PeoplePage::roleLabel( $role ) ); ?></h3>
                <table class="widefat striped" style="max-width:800px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Email', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Source', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $grouped[ $role ] as $entry ) :
                        $person  = $entry['person']  ?? null;
                        $wp_user = $entry['wp_user'] ?? null;
                        $is_legacy = ( $entry['source'] ?? '' ) === 'legacy';

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
                                <?php if ( $is_legacy ) : ?>
                                    <em style="color:#9a6700;"><?php esc_html_e( 'Legacy head_coach_id', 'talenttrack' ); ?></em>
                                <?php else : ?>
                                    <?php esc_html_e( 'Assignment', 'talenttrack' ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $is_legacy ) : ?>
                                    <span class="description">
                                        <?php esc_html_e( 'Shown for backward compatibility. Add as an explicit assignment below to manage it here.', 'talenttrack' ); ?>
                                    </span>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                        <?php wp_nonce_field( 'tt_unassign_staff_' . (int) $entry['assignment_id'], 'tt_nonce' ); ?>
                                        <input type="hidden" name="action"        value="tt_unassign_staff" />
                                        <input type="hidden" name="assignment_id" value="<?php echo (int) $entry['assignment_id']; ?>" />
                                        <input type="hidden" name="team_id"       value="<?php echo (int) $team_id; ?>" />
                                        <button type="submit" class="button-link" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Remove this staff assignment?', 'talenttrack' ) ); ?>');">
                                            <?php esc_html_e( 'Unassign', 'talenttrack' ); ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
     * action=tt_assign_staff, handled by TeamStaffHandler below.
     */
    public static function renderAddForm( int $team_id ): void {
        $repo = new PeopleRepository();
        $active_people = $repo->list( [ 'status' => 'active' ] );
        ?>
        <h3 style="margin-top:24px;"><?php esc_html_e( 'Assign staff to this team', 'talenttrack' ); ?></h3>

        <?php if ( empty( $active_people ) ) : ?>
            <p>
                <?php esc_html_e( 'No active people exist yet.', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-people&action=new' ) ); ?>" class="button">
                    <?php esc_html_e( 'Add a person first', 'talenttrack' ); ?>
                </a>
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
                        <th><label for="tt_assign_role"><?php esc_html_e( 'Role in team', 'talenttrack' ); ?></label> *</th>
                        <td>
                            <select name="role_in_team" id="tt_assign_role" required>
                                <?php foreach ( PeopleRepository::TEAM_ROLES as $r ) : ?>
                                    <option value="<?php echo esc_attr( $r ); ?>">
                                        <?php echo esc_html( PeoplePage::roleLabel( $r ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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

    /** Display order for staff role sections on the team edit page. */
    private static function displayOrder(): array {
        return [ 'head_coach', 'assistant_coach', 'manager', 'physio', 'other' ];
    }
}
