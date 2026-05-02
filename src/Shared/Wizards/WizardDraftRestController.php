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

    public static function save( WP_REST_Request $req ): WP_REST_Response {
        $user_id = get_current_user_id();
        $slug    = (string) $req->get_param( 'slug' );

        $wizard = WizardRegistry::find( $slug );
        if ( ! $wizard || ! WizardRegistry::isAvailable( $slug, $user_id ) ) {
            return new WP_REST_Response(
                [ 'error' => 'wizard_unavailable' ],
                403
            );
        }

        $body   = $req->get_json_params();
        $fields = is_array( $body['fields'] ?? null ) ? (array) $body['fields'] : [];
        if ( $fields === [] ) {
            return new WP_REST_Response( [ 'saved_at' => null, 'noop' => true ], 200 );
        }

        $patch = self::sanitiseFields( $fields );
        if ( $patch !== [] ) {
            WizardState::merge( $user_id, $slug, $patch );
        }

        return new WP_REST_Response( [
            'saved_at' => gmdate( 'c' ),
        ], 200 );
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
