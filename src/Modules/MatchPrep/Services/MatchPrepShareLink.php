<?php
namespace TT\Modules\MatchPrep\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchPrep\MatchPrepShareToken;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MatchPrepShareLink (#2892) — builds and resolves the staff share URL.
 *
 * Shape: `?tt_view=match-prep-share&id=<uuid>&token=<hmac>`, matching the
 * match-analysis and team-blueprint links. Kept in a service rather than in
 * the view so the URL is built identically wherever it is offered, and so
 * `resolve()` — the security-bearing half — has one implementation to
 * review.
 */
final class MatchPrepShareLink {

    public const VIEW_SLUG = 'match-prep-share';

    /**
     * The current share URL for a prep, minting the seed if this is the
     * first time anyone asked for one.
     */
    public static function urlFor( int $prep_id ): string {
        $repo = new MatchPrepRepository();
        $prep = $repo->find( $prep_id );
        if ( ! $prep ) return '';

        $seed  = $repo->ensureShareTokenSeed( $prep_id );
        $uuid  = (string) ( $prep->uuid ?? '' );
        if ( $seed === '' || $uuid === '' ) return '';

        $token = MatchPrepShareToken::tokenFor( $prep_id, $uuid, $seed );

        return add_query_arg(
            [
                'tt_view' => self::VIEW_SLUG,
                'id'      => $uuid,
                'token'   => $token,
            ],
            RecordLink::dashboardUrl()
        );
    }

    /**
     * Resolve a `(uuid, token)` pair to the prep it addresses, or null.
     *
     * Returns null for every failure mode without distinguishing them —
     * unknown uuid, never-shared prep, wrong token and rotated seed all
     * look identical from outside, so a probe learns nothing about which
     * part it got wrong.
     *
     * The seed is read as stored and never initialised here: a prep whose
     * link was never generated has no seed, and minting one on the way in
     * would let a guessed uuid create the very secret it needs.
     */
    public static function resolve( string $uuid, string $token ): ?object {
        $uuid  = trim( $uuid );
        $token = trim( $token );
        if ( $uuid === '' || $token === '' ) return null;

        $repo = new MatchPrepRepository();
        $prep = $repo->findByUuid( $uuid );
        if ( ! $prep ) return null;

        $seed = (string) ( $prep->share_token_seed ?? '' );
        if ( $seed === '' ) return null;

        if ( ! MatchPrepShareToken::verify( (int) $prep->id, $uuid, $seed, $token ) ) {
            return null;
        }

        return $prep;
    }
}
