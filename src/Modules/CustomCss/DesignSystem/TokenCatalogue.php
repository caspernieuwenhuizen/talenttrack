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
    public const CATEGORY_LINKS       = 'links';
    public const CATEGORY_CONTENT     = 'content';
    public const CATEGORY_CARDS       = 'cards';
    public const CATEGORY_LISTS       = 'lists';
    public const CATEGORY_TABLES      = 'tables';
    public const CATEGORY_FEEDBACK    = 'feedback';
    public const CATEGORY_OVERLAYS    = 'overlays';
    public const CATEGORY_PERSONA_DASH = 'persona_dashboard';

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
            [ 'key' => 'font_size_h4',          'css_var' => '--tt-fs-h4',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 4 size (rem)',        'talenttrack' ), 'default' => '1.125','min' => 0.875,'max' => 1.5,  'step' => 0.0625, 'unit' => 'rem' ],
            [ 'key' => 'font_size_h5',          'css_var' => '--tt-fs-h5',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 5 size (rem)',        'talenttrack' ), 'default' => '1',    'min' => 0.875,'max' => 1.25, 'step' => 0.0625, 'unit' => 'rem' ],
            [ 'key' => 'font_size_h6',          'css_var' => '--tt-fs-h6',            'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading 6 size (rem)',        'talenttrack' ), 'default' => '0.875','min' => 0.75, 'max' => 1.125,'step' => 0.0625, 'unit' => 'rem' ],
            [ 'key' => 'line_height_body',      'css_var' => '--tt-lh-body',          'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Body line height',            'talenttrack' ), 'default' => '1.5',  'min' => 1.2,  'max' => 1.8,  'step' => 0.05 ],
            [ 'key' => 'line_height_heading',   'css_var' => '--tt-lh-heading',       'category' => self::CATEGORY_TYPE_SCALE,'kind' => self::KIND_FLOAT,  'label' => __( 'Heading line height',         'talenttrack' ), 'default' => '1.2',  'min' => 1.0,  'max' => 1.5,  'step' => 0.05 ],

            // --- Buttons (#0075 Sprint 1 PR 5) ---
            [ 'key' => 'btn_primary_bg',        'css_var' => '--tt-btn-primary-bg',         'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — background',     'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'btn_primary_text',      'css_var' => '--tt-btn-primary-text',       'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — text',           'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'btn_primary_hover_bg',  'css_var' => '--tt-btn-primary-hover-bg',   'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Primary button — hover background','talenttrack' ), 'default' => '#0a3327' ],
            [ 'key' => 'btn_secondary_border',  'css_var' => '--tt-btn-secondary-border',   'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Secondary button — border',       'talenttrack' ), 'default' => '#0b3d2e' ],

            // --- Forms (#0075 Sprint 1 PR 5) ---
            [ 'key' => 'input_border_color',    'css_var' => '--tt-input-border',           'category' => self::CATEGORY_FORMS, 'kind' => self::KIND_COLOR, 'label' => __( 'Input border',           'talenttrack' ), 'default' => '#e3e1d8' ],
            [ 'key' => 'input_focus_border',    'css_var' => '--tt-input-focus-border',     'category' => self::CATEGORY_FORMS, 'kind' => self::KIND_COLOR, 'label' => __( 'Input border on focus',  'talenttrack' ), 'default' => '#0b3d2e' ],

            // --- Links (#0075 Sprint 2 PR 1) ---
            [ 'key' => 'link_color',            'css_var' => '--tt-link-color',             'category' => self::CATEGORY_LINKS, 'kind' => self::KIND_COLOR, 'label' => __( 'Link colour',            'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'link_hover_color',      'css_var' => '--tt-link-hover-color',       'category' => self::CATEGORY_LINKS, 'kind' => self::KIND_COLOR, 'label' => __( 'Link colour on hover',   'talenttrack' ), 'default' => '#e8b624' ],
            [ 'key' => 'link_underline',        'css_var' => '--tt-link-decoration',        'category' => self::CATEGORY_LINKS, 'kind' => self::KIND_SELECT, 'label' => __( 'Link underline',         'talenttrack' ), 'default' => 'hover',
                'options' => [ 'always' => __( 'Always', 'talenttrack' ), 'hover' => __( 'On hover only', 'talenttrack' ), 'never' => __( 'Never', 'talenttrack' ) ] ],

            // --- Content (#0075 Sprint 2 PR 2) — inline code, blockquotes, captions, labels ---
            [ 'key' => 'code_bg',               'css_var' => '--tt-code-bg',                'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Inline code — background', 'talenttrack' ), 'default' => '#f4f6f8' ],
            [ 'key' => 'code_text',             'css_var' => '--tt-code-text',              'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Inline code — text',       'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'quote_border',          'css_var' => '--tt-quote-border',           'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Blockquote — left border', 'talenttrack' ), 'default' => '#e8b624' ],
            [ 'key' => 'quote_text',            'css_var' => '--tt-quote-text',             'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Blockquote — text',        'talenttrack' ), 'default' => '#5b6e75' ],
            [ 'key' => 'caption_color',         'css_var' => '--tt-caption-color',          'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Caption — text',           'talenttrack' ), 'default' => '#5b6e75' ],
            [ 'key' => 'label_color',           'css_var' => '--tt-label-color',            'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Form label — text',        'talenttrack' ), 'default' => '#1a1d21' ],
            [ 'key' => 'helper_text_color',     'css_var' => '--tt-helper-text-color',      'category' => self::CATEGORY_CONTENT, 'kind' => self::KIND_COLOR, 'label' => __( 'Helper text — colour',     'talenttrack' ), 'default' => '#5b6e75' ],

            // --- Buttons (per-state) #0075 Sprint 2 PR 2 ---
            [ 'key' => 'btn_secondary_bg',      'css_var' => '--tt-btn-secondary-bg',       'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Secondary button — background',     'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'btn_secondary_text',    'css_var' => '--tt-btn-secondary-text',     'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Secondary button — text',           'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'btn_secondary_hover_bg','css_var' => '--tt-btn-secondary-hover-bg', 'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Secondary button — hover bg',       'talenttrack' ), 'default' => '#faf8f3' ],
            [ 'key' => 'btn_danger_bg',         'css_var' => '--tt-btn-danger-bg',          'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Danger button — background',        'talenttrack' ), 'default' => '#b32d2e' ],
            [ 'key' => 'btn_danger_text',       'css_var' => '--tt-btn-danger-text',        'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_COLOR, 'label' => __( 'Danger button — text',              'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'btn_disabled_opacity',  'css_var' => '--tt-btn-disabled-opacity',   'category' => self::CATEGORY_BUTTONS, 'kind' => self::KIND_FLOAT, 'label' => __( 'Disabled button — opacity',         'talenttrack' ), 'default' => '0.5', 'min' => 0.2, 'max' => 0.9, 'step' => 0.05 ],

            // --- Cards (#0075 Sprint 2 PR 2) ---
            [ 'key' => 'card_bg',               'css_var' => '--tt-card-bg',                'category' => self::CATEGORY_CARDS, 'kind' => self::KIND_COLOR,  'label' => __( 'Card background',            'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'card_border_color',     'css_var' => '--tt-card-border',            'category' => self::CATEGORY_CARDS, 'kind' => self::KIND_COLOR,  'label' => __( 'Card border',                'talenttrack' ), 'default' => '#e3e1d8' ],
            [ 'key' => 'card_padding',          'css_var' => '--tt-card-padding',           'category' => self::CATEGORY_CARDS, 'kind' => self::KIND_NUMBER, 'label' => __( 'Card padding (px)',          'talenttrack' ), 'default' => '16',     'min' => 8,    'max' => 32, 'step' => 2 ],
            [ 'key' => 'card_accent_border',    'css_var' => '--tt-card-accent-border',     'category' => self::CATEGORY_CARDS, 'kind' => self::KIND_COLOR,  'label' => __( 'Card left-accent border',    'talenttrack' ), 'default' => '#0b3d2e' ],

            // --- Lists (#0075 Sprint 2 PR 2) ---
            [ 'key' => 'list_item_padding',     'css_var' => '--tt-list-item-padding',      'category' => self::CATEGORY_LISTS, 'kind' => self::KIND_NUMBER, 'label' => __( 'List item padding (px)',     'talenttrack' ), 'default' => '8',      'min' => 4,    'max' => 24, 'step' => 1 ],
            [ 'key' => 'list_divider_color',    'css_var' => '--tt-list-divider',           'category' => self::CATEGORY_LISTS, 'kind' => self::KIND_COLOR,  'label' => __( 'List divider colour',        'talenttrack' ), 'default' => '#e3e1d8' ],
            [ 'key' => 'list_hover_bg',         'css_var' => '--tt-list-hover-bg',          'category' => self::CATEGORY_LISTS, 'kind' => self::KIND_COLOR,  'label' => __( 'List item hover background', 'talenttrack' ), 'default' => '#faf8f3' ],

            // --- Tables (#0075 Sprint 2 PR 2) ---
            [ 'key' => 'table_header_bg',       'css_var' => '--tt-table-header-bg',        'category' => self::CATEGORY_TABLES, 'kind' => self::KIND_COLOR,  'label' => __( 'Table header — background', 'talenttrack' ), 'default' => '#0b3d2e' ],
            [ 'key' => 'table_header_text',     'css_var' => '--tt-table-header-text',      'category' => self::CATEGORY_TABLES, 'kind' => self::KIND_COLOR,  'label' => __( 'Table header — text',       'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'table_row_alt_bg',      'css_var' => '--tt-table-row-alt-bg',       'category' => self::CATEGORY_TABLES, 'kind' => self::KIND_COLOR,  'label' => __( 'Table — striped row',       'talenttrack' ), 'default' => '#faf8f3' ],
            [ 'key' => 'table_border_color',    'css_var' => '--tt-table-border',           'category' => self::CATEGORY_TABLES, 'kind' => self::KIND_COLOR,  'label' => __( 'Table — cell border',       'talenttrack' ), 'default' => '#e3e1d8' ],

            // --- Feedback (#0075 Sprint 2 PR 2) ---
            [ 'key' => 'badge_padding_x',       'css_var' => '--tt-badge-padding-x',        'category' => self::CATEGORY_FEEDBACK, 'kind' => self::KIND_NUMBER, 'label' => __( 'Badge — horizontal padding (px)', 'talenttrack' ), 'default' => '8', 'min' => 4, 'max' => 16, 'step' => 1 ],
            [ 'key' => 'badge_radius',          'css_var' => '--tt-badge-radius',           'category' => self::CATEGORY_FEEDBACK, 'kind' => self::KIND_NUMBER, 'label' => __( 'Badge — corner radius (px)',      'talenttrack' ), 'default' => '999', 'min' => 0, 'max' => 999, 'step' => 1 ],
            [ 'key' => 'spinner_color',         'css_var' => '--tt-spinner-color',          'category' => self::CATEGORY_FEEDBACK, 'kind' => self::KIND_COLOR,  'label' => __( 'Spinner — colour',                  'talenttrack' ), 'default' => '#0b3d2e' ],

            // --- Overlays (#0075 Sprint 2 PR 2) — modal backdrop is
            // intentionally not catalogued because <input type="color">
            // can't carry an alpha channel; clubs that need a different
            // backdrop hue can author it in the Path B CSS editor. ---
            [ 'key' => 'tooltip_bg',            'css_var' => '--tt-tooltip-bg',             'category' => self::CATEGORY_OVERLAYS, 'kind' => self::KIND_COLOR,  'label' => __( 'Tooltip — background',           'talenttrack' ), 'default' => '#1a1d21' ],
            [ 'key' => 'tooltip_text',          'css_var' => '--tt-tooltip-text',           'category' => self::CATEGORY_OVERLAYS, 'kind' => self::KIND_COLOR,  'label' => __( 'Tooltip — text',                 'talenttrack' ), 'default' => '#ffffff' ],

            // --- Persona dashboard (#0077 follow-up M5) ---
            // Mirrors the --tt-pd-* token block declared on :root in
            // assets/css/persona-dashboard.css. Editor overrides emit
            // on .tt-root which has higher specificity than :root, so
            // operator values win for elements rendered inside the
            // dashboard wrapper.
            [ 'key' => 'pd_surface',            'css_var' => '--tt-pd-surface',             'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Surface — card',           'talenttrack' ), 'default' => '#ffffff' ],
            [ 'key' => 'pd_surface_subtle',     'css_var' => '--tt-pd-surface-subtle',      'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Surface — subtle',         'talenttrack' ), 'default' => '#f8fafc' ],
            [ 'key' => 'pd_surface_hover',      'css_var' => '--tt-pd-surface-hover',       'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Surface — hover',          'talenttrack' ), 'default' => '#f1f5f9' ],
            [ 'key' => 'pd_text_primary',       'css_var' => '--tt-pd-text-primary',        'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Text — primary',           'talenttrack' ), 'default' => '#0b1f3a' ],
            [ 'key' => 'pd_text_secondary',     'css_var' => '--tt-pd-text-secondary',      'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Text — secondary',         'talenttrack' ), 'default' => '#334155' ],
            [ 'key' => 'pd_text_muted',         'css_var' => '--tt-pd-text-muted',          'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Text — muted',             'talenttrack' ), 'default' => '#64748b' ],
            [ 'key' => 'pd_accent',             'css_var' => '--tt-pd-accent',              'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Accent',                   'talenttrack' ), 'default' => '#2563eb' ],
            [ 'key' => 'pd_success',            'css_var' => '--tt-pd-success',             'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Status — success',         'talenttrack' ), 'default' => '#15803d' ],
            [ 'key' => 'pd_warning',            'css_var' => '--tt-pd-warning',             'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Status — warning',         'talenttrack' ), 'default' => '#d97706' ],
            [ 'key' => 'pd_danger',             'css_var' => '--tt-pd-danger',              'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Status — danger',          'talenttrack' ), 'default' => '#b91c1c' ],
            [ 'key' => 'pd_divider',            'css_var' => '--tt-pd-divider',             'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Divider',                  'talenttrack' ), 'default' => '#e2e8f0' ],
            [ 'key' => 'pd_hero_start',         'css_var' => '--tt-pd-hero-start',          'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Hero gradient — start',    'talenttrack' ), 'default' => '#0b1f3a' ],
            [ 'key' => 'pd_hero_end',           'css_var' => '--tt-pd-hero-end',            'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Hero gradient — end',      'talenttrack' ), 'default' => '#1a3a5f' ],
            [ 'key' => 'pd_hero_cta',           'css_var' => '--tt-pd-hero-cta',            'category' => self::CATEGORY_PERSONA_DASH, 'kind' => self::KIND_COLOR, 'label' => __( 'Hero CTA',                 'talenttrack' ), 'default' => '#facc15' ],
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
            self::CATEGORY_LINKS      => __( 'Links',           'talenttrack' ),
            self::CATEGORY_CONTENT    => __( 'Content elements','talenttrack' ),
            self::CATEGORY_CARDS      => __( 'Cards',           'talenttrack' ),
            self::CATEGORY_LISTS      => __( 'Lists',           'talenttrack' ),
            self::CATEGORY_TABLES     => __( 'Tables',          'talenttrack' ),
            self::CATEGORY_FEEDBACK   => __( 'Feedback',        'talenttrack' ),
            self::CATEGORY_OVERLAYS   => __( 'Overlays',        'talenttrack' ),
            self::CATEGORY_PERSONA_DASH => __( 'Persona dashboard', 'talenttrack' ),
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
