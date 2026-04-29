<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * StaffPickerComponent — autocomplete-driven staff/coach picker.
 *
 * Mirror of PlayerSearchPickerComponent, but the candidate set is
 * WP users with TalentTrack staff roles (head_dev, club_admin,
 * coach, scout, staff, administrator) instead of `tt_players` rows.
 *
 * Reuses the same `.tt-psp` DOM contract + JS hydrator at
 * `assets/js/components/player-search-picker.js` — that hydrator only
 * cares about the embedded JSON rows, not the source. Keeping one
 * hydrator means staff and player pickers stay visually + behaviourally
 * consistent; the cost is a single namespacing convention shared
 * across both pickers.
 *
 * Replaces plain `<select>` user dropdowns in:
 *   - `FrontendTrialCaseView::renderAssignStaffForm`
 *   - `FrontendTrialsManageView::renderCreateForm` (3 initial-staff slots)
 *   - `Modules/Wizards/Team/StaffStep` (head coach / assistant / manager / physio)
 *
 * Usage:
 *
 *   echo StaffPickerComponent::render([
 *       'name'      => 'staff_user_id',
 *       'label'     => __( 'Staff member', 'talenttrack' ),
 *       'required'  => true,
 *       'roles'     => [ 'tt_coach', 'tt_head_dev', 'tt_club_admin', 'administrator' ],
 *       'selected'  => 0,
 *   ]);
 *
 * @see PlayerSearchPickerComponent
 */
class StaffPickerComponent {

    /**
     * @param array{
     *   name?:string,
     *   label?:string,
     *   required?:bool,
     *   roles?:array<int,string>,
     *   selected?:int,
     *   placeholder?:string,
     * } $args
     */
    public static function render( array $args = [] ): string {
        $name        = (string) ( $args['name'] ?? 'staff_user_id' );
        $label       = (string) ( $args['label'] ?? __( 'Staff member', 'talenttrack' ) );
        $required    = ! empty( $args['required'] );
        $placeholder = (string) ( $args['placeholder'] ?? __( 'Type a name to search…', 'talenttrack' ) );
        $selected    = (int) ( $args['selected'] ?? 0 );
        $roles       = (array) ( $args['roles'] ?? [ 'tt_coach', 'tt_head_dev', 'tt_club_admin', 'administrator' ] );

        $rows = self::buildRows( $roles );
        $selected_label = '';
        foreach ( $rows as $r ) {
            if ( (int) $r['id'] === $selected ) {
                $selected_label = (string) $r['label'];
                break;
            }
        }

        $instance = 'tt-psp-' . wp_generate_uuid4();

        ob_start();
        ?>
        <div class="tt-field tt-psp tt-psp-staff" data-tt-psp data-instance="<?php echo esc_attr( $instance ); ?>">
            <label class="tt-field-label<?php echo $required ? ' tt-field-required' : ''; ?>" for="<?php echo esc_attr( $instance . '-search' ); ?>">
                <?php echo esc_html( $label ); ?>
            </label>

            <input
                type="hidden"
                name="<?php echo esc_attr( $name ); ?>"
                value="<?php echo esc_attr( (string) $selected ); ?>"
                <?php echo $required ? 'required' : ''; ?>
                data-tt-psp-value
            />

            <div class="tt-psp-selected" style="<?php echo $selected ? '' : 'display:none;'; ?>" data-tt-psp-selected>
                <span class="tt-psp-selected-label" data-tt-psp-selected-label>
                    <?php echo esc_html( $selected_label ); ?>
                </span>
                <button type="button" class="tt-psp-clear" data-tt-psp-clear aria-label="<?php esc_attr_e( 'Clear selection', 'talenttrack' ); ?>">×</button>
            </div>

            <input
                type="text"
                id="<?php echo esc_attr( $instance . '-search' ); ?>"
                class="tt-input tt-psp-search"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                autocomplete="off"
                data-tt-psp-search
                style="<?php echo $selected ? 'display:none;' : ''; ?>"
            />

            <ul class="tt-psp-results" data-tt-psp-results role="listbox" hidden></ul>

            <script type="application/json" class="tt-psp-data" data-tt-psp-data>
                <?php echo wp_json_encode( $rows ); ?>
            </script>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build a flat array of {id, label, team_id, search} rows for the
     * shared client-side list. `team_id` is always 0 (staff aren't
     * scoped to a team here — that's per-assignment via tt_team_people).
     * `search` is lower-cased "name + role-label" for prefix matching.
     *
     * @param array<int,string> $roles
     * @return array<int, array{id:int, label:string, team_id:int, search:string}>
     */
    private static function buildRows( array $roles ): array {
        $users = get_users( [
            'role__in' => $roles,
            'fields'   => [ 'ID', 'display_name', 'user_login' ],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ] );

        $out = [];
        foreach ( $users as $u ) {
            $name      = (string) $u->display_name ?: (string) $u->user_login;
            $role_lbl  = self::primaryRoleLabel( (int) $u->ID );
            $label     = $role_lbl !== '' ? sprintf( '%s — %s', $name, $role_lbl ) : $name;
            $search    = strtolower( $name . ' ' . $role_lbl );
            $out[] = [
                'id'      => (int) $u->ID,
                'label'   => $label,
                'team_id' => 0,
                'search'  => $search,
            ];
        }
        return $out;
    }

    /**
     * Return a one-line role label for the user, prioritising the
     * highest-trust role they hold. Used to disambiguate two staff with
     * the same display name.
     */
    private static function primaryRoleLabel( int $user_id ): string {
        $u = get_userdata( $user_id );
        if ( ! $u ) return '';
        $priority = [
            'tt_head_dev'   => __( 'Head of Development', 'talenttrack' ),
            'tt_club_admin' => __( 'Club Admin', 'talenttrack' ),
            'tt_coach'      => __( 'Coach', 'talenttrack' ),
            'tt_scout'      => __( 'Scout', 'talenttrack' ),
            'tt_staff'      => __( 'Staff', 'talenttrack' ),
            'administrator' => __( 'Administrator', 'talenttrack' ),
        ];
        foreach ( $priority as $slug => $label ) {
            if ( in_array( $slug, (array) $u->roles, true ) ) return $label;
        }
        return '';
    }
}
