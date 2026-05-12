<?php
namespace TT\Shared\Wizards;

if ( ! defined( 'ABSPATH' ) ) exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * WizardDraftRestController (#0072 follow-up) — POST /wizards/{slug}/draft.
 *
 * Powers the autosave indicator on every wizard step. The JS listens
 * for `input` events on the wizard form, debounces ~800ms, and posts
 * the current field map here. The endpoint merges the sanitised
 * field map into `WizardState` so the next page load (or cross-device
 * resume via `tt_wizard_drafts`) sees the partial input.
 *
 * No validation is run — half-typed input is the point. Full
 * validation still happens on `Next` via the step's `validate()`.
 */
final class WizardDraftRestController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route(
            'talenttrack/v1',
            '/wizards/(?P<slug>[a-z0-9_-]+)/draft',
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'save' ],
                'permission_callback' => static fn(): bool => is_user_logged_in() && current_user_can( 'read' ),
                'args'                => [
                    'slug' => [ 'sanitize_callback' => 'sanitize_key', 'required' => true ],
                ],
            ]
        );
    }

    /**
     * v3.110.84 — endpoint kept registered (so cached clients with the
     * old `wizard-autosave.js` don't see 404s and surface error toasts)
     * but the write path is gone. The handler now always responds 200
     * with `noop: true` regardless of the payload. The autosave runtime
     * is no longer enqueued; this method only ever runs against stale
     * browser caches, and silently discarding their writes is the
     * intended behaviour.
     *
     * Race condition that caused the gut: in-flight autosave POSTs
     * landed milliseconds AFTER `WizardState::clear()` fired on Cancel
     * / Submit and re-created the `tt_wizard_drafts` row. Next wizard
     * load resumed from the resurrected draft, which is why pilots saw
     * the wizard "keep coming back at the check stage. Only if I click
     * cancel a few times it clears." Several stale POSTs eventually
     * stop firing once the browser navigates off the wizard view.
     *
     * Wizards that want real cross-session drafts implement
     * `SupportsCancelAsDraft` and use the explicit "Save as draft"
     * button on the wizard chrome — a single user-triggered write,
     * not a periodic background race.
     */
    public static function save( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( [ 'saved_at' => null, 'noop' => true ], 200 );
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private static function sanitiseFields( array $fields ): array {
        $out = [];
        foreach ( $fields as $key => $value ) {
            $key = (string) $key;
            // Skip framework fields the form may post inadvertently.
            if ( in_array( $key, [ 'tt_wizard_nonce', 'tt_wizard_action', '_cancel_url', '_wpnonce', '_wp_http_referer' ], true ) ) {
                continue;
            }
            if ( strncmp( $key, '_', 1 ) === 0 ) continue;

            if ( is_array( $value ) ) {
                $out[ $key ] = self::sanitiseFields( $value );
                continue;
            }
            $out[ $key ] = sanitize_textarea_field( wp_unslash( (string) $value ) );
        }
        return $out;
    }
}
