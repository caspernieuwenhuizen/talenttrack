<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Exercises\ExercisesModule;
use TT\Modules\Exercises\Vision\ExerciseFuzzyMatcher;

/**
 * VisionExtractRestController (#0016 Sprint 3-6) — orchestrates the
 * photo-to-session extraction flow that the Sprint 3 capture UI calls
 * into.
 *
 *   POST /wp-json/talenttrack/v1/vision/extract
 *
 * Body (multipart/form-data preferred for the photo bytes; raw base64
 * accepted as JSON for clients that can't multipart):
 *   - photo (file)        OR  photo_base64 (string, when JSON)
 *   - team_id (int, optional) — drives the fuzzy-matcher visibility
 *                               filter so the matched library entries
 *                               are scoped to what this team can see
 *   - language (string, optional) — hints the extraction prompt
 *
 * Returns the `ExtractedSession` JSON shape that Sprint 4's review
 * wizard renders directly:
 *
 *   {
 *     "exercises": [
 *       {
 *         "name": "...",
 *         "duration_minutes": N,
 *         "notes": "...",
 *         "confidence": 0.0-1.0,
 *         "matched_exercise_id": <id>|null,
 *         "matched_similarity": 0.0-1.0,
 *         "match_candidates": [...]
 *       }
 *     ],
 *     "attendance": [...],
 *     "overall_confidence": 0.0-1.0,
 *     "notes": "..."
 *   }
 *
 * Cap gate: `tt_edit_activities` (a coach who can edit an activity
 * can capture one).
 *
 * Failure handling: when every configured provider fails (or none is
 * configured), returns 503 with `{ ok: false, error: "..." }`. The
 * Sprint 3 capture UI surfaces this as "we couldn't read this photo
 * — the manual flow is right there →" without burning the photo.
 */
final class VisionExtractRestController {

    const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/vision/extract', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'extract' ],
                'permission_callback' => static fn() => current_user_can( 'tt_edit_activities' ),
            ],
        ] );
    }

    public static function extract( \WP_REST_Request $r ): \WP_REST_Response {
        $bytes = self::extractImageBytes( $r );
        if ( $bytes === null ) {
            return RestResponse::error(
                'no_image',
                __( 'No image was uploaded. Attach a photo via the `photo` file field or `photo_base64` body field.', 'talenttrack' ),
                400
            );
        }

        $team_id  = (int) ( $r->get_param( 'team_id' ) ?? 0 );
        $language = (string) ( $r->get_param( 'language' ) ?? '' );

        try {
            $extracted = ExercisesModule::extractWithFallback( $bytes, [
                'team_id'  => $team_id,
                'language' => $language,
            ] );
        } catch ( \RuntimeException $e ) {
            Logger::error( 'rest.vision_extract.failed', [ 'error' => $e->getMessage(), 'team_id' => $team_id ] );
            return RestResponse::error(
                'extract_failed',
                __( 'The vision provider could not read this photo. Use the manual flow instead.', 'talenttrack' ),
                503,
                [ 'detail' => $e->getMessage() ]
            );
        }

        // Run each extracted exercise through the fuzzy matcher so
        // the response carries the best library hit per row. The
        // wizard surfaces high-confidence matches as auto-accepted.
        $matcher = new ExerciseFuzzyMatcher();
        $rows    = [];
        foreach ( $extracted->exercises as $ex ) {
            $match = $matcher->bestMatch( $ex->name, $team_id );
            $rows[] = [
                'name'                 => $ex->name,
                'duration_minutes'     => $ex->duration_minutes,
                'notes'                => $ex->notes,
                'confidence'           => $ex->confidence,
                'matched_exercise_id'  => $match['exercise'] ? (int) $match['exercise']->id : null,
                'matched_similarity'   => $match['similarity'],
                'match_candidates'     => array_map(
                    static fn( $c ) => [
                        'exercise_id' => (int) $c['exercise']->id,
                        'name'        => (string) $c['exercise']->name,
                        'similarity'  => $c['similarity'],
                    ],
                    $match['candidates']
                ),
            ];
        }

        return RestResponse::success( [
            'exercises'          => $rows,
            'attendance'         => $extracted->attendance,
            'overall_confidence' => $extracted->overall_confidence,
            'notes'              => $extracted->notes,
        ] );
    }

    /**
     * Pull the image bytes from either a multipart upload or a
     * `photo_base64` JSON body. Returns null when neither is present.
     */
    private static function extractImageBytes( \WP_REST_Request $r ): ?string {
        $files = $r->get_file_params();
        if ( isset( $files['photo']['tmp_name'] ) && is_string( $files['photo']['tmp_name'] ) && is_readable( $files['photo']['tmp_name'] ) ) {
            $bytes = file_get_contents( $files['photo']['tmp_name'] );
            return $bytes !== false ? $bytes : null;
        }

        $body = $r->get_json_params();
        if ( is_array( $body ) && ! empty( $body['photo_base64'] ) ) {
            $decoded = base64_decode( (string) $body['photo_base64'], true );
            return $decoded !== false ? $decoded : null;
        }
        return null;
    }
}
