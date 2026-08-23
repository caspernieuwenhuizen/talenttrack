<?php
namespace TT\Modules\MatchAnalysis\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisShareToken;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MatchAnalysisShareLink (#2709) — builds and resolves the staff share URL.
 *
 * Shape: `?tt_view=match-analysis-share&id=<uuid>&token=<hmac>`, the same
 * as the team-blueprint link. Kept in a service rather than in the view so
 * the URL is built identically wherever it is offered (the surface, the
 * rotate endpoint, a future email) and so `resolve()` — the security-
 * bearing half — has one implementation to review.
 */
final class MatchAnalysisShareLink {

    public const VIEW_SLUG = 'match-analysis-share';

    /**
     * The current share URL for an analysis, minting the seed if this is
     * the first time anyone asked for one.
     */
    public static function urlFor( int $analysis_id ): string {
        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->find( $analysis_id );
        if ( ! $analysis ) return '';

        $seed  = $repo->ensureShareTokenSeed( $analysis_id );
        $uuid  = (string) $analysis->uuid;
        $token = MatchAnalysisShareToken::tokenFor( $analysis_id, $uuid, $seed );

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
     * Resolve a `(uuid, token)` pair to the analysis it addresses, or null.
     *
     * Returns null for every failure mode without distinguishing them —
     * unknown uuid, never-shared analysis, wrong token and rotated seed all
     * look identical from outside, so a probe learns nothing about which
     * part it got wrong.
     *
     * Note the seed is read as stored, never initialised here: an analysis
     * whose link was never generated has no seed, and minting one on the
     * way in would let a guessed uuid create the very secret it needs.
     */
    public static function resolve( string $uuid, string $token ): ?object {
        $uuid  = trim( $uuid );
        $token = trim( $token );
        if ( $uuid === '' || $token === '' ) return null;

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByUuid( $uuid );
        if ( ! $analysis ) return null;

        $seed = (string) ( $analysis->share_token_seed ?? '' );
        if ( $seed === '' ) return null;

        if ( ! MatchAnalysisShareToken::verify( (int) $analysis->id, $uuid, $seed, $token ) ) {
            return null;
        }

        return $analysis;
    }
}
