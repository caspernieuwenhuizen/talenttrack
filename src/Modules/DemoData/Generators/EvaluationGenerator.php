<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\SeedLoader;

/**
 * EvaluationGenerator — writes tt_evaluations + tt_eval_ratings.
 *
 * Cadence: ~2 evaluations per player per week across the activity
 * window. Mix of Training (75%) and Match (25%). Match rows also carry
 * opponent, competition, home/away, result, minutes_played.
 *
 * Ratings are archetype-driven. Each player's archetype is read from
 * tt_demo_tags.extra_json.archetype (set by PlayerGenerator) and
 * mapped to a trajectory function over normalized time t ∈ [0,1]:
 *
 *   rising_star   — climbs 2.5 → 4.5 linearly
 *   in_a_slump    — 4.0 → 2.5 over first half, flat 2.5 after
 *   steady_solid  — flat 3.5
 *   late_bloomer  — flat 3.0 for first half, 3.0 → 4.5 after
 *   inconsistent  — 3.0 mean with ±1.5 swing per evaluation
 *   new_arrival   — only last 4–6 weeks, ~3.0 with tiny climb
 *
 * Per-category bias adds ±0.3 so the radar shows a plausible shape
 * rather than a flat polygon. Per-eval noise adds ±0.3 on top.
 */
class EvaluationGenerator {

    private const TYPE_TRAINING_PROB = 75; // out of 100

