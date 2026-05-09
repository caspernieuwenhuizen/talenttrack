<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExercisesRepository;

/**
 * ExerciseFuzzyMatcher (#0016 Sprint 4) — match an AI-extracted
 * exercise name against the live `tt_exercises` library.
 *
 * The vision provider returns raw text the coach scribbled on the
 * paper plan ("4v4 rondo", "passing lanes", "shooting under pressure").
 * Sprint 4's review wizard wants to suggest the best library hit
 * so the operator can accept-with-one-click rather than retype.
 *
 * Algorithm: normalise both candidate + library names (lowercase,
 * strip punctuation, collapse whitespace), then compute Levenshtein
 * similarity ratio. Returns the highest-scoring library row when
 * its similarity ≥ `min_similarity` (default 0.6 per spec § Sprint 4
 * "rule out < 60% similarity"). Below threshold returns null and
 * the wizard surfaces "save-as-new-library-entry."
 *
 * Tenancy + visibility: matches against `ExercisesRepository::listForTeam()`
 * when a team_id is supplied, falling back to `listActive()` for
 * club-wide matching.
 */
final class ExerciseFuzzyMatcher {

    private const DEFAULT_MIN_SIMILARITY = 0.6;

    private ExercisesRepository $repo;

    public function __construct( ?ExercisesRepository $repo = null ) {
        $this->repo = $repo ?? new ExercisesRepository();
    }

    /**
     * Find the best library match for an extracted name.
     *
     * @param string  $extracted_name The raw text from the photo.
     * @param int     $team_id        0 = club-wide search; >0 applies
     *                                visibility rules for the team.
     * @param float   $min_similarity Threshold (0.0-1.0). Default 0.6.
     *
     * @return array{exercise:?object,similarity:float,candidates:list<array{exercise:object,similarity:float}>}
     *   `exercise` is null when no library row clears the threshold.
     *   `candidates` is the top-3 sorted descending so the wizard
     *   can offer alternatives.
     */
    public function bestMatch(
        string $extracted_name,
        int $team_id = 0,
        float $min_similarity = self::DEFAULT_MIN_SIMILARITY
    ): array {
        $extracted_name = trim( $extracted_name );
        if ( $extracted_name === '' ) {
            return [ 'exercise' => null, 'similarity' => 0.0, 'candidates' => [] ];
        }

        $library = $team_id > 0
            ? $this->repo->listForTeam( $team_id )
            : $this->repo->listActive();

        if ( empty( $library ) ) {
            return [ 'exercise' => null, 'similarity' => 0.0, 'candidates' => [] ];
        }

        $needle = self::normalise( $extracted_name );
        $scored = [];
        foreach ( $library as $row ) {
            $haystack = self::normalise( (string) ( $row->name ?? '' ) );
            if ( $haystack === '' ) continue;
            $sim = self::similarity( $needle, $haystack );
            $scored[] = [ 'exercise' => $row, 'similarity' => $sim ];
        }

        usort( $scored, static fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );

        $top   = $scored[0] ?? null;
        $best  = ( $top !== null && $top['similarity'] >= $min_similarity ) ? $top['exercise'] : null;
        $score = $top['similarity'] ?? 0.0;

        return [
            'exercise'   => $best,
            'similarity' => $score,
            'candidates' => array_slice( $scored, 0, 3 ),
        ];
    }

    /**
     * Lowercase, drop punctuation + accents, collapse whitespace.
     */
    private static function normalise( string $s ): string {
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        // Strip diacritics on common European accents — coaches write
        // "rondó" as "rondo" half the time and the matcher should
        // not penalise that.
        if ( function_exists( 'iconv' ) ) {
            $stripped = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
            if ( is_string( $stripped ) ) $s = $stripped;
        }
        $s = preg_replace( '/[^a-z0-9\s]/', ' ', $s );
        $s = preg_replace( '/\s+/', ' ', $s );
        return trim( $s );
    }

    /**
     * Levenshtein-based similarity ratio in [0.0, 1.0]. PHP's
     * `levenshtein()` returns the number of edits; we convert to a
     * ratio normalised on the longer string's length.
     */
    private static function similarity( string $a, string $b ): float {
        if ( $a === $b ) return 1.0;
        $longest = max( strlen( $a ), strlen( $b ) );
        if ( $longest === 0 ) return 0.0;
        // PHP levenshtein() is byte-based; for UTF-8 strings whose
        // characters all live in ASCII after normalisation, that's
        // accurate. The normalise() pass above strips most non-ASCII.
        $dist = levenshtein( $a, $b );
        return max( 0.0, 1.0 - ( $dist / $longest ) );
    }
}
