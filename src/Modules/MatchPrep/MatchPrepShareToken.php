<?php
namespace TT\Modules\MatchPrep;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatchPrepShareToken (#2892) — HMAC sign + verify for the staff share link.
 *
 * Same construction as `MatchAnalysis\MatchAnalysisShareToken` and
 * `TeamDevelopment\BlueprintShareToken`: SHA-256 HMAC over
 * `(prep_id, uuid, share_token_seed)` keyed on `wp_salt('auth')`.
 *
 * Deliberately a third copy of thirty lines rather than a shared abstract:
 * the three differ only in which table they read, and a change to the
 * signing construction should be a decision taken per surface rather than
 * something that silently alters the other two. If a fourth appears, that
 * is the moment to extract one — not before.
 *
 * The uuid supplies randomness against enumeration; the HMAC binds the URL
 * to the seed, so a leaked `(id, uuid)` pair is not enough to forge one.
 * Rotating the seed invalidates every prior URL for that prep.
 *
 * A shared match prep names minors and says which of them is expected to
 * start, so revocation is why the seed exists separately from the uuid.
 */
final class MatchPrepShareToken {

    public static function tokenFor( int $prep_id, string $uuid, string $seed ): string {
        $payload = $prep_id . '|' . $uuid . '|' . $seed;
        return hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
    }

    public static function verify( int $prep_id, string $uuid, string $seed, string $token ): bool {
        if ( $token === '' || $seed === '' || $uuid === '' ) return false;
        return hash_equals( self::tokenFor( $prep_id, $uuid, $seed ), $token );
    }
}
