<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DemoEvaluationWriter — one place that writes a demo evaluation and its
 * per-category ratings.
 *
 * Two generators write evaluations, for two different reasons:
 *
 *   - `EvaluationGenerator` writes the **round** evaluations, four a
 *     season, against the PDP cycle.
 *   - `MatchDayGenerator` writes the **match** evaluations, against the
 *     match it has just generated — because only that generator knows who
 *     was fielded, for how long, and how the match ended (#3658).
 *
 * Both tag their rows `evaluation` / `eval_rating`, so the `evaluations`
 * and `players` wipe cascades reach every row whichever generator wrote it.
 *
 * Ratings are archetype-driven. Each player's archetype is read from
 * tt_demo_tags.extra_json.archetype (set by PlayerGenerator) and mapped to
 * a trajectory over normalised window time t in [0,1], expressed in the
 * install's own rating units:
 *
 *   rising_star   — climbs a step a season, from below average
 *   in_a_slump    — falls the same distance over the first half, flat after
 *   steady_solid  — flat, a little above the middle
 *   late_bloomer  — flat for the first half, then climbs
 *   inconsistent  — middling, swinging a step either way per evaluation
 *   new_arrival   — starts middling with a small climb
 *   departed      — slides, which is the story the release verdict tells
 *
 * Every value is snapped to the configured scale (`DemoRatingScale`), so a
 * season's climb reads as 6 -> 7 rather than as 6.4 -> 6.7 on a scale whose
 * step is 1.
 */
class DemoEvaluationWriter {

    /** Per-category flavour adjustment, in scale steps, applied to every eval. */
    private const CATEGORY_BIASES = [
        'Technical' => 0.5,
        'Tactical'  => -0.5,
        'Physical'  => 0.0,
        'Mental'    => 0.25,
    ];

    private DemoBatchRegistry $registry;

    private DemoCalendar $calendar;

    private DemoRatingScale $scale;

    private float $climb;

    /** @var list<array{id:int, name:string}>|null main categories, resolved once */
    private ?array $categories = null;

    /** @var array<int, list<int>>|null per-request cache of subcategory ids keyed by parent id */
    private ?array $subcat_cache = null;

    public function __construct( DemoBatchRegistry $registry, DemoCalendar $calendar ) {
        $this->registry = $registry;
        $this->calendar = $calendar;
        $this->scale    = DemoRatingScale::fromConfig();
        $this->climb    = $this->scale->climbOver( max( 1, count( $calendar->seasons() ) ) );
    }

    /**
     * The install's main evaluation categories. Empty when the plugin's
     * migrations have not seeded any, which a caller decides what to do
     * about: the round evaluations are the point of their generator and it
     * throws, a match evaluation is a garnish on a match and is skipped.
     *
     * @return list<array{id:int, name:string}>
     */
    public function categories(): array {
        if ( $this->categories === null ) {
            try {
                $repo = new EvalCategoriesRepository();
                $rows = $repo->getMainCategoriesLegacyShape();
            } catch ( \Throwable $_ ) {
                $rows = QueryHelpers::get_categories();
            }

            $out = [];
            foreach ( $rows as $row ) {
                $id = (int) ( $row->id ?? 0 );
                if ( $id <= 0 ) continue;
                $out[] = [ 'id' => $id, 'name' => (string) ( $row->name ?? '' ) ];
            }
            $this->categories = $out;
        }
        return $this->categories;
    }

    /** @var array{training?:int, match?:int}|null */
    private ?array $eval_types = null;

    /**
     * The install's evaluation types, by the kind the demo needs. Empty
     * when the plugin's migrations have not seeded any.
     *
     * @return array{training?:int, match?:int}
     */
    public function evalTypes(): array {
        if ( $this->eval_types === null ) {
            $map = [];
            foreach ( QueryHelpers::get_eval_types() as $type ) {
                $name = strtolower( (string) ( $type->name ?? '' ) );
                $id   = (int) ( $type->id ?? 0 );
                if ( $id <= 0 ) continue;
                if ( strpos( $name, 'train' ) !== false )     $map['training'] = $id;
                elseif ( strpos( $name, 'match' ) !== false ) $map['match']    = $id;
            }
            $this->eval_types = $map;
        }
        return $this->eval_types;
    }

    /**
     * Write one evaluation plus a rating per category and subcategory.
     *
     * @param array<string,mixed> $row the tt_evaluations row
     * @param array<string,mixed> $tag extra demo-tag payload (round, season, match, team_id)
     * @return int the evaluation id, or 0 when nothing was written
     */
    public function write( array $row, int $player_id, string $archetype, array $tag ): int {
        $categories = $this->categories();
        if ( ! $categories ) return 0;

        $eval_id = $this->insert( $row, $player_id, $archetype, $tag );
        if ( $eval_id <= 0 ) return 0;

        $this->writeRatings(
            $eval_id,
            $categories,
            $archetype,
            $this->calendar->progressForDate( (string) ( $row['eval_date'] ?? '' ) )
        );

        return $eval_id;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $tag
     */
    private function insert( array $row, int $player_id, string $archetype, array $tag ): int {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", $row );
        $eval_id = (int) $wpdb->insert_id;
        if ( ! $eval_id ) return 0;

        $this->registry->tag( 'evaluation', $eval_id, array_merge(
            [ 'player_id' => $player_id, 'archetype' => $archetype ],
            $tag
        ) );

        // v3.91.7 — fire the runtime hook so JourneyEventSubscriber writes an
        // `evaluation_completed` journey event for this evaluation. Without
        // it, demo runs leave `tt_player_events` empty for this category.
        do_action( 'tt_evaluation_saved', $player_id, $eval_id );

        return $eval_id;
    }

    /**
     * One rating per main category, plus one per subcategory so the detail
     * drill-in shows plausible variation around the main score.
     *
     * @param list<array{id:int, name:string}> $categories
     */
    private function writeRatings( int $eval_id, array $categories, string $archetype, float $t ): void {
        $step = $this->scale->step();
        foreach ( $categories as $cat ) {
            $bias   = ( self::CATEGORY_BIASES[ $cat['name'] ] ?? 0.0 ) * $step;
            $centre = $this->archetypeRating( $archetype, $t, $this->scale, $this->climb ) + $bias;

            $main = $this->scale->quantise( $centre + ( mt_rand( -40, 40 ) / 100 ) * $step );
            $this->writeRating( $eval_id, $cat['id'], $main );
            $this->maybeWriteNote( $eval_id, $cat['id'] );

            foreach ( $this->subcategoriesFor( $cat['id'] ) as $sub_id ) {
                $this->writeRating(
                    $eval_id,
                    $sub_id,
                    $this->scale->quantise( $centre + ( mt_rand( -60, 60 ) / 100 ) * $step )
                );
            }
        }
    }

    /**
     * #3949 — a category note on roughly one main category in six.
     *
     * Chosen from the ids, not from `mt_rand()`: every dependent generator
     * draws from one seeded stream, and taking values from it here would
     * shift everything generated after this and break the reproducibility
     * of a (seed, preset) pair.
     */
    private function maybeWriteNote( int $eval_id, int $category_id ): void {
        if ( ( $eval_id + $category_id ) % 6 !== 0 ) return;
        $notes = [
            __( 'Strong in the first half; faded once the tempo went up.', 'talenttrack' ),
            __( 'Good choices under no pressure, rushed when pressed.', 'talenttrack' ),
            __( 'Clear step forward since the last evaluation.', 'talenttrack' ),
            __( 'Keep working on this in small-sided games.', 'talenttrack' ),
        ];
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_eval_category_notes", [
            'club_id'       => CurrentClub::id(),
            'evaluation_id' => $eval_id,
            'category_id'   => $category_id,
            'note'          => $notes[ ( $eval_id + $category_id ) % count( $notes ) ],
        ] );
        $note_id = (int) $wpdb->insert_id;
        if ( $ok !== false && $note_id ) {
            $this->registry->tag( 'eval_category_note', $note_id );
        }
    }

    private function writeRating( int $eval_id, int $category_id, float $rating ): void {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
            'club_id'       => CurrentClub::id(),
            'evaluation_id' => $eval_id,
            'category_id'   => $category_id,
            'rating'        => $rating,
        ] );
        $rating_id = (int) $wpdb->insert_id;
        if ( $rating_id ) {
            $this->registry->tag( 'eval_rating', $rating_id );
        }
    }

    /**
     * @return list<int> subcategory ids for the given main category
     */
    private function subcategoriesFor( int $parent_id ): array {
        if ( $this->subcat_cache === null ) {
            $this->subcat_cache = [];
            try {
                $repo  = new EvalCategoriesRepository();
                $mains = $repo->getMainCategories( true );
                foreach ( $mains as $main ) {
                    $main_id = (int) ( $main->id ?? 0 );
                    if ( $main_id <= 0 ) continue;

                    $children = [];
                    foreach ( $repo->getChildren( $main_id, true ) as $child ) {
                        $child_id = (int) ( $child->id ?? 0 );
                        if ( $child_id > 0 ) $children[] = $child_id;
                    }
                    $this->subcat_cache[ $main_id ] = $children;
                }
            } catch ( \Throwable $_ ) {
                $this->subcat_cache = [];
            }
        }
        return $this->subcat_cache[ $parent_id ] ?? [];
    }

    /**
     * An archetype's rating at window-time `$t`, in the install's own units.
     *
     * Public so a test can assert the property that matters: across a
     * season, an improving archetype moves at least one step of whatever
     * scale the academy has configured (#3401).
     */
    public function archetypeRating( string $archetype, float $t, DemoRatingScale $scale, float $climb ): float {
        $low = $scale->min() + ( $scale->span() - $climb ) * 0.35;
        $mid = $scale->min() + $scale->span() * 0.5;

        switch ( $archetype ) {
            case 'rising_star':
                return $low + $climb * $t;
            case 'in_a_slump':
                return $t < 0.5 ? $low + $climb * ( 1 - 2 * $t ) : $low;
            case 'late_bloomer':
                return $t < 0.5 ? $low : $low + $climb * 2 * ( $t - 0.5 );
            case 'inconsistent':
                return $mid + ( mt_rand( -100, 100 ) / 100 ) * $scale->step();
            case 'new_arrival':
                return $low + $climb * ( 0.25 + 0.4 * $t );
            case DemoRoster::ARCHETYPE_DEPARTED:
                return $mid - $climb * 0.5 * $t;
            case 'steady_solid':
            default:
                return $scale->min() + $scale->span() * 0.55;
        }
    }
}
