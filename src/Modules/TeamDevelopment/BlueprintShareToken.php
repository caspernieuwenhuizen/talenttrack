<?php
namespace TT\Modules\TeamDevelopment;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BlueprintShareToken (#0068 Phase 4) — HMAC sign + verify for the
 * public blueprint share-link.
 *
 * Mirrors the #0081 `ParentConfirmationController::tokenFor()`
 * pattern: SHA-256 HMAC over `(blueprint_id, uuid, share_token_seed)`
 * keyed on `wp_salt('auth')`. The `share_token_seed` lives on
 * `tt_team_blueprints` (migration 0078); rotating the seed invalidates
 * every prior URL for that blueprint.
 *
 * The blueprint's `uuid` already provides cryptographic randomness
 * against enumeration; the HMAC binds the URL to the seed so a
 * leaked `(blueprint_id, uuid)` pair is not enough to forge a token.
 */
final class BlueprintShareToken {

    public static function tokenFor( int $blueprint_id, string $uuid, string $seed ): string {
        $payload = $blueprint_id . '|' . $uuid . '|' . $seed;
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    public static function verify( int $blueprint_id, string $uuid, string $seed, string $token ): bool {
        if ( $token === '' || $seed === '' ) return false;
        return hash_equals( self::tokenFor( $blueprint_id, $uuid, $seed ), $token );
    }
}
