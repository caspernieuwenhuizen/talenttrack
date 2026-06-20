<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Core\FeatureRegistry;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendModulesView (#1451) — frontend equivalent of the wp-admin
 * Modules toggle, so module enable/disable is reachable from the
 * frontend admin surface (CLAUDE.md §4 SaaS-readiness), not only
 * `wp-admin/admin.php?page=tt-modules`.
 *
 * Gated by the `tt_manage_modules` capability (administrator +
 * tt_club_admin by default). Business logic lives in ModuleRegistry;
 * this view + ModulesRestController both call into it.
 */
class FrontendModulesView extends FrontendViewBase {

    public const CAP = 'tt_manage_modules';

    /** Wire the cap-ensure + the frontend save handler. Called from Kernel::boot. */
    public static function init(): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        add_action( 'admin_post_tt_modules_frontend_save', [ self::class, 'handleSave' ] );
        // Surface an entry tile on the Configuration tile-landing.
        add_filter( 'tt_config_tile_groups', [ self::class, 'addConfigTile' ], 10, 1 );
        // #1451 — REST so a non-WordPress front end can read/toggle modules
        // (CLAUDE.md §4). Both this view and REST call into ModuleRegistry.
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    public static function registerRest(): void {
        register_rest_route( 'talenttrack/v1', '/modules', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restList' ],
                'permission_callback' => [ self::class, 'restPermission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'restToggle' ],
                'permission_callback' => [ self::class, 'restPermission' ],
                'args'                => [
                    'class'   => [ 'required' => true, 'type' => 'string' ],
                    'enabled' => [ 'required' => true, 'type' => 'boolean' ],
                ],
            ],
        ] );
        // #1485 — sub-feature flags. Read/write the on/off state of an
        // individual feature inside a module (e.g. cohort_transitions).
        register_rest_route( 'talenttrack/v1', '/features', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restFeatureList' ],
                'permission_callback' => [ self::class, 'restPermission' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'restFeatureToggle' ],
                'permission_callback' => [ self::class, 'restPermission' ],
                'args'                => [
                    'key'     => [ 'required' => true, 'type' => 'string' ],
                    'enabled' => [ 'required' => true, 'type' => 'boolean' ],
                ],
            ],
        ] );
    }

    public static function restPermission(): bool {
        return current_user_can( self::CAP );
    }

    /** @return \WP_REST_Response */
    public static function restFeatureList() {
        $out = array_map(
            static fn( $f ) => [
                'key'          => (string) $f['key'],
                'label'        => (string) $f['label'],
                'description'  => (string) $f['description'],
                'module_class' => (string) $f['module_class'],
                'enabled'      => ! empty( $f['enabled'] ),
            ],
            FeatureRegistry::allWithState()
        );
        return new \WP_REST_Response( array_values( $out ), 200 );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function restFeatureToggle( \WP_REST_Request $req ) {
        $key     = (string) $req->get_param( 'key' );
        $enabled = (bool) $req->get_param( 'enabled' );
        if ( ! FeatureRegistry::exists( $key ) ) {
            return new \WP_Error( 'tt_unknown_feature', __( 'Unknown feature.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        FeatureRegistry::setEnabled( $key, $enabled );
        return new \WP_REST_Response( [ 'key' => $key, 'enabled' => $enabled ], 200 );
    }

    /** @return \WP_REST_Response */
    public static function restList() {
        $out = array_map(
            static fn( $m ) => [
                'class'     => (string) $m['class'],
                'enabled'   => ! empty( $m['enabled'] ),
                'always_on' => ! empty( $m['always_on'] ),
            ],
            ModuleRegistry::allWithState()
        );
        return new \WP_REST_Response( array_values( $out ), 200 );
    }

    /** @return \WP_REST_Response|\WP_Error */
    public static function restToggle( \WP_REST_Request $req ) {
        $class   = (string) $req->get_param( 'class' );
        $enabled = (bool) $req->get_param( 'enabled' );
        $found   = null;
        foreach ( ModuleRegistry::allWithState() as $m ) {
            if ( (string) $m['class'] === $class ) { $found = $m; break; }
        }
        if ( ! $found ) {
            return new \WP_Error( 'tt_unknown_module', __( 'Unknown module.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        if ( ! empty( $found['always_on'] ) ) {
            return new \WP_Error( 'tt_core_module', __( 'Core modules cannot be disabled.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        ModuleRegistry::setEnabled( $class, $enabled );
        return new \WP_REST_Response( [ 'class' => $class, 'enabled' => $enabled ], 200 );
    }

    /** Grant tt_manage_modules to administrator + tt_club_admin. Idempotent. */
    public static function ensureCapabilities(): void {
        foreach ( [ 'administrator', 'tt_club_admin' ] as $role_key ) {
            $role = get_role( $role_key );
            if ( $role && ! $role->has_cap( self::CAP ) ) {
                $role->add_cap( self::CAP );
            }
        }
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( self::CAP ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage modules.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        FrontendBreadcrumbs::fromDashboard( __( 'Modules', 'talenttrack' ) );
        self::renderHeader( __( 'Modules', 'talenttrack' ) );

        if ( isset( $_GET['tt_msg'] ) && $_GET['tt_msg'] === 'saved' ) {
            echo '<div class="tt-flash tt-flash-success" style="margin-bottom:var(--tt-sp-4);">'
                . esc_html__( 'Module state saved. Reload open tabs to see the effect.', 'talenttrack' )
                . '</div>';
        }

        $modules = ModuleRegistry::allWithState();
        ?>
        <p style="max-width:680px;">
            <?php esc_html_e( 'Turn TalentTrack modules on or off. A disabled module registers no hooks, REST routes, pages, or capabilities until re-enabled. Core modules cannot be disabled.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( __( 'Change active modules now? Disabling a module hides its surfaces immediately — reload other open tabs after saving.', 'talenttrack' ) ); ?>');">
            <?php wp_nonce_field( 'tt_modules_frontend_save', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_modules_frontend_save" />
            <div class="tt-table-wrap">
                <table class="tt-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Module', 'talenttrack' ); ?></th>
                        <th style="width:96px;"><?php esc_html_e( 'Enabled', 'talenttrack' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $modules as $m ) :
                        $class     = (string) $m['class'];
                        $enabled   = ! empty( $m['enabled'] );
                        $always_on = ! empty( $m['always_on'] );
                        $short     = self::shortName( $class );
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $short ); ?></strong>
                                <?php if ( $always_on ) : ?>
                                    <span style="color:var(--tt-muted); font-size:11px; margin-left:6px;"><?php esc_html_e( '(core)', 'talenttrack' ); ?></span>
                                <?php endif; ?>
                                <div style="color:var(--tt-muted); font-size:11px;"><code><?php echo esc_html( $class ); ?></code></div>
                            </td>
                            <td>
                                <label style="display:inline-flex; align-items:center; gap:6px; min-height:48px;">
                                    <input type="checkbox" name="enabled[]" value="<?php echo esc_attr( $class ); ?>"
                                        <?php checked( $enabled ); ?> <?php disabled( $always_on ); ?> />
                                    <?php echo $enabled ? esc_html__( 'On', 'talenttrack' ) : esc_html__( 'Off', 'talenttrack' ); ?>
                                </label>
                            </td>
                        </tr>
                        <?php
                        // #1485 — sub-feature toggles nested beneath their
                        // parent module. Only meaningful while the module
                        // is on; a disabled module hides its whole surface
                        // so per-feature switches would be dead controls.
                        $features = $enabled ? FeatureRegistry::forModule( $class ) : [];
                        foreach ( $features as $f ) :
                            $f_on = ! empty( $f['enabled'] );
                            ?>
                            <tr class="tt-feature-row">
                                <td style="padding-left:28px;">
                                    <span aria-hidden="true" style="color:var(--tt-muted); margin-right:6px;">↳</span>
                                    <strong><?php echo esc_html( (string) $f['label'] ); ?></strong>
                                    <span style="color:var(--tt-muted); font-size:11px; margin-left:6px;"><?php esc_html_e( '(feature)', 'talenttrack' ); ?></span>
                                    <div style="color:var(--tt-muted); font-size:12px; max-width:560px;"><?php echo esc_html( (string) $f['description'] ); ?></div>
                                </td>
                                <td>
                                    <label style="display:inline-flex; align-items:center; gap:6px; min-height:48px;">
                                        <input type="checkbox" name="features[]" value="<?php echo esc_attr( (string) $f['key'] ); ?>"
                                            <?php checked( $f_on ); ?> />
                                        <?php echo $f_on ? esc_html__( 'On', 'talenttrack' ) : esc_html__( 'Off', 'talenttrack' ); ?>
                                    </label>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            echo FormSaveButton::render( [
                'label'      => __( 'Save module state', 'talenttrack' ),
                'cancel_url' => self::dashboardUrl(),
            ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component returns escaped HTML.
            ?>
        </form>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_modules_frontend_save', 'tt_nonce' );
        \TT\Modules\Authorization\Impersonation\ImpersonationContext::blockDestructiveAdminHandler( 'modules.save' );

        $checked = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['enabled'] ) )
            : [];
        $checked_set = array_flip( $checked );

        foreach ( ModuleRegistry::allWithState() as $m ) {
            $class = (string) $m['class'];
            if ( ! empty( $m['always_on'] ) ) continue;
            $now = isset( $checked_set[ $class ] );
            if ( $now !== ! empty( $m['enabled'] ) ) {
                ModuleRegistry::setEnabled( $class, $now );
            }
        }

        // #1485 — persist sub-feature toggles. Only features whose parent
        // module is currently on are present in the form (FeatureRegistry
        // omits the rest), so absence means "unchecked", not "untouched".
        $features_checked = isset( $_POST['features'] ) && is_array( $_POST['features'] )
            ? array_flip( array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['features'] ) ) )
            : [];
        foreach ( FeatureRegistry::allWithState() as $f ) {
            $key = (string) $f['key'];
            $now = isset( $features_checked[ $key ] );
            if ( $now !== ! empty( $f['enabled'] ) ) {
                FeatureRegistry::setEnabled( $key, $now );
            }
        }

        $url = add_query_arg(
            [ 'tt_view' => 'modules', 'tt_msg' => 'saved' ],
            self::dashboardUrl()
        );
        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Add a "Modules" tile to the Configuration tile-landing.
     *
     * @param array<int, array{label:string, tiles:array<int,array<string,mixed>>}> $groups
     * @return array<int, array{label:string, tiles:array<int,array<string,mixed>>}>
     */
    public static function addConfigTile( array $groups ): array {
        if ( ! current_user_can( self::CAP ) ) return $groups;
        $tile = [
            'label'       => __( 'Modules', 'talenttrack' ),
            'description' => __( 'Turn TalentTrack modules on or off.', 'talenttrack' ),
            'icon'        => '🧱',
            'url'         => add_query_arg( [ 'tt_view' => 'modules' ], self::dashboardUrl() ),
            'cap'         => self::CAP,
        ];
        foreach ( $groups as &$group ) {
            if ( ! is_array( $group ) ) continue;
            if ( strpos( (string) ( $group['label'] ?? '' ), 'Branding' ) !== false ) {
                $group['tiles'][] = $tile;
                return $groups;
            }
        }
        unset( $group );
        $groups[] = [ 'label' => __( 'Configuration', 'talenttrack' ), 'tiles' => [ $tile ] ];
        return $groups;
    }

    private static function dashboardUrl(): string {
        return \TT\Shared\Frontend\Components\RecordLink::dashboardUrl();
    }

    private static function shortName( string $class ): string {
        $parts = explode( '\\', $class );
        $last  = (string) end( $parts );
        return preg_replace( '/Module$/', '', $last ) ?: $last;
    }
}
