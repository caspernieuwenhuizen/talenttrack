<?php
namespace TTB\Admin;

use TTB\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SettingsPage — top-level wp-admin page for the branding plugin.
 *
 * Lives at  Settings → TalentTrack Branding.  Holds the screenshot
 * toggle the user explicitly asked for ("for now placeholders but I
 * want to be able to disable them") plus a few other knobs we'd
 * otherwise have to hand-edit in code.
 */
final class SettingsPage {

    private const SLUG = 'ttb-settings';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register' ] );
        add_action( 'admin_init', [ self::class, 'registerFields' ] );
    }

    public static function register(): void {
        add_options_page(
            __( 'TalentTrack Branding', 'talenttrack-branding' ),
            __( 'TalentTrack Branding', 'talenttrack-branding' ),
            'manage_options',
            self::SLUG,
            [ self::class, 'render' ]
        );
    }

    public static function registerFields(): void {
        register_setting(
            'ttb_settings_group',
            Settings::OPTION,
            [ 'sanitize_callback' => [ Settings::class, 'sanitize' ] ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $s     = Settings::all();
        $pages = (array) get_option( 'ttb_pages', [] );

        ?>
        <div class="wrap ttb-admin">
            <h1><?php esc_html_e( 'TalentTrack Branding', 'talenttrack-branding' ); ?></h1>

            <p class="description">
                <?php esc_html_e( 'Configuration for the public-facing marketing site. The pages themselves (home, features, pricing, pilot, demo, about, contact) live in Pages — edit copy there. This screen owns the cross-page knobs.', 'talenttrack-branding' ); ?>
            </p>

            <form method="post" action="options.php" class="ttb-admin__form">
                <?php settings_fields( 'ttb_settings_group' ); ?>

                <h2><?php esc_html_e( 'Visuals', 'talenttrack-branding' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Show product screenshots', 'talenttrack-branding' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[show_screenshots]" value="1" <?php checked( ! empty( $s['show_screenshots'] ) ); ?> />
                                <?php esc_html_e( 'Render screenshot blocks on the home and features pages.', 'talenttrack-branding' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Off by default — the bundled placeholders are not the final imagery. Switch on once you have polished captures.', 'talenttrack-branding' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttb-tagline"><?php esc_html_e( 'Tagline', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-tagline" type="text" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[tagline]" value="<?php echo esc_attr( (string) $s['tagline'] ); ?>" />
                            <p class="description"><?php esc_html_e( 'Shown under the logo in the navigation header.', 'talenttrack-branding' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Pricing display', 'talenttrack-branding' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ttb-price"><?php esc_html_e( 'Headline price', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-price" type="text" class="small-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[price_monthly]" value="<?php echo esc_attr( (string) $s['price_monthly'] ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttb-pricenote"><?php esc_html_e( 'Price footnote', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-pricenote" type="text" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[currency_note]" value="<?php echo esc_attr( (string) $s['currency_note'] ); ?>" />
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Programme & links', 'talenttrack-branding' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Pilot programme', 'talenttrack-branding' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[pilot_open]" value="1" <?php checked( ! empty( $s['pilot_open'] ) ); ?> />
                                <?php esc_html_e( 'Pilot is currently open for applications.', 'talenttrack-branding' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'When off, the Pilot page shows a "currently closed" notice instead of the application CTA.', 'talenttrack-branding' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttb-demo"><?php esc_html_e( 'Demo URL', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-demo" type="url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[demo_url]" value="<?php echo esc_attr( (string) $s['demo_url'] ); ?>" />
                            <p class="description"><?php esc_html_e( 'Where the "Try the live demo" buttons send visitors. Defaults to jg4it.mediamaniacs.nl.', 'talenttrack-branding' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttb-github"><?php esc_html_e( 'GitHub URL', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-github" type="url" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[github_url]" value="<?php echo esc_attr( (string) $s['github_url'] ); ?>" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ttb-email"><?php esc_html_e( 'Contact form recipient', 'talenttrack-branding' ); ?></label></th>
                        <td>
                            <input id="ttb-email" type="email" class="regular-text" name="<?php echo esc_attr( Settings::OPTION ); ?>[contact_email]" value="<?php echo esc_attr( (string) $s['contact_email'] ); ?>" />
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e( 'Bundled pages', 'talenttrack-branding' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'These were created automatically on activation. Edit copy from Pages; structure is owned by the shortcode.', 'talenttrack-branding' ); ?>
            </p>
            <table class="widefat striped ttb-admin__pages">
                <thead><tr>
                    <th><?php esc_html_e( 'Shortcode', 'talenttrack-branding' ); ?></th>
                    <th><?php esc_html_e( 'Page', 'talenttrack-branding' ); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $pages as $tag => $page_id ) :
                    $post = get_post( (int) $page_id );
                    if ( ! $post ) continue; ?>
                    <tr>
                        <td><code>[<?php echo esc_html( $tag ); ?>]</code></td>
                        <td><?php echo esc_html( $post->post_title ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack-branding' ); ?></a>
                            &nbsp;|&nbsp;
                            <a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'talenttrack-branding' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
