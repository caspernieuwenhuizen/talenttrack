<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\BrandFonts;
use TT\Shared\Frontend\Components\FormSaveButton;

/**
 * FrontendConfigurationView — frontend mirror of the wp-admin
 * Configuration page.
 *
 * Layout follows the wp-admin Configuration tile grid: a landing page
 * with one sub-tile per configuration area. Branding, Theme & fonts,
 * and Rating scale render frontend forms inline (?config_sub=…); the
 * remaining areas (lookups, evaluation types, feature toggles,
 * backups, translations, audit log) link out to the existing wp-admin
 * tabs because they're heavier admin work that doesn't yet have a
 * dedicated frontend port.
 *
 * Saving the inline forms still goes through
 * `POST /wp-json/talenttrack/v1/config`.
 */
class FrontendConfigurationView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_access_frontend_admin' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view this section.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $sub = isset( $_GET['config_sub'] ) ? sanitize_key( (string) $_GET['config_sub'] ) : '';

        switch ( $sub ) {
            case 'branding':
                self::renderHeader( __( 'Branding', 'talenttrack' ) );
                self::renderSubBackLink();
                wp_enqueue_media();
                self::renderBrandingForm();
                return;
            case 'theme':
                self::renderHeader( __( 'Theme & fonts', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderThemeForm();
                return;
            case 'rating':
                self::renderHeader( __( 'Rating scale', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderRatingForm();
                return;
            case 'menus':
                self::renderHeader( __( 'wp-admin menus', 'talenttrack' ) );
                self::renderSubBackLink();
                self::renderMenusForm();
                return;
        }

        self::renderHeader( __( 'Configuration', 'talenttrack' ) );
        self::renderTileGrid();
    }

    private static function renderSubBackLink(): void {
        $base = remove_query_arg( [ 'config_sub' ] );
        echo '<p style="margin:0 0 var(--tt-sp-3);"><a class="tt-link" href="' . esc_url( $base ) . '">← ' . esc_html__( 'All configuration', 'talenttrack' ) . '</a></p>';
    }

    private static function renderTileGrid(): void {
        $base       = remove_query_arg( [ 'config_sub' ] );
        $admin_url  = admin_url( 'admin.php?page=tt-config' );

        $frontend_tiles = [
            'branding' => [ __( 'Branding', 'talenttrack' ),     __( 'Academy name, logo, primary and secondary colours.', 'talenttrack' ) ],
            'theme'    => [ __( 'Theme & fonts', 'talenttrack' ), __( 'Theme inheritance, display + body fonts and accent colours.', 'talenttrack' ) ],
            'rating'   => [ __( 'Rating scale', 'talenttrack' ),  __( 'Min, max and step for evaluation ratings.', 'talenttrack' ) ],
            'menus'    => [ __( 'wp-admin menus', 'talenttrack' ), __( 'Show or hide the legacy wp-admin menu entries.', 'talenttrack' ) ],
        ];

        $admin_tiles = [
            [ __( 'Lookups & evaluation types', 'talenttrack' ), __( 'Activity types, positions, age groups, goal statuses, evaluation types — all in wp-admin.', 'talenttrack' ), add_query_arg( [ 'tab' => 'eval_types' ], $admin_url ) ],
            [ __( 'Feature toggles', 'talenttrack' ),            __( 'Per-module enable/disable toggles. Live in wp-admin.', 'talenttrack' ),                                add_query_arg( [ 'tab' => 'toggles' ],     $admin_url ) ],
            [ __( 'Backups', 'talenttrack' ),                    __( 'Manual + scheduled database backups. Lives in wp-admin.', 'talenttrack' ),                              add_query_arg( [ 'tab' => 'backups' ],     $admin_url ) ],
            [ __( 'Translations', 'talenttrack' ),               __( 'Per-locale string overrides and the .po/.mo refresh job.', 'talenttrack' ),                              add_query_arg( [ 'tab' => 'translations' ], $admin_url ) ],
            [ __( 'Audit log', 'talenttrack' ),                  __( 'Settings + sensitive data change history.', 'talenttrack' ),                                              add_query_arg( [ 'tab' => 'audit' ],       $admin_url ) ],
            [ __( 'Setup wizard', 'talenttrack' ),               __( 'Re-run the first-run onboarding wizard.', 'talenttrack' ),                                                add_query_arg( [ 'tab' => 'wizard' ],      $admin_url ) ],
        ];

        echo '<p style="margin-bottom:var(--tt-sp-4); color:var(--tt-muted);">';
        esc_html_e( 'Pick a configuration area. Branding, theme and rating-scale settings are edited inline; the remaining areas open in wp-admin.', 'talenttrack' );
        echo '</p>';

        ?>
        <style>
        .tt-cfg-tile-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
        .tt-cfg-tile { display: block; background: #fff; border: 1px solid #e5e7ea; border-radius: 8px; padding: 14px; text-decoration: none; color: #1a1d21; min-height: 76px; transition: transform 180ms cubic-bezier(0.2, 0.8, 0.2, 1), box-shadow 180ms ease, border-color 180ms ease; }
        .tt-cfg-tile:hover, .tt-cfg-tile:focus { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: #d0d4d8; color: #1a1d21; }
        .tt-cfg-tile-title { font-weight: 600; font-size: 14px; line-height: 1.25; margin: 0 0 4px; color: #1a1d21; }
        .tt-cfg-tile-desc { color: #6b7280; font-size: 12px; line-height: 1.35; margin: 0; }
        </style>
        <?php

        echo '<div class="tt-cfg-tile-grid">';
        foreach ( $frontend_tiles as $slug => $meta ) {
            [ $title, $desc ] = $meta;
            $url = add_query_arg( [ 'config_sub' => $slug ], $base );
            echo '<a class="tt-cfg-tile" href="' . esc_url( $url ) . '">';
            echo '<div class="tt-cfg-tile-title">' . esc_html( $title ) . '</div>';
            echo '<div class="tt-cfg-tile-desc">' . esc_html( $desc ) . '</div>';
            echo '</a>';
        }
        foreach ( $admin_tiles as $tile ) {
            [ $title, $desc, $url ] = $tile;
            echo '<a class="tt-cfg-tile" href="' . esc_url( $url ) . '">';
            echo '<div class="tt-cfg-tile-title">' . esc_html( $title ) . ' ↗</div>';
            echo '<div class="tt-cfg-tile-desc">' . esc_html( $desc ) . '</div>';
            echo '</a>';
        }
        echo '</div>';
    }

    private static function renderBrandingForm(): void {
        $logo = QueryHelpers::get_config( 'logo_url', '' );
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="branding">
            <div class="tt-panel">
                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-academy-name"><?php esc_html_e( 'Academy name', 'talenttrack' ); ?></label>
                        <input type="text" id="tt-cfg-academy-name" class="tt-input" name="config[academy_name]" value="<?php echo esc_attr( QueryHelpers::get_config( 'academy_name', '' ) ); ?>" />
                    </div>

                    <div class="tt-field">
                        <span class="tt-field-label"><?php esc_html_e( 'Logo', 'talenttrack' ); ?></span>
                        <input type="hidden" id="tt-cfg-logo-url" name="config[logo_url]" value="<?php echo esc_attr( $logo ); ?>" />
                        <div id="tt-cfg-logo-preview" style="margin-bottom:8px;">
                            <?php if ( $logo ) : ?>
                                <img src="<?php echo esc_url( $logo ); ?>" alt="" style="max-height:80px; border-radius:6px; border:1px solid var(--tt-line);" />
                            <?php endif; ?>
                        </div>
                        <button type="button" class="tt-btn tt-btn-secondary" id="tt-cfg-logo-pick"><?php esc_html_e( 'Choose logo…', 'talenttrack' ); ?></button>
                        <button type="button" class="tt-btn tt-btn-secondary" id="tt-cfg-logo-clear" style="margin-left:6px;"><?php esc_html_e( 'Remove', 'talenttrack' ); ?></button>
                    </div>

                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-primary-color"><?php esc_html_e( 'Primary color', 'talenttrack' ); ?></label>
                        <input type="color" id="tt-cfg-primary-color" name="config[primary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'primary_color', '#0b3d2e' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-secondary-color"><?php esc_html_e( 'Secondary color', 'talenttrack' ); ?></label>
                        <input type="color" id="tt-cfg-secondary-color" name="config[secondary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'secondary_color', '#e8b624' ) ); ?>" />
                    </div>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save branding', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( true );
    }

    private static function renderThemeForm(): void {
        $theme_inherit = (string) QueryHelpers::get_config( 'theme_inherit', '0' );
        $font_display  = (string) QueryHelpers::get_config( 'font_display',  BrandFonts::SYSTEM_DEFAULT );
        $font_body     = (string) QueryHelpers::get_config( 'font_body',     BrandFonts::SYSTEM_DEFAULT );
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="theme">
            <div class="tt-panel">
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted);">
                    <?php esc_html_e( 'Inheritance applies to fonts, colors, and basic links/buttons. TalentTrack’s structural design (spacing, layout, player cards) is unchanged. Pick fonts and accent colors below — fields left as “(System default)” or empty fall back to TalentTrack’s defaults.', 'talenttrack' ); ?>
                </p>

                <div class="tt-field">
                    <label>
                        <input type="checkbox" name="config[theme_inherit]" value="1" <?php checked( $theme_inherit, '1' ); ?> />
                        <?php esc_html_e( 'Defer typography, link color, headings and plain buttons to the active WP theme.', 'talenttrack' ); ?>
                    </label>
                </div>

                <div class="tt-grid tt-grid-2">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-font-display"><?php esc_html_e( 'Display font', 'talenttrack' ); ?></label>
                        <select id="tt-cfg-font-display" class="tt-input" name="config[font_display]">
                            <?php foreach ( BrandFonts::displayOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_display, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-font-body"><?php esc_html_e( 'Body font', 'talenttrack' ); ?></label>
                        <select id="tt-cfg-font-body" class="tt-input" name="config[font_body]">
                            <?php foreach ( BrandFonts::bodyOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_body, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php
                    foreach ( [
                        'color_accent'  => [ __( 'Accent color',     'talenttrack' ), '#1e88e5' ],
                        'color_danger'  => [ __( 'Danger color',     'talenttrack' ), '#b32d2e' ],
                        'color_warning' => [ __( 'Warning color',    'talenttrack' ), '#dba617' ],
                        'color_success' => [ __( 'Success color',    'talenttrack' ), '#00a32a' ],
                        'color_info'    => [ __( 'Info color',       'talenttrack' ), '#2271b1' ],
                        'color_focus'   => [ __( 'Focus ring color', 'talenttrack' ), '#1e88e5' ],
                    ] as $key => $meta ) :
                        [ $label, $default ] = $meta;
                        ?>
                        <div class="tt-field">
                            <label class="tt-field-label" for="tt-cfg-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                            <input type="color" id="tt-cfg-<?php echo esc_attr( $key ); ?>" name="config[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( QueryHelpers::get_config( $key, $default ) ); ?>" />
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save theme', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderRatingForm(): void {
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="rating">
            <div class="tt-panel">
                <div class="tt-grid tt-grid-3">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-min"><?php esc_html_e( 'Min', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-min" class="tt-input" name="config[rating_min]" min="0" max="10" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_min', '1' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-max"><?php esc_html_e( 'Max', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-max" class="tt-input" name="config[rating_max]" min="1" max="20" step="0.5" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_max', '5' ) ); ?>" />
                    </div>
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-cfg-rating-step"><?php esc_html_e( 'Step', 'talenttrack' ); ?></label>
                        <input type="number" inputmode="decimal" id="tt-cfg-rating-step" class="tt-input" name="config[rating_step]" min="0.1" max="1" step="0.1" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_step', '0.5' ) ); ?>" />
                    </div>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save rating scale', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderMenusForm(): void {
        ?>
        <form id="tt-config-form" data-tt-config-form="1" data-tt-config-sub="menus">
            <div class="tt-panel">
                <p style="margin:0 0 var(--tt-sp-3); color:var(--tt-muted);">
                    <?php esc_html_e( 'TalentTrack admin tools moved to the frontend in v3.12.0. The legacy wp-admin menu entries (Players, Teams, Configuration, …) are hidden by default. Direct URLs to those pages still work as an emergency fallback.', 'talenttrack' ); ?>
                </p>
                <div class="tt-field">
                    <input type="hidden" name="config[show_legacy_menus]" value="0" />
                    <label>
                        <input type="checkbox" name="config[show_legacy_menus]" value="1" <?php checked( QueryHelpers::get_config( 'show_legacy_menus', '0' ), '1' ); ?> />
                        <?php esc_html_e( 'Show legacy wp-admin menus', 'talenttrack' ); ?>
                    </label>
                    <p class="tt-field-hint" style="margin-top:6px;">
                        <?php esc_html_e( 'Re-expose the legacy menu entries in wp-admin for users who prefer them. Plugin still works on both surfaces; this just controls menu visibility.', 'talenttrack' ); ?>
                    </p>
                </div>
            </div>
            <div class="tt-form-actions" style="margin-top:16px;">
                <?php echo FormSaveButton::render( [ 'label' => __( 'Save menus', 'talenttrack' ) ] ); ?>
            </div>
            <div class="tt-form-msg"></div>
        </form>
        <?php
        self::renderConfigJs( false );
    }

    private static function renderConfigJs( bool $with_logo ): void {
        ?>
        <script>
        (function(){
            <?php if ( $with_logo ) : ?>
            if (typeof wp !== 'undefined' && wp.media) {
                var frame;
                var pickBtn = document.getElementById('tt-cfg-logo-pick');
                var clearBtn = document.getElementById('tt-cfg-logo-clear');
                var hidden  = document.getElementById('tt-cfg-logo-url');
                var preview = document.getElementById('tt-cfg-logo-preview');
                if (pickBtn) pickBtn.addEventListener('click', function(){
                    if (!frame) {
                        frame = wp.media({
                            title: '<?php echo esc_js( __( 'Select logo', 'talenttrack' ) ); ?>',
                            button: { text: '<?php echo esc_js( __( 'Use', 'talenttrack' ) ); ?>' },
                            library: { type: 'image' },
                            multiple: false
                        });
                        frame.on('select', function(){
                            var att = frame.state().get('selection').first().toJSON();
                            hidden.value = att.url;
                            preview.innerHTML = '<img src="' + att.url + '" alt="" style="max-height:80px; border-radius:6px; border:1px solid var(--tt-line);" />';
                        });
                    }
                    frame.open();
                });
                if (clearBtn) clearBtn.addEventListener('click', function(){
                    hidden.value = '';
                    preview.innerHTML = '';
                });
            }
            <?php endif; ?>

            var form = document.getElementById('tt-config-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('.tt-save-btn');
                var i18n = (window.TT && window.TT.i18n) || {};
                var rest = window.TT || {};
                if (btn) btn.setAttribute('data-state', 'saving');

                var fd = new FormData(form);
                var config = {};
                fd.forEach(function(value, key){
                    var m = /^config\[(.+)\]$/.exec(key);
                    if (m) config[m[1]] = value;
                });
                if (form.dataset.ttConfigSub === 'theme' && (config.theme_inherit === undefined || config.theme_inherit === '')) config.theme_inherit = '0';
                if (form.dataset.ttConfigSub === 'menus' && (config.show_legacy_menus === undefined || config.show_legacy_menus === '')) config.show_legacy_menus = '0';

                var url = (rest.rest_url || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/') + 'config';
                var headers = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
                if (rest.rest_nonce) headers['X-WP-Nonce'] = rest.rest_nonce;
                fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify({ config: config }) })
                    .then(function(res){ return res.json().then(function(json){ return { ok: res.ok, json: json }; }); })
                    .then(function(r){
                        var msg = form.querySelector('.tt-form-msg');
                        if (r.ok && r.json && r.json.success) {
                            if (btn) btn.setAttribute('data-state', 'saved');
                            if (msg) { msg.classList.add('tt-success'); msg.textContent = i18n.saved || 'Saved.'; }
                            setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 1500);
                        } else {
                            if (btn) btn.setAttribute('data-state', 'error');
                            var errMsg = (r.json && r.json.errors && r.json.errors[0] && r.json.errors[0].message) || i18n.error_generic || 'Error.';
                            if (msg) { msg.classList.add('tt-error'); msg.textContent = errMsg; }
                            setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                        }
                    })
                    .catch(function(){
                        if (btn) btn.setAttribute('data-state', 'error');
                        var msg = form.querySelector('.tt-form-msg');
                        if (msg) { msg.classList.add('tt-error'); msg.textContent = (i18n.network_error || 'Network error.'); }
                        setTimeout(function(){ if (btn) btn.setAttribute('data-state', 'idle'); }, 2500);
                    });
            });
        })();
        </script>
        <?php
    }
}
