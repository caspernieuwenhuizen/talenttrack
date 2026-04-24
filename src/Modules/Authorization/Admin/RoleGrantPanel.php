<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\AuthorizationRepository;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * RoleGrantPanel — renders the "Role assignments" section on the Person
 * edit page. Shows current grants and provides a form to grant a new role.
 *
 * Designed as a pure static helper so PeoplePage can call it without
 * introducing a hard dependency on the Authorization module. If the
 * Authorization module is disabled, PeoplePage simply doesn't call
 * these methods.
 */
class RoleGrantPanel {

    /**
     * Render the assignments table + grant form for a given person.
     *
     * @param int $person_id
     */
    public static function render( int $person_id ): void {
        if ( $person_id <= 0 ) return;

        $repo        = new AuthorizationRepository();
        $assignments = $repo->getPersonAssignments( $person_id );
        $roles       = $repo->listRoles();

        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Role assignments', 'talenttrack' ); ?></h2>

        <p class="description">
            <?php esc_html_e( 'Grant this person one or more roles. Each role can be scoped globally, to a team, or to a specific player depending on the role type. Roles determine what this person can do in TalentTrack.', 'talenttrack' ); ?>
        </p>

        <?php self::renderAssignmentsTable( $person_id, $assignments ); ?>
        <?php self::renderGrantForm( $person_id, $roles ); ?>
        <?php
    }

