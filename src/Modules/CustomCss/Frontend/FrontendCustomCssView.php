<?php
namespace TT\Modules\CustomCss\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomCss\CustomCssEnqueue;
use TT\Modules\CustomCss\Repositories\CustomCssRepository;
use TT\Modules\CustomCss\Sanitizer\CssSanitizer;
use TT\Modules\CustomCss\Templates\StarterTemplates;
use TT\Modules\CustomCss\VisualEditor;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendCustomCssView — the #0064 custom-CSS authoring surface.
 *
 * Single page with four tabs:
 *   - **Visual** (Path C) — colour pickers, font dropdowns, weight,
 *     corner radius, spacing scale, shadow strength. Save round-trips
 *     into a generated CSS body that lives in the same storage as
 *     Paths A and B.
 *   - **CSS editor** (Path B) — textarea (CodeMirror via
 *     `wp_enqueue_code_editor`) with a Preview button that opens the
 *     `[tt_dashboard]` page in a new tab carrying a draft override.
 *   - **Upload** (Path A) — file upload + a "Apply starter template"
 *     section. Uploads run through `CssSanitizer` before save.
 *   - **History** — last 10 auto-saves + named presets. Click "Revert"
 *     to restore an earlier save (which itself becomes a new auto row,
 *     so the revert is undoable).
 *
 * Surface tabs (Frontend / wp-admin) sit at the top; switching tab
 * routes between `?tt_view=custom-css&surface=frontend` and
 * `&surface=admin`.
 *
 * Capability: `tt_admin_styling`. Mutex with #0023 theme-inherit
 * (`theme_inherit` config key) is surfaced as a banner — picking
 * Custom CSS disables the inherit toggle in the Branding sub-tile.
 */
