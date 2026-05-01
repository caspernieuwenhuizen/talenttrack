<?php
namespace TT\Modules\CustomCss\DesignSystem;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TokenCatalogue — single source of truth for the design-system
 * tokens the #0075 visual editor exposes.
 *
 * Each token has a stable storage key (`primary_color`, `shadow_sm`,
 * …) and a CSS custom property the consumer stylesheets read
 * (`--tt-primary`, `--tt-shadow-sm`). The category groups tokens for
 * the editor's accordion layout; the kind drives which form control
 * the editor renders. The default value is what the renderer falls
 * back to if the operator hasn't picked one yet — it doubles as the
 * initial value for the form control.
 *
 * Sprint 1 PR 1 ships the foundation: 13 colour tokens + 4 colour
 * state variants + 4 status colour tokens + 4 status colour tints +
 * 2 fonts + 2 font weights + 2 corner radii + 1 spacing scale + 1
 * shadow shorthand + 3 explicit shadow tokens + 3 motion duration
 * tokens. Categories the spec lists but this PR doesn't expose yet
 * (z-index, layout, breakpoints) get added in subsequent PRs.
 *
 * The catalogue is intentionally a static array, not a database
 * table or `tt_lookups` row — these are TalentTrack-internal
 * primitives, not user-editable taxonomy. Adding or renaming a token
 * is a code change that ships with the consumer CSS that uses it.
 */
final class TokenCatalogue {

    public const KIND_COLOR  = 'color';
    public const KIND_NUMBER = 'number';
    public const KIND_FLOAT  = 'float';
    public const KIND_SELECT = 'select';

    public const CATEGORY_BRAND       = 'brand';
    public const CATEGORY_STATUS      = 'status';
    public const CATEGORY_SURFACE     = 'surface';
    public const CATEGORY_TEXT        = 'text';
    public const CATEGORY_TYPOGRAPHY  = 'typography';
    public const CATEGORY_TYPE_SCALE  = 'type_scale';
    public const CATEGORY_SHAPE       = 'shape';
    public const CATEGORY_SHADOW      = 'shadow';
    public const CATEGORY_MOTION      = 'motion';
    public const CATEGORY_BUTTONS     = 'buttons';
    public const CATEGORY_FORMS       = 'forms';

    /**
     * @return array<string, array{
     *     key: string,
     *     css_var: string,
     *     category: string,
     *     kind: string,
     *     label: string,
     *     default: string,
     *     min?: float|int,
     *     max?: float|int,
     *     step?: float|int,
     *     options?: array<string,string>,
     * }>
     */
    public static function all(): array {
        $tokens = [];
        foreach ( self::definitions() as $def ) {
            $tokens[ $def['key'] ] = $def;
        }
        return $tokens;
    }

