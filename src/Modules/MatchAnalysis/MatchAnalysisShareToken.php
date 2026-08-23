<?php
namespace TT\Modules\MatchAnalysis;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchAnalysisShareToken (#2709) — HMAC sign + verify for the staff
 * share-link.
 *
 * Same construction as `TeamDevelopment\BlueprintShareToken`: SHA-256 HMAC
 * over `(analysis_id, uuid, share_token_seed)` keyed on `wp_salt('auth')`.
 * The uuid supplies randomness against enumeration; the HMAC binds the URL
 * to the seed, so a leaked `(id, uuid)` pair is not enough to forge one.
 * Rotating the seed invalidates every prior URL for that analysis.
 *
 * A shared match analysis names minors and says which of them fell short,
 * so revocation is not a nice-to-have — it is the reason the seed exists
 * separately from the uuid.
 */
final class MatchAnalysisShareToken {

    public static function tokenFor( int $analysis_id, string $uuid, string $seed ): string {
        $payload = $analysis_id . '|' . $uuid . '|' . $seed;
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    public static function verify( int $analysis_id, string $uuid, string $seed, string $token ): bool {
        if ( $token === '' || $seed === '' || $uuid === '' ) return false;
        return hash_equals( self::tokenFor( $analysis_id, $uuid, $seed ), $token );
    }
}