class FrontendCustomCssView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_admin_styling' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to manage custom styling.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
        wp_enqueue_script( 'wp-theme-plugin-editor' );
        wp_enqueue_script( 'wp-codemirror' );
        wp_enqueue_style( 'wp-codemirror' );

        $surface = isset( $_GET['surface'] ) ? CustomCssRepository::sanitizeSurface( (string) $_GET['surface'] ) : CustomCssRepository::SURFACE_FRONTEND;
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'visual';
        $messages = self::handlePost( $surface );

        $repo = new CustomCssRepository();
        $live = $repo->getLive( $surface );

        self::renderHeader( __( 'Custom CSS', 'talenttrack' ) );
        self::renderMutexBanner();
        self::renderSurfaceSwitcher( $surface );
        self::renderEnabledToggle( $surface, $live['enabled'] );
        self::renderMessages( $messages );

        self::renderTabBar( $surface, $tab );

        switch ( $tab ) {
            case 'editor':
                self::renderEditorTab( $surface, $live );
                break;
            case 'upload':
                self::renderUploadTab( $surface, $live );
                break;
            case 'history':
                self::renderHistoryTab( $surface, $repo->listHistory( $surface ) );
                break;
            case 'visual':
            default:
                self::renderVisualTab( $surface, $live );
        }

        self::renderSafeModeFooter();
    }

    /* ===== POST handling ===== */

    /**
     * @return array{success:string, errors:string[]}
     */
    private static function handlePost( string $surface ): array {
        $out = [ 'success' => '', 'errors' => [] ];
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return $out;
        $action = isset( $_POST['tt_css_action'] ) ? sanitize_key( (string) $_POST['tt_css_action'] ) : '';
        if ( $action === '' ) return $out;
        if ( ! isset( $_POST['tt_css_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_css_nonce'] ) ), 'tt_custom_css_save' ) ) {
            $out['errors'][] = __( 'Security check failed. Reload and try again.', 'talenttrack' );
            return $out;
        }
        $repo = new CustomCssRepository();
        $sanitizer = new CssSanitizer();
        $user_id = get_current_user_id();
        $live = $repo->getLive( $surface );

        switch ( $action ) {
            case 'toggle_enabled': {
                $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1';
                $repo->saveLive( $surface, $live['css'], $enabled, $live['visual_settings'], $user_id );
                $out['success'] = $enabled
                    ? __( 'Custom CSS is now active for this surface.', 'talenttrack' )
                    : __( 'Custom CSS is now off; the plugin\'s default styling is back.', 'talenttrack' );
                // Mutex with #0023 theme inheritance — turning custom CSS on
                // for the frontend forces the theme-inherit toggle off so the
                // two never compete on the same surface.
                if ( $enabled && $surface === CustomCssRepository::SURFACE_FRONTEND ) {
                    \TT\Infrastructure\Query\QueryHelpers::set_config( 'theme_inherit', '0' );
                }
                return $out;
            }
            case 'save_visual': {
                $settings = self::collectVisualSettings( $_POST );
                $css = VisualEditor::generateCss( $settings );
                $sanitized = $sanitizer->sanitize( $css );
                if ( is_wp_error( $sanitized ) ) {
                    $out['errors'][] = (string) $sanitized->get_error_message();
                    return $out;
                }
                $repo->saveLive( $surface, $sanitized, $live['enabled'], $settings, $user_id );
                $out['success'] = __( 'Visual settings saved.', 'talenttrack' );
                return $out;
            }
            case 'save_editor': {
                $css = isset( $_POST['css_body'] ) ? (string) wp_unslash( $_POST['css_body'] ) : '';
                $sanitized = $sanitizer->sanitize( $css );
                if ( is_wp_error( $sanitized ) ) {
                    $out['errors'][] = (string) $sanitized->get_error_message();
                    return $out;
                }
                // Hand-edited CSS clears the visual_settings imprint (Path C
                // round-trip rule).
                $repo->saveLive( $surface, $sanitized, $live['enabled'], null, $user_id );
                $out['success'] = __( 'CSS saved.', 'talenttrack' );
                return $out;
            }
            case 'apply_template': {
                $key = isset( $_POST['template_key'] ) ? sanitize_key( (string) $_POST['template_key'] ) : '';
                $tpl = StarterTemplates::find( $key );
                if ( ! $tpl ) {
                    $out['errors'][] = __( 'Unknown starter template.', 'talenttrack' );
                    return $out;
                }
                $sanitized = $sanitizer->sanitize( (string) $tpl['css'] );
                if ( is_wp_error( $sanitized ) ) {
                    $out['errors'][] = (string) $sanitized->get_error_message();
                    return $out;
                }
                $repo->saveLive( $surface, $sanitized, $live['enabled'], null, $user_id );
                $out['success'] = sprintf(
                    /* translators: %s = template label */
                    __( 'Applied "%s" starter template.', 'talenttrack' ),
                    (string) $tpl['label']
                );
                return $out;
            }
            case 'upload': {
                if ( ! isset( $_FILES['css_file'] ) || ! is_array( $_FILES['css_file'] ) || (int) ( $_FILES['css_file']['error'] ?? 0 ) !== UPLOAD_ERR_OK ) {
                    $out['errors'][] = __( 'No file uploaded, or the upload failed.', 'talenttrack' );
                    return $out;
                }
                $tmp = (string) $_FILES['css_file']['tmp_name'];
                $name = (string) $_FILES['css_file']['name'];
                if ( substr( strtolower( $name ), -4 ) !== '.css' ) {
                    $out['errors'][] = __( 'Only .css files are accepted.', 'talenttrack' );
                    return $out;
                }
                $contents = (string) file_get_contents( $tmp );
                $sanitized = $sanitizer->sanitize( $contents );
                if ( is_wp_error( $sanitized ) ) {
                    $out['errors'][] = (string) $sanitized->get_error_message();
                    return $out;
                }
                $repo->saveLive( $surface, $sanitized, $live['enabled'], null, $user_id );
                $out['success'] = sprintf(
                    /* translators: %s = uploaded filename */
                    __( 'Uploaded %s.', 'talenttrack' ),
                    sanitize_file_name( $name )
                );
                return $out;
            }
            case 'save_preset': {
                $name = isset( $_POST['preset_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['preset_name'] ) ) : '';
                if ( $name === '' ) {
                    $out['errors'][] = __( 'Give the preset a name first.', 'talenttrack' );
                    return $out;
                }
                $repo->savePreset( $surface, $name, $user_id );
                $out['success'] = sprintf(
                    /* translators: %s = preset name */
                    __( 'Preset "%s" saved.', 'talenttrack' ),
                    $name
                );
                return $out;
            }
            case 'revert': {
                $id = isset( $_POST['history_id'] ) ? (int) $_POST['history_id'] : 0;
                $version = $repo->revertTo( $id, $user_id );
                if ( $version <= 0 ) {
                    $out['errors'][] = __( 'Could not revert to that snapshot.', 'talenttrack' );
                    return $out;
                }
                $out['success'] = __( 'Reverted to the selected snapshot.', 'talenttrack' );
                return $out;
            }
            case 'delete_history': {
                $id = isset( $_POST['history_id'] ) ? (int) $_POST['history_id'] : 0;
                if ( $repo->deleteHistoryRow( $id ) ) {
                    $out['success'] = __( 'Snapshot deleted.', 'talenttrack' );
                } else {
                    $out['errors'][] = __( 'Could not delete the snapshot.', 'talenttrack' );
                }
                return $out;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private static function collectVisualSettings( array $post ): array {
        $out = [];
        foreach ( VisualEditor::FIELDS as $field ) {
            $out[ $field ] = isset( $post[ $field ] ) ? (string) wp_unslash( $post[ $field ] ) : '';
        }
        return $out;
    }

    /* ===== Render helpers ===== */

    private static function renderMessages( array $messages ): void {
        if ( $messages['success'] !== '' ) {
            echo '<div class="tt-notice tt-notice-success" style="background:#dff5e1; color:#1a6b2c; border-left:4px solid #1a6b2c; padding:8px 12px; margin-bottom:12px;">'
                . esc_html( $messages['success'] ) . '</div>';
        }
        foreach ( $messages['errors'] as $err ) {
            echo '<div class="tt-notice tt-notice-error" style="background:#fde2e2; color:#a02828; border-left:4px solid #a02828; padding:8px 12px; margin-bottom:12px;">'
                . esc_html( $err ) . '</div>';
        }
    }

    private static function renderMutexBanner(): void {
        $inherit_on = \TT\Infrastructure\Query\QueryHelpers::get_config( 'theme_inherit', '0' ) === '1';
        if ( ! $inherit_on ) return;
        echo '<div class="tt-notice" style="background:#fdf3d8; color:#7a5a05; border-left:4px solid #c9962a; padding:10px 14px; margin-bottom:16px;">';
        echo '<strong>' . esc_html__( 'Theme inheritance is on.', 'talenttrack' ) . '</strong> '
            . esc_html__( 'Frontend currently defers fonts + colours to the active WP theme (#0023). Saving custom CSS for the Frontend surface will turn that off — the two surfaces are mutually exclusive.', 'talenttrack' );
        echo '</div>';
    }

    private static function renderSurfaceSwitcher( string $surface ): void {
        $base = remove_query_arg( [ 'surface', 'tab', '_wp_http_referer' ] );
        $front_url = esc_url( add_query_arg( [ 'surface' => CustomCssRepository::SURFACE_FRONTEND ], $base ) );
        $admin_url = esc_url( add_query_arg( [ 'surface' => CustomCssRepository::SURFACE_ADMIN ], $base ) );
        echo '<nav class="tt-tabbar" role="tablist" aria-label="' . esc_attr__( 'Surface', 'talenttrack' ) . '" style="margin-bottom:14px;">';
        echo '<a class="tt-tab' . ( $surface === CustomCssRepository::SURFACE_FRONTEND ? ' tt-tab-current' : '' ) . '" href="' . $front_url . '">'
            . esc_html__( 'Frontend dashboard', 'talenttrack' ) . '</a>';
        echo '<a class="tt-tab' . ( $surface === CustomCssRepository::SURFACE_ADMIN ? ' tt-tab-current' : '' ) . '" href="' . $admin_url . '">'
            . esc_html__( 'wp-admin pages', 'talenttrack' ) . '</a>';
        echo '</nav>';
    }

    private static function renderEnabledToggle( string $surface, bool $enabled ): void {
        echo '<form method="post" style="margin:0 0 16px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="toggle_enabled">';
        echo '<input type="hidden" name="enabled" value="' . ( $enabled ? '0' : '1' ) . '">';
        $label = $enabled
            ? __( 'Custom CSS is ON for this surface — click to turn OFF.', 'talenttrack' )
            : __( 'Custom CSS is OFF for this surface — click to turn ON.', 'talenttrack' );
        $color = $enabled ? '#1a6b2c' : '#7a5a05';
        echo '<button type="submit" class="tt-btn" style="border:1px solid ' . esc_attr( $color ) . '; color:' . esc_attr( $color ) . ';">';
        echo esc_html( $label );
        echo '</button>';
        echo '</form>';
    }

    private static function renderTabBar( string $surface, string $current ): void {
        $base = remove_query_arg( [ 'tab', '_wp_http_referer' ] );
        $tabs = [
            'visual'  => __( 'Visual settings', 'talenttrack' ),
            'editor'  => __( 'CSS editor', 'talenttrack' ),
            'upload'  => __( 'Upload + templates', 'talenttrack' ),
            'history' => __( 'History', 'talenttrack' ),
        ];
        echo '<nav class="tt-tabbar" role="tablist" aria-label="' . esc_attr__( 'Authoring path', 'talenttrack' ) . '">';
        foreach ( $tabs as $slug => $label ) {
            $url = esc_url( add_query_arg( [ 'tab' => $slug ], $base ) );
            $cls = $slug === $current ? 'tt-tab tt-tab-current' : 'tt-tab';
            echo '<a class="' . esc_attr( $cls ) . '" href="' . $url . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * @param array{css:string, enabled:bool, version:int, visual_settings:?array} $live
     */
    private static function renderVisualTab( string $surface, array $live ): void {
        $settings = $live['visual_settings'] ?? [];
        $is_hand_edited = $live['css'] !== '' && ! VisualEditor::isGenerated( $live['css'] );
        if ( $is_hand_edited ) {
            echo '<div class="tt-notice" style="background:#fdf3d8; color:#7a5a05; border-left:4px solid #c9962a; padding:10px 14px; margin-bottom:14px;">';
            echo '<strong>' . esc_html__( 'CSS has been hand-edited.', 'talenttrack' ) . '</strong> ';
            echo esc_html__( 'Saving with the visual editor will overwrite your hand-written rules. Save them as a named preset on the History tab first if you want to restore them later.', 'talenttrack' );
            echo '</div>';
        }
        echo '<form method="post" class="tt-form" style="max-width:760px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="save_visual">';

        echo '<h3 style="margin:0 0 12px;">' . esc_html__( 'Colours', 'talenttrack' ) . '</h3>';
        echo '<div class="tt-grid tt-grid-3">';
        foreach ( [
            'primary_color'    => __( 'Primary', 'talenttrack' ),
            'secondary_color'  => __( 'Secondary', 'talenttrack' ),
            'accent_color'     => __( 'Accent', 'talenttrack' ),
            'success_color'    => __( 'Success', 'talenttrack' ),
            'info_color'       => __( 'Info', 'talenttrack' ),
            'warning_color'    => __( 'Warning', 'talenttrack' ),
            'danger_color'     => __( 'Danger', 'talenttrack' ),
            'focus_ring_color' => __( 'Focus ring', 'talenttrack' ),
            'background_color' => __( 'Background', 'talenttrack' ),
            'surface_color'    => __( 'Card surface', 'talenttrack' ),
            'text_color'       => __( 'Text', 'talenttrack' ),
            'muted_color'      => __( 'Muted text', 'talenttrack' ),
            'line_color'       => __( 'Lines + borders', 'talenttrack' ),
        ] as $field => $label ) {
            $value = (string) ( $settings[ $field ] ?? '' );
            echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label>';
            echo '<input type="color" id="tt-css-' . esc_attr( $field ) . '" name="' . esc_attr( $field ) . '" value="' . esc_attr( $value !== '' ? $value : '#000000' ) . '">';
            echo '</div>';
        }
        echo '</div>';

        echo '<h3 style="margin:18px 0 12px;">' . esc_html__( 'Typography', 'talenttrack' ) . '</h3>';
        echo '<div class="tt-grid tt-grid-2">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-font-display">' . esc_html__( 'Display font (headings)', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-css-font-display" name="font_display" class="tt-input" placeholder="\'Oswald\', sans-serif" value="' . esc_attr( (string) ( $settings['font_display'] ?? '' ) ) . '">';
        echo '</div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-font-body">' . esc_html__( 'Body font', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-css-font-body" name="font_body" class="tt-input" placeholder="\'Inter\', system-ui, sans-serif" value="' . esc_attr( (string) ( $settings['font_body'] ?? '' ) ) . '">';
        echo '</div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-fw-body">' . esc_html__( 'Body weight', 'talenttrack' ) . '</label>';
        echo '<select id="tt-css-fw-body" name="font_weight_body" class="tt-input">';
        foreach ( [ '', '300', '400', '500', '600' ] as $w ) {
            $sel = ( (string) ( $settings['font_weight_body'] ?? '' ) === $w ) ? ' selected' : '';
            $lbl = $w === '' ? __( '(default)', 'talenttrack' ) : $w;
            echo '<option value="' . esc_attr( $w ) . '"' . $sel . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-fw-heading">' . esc_html__( 'Heading weight', 'talenttrack' ) . '</label>';
        echo '<select id="tt-css-fw-heading" name="font_weight_heading" class="tt-input">';
        foreach ( [ '', '500', '600', '700', '800' ] as $w ) {
            $sel = ( (string) ( $settings['font_weight_heading'] ?? '' ) === $w ) ? ' selected' : '';
            $lbl = $w === '' ? __( '(default)', 'talenttrack' ) : $w;
            echo '<option value="' . esc_attr( $w ) . '"' . $sel . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select></div>';
        echo '</div>';

        echo '<h3 style="margin:18px 0 12px;">' . esc_html__( 'Shape + spacing', 'talenttrack' ) . '</h3>';
        echo '<div class="tt-grid tt-grid-3">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-r-md">' . esc_html__( 'Corner radius — medium (px)', 'talenttrack' ) . '</label>';
        echo '<input type="number" inputmode="numeric" id="tt-css-r-md" name="corner_radius_md" class="tt-input" min="0" max="32" value="' . esc_attr( (string) ( $settings['corner_radius_md'] ?? '' ) ) . '"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-r-lg">' . esc_html__( 'Corner radius — large (px)', 'talenttrack' ) . '</label>';
        echo '<input type="number" inputmode="numeric" id="tt-css-r-lg" name="corner_radius_lg" class="tt-input" min="0" max="40" value="' . esc_attr( (string) ( $settings['corner_radius_lg'] ?? '' ) ) . '"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-spacing">' . esc_html__( 'Spacing scale (0.6–1.6)', 'talenttrack' ) . '</label>';
        echo '<input type="number" inputmode="decimal" step="0.05" id="tt-css-spacing" name="spacing_scale" class="tt-input" min="0.6" max="1.6" value="' . esc_attr( (string) ( $settings['spacing_scale'] ?? '' ) ) . '"></div>';
        echo '</div>';

        echo '<div class="tt-field" style="margin-top:14px;"><label class="tt-field-label" for="tt-css-shadow">' . esc_html__( 'Card shadow', 'talenttrack' ) . '</label>';
        echo '<select id="tt-css-shadow" name="shadow_strength" class="tt-input" style="max-width:240px;">';
        foreach ( [
            ''       => __( '(default)', 'talenttrack' ),
            'none'   => __( 'None — flat cards', 'talenttrack' ),
            'light'  => __( 'Light', 'talenttrack' ),
            'strong' => __( 'Strong', 'talenttrack' ),
        ] as $val => $label ) {
            $sel = ( (string) ( $settings['shadow_strength'] ?? '' ) === $val ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $val ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="tt-form-actions" style="margin-top:18px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save visual settings', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    /**
     * @param array{css:string, enabled:bool, version:int, visual_settings:?array} $live
     */
    private static function renderEditorTab( string $surface, array $live ): void {
        $preview_url = self::previewUrl( $surface );
        echo '<form method="post" class="tt-form" style="max-width:980px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="save_editor">';
        echo '<p class="tt-field-hint" style="margin:0 0 8px;">';
        echo esc_html__( 'Custom CSS rules. Wrap in `.tt-root` for safety. Saved CSS lives in the database; remote @import and external @font-face URLs are blocked. Changes take effect on the next page load.', 'talenttrack' );
        echo '</p>';
        echo '<textarea id="tt-css-body" name="css_body" rows="22" class="tt-input" style="font-family:Menlo, Consolas, monospace; font-size:13px;">' . esc_textarea( (string) $live['css'] ) . '</textarea>';
        echo '<div class="tt-form-actions" style="margin-top:14px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save CSS', 'talenttrack' ) . '</button> ';
        echo '<a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="' . esc_url( $preview_url ) . '">' . esc_html__( 'Open preview in new tab', 'talenttrack' ) . '</a>';
        echo '</div>';
        echo '</form>';

        // Initialise CodeMirror via the editor settings WP exposes through
        // wp_enqueue_code_editor.
        ?>
        <script>
        (function(){
            if ( typeof wp === 'undefined' || ! wp.codeEditor ) return;
            var settings = wp.codeEditor.defaultSettings ? Object.assign({}, wp.codeEditor.defaultSettings) : {};
            settings.codemirror = Object.assign({}, settings.codemirror || {}, {
                mode: 'css',
                lineNumbers: true,
                lineWrapping: true,
                indentUnit: 4,
                tabSize: 4,
            });
            var ta = document.getElementById('tt-css-body');
            if (ta) wp.codeEditor.initialize(ta, settings);
        })();
        </script>
        <?php
    }

    /**
     * @param array{css:string, enabled:bool, version:int, visual_settings:?array} $live
     */
    private static function renderUploadTab( string $surface, array $live ): void {
        echo '<div style="display:grid; grid-template-columns: 1fr; gap:24px; max-width:760px;">';

        echo '<section class="tt-panel">';
        echo '<h3 class="tt-panel-title">' . esc_html__( 'Upload a .css file', 'talenttrack' ) . '</h3>';
        echo '<form method="post" enctype="multipart/form-data" class="tt-form">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="upload">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-file">' . esc_html__( 'File (max 200 KB)', 'talenttrack' ) . '</label>';
        echo '<input type="file" id="tt-css-file" name="css_file" accept=".css,text/css" required>';
        echo '<p class="tt-field-hint" style="margin-top:6px;">'
            . esc_html__( 'The file replaces the live CSS for this surface. Sanitization runs before save — JavaScript URLs, expression(), behavior:, remote @import and external @font-face URLs are rejected.', 'talenttrack' )
            . '</p></div>';
        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Upload', 'talenttrack' ) . '</button></div>';
        echo '</form>';
        echo '</section>';

        echo '<section class="tt-panel">';
        echo '<h3 class="tt-panel-title">' . esc_html__( 'Apply a starter template', 'talenttrack' ) . '</h3>';
        echo '<p class="tt-field-hint" style="margin:0 0 12px;">' . esc_html__( 'Three light-leaning starting points. Each replaces the live CSS for this surface — use the History tab to revert if needed.', 'talenttrack' ) . '</p>';
        echo '<div class="tt-grid tt-grid-2" style="gap:14px;">';
        foreach ( StarterTemplates::all() as $key => $tpl ) {
            echo '<form method="post" class="tt-panel" style="border:1px solid var(--tt-line, #e5e7ea); padding:14px; border-radius:10px;">';
            wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
            echo '<input type="hidden" name="tt_css_action" value="apply_template">';
            echo '<input type="hidden" name="template_key" value="' . esc_attr( $key ) . '">';
            echo '<h4 style="margin:0 0 6px;">' . esc_html( (string) $tpl['label'] ) . '</h4>';
            echo '<p style="margin:0 0 10px; font-size:13px; color:var(--tt-muted, #5b6e75);">' . esc_html( (string) $tpl['description'] ) . '</p>';
            echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button>';
            echo '</form>';
        }
        echo '</div>';
        echo '</section>';

        echo '</div>';
    }

    /**
     * @param object[] $rows
     */
    private static function renderHistoryTab( string $surface, array $rows ): void {
        echo '<form method="post" style="margin:0 0 18px; max-width:760px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="save_preset">';
        echo '<div class="tt-field" style="display:flex; gap:8px; align-items:flex-end;">';
        echo '<div style="flex:1;"><label class="tt-field-label" for="tt-css-preset-name">' . esc_html__( 'Save current CSS as a named preset', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-css-preset-name" name="preset_name" class="tt-input" maxlength="120" placeholder="' . esc_attr__( 'e.g. summer-tournament-2026', 'talenttrack' ) . '"></div>';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save preset', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '<p class="tt-field-hint" style="margin:6px 0 0;">' . esc_html__( 'Named presets do not count against the rolling 10-save cap and are kept until you delete them.', 'talenttrack' ) . '</p>';
        echo '</form>';

        if ( empty( $rows ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No history yet. Snapshots appear here automatically every time you save.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<table class="tt-table" style="width:100%; max-width:980px;"><thead><tr>';
        echo '<th>' . esc_html__( 'When', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Kind', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Saved by', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Size', 'talenttrack' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'talenttrack' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $u = get_userdata( (int) $r->saved_by_user_id );
            $author = $u ? (string) $u->display_name : '#' . (int) $r->saved_by_user_id;
            $kind = $r->kind === CustomCssRepository::KIND_PRESET
                ? '★ ' . esc_html( (string) ( $r->preset_name ?? '' ) )
                : esc_html__( 'auto', 'talenttrack' );
            echo '<tr>';
            echo '<td>' . esc_html( (string) $r->saved_at ) . '</td>';
            echo '<td>' . $kind . '</td>';
            echo '<td>' . esc_html( $author ) . '</td>';
            echo '<td>' . esc_html( size_format( (int) $r->byte_count ) ) . '</td>';
            echo '<td style="display:flex; gap:6px;">';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
            echo '<input type="hidden" name="tt_css_action" value="revert">';
            echo '<input type="hidden" name="history_id" value="' . esc_attr( (string) $r->id ) . '">';
            echo '<button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Revert', 'talenttrack' ) . '</button>';
            echo '</form>';
            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this snapshot?', 'talenttrack' ) ) . '\');">';
            wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
            echo '<input type="hidden" name="tt_css_action" value="delete_history">';
            echo '<input type="hidden" name="history_id" value="' . esc_attr( (string) $r->id ) . '">';
            echo '<button type="submit" class="tt-btn tt-btn-link" style="color:#a02828;">' . esc_html__( 'Delete', 'talenttrack' ) . '</button>';
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderSafeModeFooter(): void {
        $base = home_url( '/' );
        $safe = esc_url( add_query_arg( [ 'tt_safe_css' => '1' ], $base ) );
        echo '<p class="tt-field-hint" style="margin-top:32px; max-width:760px;">';
        echo '<strong>' . esc_html__( 'Stuck behind a broken save?', 'talenttrack' ) . '</strong> '
            . sprintf(
                /* translators: %s = safe-mode URL */
                esc_html__( 'Open the dashboard with the safe-mode URL: %s — it skips custom CSS so you can recover without database access.', 'talenttrack' ),
                '<code><a href="' . $safe . '">' . esc_html( $safe ) . '</a></code>'
            );
        echo '</p>';
    }

    private static function previewUrl( string $surface ): string {
        if ( $surface === CustomCssRepository::SURFACE_ADMIN ) {
            return admin_url( 'admin.php?page=talenttrack' );
        }
        return home_url( '/' );
    }
}
