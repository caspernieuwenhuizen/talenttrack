<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Core\FeatureRegistry;
use TT\Shared\Modules\ModuleMetadata;
use TT\Shared\Icons\IconRenderer;
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
        wp_enqueue_style(
            'tt-frontend-modules',
            TT_PLUGIN_URL . 'assets/css/frontend-modules.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
        FrontendBreadcrumbs::fromDashboard( __( 'Modules', 'talenttrack' ) );
        self::renderHeader( __( 'Modules', 'talenttrack' ) );

        if ( isset( $_GET['tt_msg'] ) && $_GET['tt_msg'] === 'saved' ) {
            echo '<div class="tt-flash tt-flash-success" style="margin-bottom:var(--tt-sp-4);">'
                . esc_html__( 'Module state saved. Reload open tabs to see the effect.', 'talenttrack' )
                . '</div>';
        }

        // Group every declared module under its category. Categories with
        // no modules drop out. The render below is a pure composer — all
        // state comes from ModuleRegistry / FeatureRegistry, all wording
        // from ModuleMetadata (CLAUDE.md §4: no business logic in the view).
        $categories = ModuleMetadata::categories();
        $grouped    = array_fill_keys( array_keys( $categories ), [] );
        foreach ( ModuleRegistry::allWithState() as $m ) {
            $meta = ModuleMetadata::for( (string) $m['class'] );
            $cat  = isset( $grouped[ $meta['category'] ] ) ? $meta['category'] : ModuleMetadata::CAT_ADVANCED;
            $grouped[ $cat ][] = [ 'state' => $m, 'meta' => $meta ];
        }
        ?>
        <p class="tt-modules-intro">
            <?php esc_html_e( 'Turn TalentTrack modules on or off. A disabled module registers no hooks, REST routes, pages, or capabilities until re-enabled. Core modules cannot be disabled.', 'talenttrack' ); ?>
        </p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tt-modules-form"
              onsubmit="return confirm('<?php echo esc_js( __( 'Change active modules now? Disabling a module hides its surfaces immediately — reload other open tabs after saving.', 'talenttrack' ) ); ?>');">
            <?php wp_nonce_field( 'tt_modules_frontend_save', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_modules_frontend_save" />

            <?php foreach ( $categories as $cat_key => $cat_label ) :
                if ( empty( $grouped[ $cat_key ] ) ) continue;
                ?>
                <section class="tt-modules-cat" aria-labelledby="tt-cat-<?php echo esc_attr( $cat_key ); ?>">
                    <h2 class="tt-modules-cat-title" id="tt-cat-<?php echo esc_attr( $cat_key ); ?>">
                        <?php echo esc_html( $cat_label ); ?>
                    </h2>
                    <div class="tt-modules-grid">
                        <?php foreach ( $grouped[ $cat_key ] as $entry ) :
                            $class     = (string) $entry['state']['class'];
                            $enabled   = ! empty( $entry['state']['enabled'] );
                            $always_on = ! empty( $entry['state']['always_on'] );
                            $meta      = $entry['meta'];
                            $features  = $enabled ? FeatureRegistry::forModule( $class ) : [];
                            $f_count   = count( $features );
                            ?>
                            <article class="tt-module-card<?php echo $enabled ? '' : ' is-off'; ?><?php echo $always_on ? ' is-core' : ''; ?>">
                                <div class="tt-module-card-head">
                                    <span class="tt-module-icon" aria-hidden="true">
                                        <?php echo IconRenderer::render( (string) $meta['icon'], [ 'width' => 22, 'height' => 22 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- inline SVG from a trusted local file. ?>
                                    </span>
                                    <div class="tt-module-card-titles">
                                        <h3 class="tt-module-name"><?php echo esc_html( (string) $meta['label'] ); ?></h3>
                                        <div class="tt-module-tags">
                                            <span class="tt-tag tt-tag-type"><?php esc_html_e( 'Module', 'talenttrack' ); ?></span>
                                            <?php if ( $always_on ) : ?>
                                                <span class="tt-tag tt-tag-core"><?php esc_html_e( 'Core', 'talenttrack' ); ?></span>
                                            <?php elseif ( $enabled ) : ?>
                                                <span class="tt-tag tt-tag-on"><?php esc_html_e( 'On', 'talenttrack' ); ?></span>
                                            <?php else : ?>
                                                <span class="tt-tag tt-tag-off"><?php esc_html_e( 'Off', 'talenttrack' ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $f_count > 0 ) : ?>
                                                <span class="tt-tag tt-tag-count">
                                                    <?php
                                                    printf(
                                                        esc_html( _n( '%d feature', '%d features', $f_count, 'talenttrack' ) ),
                                                        (int) $f_count
                                                    );
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <label class="tt-module-toggle">
                                        <span class="tt-screen-reader-text">
                                            <?php
                                            /* translators: %s: module name */
                                            printf( esc_html__( 'Enable %s', 'talenttrack' ), esc_html( (string) $meta['label'] ) );
                                            ?>
                                        </span>
                                        <input type="checkbox" name="enabled[]" value="<?php echo esc_attr( $class ); ?>"
                                            <?php checked( $enabled ); ?> <?php disabled( $always_on ); ?> />
                                        <span class="tt-switch" aria-hidden="true"></span>
                                    </label>
                                </div>

                                <?php if ( $meta['description'] !== '' ) : ?>
                                    <p class="tt-module-desc"><?php echo esc_html( (string) $meta['description'] ); ?></p>
                                <?php endif; ?>

                                <?php if ( $f_count > 0 ) : ?>
                                    <details class="tt-module-features">
                                        <summary class="tt-module-features-summary">
                                            <?php
                                            printf(
                                                esc_html( _n( '%d feature', '%d features', $f_count, 'talenttrack' ) ),
                                                (int) $f_count
                                            );
                                            ?>
                                        </summary>
                                        <ul class="tt-feature-list">
                                        <?php foreach ( $features as $f ) :
                                            $f_on  = ! empty( $f['enabled'] );
                                            $f_lbl = (string) $f['label'];
                                            ?>
                                            <li class="tt-feature-item">
                                                <div class="tt-feature-titles">
                                                    <span class="tt-feature-name"><?php echo esc_html( $f_lbl ); ?></span>
                                                    <span class="tt-tag tt-tag-feature"><?php esc_html_e( 'Feature', 'talenttrack' ); ?></span>
                                                    <p class="tt-feature-desc"><?php echo esc_html( (string) $f['description'] ); ?></p>
                                                </div>
                                                <label class="tt-module-toggle tt-feature-toggle">
                                                    <span class="tt-screen-reader-text">
                                                        <?php
                                                        /* translators: %s: feature name */
                                                        printf( esc_html__( 'Enable %s', 'talenttrack' ), esc_html( $f_lbl ) );
                                                        ?>
                                                    </span>
                                                    <input type="checkbox" name="features[]" value="<?php echo esc_attr( (string) $f['key'] ); ?>"
                                                        <?php checked( $f_on ); ?> />
                                                    <span class="tt-switch" aria-hidden="true"></span>
                                                </label>
                                            </li>
                                        <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>

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
}
