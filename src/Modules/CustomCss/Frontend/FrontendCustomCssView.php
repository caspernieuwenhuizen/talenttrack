<?php
namespace TT\Modules\CustomCss\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomCss\CustomCssEnqueue;
use TT\Modules\CustomCss\DesignSystem\ClassCatalogue;
use TT\Modules\CustomCss\DesignSystem\TokenCatalogue;
use TT\Modules\CustomCss\Repositories\CustomCssRepository;
use TT\Modules\CustomCss\Sanitizer\CssSanitizer;
use TT\Modules\CustomCss\Templates\StarterTemplates;
use TT\Modules\CustomCss\VisualEditor;
use TT\Shared\Frontend\BrandFonts;
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

    private const TRANSIENT_PREFIX = 'tt_css_msg_';

    /**
     * Register hooks. Called once from CustomCssModule::boot.
     *
     * The POST handler runs on `template_redirect` so it fires before
     * `wp_head` — without this, the same response would still inject
     * the pre-toggle inline `<style>` even though the DB has been
     * updated. PRG (POST → redirect → GET) guarantees the redirected
     * GET sees the fresh state.
     */
    public static function register(): void {
        add_action( 'template_redirect', [ self::class, 'maybeHandlePost' ], 5 );
    }

    public static function maybeHandlePost(): void {
        // GET-side endpoint: download the saved CSS as a .css file.
        // Lives next to the POST handler so the same template_redirect
        // hook covers both (fires before wp_head, can stream content
        // and exit cleanly without the dashboard chrome rendering).
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'GET' ) {
            self::maybeHandleDownload();
            return;
        }
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) return;
        if ( empty( $_POST['tt_css_action'] ) ) return;
        $tt_view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $tt_view !== 'custom-css' ) return;
        if ( ! current_user_can( 'tt_admin_styling' ) ) return;

        $surface = isset( $_GET['surface'] ) ? CustomCssRepository::sanitizeSurface( (string) $_GET['surface'] ) : CustomCssRepository::SURFACE_FRONTEND;
        $messages = self::handlePost( $surface );

        $stash_key = self::TRANSIENT_PREFIX . get_current_user_id();
        if ( $messages['success'] !== '' || ! empty( $messages['errors'] ) ) {
            set_transient( $stash_key, $messages, 60 );
        }

        // Strip POST-only markers; preserve tt_view/surface/tab so the
        // redirected GET lands on the same tab the user was editing.
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $redirect = remove_query_arg( [ '_wp_http_referer', '_wpnonce' ], $request_uri );
        $redirect = add_query_arg( [ 'tt_msg' => $messages['success'] !== '' ? 'ok' : 'err' ], $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

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
        $messages = self::readStashedMessages();

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
            case 'classes':
                self::renderClassesTab( $surface );
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
     * Read + clear messages stashed by `maybeHandlePost` before the
     * redirect. Returns an empty pair when there's nothing to surface.
     *
     * @return array{success:string, errors:string[]}
     */
    private static function readStashedMessages(): array {
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $stash = get_transient( $key );
        if ( ! is_array( $stash ) ) return [ 'success' => '', 'errors' => [] ];
        delete_transient( $key );
        return [
            'success' => isset( $stash['success'] ) ? (string) $stash['success'] : '',
            'errors'  => isset( $stash['errors'] ) && is_array( $stash['errors'] ) ? array_values( array_map( 'strval', $stash['errors'] ) ) : [],
        ];
    }

    /**
     * @return array{success:string, errors:string[]}
     */
    private static function handlePost( string $surface ): array {
        $out = [ 'success' => '', 'errors' => [] ];
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
        // Use the catalogue-driven dynamic field list (88 tokens at the
        // close of #0075) rather than the v3.73 frozen FIELDS const,
        // which only knew about 36 tokens — every catalogue token added
        // after Sprint 1 was silently dropped from $_POST on save.
        foreach ( VisualEditor::fields() as $field ) {
            $out[ $field ] = isset( $post[ $field ] ) ? (string) wp_unslash( $post[ $field ] ) : '';
        }
        return $out;
    }

    /* ===== Render helpers ===== */

    private static function renderMessages( array $messages ): void {
        if ( $messages['success'] !== '' ) {
            echo '<div class="tt-notice tt-notice-success">' . esc_html( $messages['success'] ) . '</div>';
        }
        foreach ( $messages['errors'] as $err ) {
            echo '<div class="tt-notice tt-notice-error">' . esc_html( $err ) . '</div>';
        }
    }

    private static function renderMutexBanner(): void {
        $inherit_on = \TT\Infrastructure\Query\QueryHelpers::get_config( 'theme_inherit', '0' ) === '1';
        if ( ! $inherit_on ) return;
        echo '<div class="tt-notice tt-notice-warning">';
        echo '<strong>' . esc_html__( 'Theme inheritance is on.', 'talenttrack' ) . '</strong> '
            . esc_html__( 'Frontend currently defers fonts + colours to the active WP theme (#0023). Saving custom CSS for the Frontend surface will turn that off — the two surfaces are mutually exclusive.', 'talenttrack' );
        echo '</div>';
    }

    private static function renderSurfaceSwitcher( string $surface ): void {
        $base = remove_query_arg( [ 'surface', 'tab', '_wp_http_referer' ] );
        $front_url = esc_url( add_query_arg( [ 'surface' => CustomCssRepository::SURFACE_FRONTEND ], $base ) );
        $admin_url = esc_url( add_query_arg( [ 'surface' => CustomCssRepository::SURFACE_ADMIN ], $base ) );
        echo '<nav class="tt-tabbar" role="tablist" aria-label="' . esc_attr__( 'Surface', 'talenttrack' ) . '" style="margin-bottom:14px;">';
        echo '<a class="tt-tab' . ( $surface === CustomCssRepository::SURFACE_FRONTEND ? ' tt-tab-active' : '' ) . '" href="' . $front_url . '">'
            . esc_html__( 'Frontend dashboard', 'talenttrack' ) . '</a>';
        echo '<a class="tt-tab' . ( $surface === CustomCssRepository::SURFACE_ADMIN ? ' tt-tab-active' : '' ) . '" href="' . $admin_url . '">'
            . esc_html__( 'wp-admin pages', 'talenttrack' ) . '</a>';
        echo '</nav>';
    }

    /**
     * Radio-toggle for the per-surface enabled state. Renders two
     * <input type="radio"> + <label> pairs; submitting the form posts
     * the chosen value through the same `toggle_enabled` action handler
     * that's been there since v3.64. The legacy single-button pattern
     * (one button whose label flipped) was unclear about which way
     * clicking moved the state.
     */
    private static function renderEnabledToggle( string $surface, bool $enabled ): void {
        $on_label  = __( 'On',  'talenttrack' );
        $off_label = __( 'Off', 'talenttrack' );
        $explainer = $enabled
            ? __( 'Custom CSS is active for this surface. Switch off to revert to the bundled defaults without losing your saved values.', 'talenttrack' )
            : __( 'Custom CSS is currently inactive for this surface. Switch on to apply your saved values.', 'talenttrack' );
        echo '<form method="post" class="tt-css-toggle-form" style="margin:0 0 16px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="toggle_enabled">';
        echo '<fieldset class="tt-css-toggle" style="border:1px solid var(--tt-line, #e5e7ea); border-radius:8px; padding:8px 12px; display:inline-flex; align-items:center; gap:14px;">';
        echo '<legend style="padding:0 6px; font-weight:600;">' . esc_html__( 'Custom CSS', 'talenttrack' ) . '</legend>';
        echo '<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">';
        echo '<input type="radio" name="enabled" value="1"' . checked( $enabled, true, false ) . ' onchange="this.form.submit()">';
        echo esc_html( $on_label );
        echo '</label>';
        echo '<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">';
        echo '<input type="radio" name="enabled" value="0"' . checked( $enabled, false, false ) . ' onchange="this.form.submit()">';
        echo esc_html( $off_label );
        echo '</label>';
        echo '<noscript><button type="submit" class="tt-btn tt-btn-secondary" style="margin-left:6px;">' . esc_html__( 'Save', 'talenttrack' ) . '</button></noscript>';
        echo '</fieldset>';
        echo '<p class="tt-field-hint" style="margin:6px 0 0; max-width:560px;">' . esc_html( $explainer ) . '</p>';
        echo '</form>';
    }

    private static function renderTabBar( string $surface, string $current ): void {
        $base = remove_query_arg( [ 'tab', '_wp_http_referer', 'prefill' ] );
        $tabs = [
            'visual'  => __( 'Visual settings', 'talenttrack' ),
            'editor'  => __( 'CSS editor', 'talenttrack' ),
            'classes' => __( 'Classes', 'talenttrack' ),
            'upload'  => __( 'Upload + templates', 'talenttrack' ),
            'history' => __( 'History', 'talenttrack' ),
        ];
        echo '<nav class="tt-tabbar" role="tablist" aria-label="' . esc_attr__( 'Authoring path', 'talenttrack' ) . '">';
        foreach ( $tabs as $slug => $label ) {
            $url = esc_url( add_query_arg( [ 'tab' => $slug ], $base ) );
            $cls = $slug === $current ? 'tt-tab tt-tab-active' : 'tt-tab';
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
            echo '<div class="tt-notice tt-notice-warning">';
            echo '<strong>' . esc_html__( 'CSS has been hand-edited.', 'talenttrack' ) . '</strong> ';
            echo esc_html__( 'Saving with the visual editor will overwrite your hand-written rules. Save them as a named preset on the History tab first if you want to restore them later.', 'talenttrack' );
            echo '</div>';
        }

        echo '<form method="post" class="tt-form tt-css-visual-form" style="max-width:760px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="save_visual">';

        $catalogue = TokenCatalogue::all();
        $first = true;
        foreach ( TokenCatalogue::categoriesInOrder() as $category => $category_label ) {
            $keys = TokenCatalogue::keysInCategory( $category );
            if ( empty( $keys ) ) continue;

            $open_attr = $first ? ' open' : '';
            $first = false;
            echo '<details class="tt-css-section"' . $open_attr . ' style="margin-bottom:12px; border:1px solid var(--tt-line, #e5e7ea); border-radius:8px;">';
            echo '<summary style="font-weight:600; padding:10px 14px; cursor:pointer; list-style:revert;">' . esc_html( $category_label ) . '</summary>';
            echo '<div style="padding:12px 14px 16px;">';

            $kind_first = $catalogue[ $keys[0] ]['kind'] ?? '';
            $grid_class = ( $kind_first === TokenCatalogue::KIND_COLOR ) ? 'tt-grid tt-grid-3' : 'tt-grid tt-grid-2';
            echo '<div class="' . esc_attr( $grid_class ) . '">';
            foreach ( $keys as $key ) {
                self::renderTokenField( $catalogue[ $key ], $settings );
            }
            echo '</div>';

            echo '</div>';
            echo '</details>';
        }

        echo '<div class="tt-form-actions" style="margin-top:18px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save visual settings', 'talenttrack' ) . '</button>';
        echo ' <span class="tt-css-preview-status" aria-live="polite" style="margin-left:10px; color:var(--tt-muted, #5b6e75); font-size:13px;">' . esc_html__( 'Live preview is on — changes appear immediately on this page; click Save to persist.', 'talenttrack' ) . '</span>';
        echo '</div>';
        echo '</form>';

        self::renderLivePreviewStyle( $catalogue );
        self::renderLivePreviewScript( $catalogue );
    }

    /**
     * Empty `<style id="tt-css-preview">` placeholder. Lives in the
     * page after the form so the inline JS can target it. Unlike the
     * saved CSS (which lives in `<head>` via CustomCssEnqueue), the
     * preview style sits in `<body>` and only takes effect for the
     * editor surface itself; saving moves the values into `tt_config`
     * which the next page-load will emit through the normal pipeline.
     */
    private static function renderLivePreviewStyle( array $catalogue ): void {
        echo '<style id="tt-css-preview"></style>';
    }

    /**
     * Live preview JS. Walks `[data-css-var]` elements on every input
     * change, applies kind-specific value transforms (px suffix for
     * numbers, preset → CSS-value lookups for shadow + motion, font-
     * family quoting for select-font tokens), and rewrites the
     * `<style id="tt-css-preview">` body. Mirrors the validation +
     * emission shape of `VisualEditor::generateCss` so the visual
     * result on save matches the preview.
     *
     * Catalogue is rendered into the script as a JSON object so we
     * don't need a separate REST round-trip. The preset → CSS-value
     * maps (shadow + motion) are pulled from the corresponding
     * `TokenCatalogue::*Declaration` helpers.
     *
     * @param array<string, mixed> $catalogue
     */
    private static function renderLivePreviewScript( array $catalogue ): void {
        $shadow_map = [];
        foreach ( [ 'none', 'light', 'medium', 'strong' ] as $preset ) {
            $shadow_map[ $preset ] = TokenCatalogue::shadowDeclaration( $preset );
        }
        $duration_map = [];
        foreach ( [ 'fast', 'base', 'slow' ] as $preset ) {
            $duration_map[ $preset ] = TokenCatalogue::motionDurationMs( $preset );
        }
        $easing_map = [];
        foreach ( [ 'standard', 'in', 'out', 'in_out' ] as $preset ) {
            $easing_map[ $preset ] = TokenCatalogue::motionEasing( $preset );
        }

        $js_catalogue = [];
        foreach ( $catalogue as $key => $def ) {
            $entry = [
                'var'  => (string) $def['css_var'],
                'kind' => (string) $def['kind'],
            ];
            if ( isset( $def['unit'] ) && $def['unit'] !== '' ) {
                $entry['unit'] = (string) $def['unit'];
            }
            if ( $key === 'shadow_sm' || $key === 'shadow_md' || $key === 'shadow_lg' ) {
                $entry['kind'] = 'shadow';
                $entry['map'] = $shadow_map;
            } elseif ( $key === 'motion_duration' ) {
                $entry['kind'] = 'motion';
                $entry['map'] = $duration_map;
            } elseif ( $key === 'motion_easing' ) {
                $entry['kind'] = 'motion';
                $entry['map'] = $easing_map;
            } elseif ( $key === 'font_display' || $key === 'font_body' ) {
                $entry['kind'] = 'font';
            }
            $js_catalogue[ $key ] = $entry;
        }
        ?>
        <script>
        (function(){
            var form = document.querySelector('.tt-css-visual-form');
            var styleEl = document.getElementById('tt-css-preview');
            if (!form || !styleEl) return;

            var catalogue = <?php echo wp_json_encode( $js_catalogue ); ?>;
            // Sentinel font values surfaced by BrandFonts; emit empty
            // (let the default stack win) for these. Mirrors
            // BrandFonts::resolveFamily on the server side.
            var FONT_SENTINELS = ['', '__inherit__'];

            function quoteFamily(name) {
                name = String(name || '').trim();
                if (!name) return '';
                if (FONT_SENTINELS.indexOf(name) !== -1) return '';
                return /\s/.test(name) ? "'" + name + "'" : name;
            }

            function emit(key, raw) {
                var def = catalogue[key];
                if (!def) return '';
                if (raw === '' || raw === null || raw === undefined) return '';
                var v = '';
                switch (def.kind) {
                    case 'color':
                        v = String(raw);
                        break;
                    case 'number':
                        if (!/^-?\d+$/.test(String(raw))) return '';
                        v = String(raw) + 'px';
                        break;
                    case 'float':
                        if (isNaN(parseFloat(String(raw)))) return '';
                        v = String(raw) + (def.unit || '');
                        break;
                    case 'shadow':
                    case 'motion':
                        v = (def.map && def.map[String(raw)]) || '';
                        break;
                    case 'font':
                        v = quoteFamily(raw);
                        break;
                    case 'select':
                        // Generic select — emit raw. Currently used by
                        // font_weight_* whose values map 1:1 to CSS.
                        v = String(raw);
                        break;
                    default:
                        return '';
                }
                if (v === '') return '';
                return def.var + ': ' + v + ';';
            }

            function rebuild() {
                var decls = [];
                Object.keys(catalogue).forEach(function(key){
                    var input = form.querySelector('[name="' + key + '"]');
                    if (!input) return;
                    // For colour pickers: skip emission when the value
                    // matches the catalogue default — preserves the
                    // "(unset)" semantics of the saved storage layer.
                    var raw = input.value;
                    var line = emit(key, raw);
                    if (line) decls.push('    ' + line);
                });
                if (decls.length === 0) {
                    styleEl.textContent = '';
                    return;
                }
                styleEl.textContent = '.tt-root {\n' + decls.join('\n') + '\n}\n';
            }

            form.addEventListener('input',  rebuild);
            form.addEventListener('change', rebuild);
            // Initial render so the preview reflects the saved state
            // even before the operator touches anything.
            rebuild();
        })();
        </script>
        <?php
    }

    /**
     * Render one token's form control. Branching on `kind` keeps the
     * mapping in one place — adding a token category later means
     * extending this switch + the catalogue, nothing else in the view.
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $settings
     */
    private static function renderTokenField( array $def, array $settings ): void {
        $key   = (string) $def['key'];
        $id    = 'tt-css-' . str_replace( '_', '-', $key );
        $label = (string) $def['label'];
        $value = (string) ( $settings[ $key ] ?? '' );

        echo '<div class="tt-field"><label class="tt-field-label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        switch ( $def['kind'] ) {
            case TokenCatalogue::KIND_COLOR:
                $current = $value !== '' ? $value : (string) ( $def['default'] ?? '#000000' );
                echo '<input type="color" id="' . esc_attr( $id ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $current ) . '">';
                break;

            case TokenCatalogue::KIND_NUMBER:
                $min  = isset( $def['min'] )  ? (string) $def['min']  : '0';
                $max  = isset( $def['max'] )  ? (string) $def['max']  : '999';
                $step = isset( $def['step'] ) ? (string) $def['step'] : '1';
                echo '<input type="number" inputmode="numeric" id="' . esc_attr( $id ) . '" class="tt-input" name="' . esc_attr( $key ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '" value="' . esc_attr( $value ) . '">';
                break;

            case TokenCatalogue::KIND_FLOAT:
                $min  = isset( $def['min'] )  ? (string) $def['min']  : '0';
                $max  = isset( $def['max'] )  ? (string) $def['max']  : '99';
                $step = isset( $def['step'] ) ? (string) $def['step'] : '0.1';
                echo '<input type="number" inputmode="decimal" id="' . esc_attr( $id ) . '" class="tt-input" name="' . esc_attr( $key ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '" value="' . esc_attr( $value ) . '">';
                break;

            case TokenCatalogue::KIND_SELECT:
                $options = self::resolveSelectOptions( $key, $def );
                echo '<select id="' . esc_attr( $id ) . '" class="tt-input" name="' . esc_attr( $key ) . '">';
                foreach ( $options as $opt_value => $opt_label ) {
                    echo '<option value="' . esc_attr( (string) $opt_value ) . '"' . selected( $value, (string) $opt_value, false ) . '>' . esc_html( (string) $opt_label ) . '</option>';
                }
                echo '</select>';
                break;
        }
        echo '</div>';
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, string>
     */
    private static function resolveSelectOptions( string $key, array $def ): array {
        if ( $key === 'font_display' ) return BrandFonts::displayOptions();
        if ( $key === 'font_body' )    return BrandFonts::bodyOptions();
        if ( isset( $def['options'] ) && is_array( $def['options'] ) ) {
            return array_map( 'strval', $def['options'] );
        }
        return [];
    }

    /**
     * @param array{css:string, enabled:bool, version:int, visual_settings:?array} $live
     */
    private static function renderEditorTab( string $surface, array $live ): void {
        $preview_url  = self::previewUrl( $surface );
        $base_url = remove_query_arg( 'prefill' );
        $download_url = esc_url( wp_nonce_url(
            add_query_arg( [ 'tt_css_download' => '1', 'surface' => $surface ], $base_url ),
            'tt_css_download'
        ) );
        $download_full_url = esc_url( wp_nonce_url(
            add_query_arg( [ 'tt_css_download' => 'full', 'surface' => $surface ], $base_url ),
            'tt_css_download'
        ) );

        $css_body = (string) $live['css'];
        $prefill_class = isset( $_GET['prefill'] ) ? sanitize_html_class( (string) $_GET['prefill'] ) : '';
        if ( $prefill_class !== '' && strpos( $prefill_class, 'tt-' ) === 0 ) {
            // Prepend a starter rule for the picked class so the operator
            // lands on a workable scaffold. Wrapping in .tt-root protects
            // against the host theme winning on specificity.
            $starter = "/* Inserted from Classes tab — edit and Save. */\n.tt-root .{$prefill_class} {\n    /* your overrides here */\n}\n\n";
            $css_body = $starter . $css_body;
        }

        echo '<form method="post" class="tt-form" style="max-width:980px;">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="save_editor">';
        echo '<p class="tt-field-hint" style="margin:0 0 8px;">';
        echo esc_html__( 'Custom CSS rules. Wrap in `.tt-root` for safety. Saved CSS lives in the database; remote @import and external @font-face URLs are blocked. Changes take effect on the next page load.', 'talenttrack' );
        echo '</p>';
        echo '<textarea id="tt-css-body" name="css_body" rows="22" class="tt-input" style="font-family:Menlo, Consolas, monospace; font-size:13px;">' . esc_textarea( $css_body ) . '</textarea>';
        echo '<div class="tt-form-actions" style="margin-top:14px; display:flex; flex-wrap:wrap; gap:8px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Save CSS', 'talenttrack' ) . '</button>';
        echo '<a class="tt-btn tt-btn-secondary" target="_blank" rel="noopener" href="' . esc_url( $preview_url ) . '">' . esc_html__( 'Open preview in new tab', 'talenttrack' ) . '</a>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . $download_url . '" download>' . esc_html__( 'Download saved CSS', 'talenttrack' ) . '</a>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . $download_full_url . '" download>' . esc_html__( 'Download full stylesheet (for designer)', 'talenttrack' ) . '</a>';
        echo '</div>';
        echo '<p class="tt-field-hint" style="margin:8px 0 0; max-width:760px;">';
        echo esc_html__( 'Saved CSS contains only your overrides. Full stylesheet bundles every TalentTrack stylesheet plus your overrides into one file — give it to a designer for a holistic pass, then re-upload via the Upload tab.', 'talenttrack' );
        echo '</p>';
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
        $download_full_url = esc_url( wp_nonce_url(
            add_query_arg( [ 'tt_css_download' => 'full', 'surface' => $surface ], remove_query_arg( 'prefill' ) ),
            'tt_css_download'
        ) );

        echo '<div style="display:grid; grid-template-columns: 1fr; gap:24px; max-width:760px;">';

        echo '<section class="tt-panel">';
        echo '<h3 class="tt-panel-title">' . esc_html__( 'Round-trip with a designer', 'talenttrack' ) . '</h3>';
        echo '<p class="tt-field-hint" style="margin:0 0 10px;">' . esc_html__( 'Step 1 — download the full stylesheet. Step 2 — your designer edits it. Step 3 — re-upload it below. The bundled stylesheets keep loading; your edited file wins on source order, so any selectors the designer touched override the bundled defaults.', 'talenttrack' ) . '</p>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . $download_full_url . '" download>' . esc_html__( 'Download full stylesheet', 'talenttrack' ) . '</a>';
        echo '</section>';

        echo '<section class="tt-panel">';
        echo '<h3 class="tt-panel-title">' . esc_html__( 'Upload a .css file', 'talenttrack' ) . '</h3>';
        echo '<form method="post" enctype="multipart/form-data" class="tt-form">';
        wp_nonce_field( 'tt_custom_css_save', 'tt_css_nonce' );
        echo '<input type="hidden" name="tt_css_action" value="upload">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-css-file">' . esc_html__( 'File (max 500 KB)', 'talenttrack' ) . '</label>';
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

    /**
     * GET handler — streams CSS as a downloadable file. Two modes:
     *
     *   ?tt_css_download=1     → just the saved custom CSS body
     *                            (operator-authored overrides only)
     *
     *   ?tt_css_download=full  → every bundled .tt-* stylesheet plus
     *                            the saved overrides, concatenated
     *                            into a single file. The shape a
     *                            designer needs to do a holistic pass.
     *                            Re-uploaded via the Upload tab the
     *                            file replaces the saved override blob;
     *                            bundled stylesheets keep loading via
     *                            wp_enqueue_style and the upload wins
     *                            on source order (inline <style> emits
     *                            after the <link> tags).
     *
     * Cap-gated, nonce-required, reuses the same `template_redirect`
     * priority as the POST handler so the dashboard chrome doesn't
     * render before the file headers go out.
     */
    private static function maybeHandleDownload(): void {
        $mode = isset( $_GET['tt_css_download'] ) ? (string) $_GET['tt_css_download'] : '';
        if ( $mode === '' ) return;
        $tt_view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $tt_view !== 'custom-css' ) return;
        if ( ! current_user_can( 'tt_admin_styling' ) ) return;
        $nonce = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
        if ( ! wp_verify_nonce( $nonce, 'tt_css_download' ) ) return;

        $surface = isset( $_GET['surface'] ) ? CustomCssRepository::sanitizeSurface( (string) $_GET['surface'] ) : CustomCssRepository::SURFACE_FRONTEND;
        $repo = new CustomCssRepository();
        $live = $repo->getLive( $surface );
        $version = (int) ( $live['version'] ?? 0 );

        if ( $mode === 'full' ) {
            $body = self::buildFullStylesheet( (string) $live['css'], $version );
            $filename = 'talenttrack-full-stylesheet-' . $surface . '-v' . $version . '.css';
        } else {
            $body = (string) $live['css'];
            $filename = 'talenttrack-' . $surface . '-v' . $version . '.css';
        }

        nocache_headers();
        header( 'Content-Type: text/css; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $body ) );
        echo $body;
        exit;
    }

    /**
     * Build the "everything in one file" stylesheet — every bundled
     * `assets/css/*.css` concatenated in the order they're enqueued
     * for the frontend dashboard, with separator banners showing the
     * source file. Saved custom overrides appended at the end.
     *
     * The exported file is a complete picture of TalentTrack's
     * styling surface: a designer can edit holistically and re-upload
     * via the Upload tab. The upload becomes the saved override blob;
     * bundled stylesheets keep loading via wp_enqueue_style and the
     * upload wins on source order at the inline emission. Selectors
     * the designer didn't touch fall through to the bundled rules.
     *
     * Inline-PHP-rendered styles (the `<style>` block in
     * `FrontendConfigurationView::renderTileGrid` etc.) are NOT
     * captured — they're generated per render with PHP-templated
     * values. The export header notes this so the designer doesn't
     * chase missing rules.
     */
    public static function buildFullStylesheet( string $custom_css, int $version ): string {
        $base_dir = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR : '';
        if ( $base_dir === '' || ! is_dir( $base_dir . 'assets/css' ) ) {
            return $custom_css;
        }

        // Load order matches what wp_enqueue_style does on the frontend
        // dashboard: public.css first (base + login), then frontend-
        // admin.css (depends on tt-public), then the per-surface ones
        // in alphabetical order. wp-admin-only sheets (admin.css,
        // persona-dashboard-editor.css) included so designers see the
        // full vocabulary even if the export is from the frontend
        // surface.
        $files = [
            'public.css',
            'frontend-admin.css',
            'persona-dashboard.css',
            'persona-dashboard-editor.css',
            'player-card.css',
            'player-status.css',
            'frontend-profile.css',
            'frontend-activities-manage.css',
            'frontend-journey.css',
            'frontend-threads.css',
            'frontend-mobile.css',
            'admin.css',
        ];

        $out = "/*\n"
            . " * TalentTrack — full stylesheet bundle (v" . TT_VERSION . ", saved override v" . $version . ")\n"
            . " *\n"
            . " * This file concatenates every bundled .tt-* stylesheet plus\n"
            . " * the operator's saved Custom CSS overrides. Edit holistically\n"
            . " * and re-upload via the Custom CSS editor's Upload tab. The\n"
            . " * upload becomes the saved override blob; bundled stylesheets\n"
            . " * keep loading via wp_enqueue_style and the upload wins on\n"
            . " * source order. Selectors you didn't touch fall through to the\n"
            . " * bundled rules.\n"
            . " *\n"
            . " * NOT included: inline <style> blocks rendered from PHP per\n"
            . " * page (Configuration tile grid etc.) — those carry\n"
            . " * server-side templated values and can't live in a static file.\n"
            . " */\n\n";

        foreach ( $files as $name ) {
            $path = $base_dir . 'assets/css/' . $name;
            if ( ! is_readable( $path ) ) continue;
            $contents = (string) @file_get_contents( $path );
            if ( $contents === '' ) continue;
            $out .= "/* ============================================================\n"
                .  " *  assets/css/" . $name . "\n"
                .  " * ============================================================ */\n\n"
                .  $contents
                .  "\n\n";
        }

        if ( $custom_css !== '' ) {
            $out .= "/* ============================================================\n"
                .  " *  Saved Custom CSS overrides (operator-authored)\n"
                .  " * ============================================================ */\n\n"
                .  $custom_css
                .  "\n";
        }

        return $out;
    }

    /**
     * "Classes" tab — index of every `.tt-*` selector declared in the
     * plugin's bundled stylesheets, with a fuzzy-match search bar and
     * one-click "Insert into editor" affordance that prefills a starter
     * rule for the picked class on the CSS-editor tab.
     */
    private static function renderClassesTab( string $surface ): void {
        $rows = ClassCatalogue::all();
        $count = count( $rows );
        $base = remove_query_arg( [ 'tab', 'prefill', '_wp_http_referer' ] );

        echo '<p class="tt-field-hint" style="margin:0 0 12px; max-width:760px;">';
        echo esc_html(
            sprintf(
                /* translators: %d = number of classes discovered */
                _n(
                    '%d TalentTrack class found in the bundled stylesheets. Search by typing — the list filters as you type. Click Insert to prefill the CSS editor with a starter rule for that class.',
                    '%d TalentTrack classes found in the bundled stylesheets. Search by typing — the list filters as you type. Click Insert to prefill the CSS editor with a starter rule for that class.',
                    $count,
                    'talenttrack'
                ),
                $count
            )
        );
        echo '</p>';

        echo '<div class="tt-field" style="max-width:480px; margin-bottom:14px;">';
        echo '<label class="tt-field-label" for="tt-css-class-search">' . esc_html__( 'Search classes', 'talenttrack' ) . '</label>';
        echo '<input type="search" id="tt-css-class-search" class="tt-input" placeholder="' . esc_attr__( 'e.g. card, btn, table…', 'talenttrack' ) . '" autocomplete="off">';
        echo '</div>';

        echo '<ul id="tt-css-class-list" class="tt-css-class-list" style="list-style:none; margin:0; padding:0; max-height:520px; overflow-y:auto; border:1px solid var(--tt-line, #e5e7ea); border-radius:8px;">';
        foreach ( $rows as $row ) {
            $class = (string) $row['class'];
            $files = implode( ', ', $row['files'] );
            $insert_url = esc_url( add_query_arg( [ 'tab' => 'editor', 'prefill' => $class ], $base ) );
            echo '<li class="tt-css-class-item" data-class="' . esc_attr( $class ) . '" style="display:flex; gap:12px; align-items:center; padding:8px 12px; border-bottom:1px solid var(--tt-line, #e5e7ea);">';
            echo '<code style="flex:1; font-family:Menlo, Consolas, monospace; font-size:13px;">.' . esc_html( $class ) . '</code>';
            echo '<span class="tt-css-class-files" style="color:var(--tt-muted, #5b6e75); font-size:12px; flex-shrink:0;">' . esc_html( $files ) . '</span>';
            echo '<a class="tt-btn tt-btn-secondary" style="flex-shrink:0; padding:4px 10px; font-size:12px;" href="' . $insert_url . '">' . esc_html__( 'Insert', 'talenttrack' ) . ' →</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<p id="tt-css-class-empty" style="display:none; color:var(--tt-muted, #5b6e75); margin-top:10px;">' . esc_html__( 'No matches.', 'talenttrack' ) . '</p>';

        ?>
        <script>
        (function(){
            var input  = document.getElementById('tt-css-class-search');
            var list   = document.getElementById('tt-css-class-list');
            var empty  = document.getElementById('tt-css-class-empty');
            if (!input || !list) return;
            var items = Array.prototype.slice.call(list.querySelectorAll('.tt-css-class-item'));

            // Fuzzy match — query characters must appear in order in the
            // class name. Empty query matches everything.
            function fuzzy(haystack, needle) {
                if (!needle) return true;
                haystack = haystack.toLowerCase();
                needle   = needle.toLowerCase();
                var i = 0;
                for (var j = 0; j < haystack.length && i < needle.length; j++) {
                    if (haystack[j] === needle[i]) i++;
                }
                return i === needle.length;
            }

            function filter() {
                var q = input.value.trim();
                var visible = 0;
                items.forEach(function(li){
                    var cls = li.getAttribute('data-class') || '';
                    var match = fuzzy(cls, q);
                    li.style.display = match ? '' : 'none';
                    if (match) visible++;
                });
                empty.style.display = visible === 0 ? 'block' : 'none';
            }

            input.addEventListener('input', filter);
        })();
        </script>
        <?php
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
