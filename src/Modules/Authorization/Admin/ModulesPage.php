<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;

/**
 * ModulesPage — Authorization → Modules admin tab (#0033 Sprint 5).
 *
 * Lists every module declared in `config/modules.php` with its current
 * enabled state. Each row has a toggle (admin-only). Always-on core
 * modules render the toggle disabled with a tooltip.
 *
 * The License module row gets an inline warning banner when disabled
 * or recently toggled — pre-launch the disable-toggle is a dev/demo
 * convenience; post-launch it must be replaced with a hard-coded
 * enable or a `TT_DEV_MODE` constant gate (deferred).
 */
class ModulesPage {

    public static function init(): void {
        add_action( 'admin_post_tt_modules_save', [ __CLASS__, 'handleSave' ] );
    }

    public static function render(): void {
        if ( ! current_user_can( 'administrator' ) ) {
            wp_die( esc_html__( 'You must be an administrator to view this page.', 'talenttrack' ) );
        }

        $modules = ModuleRegistry::allWithState();
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';

        $license_class = 'TT\\Modules\\License\\LicenseModule';
        $license_disabled = false;
        foreach ( $modules as $m ) {
            if ( $m['class'] === $license_class && ! $m['enabled'] ) {
                $license_disabled = true;
                break;
            }
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Modules', 'talenttrack' ); ?></h1>
            <p style="color:#5b6e75; max-width:800px;">
                <?php esc_html_e( 'Each TalentTrack module can be turned off here. Disabled modules don\'t register hooks, REST routes, admin pages, or capabilities — they\'re completely invisible until re-enabled. Core modules (Auth, Configuration, Authorization) cannot be disabled.', 'talenttrack' ); ?>
            </p>

            <?php if ( $msg === 'saved' ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Module state saved. Reload any open admin tabs to see the effect.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>

            <?php if ( $license_disabled ) : ?>
                <div class="notice notice-error" style="border-left-color:#b32d2e;">
                    <p><strong>⚠️ <?php esc_html_e( 'License module is disabled.', 'talenttrack' ); ?></strong></p>
                    <p><?php
                        printf(
                            /* translators: %s: dev-mode constant suggestion */
                            esc_html__( 'All monetization gates are off (LicenseGate::* returns true unconditionally). Pre-launch this is fine for demos and dev. Before public launch, hardcode LicenseModule enabled or implement a %s constant that disables this toggle in production.', 'talenttrack' ),
                            '<code>TT_DEV_MODE</code>'
                        );
                    ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_modules_save', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_modules_save" />

                <table class="widefat striped" style="margin-top:14px;">
                    <thead>
                        <tr>
                            <th style="width:40%;"><?php esc_html_e( 'Module', 'talenttrack' ); ?></th>
                            <th style="width:50%;"><?php esc_html_e( 'Class', 'talenttrack' ); ?></th>
                            <th style="width:10%;"><?php esc_html_e( 'Enabled', 'talenttrack' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $modules as $m ) :
                        $class = $m['class'];
                        $short = self::shortName( $class );
                        $enabled = $m['enabled'];
                        $always_on = $m['always_on'];
                        $is_license = $class === $license_class;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $short ); ?></strong>
                                <?php if ( $always_on ) : ?>
                                    <span style="color:#5b6e75; font-size:11px; margin-left:8px;">
                                        <?php esc_html_e( '(core — cannot be disabled)', 'talenttrack' ); ?>
                                    </span>
                                <?php elseif ( $is_license ) : ?>
                                    <span style="color:#b32d2e; font-size:11px; margin-left:8px;">
                                        <?php esc_html_e( '(monetization gates — see warning above when disabled)', 'talenttrack' ); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:11px;"><?php echo esc_html( $class ); ?></code></td>
                            <td>
                                <label style="display:inline-flex; align-items:center; gap:6px;">
                                    <input type="checkbox"
                                           name="enabled[]"
                                           value="<?php echo esc_attr( $class ); ?>"
                                           <?php checked( $enabled ); ?>
                                           <?php disabled( $always_on ); ?>
                                           <?php echo $always_on ? 'title="' . esc_attr__( 'Core module — cannot be disabled.', 'talenttrack' ) . '"' : ''; ?> />
                                    <?php echo $enabled ? esc_html__( 'On', 'talenttrack' ) : esc_html__( 'Off', 'talenttrack' ); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:14px;">
                    <?php submit_button( __( 'Save module state', 'talenttrack' ), 'primary', 'submit', false ); ?>
                </p>
            </form>

            <p style="margin-top:24px; color:#5b6e75; font-size:12px;">
                <?php esc_html_e( 'Note: dependencies between modules are not yet enforced. Disabling a module that another depends on may break the dependent module silently. The Module Registry will surface a dependency graph in a later sprint.', 'talenttrack' ); ?>
            </p>
        </div>
        <?php
    }

    public static function handleSave(): void {
        if ( ! current_user_can( 'administrator' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_modules_save', 'tt_nonce' );

        $checked = isset( $_POST['enabled'] ) && is_array( $_POST['enabled'] )
            ? array_map( static fn( $v ) => (string) $v, (array) wp_unslash( $_POST['enabled'] ) )
            : [];
        $checked_set = array_flip( $checked );

        $modules = ModuleRegistry::allWithState();
        foreach ( $modules as $m ) {
            $class = $m['class'];
            if ( $m['always_on'] ) continue;
            $now_enabled = isset( $checked_set[ $class ] );
            if ( $now_enabled !== $m['enabled'] ) {
                ModuleRegistry::setEnabled( $class, $now_enabled );
            }
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'tt-modules', 'tt_msg' => 'saved' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private static function shortName( string $class ): string {
        $parts = explode( '\\', $class );
        $last  = end( $parts );
        return preg_replace( '/Module$/', '', $last ) ?: $last;
    }
}