    /** Per-category flavour adjustment applied to every eval of a player. */
    private const CATEGORY_BIASES = [
        'Technical' => 0.25,
        'Tactical'  => -0.15,
        'Physical'  => 0.0,
        'Mental'    => 0.1,
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    private int $weeks;

    /**
     * @param object[] $players generated players (with .id, .team_id, .archetype, .wp_user_id)
     * @param object[] $teams   generated teams (with .id, .head_coach_id)
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        int $weeks
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->weeks    = max( 1, $weeks );
    }

    /**
     * @return array{evaluations:int, ratings:int}
     */
    public function generate(): int {
        global $wpdb;

        $categories = $this->loadMainCategories();
        if ( ! $categories ) {
            throw new \RuntimeException( 'No main evaluation categories found — run the plugin\'s migrations first.' );
        }

        $eval_types = $this->loadEvalTypes();
        if ( ! $eval_types ) {
            throw new \RuntimeException( 'No evaluation types found — run the plugin\'s migrations first.' );
        }
        $training_id = $eval_types['training'] ?? 0;
        $match_id    = $eval_types['match'] ?? 0;

        $team_coach = [];
        foreach ( $this->teams as $t ) {
            $team_coach[ (int) $t->id ] = (int) $t->head_coach_id;
        }

        $opponents = SeedLoader::opponents();
        $results   = SeedLoader::matchResults();

        $total_evals   = 0;
        $total_ratings = 0;

        $start_date = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $start_date === false ) $start_date = time();

        foreach ( $this->players as $p ) {
            $archetype = (string) ( $p->archetype ?? 'steady_solid' );
            $coach_id  = $team_coach[ (int) $p->team_id ] ?? 0;
            if ( ! $coach_id ) continue;

            $window_start = $archetype === 'new_arrival'
                ? $start_date + (int) floor( ( $this->weeks * 0.6 ) * WEEK_IN_SECONDS )
                : $start_date;
            $window_len = max( 1, (int) floor( ( time() - $window_start ) / WEEK_IN_SECONDS ) );

            $eval_count = $window_len * 2;   // ~2 per week
            for ( $i = 0; $i < $eval_count; $i++ ) {
                $t = $eval_count === 1 ? 1.0 : ( $i / ( $eval_count - 1 ) );
                $offset = (int) ( ( $i / $eval_count ) * $window_len * WEEK_IN_SECONDS );
                $eval_date = gmdate( 'Y-m-d', $window_start + $offset );

                $is_match = $match_id && mt_rand( 1, 100 ) > self::TYPE_TRAINING_PROB;
                $type_id  = $is_match ? $match_id : $training_id;

                $eval_row = [
                    'player_id'    => (int) $p->id,
                    'coach_id'     => (int) $coach_id,
                    'eval_type_id' => (int) $type_id,
                    'eval_date'    => $eval_date,
                    'notes'        => '',
                ];
                if ( $is_match ) {
                    $eval_row['opponent']       = $opponents[ mt_rand( 0, max( 0, count( $opponents ) - 1 ) ) ] ?? '';
                    $eval_row['competition']    = $this->pickCompetition();
                    $eval_row['match_result']   = $results[ mt_rand( 0, max( 0, count( $results ) - 1 ) ) ] ?? '';
                    $eval_row['home_away']      = mt_rand( 0, 1 ) ? 'H' : 'A';
                    $eval_row['minutes_played'] = mt_rand( 45, 90 );
                }

                $wpdb->insert( "{$wpdb->prefix}tt_evaluations", $eval_row );
                $eval_id = (int) $wpdb->insert_id;
                if ( ! $eval_id ) continue;

                $this->registry->tag( 'evaluation', $eval_id, [
                    'player_id'  => (int) $p->id,
                    'archetype'  => $archetype,
                    'progress_t' => round( $t, 3 ),
                ] );
                $total_evals++;

                foreach ( $categories as $cat ) {
                    $bias   = self::CATEGORY_BIASES[ $cat->name ] ?? 0.0;
                    $rating = $this->archetypeRating( $archetype, $t ) + $bias + ( mt_rand( -30, 30 ) / 100 );
                    $rating = max( 1.0, min( 5.0, round( $rating, 1 ) ) );

                    $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
                        'evaluation_id' => $eval_id,
                        'category_id'   => (int) $cat->id,
                        'rating'        => $rating,
                    ] );
                    $rating_id = (int) $wpdb->insert_id;
                    if ( $rating_id ) {
                        $this->registry->tag( 'eval_rating', $rating_id );
                        $total_ratings++;
                    }
                }
            }
        }

        return $total_evals;
    }

    /**
     * @return object[]
     */
    private function loadMainCategories(): array {
        try {
            $repo = new EvalCategoriesRepository();
            return $repo->getMainCategoriesLegacyShape();
        } catch ( \Throwable $_ ) {
            return QueryHelpers::get_categories();
        }
    }

    /**
     * @return array{training?:int, match?:int}
     */
    private function loadEvalTypes(): array {
        $types  = QueryHelpers::get_eval_types();
        $map    = [];
        foreach ( $types as $t ) {
            $name = strtolower( (string) $t->name );
            if ( strpos( $name, 'train' ) !== false )     $map['training'] = (int) $t->id;
            elseif ( strpos( $name, 'match' ) !== false ) $map['match']    = (int) $t->id;
        }
        return $map;
    }

    /** @var string[]|null */
    private ?array $competition_options = null;

    private function pickCompetition(): string {
        if ( $this->competition_options === null ) {
            $this->competition_options = [];
            foreach ( QueryHelpers::get_lookups( 'competition_type' ) as $row ) {
                $name = trim( (string) $row->name );
                if ( $name !== '' ) $this->competition_options[] = $name;
            }
        }
        if ( ! $this->competition_options ) return '';
        return $this->competition_options[ mt_rand( 0, count( $this->competition_options ) - 1 ) ];
    }

    private function archetypeRating( string $archetype, float $t ): float {
        switch ( $archetype ) {
            case 'rising_star':
                return 2.5 + 2.0 * $t;
            case 'in_a_slump':
                return $t < 0.5 ? 4.0 - 3.0 * $t : 2.5;
            case 'late_bloomer':
                return $t < 0.5 ? 3.0 : 3.0 + 3.0 * ( $t - 0.5 );
            case 'inconsistent':
                return 3.0 + ( mt_rand( -150, 150 ) / 100 );
            case 'new_arrival':
                return 3.0 + 0.5 * $t;
            case 'steady_solid':
            default:
                return 3.5;
        }
    }
}
