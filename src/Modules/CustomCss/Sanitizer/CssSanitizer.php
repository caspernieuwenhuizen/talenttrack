<?php
namespace TT\Modules\CustomCss\Sanitizer;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CssSanitizer — block-list sanitizer for the #0064 custom-CSS payload.
 *
 * The threat model: the operator (a club admin holding `tt_admin_styling`)
 * is mostly trusted, but a careless paste from a third-party "free CSS
 * theme" site shouldn't be able to:
 *
 *   - Exfiltrate per-render IP via remote `@font-face` URLs.
 *   - Reach external resources via `@import url(http://attacker)`.
 *   - Execute JS in legacy IE via `expression()` or `behavior:`.
 *   - Inject code via `url(javascript:...)` or `url(data:text/html,...)`.
 *   - Wreck the layout cap by yanking 100% of the viewport with
 *     `position: fixed; top:0; bottom:0; left:0; right:0; z-index: 99999999;`
 *     (covered with a soft warning in docs, not blocked here).
 *
 * Comprehensive coverage — every block-list pattern we ship ships
 * with a test case in `tests/CssSanitizerTest.php`.
 *
 * The sanitizer is a string-level filter, not a real CSS parser. That
 * limits us to pattern-rejection of known-bad constructs; it can't
 * "almost-allow with a fix." Anything that hits a rule gets rejected
 * with a clear error message, and the offending fragment is included
 * so the operator can see what to remove.
 */
final class CssSanitizer {

    /**
     * Hard size cap on the CSS body, in bytes. Lifted from 200_000 to
     * 500_000 in v3.83.0 because the new "full stylesheet" round-trip
     * (designer downloads bundled+overrides, edits, re-uploads) ships a
     * file that's already ~170 KB of bundled content before the
     * designer adds anything. 500 KB leaves comfortable headroom and
     * remains well within sane inline-`<style>` and DB-column budgets.
     */
    public const MAX_BYTES = 500_000;

    /**
     * Run the full block-list. Returns either the sanitized CSS body
     * (which today is byte-identical to the input on success) or a
     * `WP_Error` carrying the rejection reason.
     *
     * @return string|\WP_Error
     */
    public function sanitize( string $css ) {
        if ( $css === '' ) return '';

        if ( strlen( $css ) > self::MAX_BYTES ) {
            return new \WP_Error(
                'css_too_large',
                sprintf(
                    /* translators: 1 = current size in KB, 2 = limit in KB */
                    __( 'Custom CSS is too large (%1$d KB). The maximum is %2$d KB.', 'talenttrack' ),
                    (int) ceil( strlen( $css ) / 1024 ),
                    (int) ceil( self::MAX_BYTES / 1024 )
                )
            );
        }

        $checks = [
            // JS-in-CSS — covers `url(javascript:…)`, `url("javascript:…")`, `url('javascript:…')`.
            [ '/\burl\s*\(\s*[\'"]?\s*javascript:/i',          __( 'JavaScript URLs are not allowed in custom CSS.', 'talenttrack' ) ],
            // `data:text/html,...` URLs — they can carry HTML / script payloads.
            [ '/\burl\s*\(\s*[\'"]?\s*data:\s*text\/html/i',    __( 'data:text/html URLs are not allowed in custom CSS.', 'talenttrack' ) ],
            // Legacy IE expression() — runs JS at parse time on old browsers.
            [ '/\bexpression\s*\(/i',                            __( 'CSS expression() is not allowed.', 'talenttrack' ) ],
            // Legacy IE behavior: — points at .htc files that run script.
            [ '/\bbehavior\s*:/i',                               __( 'CSS behavior: declarations are not allowed.', 'talenttrack' ) ],
            // -moz-binding — old Firefox XBL escape hatch.
            [ '/-moz-binding\s*:/i',                             __( 'CSS -moz-binding declarations are not allowed.', 'talenttrack' ) ],
            // @import of remote URL — bypasses the no-remote-fetch posture.
            [ '/@import\s+(?:url\()?[\'"]?\s*https?:\/\//i',     __( 'Remote @import is not allowed. Inline the rules instead.', 'talenttrack' ) ],
            [ '/@import\s+(?:url\()?[\'"]?\s*\/\//i',            __( 'Protocol-relative @import is not allowed. Inline the rules instead.', 'talenttrack' ) ],
            // External @font-face source — operators must serve fonts locally
            // or pick from the curated list in #0023 BrandFonts. Same posture
            // as Branding's font dropdown.
            [ '/@font-face\b[^}]*\bsrc\s*:[^;}]*url\s*\(\s*[\'"]?\s*https?:\/\//i', __( 'Remote @font-face URLs are not allowed. Use the Branding tab\'s curated fonts or self-host.', 'talenttrack' ) ],
        ];

        foreach ( $checks as [ $pattern, $message ] ) {
            if ( preg_match( $pattern, $css, $m ) ) {
                $excerpt = isset( $m[0] ) ? mb_substr( (string) $m[0], 0, 80 ) : '';
                return new \WP_Error(
                    'css_blocked',
                    $excerpt !== ''
                        ? $message . ' ' . sprintf( __( 'Offending fragment: %s', 'talenttrack' ), $excerpt )
                        : $message
                );
            }
        }

        // Strip BOM if present.
        if ( substr( $css, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $css = substr( $css, 3 );
        }

        return $css;
    }
}
