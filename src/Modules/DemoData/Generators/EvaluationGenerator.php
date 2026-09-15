<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRatingScale;
use TT\Modules\DemoData\DemoRoster;
use TT\Modules\DemoData\SeedLoader;

/**
 * EvaluationGenerator — writes tt_evaluations + tt_eval_ratings.
 *
 * Two cadences, because there are two things (#3401):
 *
 *   - **Round evaluations.** Four a season — start, two mid, end — dated a
 *     few days ahead of the PDP conversation that reviews them, so
 *     `EvidencePacket::forConversation()` has the round behind every talk.
 *     This replaced a flat two-per-week stream, which gave a player 312
 *     evaluations across a three-year window and buried the development
 *     story it was meant to tell.
 *   - **Match evaluations.** Written against the fixtures the run
 *     generates, on their own per-match cadence. They used to be 25% of the
 *     same stream and were about no match in particular.
 *
 * Ratings are archetype-driven. Each player's archetype is read from
 * tt_demo_tags.extra_json.archetype (set by PlayerGenerator) and mapped to
 * a trajectory over normalised window time t ∈ [0,1], expressed in the
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
 * season's climb reads as 6 → 7 rather than as 6.4 → 6.7 on a scale whose
 * step is 1.
 */
class EvaluationGenerator implements DependentGeneratorInterface {

    /** Share of a team's played fixtures a player is written up for. */
    private const MATCH_EVAL_PROB = 35; // out of 100

