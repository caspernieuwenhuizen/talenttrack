<?php
namespace TT\Shared\Content;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MarkdownProfile — what makes one markdown surface differ from another.
 *
 * #2663 — the plugin had two markdown renderers because two surfaces
 * disagreed about four things, not about markdown. This names those four so
 * one renderer can serve both:
 *
 *   - the class prefix on emitted elements
 *   - what a fenced block means (a registered widget, or a code sample)
 *   - what to strip before parsing (front matter, legacy metadata comments)
 *   - how a link resolves (a docs cross-reference is not a course link)
 *
 * A profile carries no state between renders and does no work of its own; it
 * is a bag of decisions the renderer asks.
 */
final class MarkdownProfile {

    /** Class prefix, e.g. `tt-lesson` or `tt-doc`. No trailing dash. */
    public string $prefix;

    /** Deepest ATX heading recognised. Anything deeper renders as prose. */
    public int $max_heading_level;

    /**
     * Resolve a fenced block. Receives the info string and the body, returns
     * `['html' => string, 'interactive' => bool]`, or null to fall through to
     * a plain code block.
     *
     * @var null|callable(string, string): ?array{html:string, interactive:bool}
     */
    public $fence_renderer;

    /**
     * Run over the whole source before parsing — front-matter stripping and
     * anything else that must never reach the line loop.
     *
     * @var null|callable(string): string
     */
    public $preprocess;

    /**
     * Turn one `[label](url)` into HTML. The label arrives already escaped,
     * because the renderer escapes the whole line before substituting. Return
     * null to fall back to the renderer's own plain anchor.
     *
     * @var null|callable(string, string): ?string
     */
    public $link_renderer;

    /**
     * @param null|callable(string, string): ?array{html:string, interactive:bool} $fence_renderer
     * @param null|callable(string): string                                        $preprocess
     * @param null|callable(string, string): ?string                               $link_renderer
     */
    public function __construct(
        string $prefix,
        int $max_heading_level = 4,
        ?callable $fence_renderer = null,
        ?callable $preprocess = null,
        ?callable $link_renderer = null
    ) {
        $this->prefix            = rtrim( $prefix, '-' );
        $this->max_heading_level = max( 1, min( 6, $max_heading_level ) );
        $this->fence_renderer    = $fence_renderer;
        $this->preprocess        = $preprocess;
        $this->link_renderer     = $link_renderer;
    }

    /** `tt-lesson` + `code` → `tt-lesson-code`. */
    public function cls( string $suffix ): string {
        return $this->prefix . '-' . ltrim( $suffix, '-' );
    }
}
