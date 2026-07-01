<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reusable help-drawer trigger. The drawer DOM + JS already lives in
 * DashboardShortcode (#0016 Part B); this component just emits the open
 * button anywhere a view wants context-aware help.
 *
 * Topic slug, when supplied, overrides the URL-based topic inference in
 * docs-drawer.js via the `data-tt-docs-topic` attribute. Without a slug
 * the drawer falls back to the `?tt_view=` -> topic map.
 *
 * #2186 — the Help affordance belongs to the Documentation module. When
 * that module is disabled (Configuration → Modules) the button renders
 * nothing, so a disabled module leaves no dangling entry point. The gate
 * lives here, centrally, so every caller (FrontendMyGoalsView,
 * FrontendWizardView, …) is covered without a per-callsite check.
 */
final class HelpDrawer {

    /**
     * FQCN of the module that owns the docs surface. Referenced as a
     * string (not `::class`) so a disabled — and therefore possibly not
     * autoloaded — module never triggers a fatal here.
     */
    private const DOCS_MODULE_CLASS = 'TT\\Modules\\Documentation\\DocumentationModule';

    /**
     * Whether the Documentation module is currently enabled, via the same
     * `tt_module_state` registry that Configuration → Modules reads/writes
     * (ModulesPage). No hardcoded check — flip the module off and the Help
     * button disappears everywhere. Defaults to enabled when the registry
     * class is unavailable (never seen in a normal boot; defensive).
     */
    private static function docsEnabled(): bool {
        if ( ! class_exists( '\\TT\\Core\\ModuleRegistry' ) ) {
            return true;
        }
        return \TT\Core\ModuleRegistry::isEnabled( self::DOCS_MODULE_CLASS );
    }

    public static function button( ?string $topic_slug = null, string $label = '' ): void {
        // #2186 — no Help entry point when the Documentation module is off.
        if ( ! self::docsEnabled() ) {
            return;
        }
        $help_url = add_query_arg( [ 'tt_view' => 'docs' ], home_url( '/' ) );
        if ( $topic_slug ) {
            $help_url = add_query_arg( [ 'topic' => $topic_slug ], $help_url );
        }
        $aria = $label !== '' ? $label : __( 'Help & docs', 'talenttrack' );
        $topic_attr = $topic_slug ? ' data-tt-docs-topic="' . esc_attr( $topic_slug ) . '"' : '';
        $label_html = $label !== '' ? '<span class="tt-help-button-label">' . esc_html( $label ) . '</span>' : '';

        echo '<a href="' . esc_url( $help_url ) . '" class="tt-help-button" '
            . 'data-tt-docs-drawer-open' . $topic_attr . ' '
            . 'title="' . esc_attr( $aria ) . '" '
            . 'aria-label="' . esc_attr( $aria ) . '">'
            . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="10"></circle>'
            . '<path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.7-2.5 2.3-2.5 4"></path>'
            . '<circle cx="12" cy="17.5" r="0.6" fill="currentColor"></circle>'
            . '</svg>'
            . $label_html
            . '</a>';
    }
}