    /** Per-category flavour adjustment, in scale steps, applied to every eval. */
    private const CATEGORY_BIASES = [
        'Technical' => 0.5,
        'Tactical'  => -0.5,
        'Physical'  => 0.0,
        'Mental'    => 0.25,
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $players;

    /** @var object[] */
    private array $teams;

    private int $weeks;

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'evaluations';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->historicPlayers(),
            $ctx->teams,
            $ctx->weeks(),
            $ctx->calendar(),
            $ctx->roster()
        );
    }

    /**
     * @param object[] $players generated players (with .id, .team_id, .archetype, .wp_user_id)
     * @param object[] $teams   generated teams (with .id, .head_coach_user_id)
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $players,
        array $teams,
        int $weeks,
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->players  = $players;
        $this->teams    = $teams;
        $this->weeks    = max( 1, $weeks );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
    }

    public function generate(): int {
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
            $team_coach[ (int) $t->id ] = (int) $t->head_coach_user_id;
        }

        $seasons = $this->calendar->seasons();
        $scale   = DemoRatingScale::fromConfig();
        $climb   = $scale->climbOver( count( $seasons ) );

        $fixtures = [];
        foreach ( $this->calendar->activitySlots() as $slot ) {
            if ( $slot['is_game'] && ! $slot['is_future'] ) $fixtures[] = $slot['date'];
        }

        $opponents = SeedLoader::opponents();
        $results   = SeedLoader::matchResults();
        $now       = $this->calendar->now();

        $total_evals = 0;
        foreach ( $this->players as $p ) {
            $player_id = (int) ( $p->id ?? 0 );
            if ( $player_id <= 0 ) continue;
            $archetype = (string) ( $p->archetype ?? 'steady_solid' );

            foreach ( $seasons as $season ) {
                $team_id = $this->roster->teamForPlayerInSeason( $player_id, (int) $season['index'] );
                $coach_id = (int) ( $team_coach[ $team_id ] ?? 0 );
                if ( $team_id <= 0 || $coach_id <= 0 ) continue;

                foreach ( $this->calendar->roundDates( $season ) as $round => $when ) {
                    $ts = (int) strtotime( $when );
                    if ( $ts <= 0 || $ts > $now ) continue; // a round that has not come round yet

                    $eval_id = $this->writeEvaluation( [
                        'club_id'      => CurrentClub::id(),
                        'player_id'    => $player_id,
                        'coach_id'     => $coach_id,
                        'eval_type_id' => (int) $training_id,
                        'eval_date'    => $when,
                        'notes'        => '',
                    ], $player_id, $archetype, [
                        'round'     => $round + 1,
                        'season'    => (string) $season['name'],
                        'team_id'   => $team_id,
                    ] );
                    if ( $eval_id <= 0 ) continue;

                    $total_evals++;
                    $this->writeRatings(
                        $eval_id,
                        $categories,
                        $archetype,
                        $this->calendar->progressForDate( $when ),
                        $scale,
                        $climb
                    );
                }
            }

            if ( $match_id <= 0 ) continue;

            foreach ( $fixtures as $when ) {
                $team_id  = $this->roster->teamForPlayerOn( $player_id, $when );
                $coach_id = (int) ( $team_coach[ $team_id ] ?? 0 );
                if ( $team_id <= 0 || $coach_id <= 0 ) continue;
                if ( mt_rand( 1, 100 ) > self::MATCH_EVAL_PROB ) continue;

                $eval_id = $this->writeEvaluation( [
                    'club_id'        => CurrentClub::id(),
                    'player_id'      => $player_id,
                    'coach_id'       => $coach_id,
                    'eval_type_id'   => (int) $match_id,
                    'eval_date'      => $when,
                    'notes'          => '',
                    'opponent'       => $opponents[ mt_rand( 0, max( 0, count( $opponents ) - 1 ) ) ] ?? '',
                    'competition'    => $this->pickCompetition(),
                    'game_result'    => $results[ mt_rand( 0, max( 0, count( $results ) - 1 ) ) ] ?? '',
                    'home_away'      => mt_rand( 0, 1 ) ? 'H' : 'A',
                    'minutes_played' => mt_rand( 45, 90 ),
                ], $player_id, $archetype, [ 'match' => 1, 'team_id' => $team_id ] );
                if ( $eval_id <= 0 ) continue;

                $total_evals++;
                $this->writeRatings(
                    $eval_id,
                    $categories,
                    $archetype,
                    $this->calendar->progressForDate( $when ),
                    $scale,
                    $climb
                );
            }
        }

        return $total_evals;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $tag
     */
    private function writeEvaluation( array $row, int $player_id, string $archetype, array $tag ): int {
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
     * @param object[] $categories
     */
    private function writeRatings(
        int $eval_id,
        array $categories,
        string $archetype,
        float $t,
        DemoRatingScale $scale,
        float $climb
    ): void {
        $step = $scale->step();
        foreach ( $categories as $cat ) {
            $bias   = ( self::CATEGORY_BIASES[ $cat->name ] ?? 0.0 ) * $step;
            $centre = $this->archetypeRating( $archetype, $t, $scale, $climb ) + $bias;

            $main = $scale->quantise( $centre + ( mt_rand( -40, 40 ) / 100 ) * $step );
            $this->writeRating( $eval_id, (int) $cat->id, $main );

            foreach ( $this->subcategoriesFor( (int) $cat->id ) as $sub ) {
                $this->writeRating(
                    $eval_id,
                    (int) $sub->id,
                    $scale->quantise( $centre + ( mt_rand( -60, 60 ) / 100 ) * $step )
                );
            }
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

    /** @var array<int, object[]>|null per-request cache of subcategory rows keyed by parent id */
    private ?array $subcat_cache = null;

    /**
     * @return object[] subcategory rows for the given main category
     */
    private function subcategoriesFor( int $parent_id ): array {
        if ( $this->subcat_cache === null ) {
            $this->subcat_cache = [];
            try {
                $repo = new EvalCategoriesRepository();
                $mains = $repo->getMainCategories( true );
                foreach ( $mains as $main ) {
                    $this->subcat_cache[ (int) $main->id ] = $repo->getChildren( (int) $main->id, true );
                }
            } catch ( \Throwable $_ ) {
                $this->subcat_cache = [];
            }
        }
        return $this->subcat_cache[ $parent_id ] ?? [];
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
            foreach ( QueryHelpers::get_lookups( 'game_subtype' ) as $row ) {
                $name = trim( (string) $row->name );
                if ( $name !== '' ) $this->competition_options[] = $name;
            }
        }
        if ( ! $this->competition_options ) return '';
        return $this->competition_options[ mt_rand( 0, count( $this->competition_options ) - 1 ) ];
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
