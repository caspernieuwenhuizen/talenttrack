<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * MatrixPage — Authorization → Matrix admin UI (#0033 Sprint 3).
 *
 * Renders the persona × entity grid with three-pill toggles per cell
 * (read / change / create_delete) and a scope dropdown. Default seed
 * values render dimmed; admin-edited values render bold. A "Reset to
 * defaults" button reseeds the table from `config/authorization_seed.php`.
 *
 * Capability gate: WordPress `administrator` (sharper than
 * `tt_edit_settings` — redefining what every role can do is the kind
 * of action that should NOT be delegable to a non-admin).
 *
 * Audit: every save writes to `tt_authorization_changelog` (bridge until
 * #0021 ships and the audit log absorbs it).
 */
class MatrixPage {

    public static function init(): void {
        add_action( 'admin_post_tt_matrix_save',  [ __CLASS__, 'handleSave' ] );
        add_action( 'admin_post_tt_matrix_reset', [ __CLASS__, 'handleReset' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $repo = new MatrixRepository();
        $personas = $repo->personas();
        $entities = $repo->entities();
        $grid = $repo->asGrid();

        $activities = [
            'read'          => __( 'R', 'talenttrack' ),
            'change'        => __( 'C', 'talenttrack' ),
            'create_delete' => __( 'D', 'talenttrack' ),
        ];
        $scope_kinds = [ 'global', 'team', 'player', 'self' ];

        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Authorization Matrix', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'access-control' ); ?></h1>
            <p style="color:#5b6e75; max-width: 800px;">
                <?php esc_html_e( 'Define what each persona can do on each entity. R = read, C = change (edit), D = create/delete. Scope narrows the grant: "global" applies everywhere; "team" or "player" require the user to also have that scope assignment.', 'talenttrack' ); ?>
            </p>

            <?php if ( $msg === 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Matrix saved.', 'talenttrack' ); ?></p></div>
            <?php elseif ( $msg === 'reset' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Matrix reset to defaults.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tt-matrix-form">
                <?php wp_nonce_field( 'tt_matrix_save', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_save" />

                <table class="widefat striped tt-matrix-table" style="margin-top:14px;">
                    <thead>
                        <tr>
                            <th style="position:sticky; left:0; background:#fff; z-index:2;"><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                            <?php foreach ( $personas as $persona ) : ?>
                                <th><?php echo esc_html( self::personaLabel( $persona ) ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $persona_cols = max( 1, count( $personas ) );
                    $grouped = self::groupEntitiesByCategory( $entities );
                    foreach ( $grouped as $category => $rows_in_category ) :
                        ?>
                        <tr class="tt-matrix-category">
                            <th colspan="<?php echo (int) ( 1 + $persona_cols ); ?>"
                                style="position:sticky; left:0; background:#eef2f5; color:#1d3a4a; text-align:left; padding:8px 12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; font-size:11px;">
                                <?php echo esc_html( $category ); ?>
                            </th>
                        </tr>
                        <?php
                        foreach ( $rows_in_category as $entity_row ) :
                            $entity = $entity_row['entity'];
                            $module = $entity_row['module_class'];
                            ?>
                        <tr>
                            <td style="position:sticky; left:0; background:#fff; font-weight:600;">
                                <?php echo esc_html( $entity ); ?>
                                <small style="display:block; color:#888; font-weight:400;"><?php echo esc_html( self::shortModule( $module ) ); ?></small>
                            </td>
                            <?php foreach ( $personas as $persona ) :
                                $cell = $grid[ $persona ][ $entity ] ?? [];
                                ?>
                                <td style="text-align:center; padding:6px;">
                                    <?php foreach ( $activities as $activity => $abbrev ) :
                                        $details   = $cell[ $activity ] ?? null;
                                        $is_set    = (bool) $details;
                                        $is_default= $details ? (int) $details['is_default'] : 1;
                                        $css = $is_set
                                            ? ( $is_default
                                                ? 'background:#c5e8d2; color:#196a32; opacity:0.7;'
                                                : 'background:#196a32; color:#fff; font-weight:700;' )
                                            : 'background:#f0f0f1; color:#999;';
                                        ?>
                                        <label style="display:inline-block; padding:2px 4px; margin:0 1px; border-radius:3px; cursor:pointer; font-family:monospace; font-size:11px; <?php echo $css; ?>" title="<?php echo esc_attr( self::cellTitle( $persona, $entity, $activity, $is_set, (bool) $is_default ) ); ?>">
                                            <input type="checkbox"
                                                   name="cell[<?php echo esc_attr( $persona . '|' . $entity . '|' . $activity ); ?>]"
                                                   value="1"
                                                   style="display:none;"
                                                   <?php checked( $is_set ); ?> />
                                            <?php echo esc_html( $abbrev ); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <br>
                                    <select name="scope[<?php echo esc_attr( $persona . '|' . $entity ); ?>]" style="font-size:11px; padding:1px;">
                                        <?php
                                        $current_scope = '';
                                        foreach ( [ 'read', 'change', 'create_delete' ] as $a ) {
                                            if ( isset( $cell[ $a ]['scope_kind'] ) ) {
                                                $current_scope = $cell[ $a ]['scope_kind'];
                                                break;
                                            }
                                        }
                                        if ( $current_scope === '' ) $current_scope = 'global';
                                        foreach ( $scope_kinds as $sk ) :
                                            ?>
                                            <option value="<?php echo esc_attr( $sk ); ?>" <?php selected( $current_scope, $sk ); ?>><?php echo esc_html( $sk ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:14px; display:flex; align-items:center; gap:12px;">
                    <?php submit_button( __( 'Save matrix', 'talenttrack' ), 'primary', 'submit', false ); ?>
                    <span id="tt-matrix-dirty-pill" style="display:none; background:#dba617; color:#1f1300; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.04em;">
                        <?php esc_html_e( 'UNSAVED CHANGES', 'talenttrack' ); ?>
                    </span>
                </p>
            </form>

            <script>
            // Inline visual feedback on cell click. The form is save-on-submit
            // (no per-click AJAX), so without this the labels look unchanged
            // and the user can't tell if their click registered.
            (function(){
                var ON_DEFAULT  = 'background:#c5e8d2; color:#196a32; opacity:0.7;';
                var ON_EDITED   = 'background:#196a32; color:#fff; font-weight:700;';
                var OFF         = 'background:#f0f0f1; color:#999;';
                var BASE        = 'display:inline-block; padding:2px 4px; margin:0 1px; border-radius:3px; cursor:pointer; font-family:monospace; font-size:11px; ';
                var pill = document.getElementById('tt-matrix-dirty-pill');
                document.querySelectorAll('input[type="checkbox"][name^="cell["]').forEach(function(cb){
                    cb.addEventListener('change', function(){
                        var label = cb.closest('label');
                        if (!label) return;
                        // After admin edits, the cell is no longer "default" — paint it edited (or off).
                        label.setAttribute('style', BASE + (cb.checked ? ON_EDITED : OFF));
                        if (pill) pill.style.display = 'inline-block';
                    });
                });
                document.querySelectorAll('select[name^="scope["]').forEach(function(sel){
                    sel.addEventListener('change', function(){ if (pill) pill.style.display = 'inline-block'; });
                });
            })();
            </script>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Reset to defaults', 'talenttrack' ); ?></h2>
            <p style="color:#5b6e75;">
                <?php esc_html_e( 'Reseeds the matrix from the shipped seed file. Any admin-edited rows are lost. Logged in the changelog.', 'talenttrack' ); ?>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return confirm('<?php echo esc_js( __( 'Reset every persona\'s permissions to the shipped defaults? This cannot be undone (except by re-editing).', 'talenttrack' ) ); ?>');">
                <?php wp_nonce_field( 'tt_matrix_reset', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_matrix_reset" />
                <button type="submit" class="button"><?php esc_html_e( 'Reset to defaults', 'talenttrack' ); ?></button>
            </form>

            <hr style="margin:24px 0;" />

            <h2><?php esc_html_e( 'Recent changes', 'talenttrack' ); ?></h2>
            <?php self::renderChangelog(); ?>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_save', 'tt_nonce' );

        $repo = new MatrixRepository();
        $entities = $repo->entities();
        $personas = $repo->personas();
        $grid = $repo->asGrid();

        $cells = isset( $_POST['cell'] ) && is_array( $_POST['cell'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['cell'] ) )
            : [];
        $scopes = isset( $_POST['scope'] ) && is_array( $_POST['scope'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['scope'] ) )
            : [];

        $entity_module = [];
        foreach ( $entities as $e ) $entity_module[ $e['entity'] ] = $e['module_class'];

        $activities = [ 'read', 'change', 'create_delete' ];
        $scope_kinds = [ 'global', 'team', 'player', 'self' ];

        global $wpdb;
        $p = $wpdb->prefix;
        $now = current_time( 'mysql' );
        $actor = get_current_user_id();

        foreach ( $personas as $persona ) {
            foreach ( $entities as $e ) {
                $entity = $e['entity'];
                $module = $e['module_class'];
                $scope_key = $persona . '|' . $entity;
                $scope_kind = isset( $scopes[ $scope_key ] ) && in_array( $scopes[ $scope_key ], $scope_kinds, true )
                    ? $scopes[ $scope_key ]
                    : 'global';
                foreach ( $activities as $activity ) {
                    $cell_key = $persona . '|' . $entity . '|' . $activity;
                    $now_set = isset( $cells[ $cell_key ] );
                    $was_details = $grid[ $persona ][ $entity ][ $activity ] ?? null;
                    $was_set = (bool) $was_details;
                    $was_scope = $was_details['scope_kind'] ?? 'global';

                    if ( $now_set === $was_set && $was_scope === $scope_kind ) continue;

                    if ( $now_set ) {
                        // If the row exists with a different scope, remove + insert
                        // (PRIMARY UNIQUE is on (persona, entity, activity, scope_kind)).
                        if ( $was_set && $was_scope !== $scope_kind ) {
                            $repo->removeRow( $persona, $entity, $activity, $was_scope );
                        }
                        $repo->setRow( $persona, $entity, $activity, $scope_kind, $module );
                        $wpdb->insert( "{$p}tt_authorization_changelog", [
                            'persona'      => $persona,
                            'entity'       => $entity,
                            'activity'     => $activity,
                            'scope_kind'   => $scope_kind,
                            'change_type'  => $was_set ? 'scope_change' : 'grant',
                            'before_value' => $was_set ? 1 : 0,
                            'after_value'  => 1,
                            'actor_user_id'=> $actor,
                            'note'         => $was_set ? "scope: {$was_scope} → {$scope_kind}" : null,
                            'created_at'   => $now,
                        ] );
                    } elseif ( $was_set ) {
                        $repo->removeRow( $persona, $entity, $activity, $was_scope );
                        $wpdb->insert( "{$p}tt_authorization_changelog", [
                            'persona'      => $persona,
                            'entity'       => $entity,
                            'activity'     => $activity,
                            'scope_kind'   => $was_scope,
                            'change_type'  => 'revoke',
                            'before_value' => 1,
                            'after_value'  => 0,
                            'actor_user_id'=> $actor,
                            'note'         => null,
                            'created_at'   => $now,
                        ] );
                    }
                }
            }
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleReset(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_matrix_reset', 'tt_nonce' );

        $repo = new MatrixRepository();
        $repo->reseed();

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_authorization_changelog", [
            'persona'      => '*',
            'entity'       => '*',
            'activity'     => '*',
            'scope_kind'   => '*',
            'change_type'  => 'reset',
            'actor_user_id'=> get_current_user_id(),
            'note'         => 'matrix reset to seed defaults',
            'created_at'   => current_time( 'mysql' ),
        ] );

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-matrix', 'tt_msg' => 'reset' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function renderChangelog(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_authorization_changelog";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            echo '<p><em>' . esc_html__( 'Changelog table not found. Run pending migrations.', 'talenttrack' ) . '</em></p>';
            return;
        }
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 30" );
        if ( empty( $rows ) ) {
            echo '<p><em>' . esc_html__( 'No matrix changes yet.', 'talenttrack' ) . '</em></p>';
            return;
        }
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'When', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Actor', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Persona', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Activity', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Scope', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Change', 'talenttrack' ); ?></th>
                    <th><?php esc_html_e( 'Note', 'talenttrack' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $actor = (int) $r->actor_user_id > 0 ? get_userdata( (int) $r->actor_user_id ) : null;
                ?>
                <tr>
                    <td><?php echo esc_html( (string) $r->created_at ); ?></td>
                    <td><?php echo esc_html( $actor ? (string) $actor->display_name : '—' ); ?></td>
                    <td><?php echo esc_html( (string) $r->persona ); ?></td>
                    <td><?php echo esc_html( (string) $r->entity ); ?></td>
                    <td><?php echo esc_html( (string) $r->activity ); ?></td>
                    <td><?php echo esc_html( (string) $r->scope_kind ); ?></td>
                    <td><?php echo esc_html( (string) $r->change_type ); ?></td>
                    <td><?php echo esc_html( (string) ( $r->note ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function personaLabel( string $persona ): string {
        $map = [
            'player'              => __( 'Player', 'talenttrack' ),
            'parent'              => __( 'Parent', 'talenttrack' ),
            'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
            'head_coach'          => __( 'Head Coach', 'talenttrack' ),
            'head_of_development' => __( 'Head of Dev', 'talenttrack' ),
            'scout'               => __( 'Scout', 'talenttrack' ),
            'team_manager'        => __( 'Team Manager', 'talenttrack' ),
            'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
        ];
        return $map[ $persona ] ?? $persona;
    }

    private static function shortModule( string $class ): string {
        $parts = explode( '\\', $class );
        return end( $parts );
    }

    /**
     * Group the flat entity list under category headers so the matrix
     * reads top-to-bottom by domain rather than alphabetically.
     *
     * Input shape: [ ['entity' => 'player', 'module_class' => 'TT\\Modules\\Players\\PlayersModule'], … ]
     * Output:      [ 'Players' => [ … rows … ], 'Teams' => [ … ], … ]
     *
     * Unmapped modules fall under "Other" so the grid can never silently
     * drop a row when a new module ships before the map is updated.
     *
     * @param array<int, array{entity:string, module_class:string}> $entities
     * @return array<string, array<int, array{entity:string, module_class:string}>>
     */
    private static function groupEntitiesByCategory( array $entities ): array {
        $module_to_category = [
            'PlayersModule'         => __( 'Players', 'talenttrack' ),
            'PeopleModule'          => __( 'Players', 'talenttrack' ),
            'TeamsModule'           => __( 'Teams', 'talenttrack' ),
            'ActivitiesModule'      => __( 'Activities', 'talenttrack' ),
            'EvaluationsModule'     => __( 'Evaluations', 'talenttrack' ),
            'GoalsModule'           => __( 'Development', 'talenttrack' ),
            'PdpModule'             => __( 'Development', 'talenttrack' ),
            'MethodologyModule'     => __( 'Development', 'talenttrack' ),
            'TeamDevelopmentModule' => __( 'Development', 'talenttrack' ),
            'DevelopmentModule'     => __( 'Development', 'talenttrack' ),
            'ReportsModule'         => __( 'Insights', 'talenttrack' ),
            'StatsModule'           => __( 'Insights', 'talenttrack' ),
            'WorkflowModule'        => __( 'Operations', 'talenttrack' ),
            'InvitationsModule'     => __( 'Operations', 'talenttrack' ),
            'DocumentationModule'   => __( 'Operations', 'talenttrack' ),
            'OnboardingModule'      => __( 'Operations', 'talenttrack' ),
            'AuthorizationModule'   => __( 'Administration', 'talenttrack' ),
            'ConfigurationModule'   => __( 'Administration', 'talenttrack' ),
            'LicenseModule'         => __( 'Administration', 'talenttrack' ),
            'BackupModule'          => __( 'Administration', 'talenttrack' ),
            'DemoDataModule'        => __( 'Administration', 'talenttrack' ),
        ];
        $category_order = [
            __( 'Players', 'talenttrack' ),
            __( 'Teams', 'talenttrack' ),
            __( 'Activities', 'talenttrack' ),
            __( 'Evaluations', 'talenttrack' ),
            __( 'Development', 'talenttrack' ),
            __( 'Insights', 'talenttrack' ),
            __( 'Operations', 'talenttrack' ),
            __( 'Administration', 'talenttrack' ),
            __( 'Other', 'talenttrack' ),
        ];

        $buckets = [];
        foreach ( $entities as $row ) {
            $short = self::shortModule( (string) $row['module_class'] );
            $cat   = $module_to_category[ $short ] ?? __( 'Other', 'talenttrack' );
            $buckets[ $cat ][] = $row;
        }
        foreach ( $buckets as &$rows ) {
            usort( $rows, static fn( $a, $b ) => strcmp( (string) $a['entity'], (string) $b['entity'] ) );
        }
        unset( $rows );

        $ordered = [];
        foreach ( $category_order as $cat ) {
            if ( isset( $buckets[ $cat ] ) ) {
                $ordered[ $cat ] = $buckets[ $cat ];
                unset( $buckets[ $cat ] );
            }
        }
        foreach ( $buckets as $cat => $rows ) {
            $ordered[ $cat ] = $rows;
        }
        return $ordered;
    }

    private static function cellTitle( string $persona, string $entity, string $activity, bool $is_set, bool $is_default ): string {
        if ( ! $is_set ) {
            return sprintf(
                /* translators: 1: persona, 2: entity, 3: activity */
                __( '%1$s cannot %3$s on %2$s. Click to grant.', 'talenttrack' ),
                $persona, $entity, $activity
            );
        }
        if ( $is_default ) {
            return sprintf(
                /* translators: 1: persona, 2: entity, 3: activity */
                __( '%1$s can %3$s on %2$s (shipped default). Click to revoke.', 'talenttrack' ),
                $persona, $entity, $activity
            );
        }
        return sprintf(
            /* translators: 1: persona, 2: entity, 3: activity */
            __( '%1$s can %3$s on %2$s (admin-edited). Click to revoke.', 'talenttrack' ),
            $persona, $entity, $activity
        );
    }
}