    private static function renderAssignmentsTable( int $person_id, array $assignments ): void {
        if ( empty( $assignments ) ) {
            echo '<p><em>' . esc_html__( 'No role assignments yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:900px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Role', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'From', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Until', 'talenttrack' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $a ) :
                    $scope_label = RolesPage::formatScope(
                        (string) $a->scope_type,
                        $a->scope_id !== null ? (int) $a->scope_id : null
                    );
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-roles&action=view&id=' . (int) $a->role_id ) ); ?>">
                                <?php echo esc_html( RolesPage::roleLabel( (string) $a->role_key ) ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $scope_label ); ?></td>
                        <td><?php echo esc_html( $a->start_date ?: '—' ); ?></td>
                        <td><?php echo esc_html( $a->end_date ?: '—' ); ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                                <?php wp_nonce_field( 'tt_revoke_role_' . (int) $a->id, 'tt_nonce' ); ?>
                                <input type="hidden" name="action" value="tt_revoke_role" />
                                <input type="hidden" name="scope_id_pk" value="<?php echo (int) $a->id; ?>" />
                                <input type="hidden" name="person_id"   value="<?php echo (int) $person_id; ?>" />
                                <input type="hidden" name="redirect_to" value="person_edit" />
                                <button type="submit" class="button-link" style="color:#b32d2e;"
                                    onclick="return confirm('<?php echo esc_js( __( 'Revoke this role assignment?', 'talenttrack' ) ); ?>');">
                                    <?php esc_html_e( 'Revoke', 'talenttrack' ); ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function renderGrantForm( int $person_id, array $roles ): void {
        global $wpdb;

        // Pre-fetch teams and players for the scope dropdowns.
        $team_scope   = QueryHelpers::apply_demo_scope( 't',  'team' );
        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $teams = $wpdb->get_results( "SELECT t.id, t.name FROM {$wpdb->prefix}tt_teams t WHERE 1=1 {$team_scope} ORDER BY t.name ASC" );
        $players = $wpdb->get_results( "SELECT pl.id, pl.first_name, pl.last_name FROM {$wpdb->prefix}tt_players pl WHERE pl.status = 'active' {$player_scope} ORDER BY pl.last_name ASC, pl.first_name ASC" );
        $people_list = $wpdb->get_results( "SELECT id, first_name, last_name FROM {$wpdb->prefix}tt_people WHERE status = 'active' ORDER BY last_name ASC, first_name ASC" );

        // Build a JSON map of role_key → allowed scope types so the UI can
        // restrict scope options when a role is selected.
        $allowed_scopes_map = [];
        $grantable_role_ids = [];
        foreach ( $roles as $r ) {
            $allowed = RolesPage::allowedScopesForRole( (string) $r->role_key );
            if ( empty( $allowed ) ) continue; // e.g. `player` is not grantable
            $allowed_scopes_map[ (int) $r->id ] = $allowed;
            $grantable_role_ids[] = (int) $r->id;
        }
        ?>
        <h3 style="margin-top:24px;"><?php esc_html_e( 'Grant a new role', 'talenttrack' ); ?></h3>

        <?php if ( empty( $grantable_role_ids ) ) : ?>
            <p><em><?php esc_html_e( 'No grantable roles available.', 'talenttrack' ); ?></em></p>
            <?php return; ?>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:700px;">
            <?php wp_nonce_field( 'tt_grant_role_' . $person_id, 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_grant_role" />
            <input type="hidden" name="person_id" value="<?php echo (int) $person_id; ?>" />

            <table class="form-table">
                <tr>
                    <th><label for="tt_grant_role_id"><?php esc_html_e( 'Role', 'talenttrack' ); ?></label> *</th>
                    <td>
                        <select name="role_id" id="tt_grant_role_id" required>
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( $roles as $r ) :
                                if ( ! in_array( (int) $r->id, $grantable_role_ids, true ) ) continue; ?>
                                <option value="<?php echo (int) $r->id; ?>" data-role-key="<?php echo esc_attr( (string) $r->role_key ); ?>">
                                    <?php echo esc_html( RolesPage::roleLabel( (string) $r->role_key ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th><label for="tt_grant_scope_type"><?php esc_html_e( 'Scope', 'talenttrack' ); ?></label> *</th>
                    <td>
                        <select name="scope_type" id="tt_grant_scope_type" required>
                            <option value="global"><?php esc_html_e( 'Global (everywhere)', 'talenttrack' ); ?></option>
                            <option value="team"><?php esc_html_e( 'Team', 'talenttrack' ); ?></option>
                            <option value="player"><?php esc_html_e( 'Player', 'talenttrack' ); ?></option>
                            <option value="person"><?php esc_html_e( 'Person', 'talenttrack' ); ?></option>
                        </select>
                        <p class="description" id="tt_scope_hint" style="margin-top:4px;">
                            <?php esc_html_e( 'Pick a role first — the scope options will restrict to what makes sense for that role.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>

                <tr id="tt_scope_team_row" style="display:none;">
                    <th><label for="tt_scope_team_id"><?php esc_html_e( 'Select team', 'talenttrack' ); ?></label></th>
                    <td>
                        <select name="scope_team_id" id="tt_scope_team_id">
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( $teams as $t ) : ?>
                                <option value="<?php echo (int) $t->id; ?>"><?php echo esc_html( (string) $t->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr id="tt_scope_player_row" style="display:none;">
                    <th><label for="tt_scope_player_id"><?php esc_html_e( 'Select player', 'talenttrack' ); ?></label></th>
                    <td>
                        <select name="scope_player_id" id="tt_scope_player_id">
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( $players as $p ) : ?>
                                <option value="<?php echo (int) $p->id; ?>">
                                    <?php echo esc_html( trim( $p->first_name . ' ' . $p->last_name ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr id="tt_scope_person_row" style="display:none;">
                    <th><label for="tt_scope_person_id"><?php esc_html_e( 'Select person', 'talenttrack' ); ?></label></th>
                    <td>
                        <select name="scope_person_id" id="tt_scope_person_id">
                            <option value=""><?php esc_html_e( '— Select —', 'talenttrack' ); ?></option>
                            <?php foreach ( $people_list as $pp ) :
                                if ( (int) $pp->id === $person_id ) continue; ?>
                                <option value="<?php echo (int) $pp->id; ?>">
                                    <?php echo esc_html( trim( $pp->first_name . ' ' . $pp->last_name ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th><label for="tt_grant_start"><?php esc_html_e( 'Start date', 'talenttrack' ); ?></label></th>
                    <td><input type="date" name="start_date" id="tt_grant_start" /></td>
                </tr>
                <tr>
                    <th><label for="tt_grant_end"><?php esc_html_e( 'End date', 'talenttrack' ); ?></label></th>
                    <td><input type="date" name="end_date" id="tt_grant_end" /></td>
                </tr>
            </table>

            <!-- The "scope_id" submitted depends on the scope_type; JS fills it from the correct dropdown. -->
            <input type="hidden" name="scope_id" id="tt_grant_scope_id" value="" />

            <?php submit_button( __( 'Grant role', 'talenttrack' ), 'secondary', 'submit', false ); ?>
        </form>

        <script>
        (function(){
            var allowedMap = <?php echo wp_json_encode( $allowed_scopes_map ); ?>;
            var roleSelect = document.getElementById('tt_grant_role_id');
            var scopeSelect = document.getElementById('tt_grant_scope_type');
            var hint = document.getElementById('tt_scope_hint');
            var teamRow = document.getElementById('tt_scope_team_row');
            var playerRow = document.getElementById('tt_scope_player_row');
            var personRow = document.getElementById('tt_scope_person_row');
            var hiddenScopeId = document.getElementById('tt_grant_scope_id');
            var teamSel = document.getElementById('tt_scope_team_id');
            var playerSel = document.getElementById('tt_scope_player_id');
            var personSel = document.getElementById('tt_scope_person_id');

            function updateScopeOptions() {
                var roleId = roleSelect.value;
                var allowed = allowedMap[roleId] || [];
                var opts = scopeSelect.options;
                var firstEnabled = null;
                for (var i = 0; i < opts.length; i++) {
                    var disabled = allowed.length > 0 && allowed.indexOf(opts[i].value) === -1;
                    opts[i].disabled = disabled;
                    opts[i].hidden = disabled;
                    if (!disabled && firstEnabled === null) firstEnabled = opts[i].value;
                }
                if (firstEnabled !== null && scopeSelect.options[scopeSelect.selectedIndex].disabled) {
                    scopeSelect.value = firstEnabled;
                }
                hint.textContent = allowed.length > 0
                    ? <?php echo wp_json_encode( __( 'Allowed scopes for this role:', 'talenttrack' ) ); ?> + ' ' + allowed.join(', ')
                    : <?php echo wp_json_encode( __( 'Pick a role first — the scope options will restrict to what makes sense for that role.', 'talenttrack' ) ); ?>;
                toggleScopeInputs();
            }

            function toggleScopeInputs() {
                var v = scopeSelect.value;
                teamRow.style.display   = v === 'team'   ? '' : 'none';
                playerRow.style.display = v === 'player' ? '' : 'none';
                personRow.style.display = v === 'person' ? '' : 'none';
                syncHiddenScopeId();
            }

            function syncHiddenScopeId() {
                var v = scopeSelect.value;
                if (v === 'team')        hiddenScopeId.value = teamSel.value;
                else if (v === 'player') hiddenScopeId.value = playerSel.value;
                else if (v === 'person') hiddenScopeId.value = personSel.value;
                else                     hiddenScopeId.value = '';
            }

            roleSelect.addEventListener('change', updateScopeOptions);
            scopeSelect.addEventListener('change', toggleScopeInputs);
            teamSel.addEventListener('change', syncHiddenScopeId);
            playerSel.addEventListener('change', syncHiddenScopeId);
            personSel.addEventListener('change', syncHiddenScopeId);

            updateScopeOptions();
        })();
        </script>
        <?php
    }
}