    /**
     * @return list<array{
     *     key: string,
     *     css_var: string,
     *     category: string,
     *     kind: string,
     *     label: string,
     *     default: string,
     *     min?: float|int,
     *     max?: float|int,
     *     step?: float|int,
     *     options?: array<string,string>,
     * }>
     */
    public static function definitions(): array {
        return [
            // --- Brand ---
            [ 'key' => 'primary_color',         'css_var' => '--tt-primary',          'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Primary',                'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'primary_hover_color',   'css_var' => '--tt-primary-hover',    'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Primary — hover',        'talenttrack' ), 'default' => '#0a3327' ],
            [ 'key' => 'secondary_color',       'css_var' => '--tt-secondary',        'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Secondary',              'talenttrack' ), 'default' => '#e8b624' ],
            [ 'key' => 'secondary_hover_color', 'css_var' => '--tt-secondary-hover',  'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Secondary — hover',      'talenttrack' ), 'default' => '#d1a31a' ],
            [ 'key' => 'accent_color',          'css_var' => '--tt-accent',           'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Accent',                 'talenttrack' ), 'default' => '#1e88e5' ],
            [ 'key' => 'accent_hover_color',    'css_var' => '--tt-accent-hover',     'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Accent — hover',         'talenttrack' ), 'default' => '#1976d2' ],
            [ 'key' => 'focus_ring_color',      'css_var' => '--tt-focus-ring',       'category' => self::CATEGORY_BRAND,    'kind' => self::KIND_COLOR,  'label' => __( 'Focus ring',             'talenttrack' ), 'default' => '#1e88e5' ],

            // --- Status ---
            [ 'key' => 'success_color',         'css_var' => '--tt-success',          'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Success',                'talenttrack' ), 'default' => '#00a32a' ],
            [ 'key' => 'success_subtle_color',  'css_var' => '--tt-success-subtle',   'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Success — subtle bg',    'talenttrack' ), 'default' => '#dff5e1' ],
            [ 'key' => 'warning_color',         'css_var' => '--tt-warning',          'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Warning',                'talenttrack' ), 'default' => '#dba617' ],
            [ 'key' => 'warning_subtle_color',  'css_var' => '--tt-warning-subtle',   'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Warning — subtle bg',    'talenttrack' ), 'default' => '#fdf3d8' ],
            [ 'key' => 'danger_color',          'css_var' => '--tt-danger',           'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Danger',                 'talenttrack' ), 'default' => '#b32d2e' ],
            [ 'key' => 'danger_subtle_color',   'css_var' => '--tt-danger-subtle',    'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Danger — subtle bg',     'talenttrack' ), 'default' => '#fde2e2' ],
            [ 'key' => 'info_color',            'css_var' => '--tt-info',             'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Info',                   'talenttrack' ), 'default' => '#2271b1' ],
            [ 'key' => 'info_subtle_color',     'css_var' => '--tt-info-subtle',      'category' => self::CATEGORY_STATUS,   'kind' => self::KIND_COLOR,  'label' => __( 'Info — subtle bg',       'talenttrack' ), 'default' => '#e6f0fa' ],

            // --- Surface ---
            [ 'key' => 'background_color',      'css_var' => '--tt-bg',               'category' => self::CATEGORY_SURFACE,  'kind' => self::KIND_COLOR,  'label' => __( 'Background',             'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'surface_color',         'css_var' => '--tt-surface',          'category' => self::CATEGORY_SURFACE,  'kind' => self::KIND_COLOR,  'label' => __( 'Card surface',           'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'line_color',            'css_var' => '--tt-line',             'category' => self::CATEGORY_SURFACE,  'kind' => self::KIND_COLOR,  'label' => __( 'Lines + borders',        'talenttrack' ), 'default' => '#e5e7ea' ],

            // --- Text ---
            [ 'key' => 'text_color',            'css_var' => '--tt-text',             'category' => self::CATEGORY_TEXT,     'kind' => self::KIND_COLOR,  'label' => __( 'Text',                   'talenttrack' ), 'default' => '#1a1d21' ],
            [ 'key' => 'muted_color',           'css_var' => '--tt-muted',            'category' => self::CATEGORY_TEXT,     'kind' => self::KIND_COLOR,  'label' => __( 'Muted text',             'talenttrack' ), 'default' => '#5b6e75' ],

            // --- Typography ---
            [ 'key' => 'font_display',          'css_var' => '--tt-font-display',     'category' => self::CATEGORY_TYPOGRAPHY,'kind' => self::KIND_SELECT, 'label' => __( 'Display font (headings)', 'talenttrack' ), 'default' => '' ],
            [ 'key' => 'font_body',             'css_var' => '--tt-font-body',        'category' => self::CATEGORY_TYPOGRAPHY,'kind' => self::KIND_SELECT, 'label' => __( 'Body font',              'talenttrack' ), 'default' => '' ],
            [ 'key' => 'font_weight_heading',   'css_var' => '--tt-fw-heading',       'category' => self::CATEGORY_TYPOGRAPHY,'kind' => self::KIND_SELECT, 'label' => __( 'Heading weight',         'talenttrack' ), 'default' => '', 'options' => [ '' => '(default)', '500' => '500', '600' => '600', '700' => '700', '800' => '800' ] ],
            [ 'key' => 'font_weight_body',      'css_var' => '--tt-fw-body',          'category' => self::CATEGORY_TYPOGRAPHY,'kind' => self::KIND_SELECT, 'label' => __( 'Body weight',            'talenttrack' ), 'default' => '', 'options' => [ '' => '(default)', '300' => '300', '400' => '400', '500' => '500', '600' => '600' ] ],

            // --- Shape ---
            [ 'key' => 'corner_radius_md',      'css_var' => '--tt-r-md',             'category' => self::CATEGORY_SHAPE,    'kind' => self::KIND_NUMBER, 'label' => __( 'Corner radius — medium (px)', 'talenttrack' ), 'default' => '8',  'min' => 0,   'max' => 32, 'step' => 1 ],
            [ 'key' => 'corner_radius_lg',      'css_var' => '--tt-r-lg',             'category' => self::CATEGORY_SHAPE,    'kind' => self::KIND_NUMBER, 'label' => __( 'Corner radius — large (px)',  'talenttrack' ), 'default' => '12', 'min' => 0,   'max' => 40, 'step' => 1 ],
            [ 'key' => 'spacing_scale',         'css_var' => '--tt-spacing-scale',    'category' => self::CATEGORY_SHAPE,    'kind' => self::KIND_FLOAT,  'label' => __( 'Spacing scale (0.6–1.6)',     'talenttrack' ), 'default' => '1', 'min' => 0.6, 'max' => 1.6, 'step' => 0.05 ],

            // --- Shadow ---
            [ 'key' => 'shadow_sm',             'css_var' => '--tt-shadow-sm',        'category' => self::CATEGORY_SHADOW,   'kind' => self::KIND_SELECT, 'label' => __( 'Card shadow — small',         'talenttrack' ), 'default' => 'light',
                'options' => [ 'none' => '(none)', 'light' => 'Light', 'medium' => 'Medium', 'strong' => 'Strong' ] ],
            [ 'key' => 'shadow_md',             'css_var' => '--tt-shadow-md',        'category' => self::CATEGORY_SHADOW,   'kind' => self::KIND_SELECT, 'label' => __( 'Card shadow — medium (hover)','talenttrack' ), 'default' => 'medium',
                'options' => [ 'none' => '(none)', 'light' => 'Light', 'medium' => 'Medium', 'strong' => 'Strong' ] ],
            [ 'key' => 'shadow_lg',             'css_var' => '--tt-shadow-lg',        'category' => self::CATEGORY_SHADOW,   'kind' => self::KIND_SELECT, 'label' => __( 'Modal / drawer shadow',       'talenttrack' ), 'default' => 'strong',
                'options' => [ 'none' => '(none)', 'light' => 'Light', 'medium' => 'Medium', 'strong' => 'Strong' ] ],

            // --- Motion ---
            [ 'key' => 'motion_duration',       'css_var' => '--tt-motion-duration',  'category' => self::CATEGORY_MOTION,   'kind' => self::KIND_SELECT, 'label' => __( 'Animation speed',             'talenttrack' ), 'default' => 'base',
                'options' => [ 'fast' => 'Fast (120ms)', 'base' => 'Base (180ms)', 'slow' => 'Slow (260ms)' ] ],
            [ 'key' => 'motion_easing',         'css_var' => '--tt-motion-easing',    'category' => self::CATEGORY_MOTION,   'kind' => self::KIND_SELECT, 'label' => __( 'Animation curve',             'talenttrack' ), 'default' => 'standard',
                'options' => [ 'standard' => 'Standard', 'in' => 'Ease-in', 'out' => 'Ease-out', 'in_out' => 'Ease-in-out' ] ],

            // --- Type scale (#0075 Sprint 1 PR 5) ---
            [ 'key' => 'font_size_body',        'css_var' => '--tt-fs-body',          'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Body size (rem)',             'talenttrack' ), 'default' => '1',    'min' => 0.75, 'max' => 1.25, 'step' => 0.0625, 'unit' => 'rem' ],
            [ 'key' => 'font_size_h1',          'css_var' => '--tt-fs-h1',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 1 size (rem)',        'talenttrack' ), 'default' => '2',    'min' => 1.25, 'max' => 3.5,  'step' => 0.125,  'unit' => 'rem' ],
            [ 'key' => 'font_size_h2',          'css_var' => '--tt-fs-h2',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 2 size (rem)',        'talenttrack' ), 'default' => '1.5',  'min' => 1.125,'max' => 2.5,  'step' => 0.125,  'unit' => 'rem' ],
            [ 'key' => 'font_size_h3',          'css_var' => '--tt-fs-h3',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 3 size (rem)',        'talenttrack' ), 'default' => '1.25', 'min' => 1,    'max' => 2,    'step' => 0.0625, 'unit' => 'rem' ],
            [ 'key' => 'line_height_body',      'css_var' => '--tt-lh-body',          'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Body line height',            'talenttrack' ), 'default' => '1.5',  'min' => 1.2,  'max' => 1.8,  'step' => 0.05 ],

            // --- Buttons (#0075 Sprint 1 PR 5) ---
            [ 'key' => 'btn_primary_bg',        'css_var' => '--tt-btn-primary-bg',         'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — background',     'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'btn_primary_text',      'css_var' => '--tt-btn-primary-text',       'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — text',           'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'btn_primary_hover_bg',  'css_var' => '--tt-btn-primary-hover-bg',   'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — hover background','talenttrack' ), 'default' => '#0a3327' ],
            [ 'key' => 'btn_secondary_border',  'css_var' => '--tt-btn-secondary-border',   'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Secondary button — border',       'talenttrack' ), 'default' => '#0b3d2e' ],

            // --- Forms (#0075 Sprint 1 PR 5) ---
            [ 'key' => 'input_border_color',    'css_var' => '--tt-input-border',           'category' => self::CATEGORY_FORMS, 'kind' => self::KIND_COLOR, 'label' => __( 'Input border',           'talenttrack' ), 'default' => '#e3e1d8' ],
            [ 'key' => 'input_focus_border',    'css_var' => '--tt-input-focus-border',     'category' => self::CATEGORY_FORMS, 'kind' => self::KIND_COLOR, 'label' => __( 'Input border on focus',  'talenttrack' ), 'default' => '#0b3d2e' ],
        ];
    }

    /**
     * Storage keys belonging to a category, in the order the editor
     * should render them.
     *
     * @return string[]
     */
    public static function keysInCategory( string $category ): array {
        $keys = [];
        foreach ( self::definitions() as $def ) {
            if ( $def['category'] === $category ) $keys[] = $def['key'];
        }
        return $keys;
    }

    /**
     * Categories ordered as the left rail / accordion should render
     * them. Brand → Status → Surface → Text → Typography → Shape →
     * Shadow → Motion.
     *
     * @return array<string, string>  category-key => label
     */
    public static function categoriesInOrder(): array {
        return [
            self::CATEGORY_BRAND      => __( 'Brand colours',   'talenttrack' ),
            self::CATEGORY_STATUS     => __( 'Status colours',  'talenttrack' ),
            self::CATEGORY_SURFACE    => __( 'Surfaces',        'talenttrack' ),
            self::CATEGORY_TEXT       => __( 'Text',            'talenttrack' ),
            self::CATEGORY_TYPOGRAPHY => __( 'Typography',      'talenttrack' ),
            self::CATEGORY_TYPE_SCALE => __( 'Type scale',      'talenttrack' ),
            self::CATEGORY_SHAPE      => __( 'Shape + spacing', 'talenttrack' ),
            self::CATEGORY_SHADOW     => __( 'Shadows',         'talenttrack' ),
            self::CATEGORY_MOTION     => __( 'Motion',          'talenttrack' ),
            self::CATEGORY_BUTTONS    => __( 'Buttons',         'talenttrack' ),
            self::CATEGORY_FORMS      => __( 'Forms',           'talenttrack' ),
        ];
    }

    /**
     * Map a `shadow_strength`-style preset value to the CSS
     * `box-shadow` declaration the generator emits. Used by the
     * three explicit shadow tokens (`shadow_sm` / `shadow_md` /
     * `shadow_lg`) so each one maps to the same vocabulary the
     * v3.64 single-strength field used.
     */
    public static function shadowDeclaration( string $preset ): string {
        switch ( $preset ) {
            case 'none':   return 'none';
            case 'light':  return '0 1px 3px rgba(0,0,0,0.05)';
            case 'medium': return '0 2px 6px rgba(0,0,0,0.08)';
            case 'strong': return '0 8px 24px rgba(0,0,0,0.12)';
        }
        return '';
    }

    /**
     * Map a motion duration preset to its millisecond value.
     */
    public static function motionDurationMs( string $preset ): string {
        switch ( $preset ) {
            case 'fast': return '120ms';
            case 'base': return '180ms';
            case 'slow': return '260ms';
        }
        return '';
    }

    /**
     * Map a motion easing preset to its CSS cubic-bezier.
     */
    public static function motionEasing( string $preset ): string {
        switch ( $preset ) {
            case 'standard': return 'cubic-bezier(0.2, 0.8, 0.2, 1)';
            case 'in':       return 'cubic-bezier(0.4, 0, 1, 1)';
            case 'out':      return 'cubic-bezier(0, 0, 0.2, 1)';
            case 'in_out':   return 'cubic-bezier(0.4, 0, 0.2, 1)';
        }
        return '';
    }
}
