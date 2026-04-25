<?php
namespace TT\Modules\License\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\License\DevOverride;
use TT\Modules\License\FeatureMap;

/**
 * DevOverridePage — hidden admin page at `?page=tt-dev-license`.
 *
 * Registered ONLY when `TT_DEV_OVERRIDE_SECRET` is defined in
 * wp-config.php. On customer installs without that constant, this
 * class registers nothing — the page slug 404s.
 *
 * The page asks for a password (compared against the constant via
 * password_verify) plus a tier choice. On match, DevOverride::activate()
 * writes a 24h transient that LicenseGate::tier() consults first.
 *
 * Also renders an admin-bar pill while an override is active so
 * Casper sees at a glance "I'm overriding the tier" and doesn't
 * forget to deactivate before recording a customer-facing demo.
 */
class DevOverridePage {

    public const SLUG = 'tt-dev-license';
    public const CAP  = 'manage_options';

    public static function init(): void {
        if ( ! DevOverride::isAvailable() ) return;

        add_action( 'admin_menu',                              [ self::class, 'register' ] );
        add_action( 'admin_post_tt_dev_override_activate',     [ self::class, 'handleActivate' ] );
        add_action( 'admin_post_tt_dev_override_deactivate',   [ self::class, 'handleDeactivate' ] );
        add_action( 'admin_bar_menu',                          [ self::class, 'addAdminBarPill' ], 100 );
    }

    public static function register(): void {
        // Hidden page (parent = null) so the slug routes but no menu
        // item appears anywhere.
        add_submenu_page(
            null,
            __( 'TalentTrack — Developer License Override', 'talenttrack' ),
            __( 'TalentTrack Developer License Override',  'talenttrack' ),
            self::CAP,
            self::SLUG,
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        if ( ! DevOverride::isAvailable() )    wp_die( esc_html__( 'Override mechanism not available on this install.', 'talenttrack' ) );

        $active = DevOverride::active();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Developer license override', 'talenttrack' ); ?></h1>
            <p style="max-width:680px;">
                <?php esc_html_e( 'Hidden override for owner-side demos and testing. Active for 24 hours, then expires automatically. Never reaches customer installs.', 'talenttrack' ); ?>
            </p>

            <?php if ( isset( $_GET['tt_msg'] ) ) :
                $messages = [
                    'activated'   => __( 'Override activated.', 'talenttrack' ),
                    'deactivated' => __( 'Override cleared.',   'talenttrack' ),
                    'wrong'       => __( 'Wrong password.',     'talenttrack' ),
                ];
                $msg = sanitize_text_field( wp_unslash( (string) $_GET['tt_msg'] ) );
                if ( isset( $messages[ $msg ] ) ) :
                    $cls = $msg === 'wrong' ? 'notice-error' : 'notice-success';
                    ?>
                    <div class="notice <?php echo esc_attr( $cls ); ?>"><p><?php echo esc_html( $messages[ $msg ] ); ?></p></div>
            <?php endif; endif; ?>

            <?php if ( $active !== null ) : ?>
                <h2><?php esc_html_e( 'Current override', 'talenttrack' ); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: 1: tier label, 2: relative time string */
                        esc_html__( 'Tier forced to %1$s; expires in %2$s.', 'talenttrack' ),
                        '<code>' . esc_html( FeatureMap::tierLabel( $active['tier'] ) ) . '</code>',
                        esc_html( human_time_diff( time(), $active['set_at'] + DevOverride::TRANSIENT_TTL ) )
                    );
                    ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'tt_dev_override_deactivate', 'tt_dev_override_nonce' ); ?>
                    <input type="hidden" name="action" value="tt_dev_override_deactivate" />
                    <p><button type="submit" class="button"><?php esc_html_e( 'Clear override', 'talenttrack' ); ?></button></p>
                </form>
            <?php endif; ?>

            <h2 style="margin-top:32px;"><?php esc_html_e( 'Set override', 'talenttrack' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'tt_dev_override_activate', 'tt_dev_override_nonce' ); ?>
                <input type="hidden" name="action" value="tt_dev_override_activate" />
                <table class="form-table">
                    <tr>
                        <th><label for="tt_dev_password"><?php esc_html_e( 'Password', 'talenttrack' ); ?></label></th>
                        <td><input type="password" id="tt_dev_password" name="password" class="regular-text" autocomplete="off" required /></td>
                    </tr>
                    <tr>
                        <th><label for="tt_dev_tier"><?php esc_html_e( 'Tier', 'talenttrack' ); ?></label></th>
                        <td>
                            <select id="tt_dev_tier" name="tier">
                                <?php foreach ( FeatureMap::tiers() as $t ) : ?>
                                    <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( FeatureMap::tierLabel( $t ) ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Activate (24h)', 'talenttrack' ) ); ?>
            </form>
        </div>
        <?php
    }

    public static function handleActivate(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_dev_override_activate', 'tt_dev_override_nonce' );
        $password = (string) wp_unslash( $_POST['password'] ?? '' );
        $tier     = (string) wp_unslash( $_POST['tier']     ?? FeatureMap::TIER_FREE );
        $ok       = DevOverride::activate( $password, $tier );
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'tt_msg' => $ok ? 'activated' : 'wrong' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public static function handleDeactivate(): void {
        if ( ! current_user_can( self::CAP ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_dev_override_deactivate', 'tt_dev_override_nonce' );
        DevOverride::deactivate();
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'tt_msg' => 'deactivated' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public static function addAdminBarPill( \WP_Admin_Bar $bar ): void {
        $active = DevOverride::active();
        if ( $active === null ) return;
        $tier_label = FeatureMap::tierLabel( $active['tier'] );
        $bar->add_node( [
            'id'     => 'tt-dev-override',
            'parent' => 'top-secondary',
            'title'  => '<span style="background:#7c3a9e;color:#fff;padding:1px 7px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:0.05em;line-height:1.6;">🔓 DEV: ' . esc_html( $tier_label ) . '</span>',
            'href'   => admin_url( 'admin.php?page=' . self::SLUG ),
            'meta'   => [ 'title' => __( 'Developer license override is active. Click to manage.', 'talenttrack' ) ],
        ] );
    }
}
