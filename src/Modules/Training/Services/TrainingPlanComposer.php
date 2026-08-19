<?php
namespace TT\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Modules\Training\Rules\TrainingExerciseSelectionPass;
use TT\Modules\Vct\Repositories\VctAgeProfilesRepository;
use TT\Modules\Vct\Repositories\VctExercisesRepository;
use TT\Modules\Vct\Repositories\VctMacroBlocksRepository;
use TT\Modules\Vct\Repositories\VctPhvFlagsRepository;
use TT\Modules\Vct\Repositories\VctSessionTemplatesRepository;
use TT\Modules\Vct\Repositories\VctTeamSchedulesRepository;
use TT\Modules\Vct\Repositories\VctWorkloadSnapshotsRepository;
use TT\Modules\Vct\Rules\AgeAdmissibilityRule;
use TT\Modules\Vct\Rules\FinalizationPass;
use TT\Modules\Vct\Rules\MdContextRule;
use TT\Modules\Vct\Rules\ProgressionRule;
use TT\Modules\Vct\Rules\Providers\NativeActivitiesReader;
use TT\Modules\Vct\Rules\Providers\NativeRecentPicksProvider;
use TT\Modules\Vct\Rules\RecoveryRule;
use TT\Modules\Vct\Rules\RulesEngine;
use TT\Modules\Vct\Rules\SessionCompositionRule;
use TT\Modules\Vct\Rules\SessionPlanContext;
use TT\Modules\Vct\Rules\TacticalThemeRule;
use TT\Modules\Vct\Rules\WorkloadCapRule;

/**
 * TrainingPlanComposer (#2497) — drafts a training plan.
 *
 * The generator exists because a blank session builder is where coaching
 * apps go to die: the competitor is a paper sheet made in ten minutes on
 * the couch, and if the app costs more the coach reverts and the data
 * stays on paper. So the coach never starts from nothing.
 *
 * ## Why this runs the VCT engine
 *
 * The constraints a training must respect — the age intensity ceiling,
 * the match-day context, the slot skeleton, the periodisation multiplier,
 * the workload cap, the recovery window — are the same whether the
 * session is conditioning or tactics. Six of the engine's eight passes
 * are therefore reused verbatim rather than reimplemented, and only the
 * pass that decides *which exercise fills each slot* is substituted (see
 * `TrainingExerciseSelectionPass`).
 *
 * That substitution is what makes the plan player-aware: selection scores
 * candidates by how many of this squad's open development targets they
 * touch, which no conditioning generator has any reason to know about.
 *
 * ## Determinism
 *
 * Same inputs, same day, same plan (#2497 acceptance). Nothing here reads
 * the clock beyond the session date it is given, nothing samples a random
 * source, and every tie in selection breaks on the lowest exercise id.
 *
 * ## Failure
 *
 * A blocking warning means the pipeline could not produce a session that
 * respects a hard rule — an unusable age profile, a missing template, an
 * intensity ceiling that cannot be met. Nothing is persisted and the
 * caller gets the reasons, exactly as `VctTrainingComposer` behaves. A
 * soft warning (an unfilled slot, a PHV ceiling to work around) persists
 * alongside the plan, because those are for the coach to judge.
 */
final class TrainingPlanComposer {

    private TrainingPlansRepository $plans;
    private TrainingPlanBlocksRepository $blocks;
    private GoalsRepository $goals;

    public function __construct(
        ?TrainingPlansRepository $plans = null,
        ?TrainingPlanBlocksRepository $blocks = null,
        ?GoalsRepository $goals = null
    ) {
        $this->plans  = $plans  ?? new TrainingPlansRepository();
        $this->blocks = $blocks ?? new TrainingPlanBlocksRepository();
        $this->goals  = $goals  ?? new GoalsRepository();
    }

    /**
     * Draft a plan without saving it. The wizard's proposal step renders
     * this, so a coach can regenerate or swap blocks before anything is
     * written.
     *
     * @param array<string,mixed> $payload
     * @return array{blocks:list<array<string,mixed>>, warnings:list<array<string,mixed>>, coverage:array<string,mixed>, blocked:bool}
     */
    public function preview( array $payload ): array {
        $roster   = $this->rosterFor( $payload );
        $targets  = $this->targetWeights( $roster );
        $ctx      = $this->buildContext( $payload, $roster );

        $engine = $this->engineFor( $targets, $this->principleMapFor() );
        $ctx    = $engine->compose( $ctx );

        return [
            'blocks'   => $ctx->blocks,
            'warnings' => $ctx->warnings,
            'coverage' => $this->coverageFor( $ctx->blocks, $roster ),
            'blocked'  => $this->isBlocked( $ctx ),
        ];
    }

    /**
     * Draft and persist. Returns the new plan id, or null when a blocking
     * warning prevented it — the caller reads `preview()['warnings']` for
     * the reasons.
     *
     * @param array<string,mixed> $payload
     * @return array{plan_id:int|null, warnings:list<array<string,mixed>>, coverage:array<string,mixed>}
     */
    public function generate( array $payload ): array {
        $draft = $this->preview( $payload );

        if ( $draft['blocked'] ) {
            return [ 'plan_id' => null, 'warnings' => $draft['warnings'], 'coverage' => $draft['coverage'] ];
        }

        $plan_id = $this->plans->create( [
            'title'            => $this->titleFor( $payload ),
            'team_id'          => (int) ( $payload['team_id'] ?? 0 ) ?: null,
            'age_group_key'    => $payload['age_group'] ?? null,
            'season_id'        => (int) ( $payload['season_id'] ?? 0 ) ?: null,
            'theme_key'        => $payload['tactical_theme'] ?? null,
            'intensity_target' => null,
            'source'           => 'generated',
            'author_user_id'   => get_current_user_id() ?: null,
            'visibility'       => 'club',
        ] );
        if ( $plan_id <= 0 ) {
            return [ 'plan_id' => null, 'warnings' => $draft['warnings'], 'coverage' => $draft['coverage'] ];
        }

        $this->blocks->replaceAll( $plan_id, array_map(
            static fn( array $b ): array => [
                'block_type'       => self::blockTypeFor( (string) ( $b['slot_category'] ?? '' ) ),
                'exercise_id'      => $b['exercise_id'] ?? null,
                'duration_minutes' => (int) ( $b['duration_minutes'] ?? 0 ),
                'intensity_band'   => $b['intensity_band'] ?? null,
            ],
            $draft['blocks']
        ) );

        return [ 'plan_id' => $plan_id, 'warnings' => $draft['warnings'], 'coverage' => $draft['coverage'] ];
    }

    // ---- context ---------------------------------------------------------

    /**
     * @param array<string,mixed> $payload
     * @param list<int>           $roster
     */
    private function buildContext( array $payload, array $roster ): SessionPlanContext {
        $ctx = new SessionPlanContext();

        $ctx->team_id      = (int) ( $payload['team_id'] ?? 0 );
        $ctx->season_id    = (int) ( $payload['season_id'] ?? 0 );
        $ctx->age_group    = (string) ( $payload['age_group'] ?? 'U13' );
        $ctx->session_date = (string) ( $payload['session_date'] ?? '' );
        $ctx->generated_by = get_current_user_id();

        $theme = $payload['tactical_theme'] ?? null;
        $ctx->tactical_theme = ( $theme === null || $theme === '' ) ? null : (string) $theme;

        $start = $payload['start_time'] ?? null;
        $ctx->start_time = ( $start === null || $start === '' ) ? null : (string) $start;

        if ( isset( $payload['requested_duration_minutes'] ) && $payload['requested_duration_minutes'] !== '' ) {
            $ctx->requested_duration_minutes = (int) $payload['requested_duration_minutes'];
        }

        // The roster drives the PHV lookup in WorkloadCapRule as well as
        // the coverage scoring, so it is the expected turnout rather than
        // the full squad list — see SquadSizeEstimator for why.
        $ctx->roster_player_ids = $roster;

        return $ctx;
    }

    /**
     * The players this session is being planned for.
     *
     * A caller may pass an explicit list (the wizard does, once the coach
     * has confirmed the expected turnout). Otherwise fall back to the
     * team's active roster.
     *
     * @param array<string,mixed> $payload
     * @return list<int>
     */
    private function rosterFor( array $payload ): array {
        $given = $payload['roster_player_ids'] ?? null;
        if ( is_array( $given ) && $given ) {
            return array_values( array_unique( array_filter( array_map( 'intval', $given ) ) ) );
        }

        return ( new SquadSizeEstimator() )->rosterFor( (int) ( $payload['team_id'] ?? 0 ) );
    }

    // ---- development targets --------------------------------------------

    /**
     * principle id => how many players in this squad have an open goal on
     * it. The weight is what lets selection prefer a drill six players
     * need over one that touches three principles nobody is working on.
     *
     * Goals answers this, not Training — the module owns its own data
     * (epic decision D13).
     *
     * @param list<int> $roster
     * @return array<int,int>
     */
    private function targetWeights( array $roster ): array {
        if ( ! $roster ) return [];

        $weights = [];
        foreach ( $this->goals->openPrincipleTargetsForPlayers( $roster ) as $principle_ids ) {
            foreach ( $principle_ids as $principle_id ) {
                $weights[ (int) $principle_id ] = ( $weights[ (int) $principle_id ] ?? 0 ) + 1;
            }
        }
        return $weights;
    }

    /**
     * exercise id => principle ids it trains, for the whole club. Loaded
     * once per generation rather than per candidate: the table is small
     * and the selection pass consults it for every slot.
     *
     * @return array<int,list<int>>
     */
    private function principleMapFor(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT exercise_id, principle_id
               FROM {$wpdb->prefix}tt_exercise_principles
              WHERE club_id = %d",
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];

        $map = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row->exercise_id ][] = (int) $row->principle_id;
        }
        return $map;
    }

    /**
     * Which players' open goals this draft actually covers — the answer
     * the wizard's review step shows by name, and the reason a coach
     * trusts the proposal.
     *
     * @param list<array<string,mixed>> $blocks
     * @param list<int>                 $roster
     * @return array{principle_ids:list<int>, player_ids:list<int>, missed_player_ids:list<int>}
     */
    private function coverageFor( array $blocks, array $roster ): array {
        $covered = [];
        foreach ( $blocks as $block ) {
            foreach ( (array) ( $block['covers_principles'] ?? [] ) as $principle_id ) {
                $covered[ (int) $principle_id ] = true;
            }
        }

        $hit    = [];
        $missed = [];
        foreach ( $this->goals->openPrincipleTargetsForPlayers( $roster ) as $player_id => $principle_ids ) {
            $touched = false;
            foreach ( $principle_ids as $principle_id ) {
                if ( isset( $covered[ (int) $principle_id ] ) ) { $touched = true; break; }
            }
            if ( $touched ) $hit[] = (int) $player_id; else $missed[] = (int) $player_id;
        }

        return [
            'principle_ids'     => array_map( 'intval', array_keys( $covered ) ),
            'player_ids'        => $hit,
            'missed_player_ids' => $missed,
        ];
    }

    // ---- wiring ----------------------------------------------------------

    /**
     * @param array<int,int>       $target_weights
     * @param array<int,list<int>> $principle_map
     */
    private function engineFor( array $target_weights, array $principle_map ): RulesEngine {
        return new RulesEngine(
            new AgeAdmissibilityRule( new VctAgeProfilesRepository() ),
            new MdContextRule( new NativeActivitiesReader(), new VctTeamSchedulesRepository() ),
            new SessionCompositionRule( new VctSessionTemplatesRepository() ),
            new TacticalThemeRule(),
            new ProgressionRule( new VctMacroBlocksRepository() ),
            new TrainingExerciseSelectionPass(
                new VctExercisesRepository(),
                new NativeRecentPicksProvider(),
                $target_weights,
                $principle_map
            ),
            new WorkloadCapRule( new VctPhvFlagsRepository() ),
            new RecoveryRule( new VctWorkloadSnapshotsRepository() ),
            new FinalizationPass()
        );
    }

    private function isBlocked( SessionPlanContext $ctx ): bool {
        foreach ( $ctx->warnings as $warning ) {
            if ( ( $warning['severity'] ?? '' ) === 'block' ) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $payload */
    private function titleFor( array $payload ): string {
        $theme = (string) ( $payload['tactical_theme'] ?? '' );
        $date  = (string) ( $payload['session_date'] ?? '' );

        if ( $theme !== '' && $date !== '' ) {
            /* translators: 1: tactical theme, 2: session date. */
            return mb_substr( sprintf( __( '%1$s · %2$s', 'talenttrack' ), $theme, $date ), 0, 190 );
        }
        if ( $theme !== '' ) return mb_substr( $theme, 0, 190 );

        return __( 'Training plan', 'talenttrack' );
    }

    /**
     * Map the engine's conditioning slot vocabulary onto the block types
     * the Training module shows. Anything unrecognised becomes `main`,
     * which is what the block repository would coerce it to anyway.
     */
    private static function blockTypeFor( string $slot_category ): string {
        switch ( $slot_category ) {
            case 'warmup':       return 'warmup';
            case 'technical':    return 'main';
            case 'sided_game':   return 'game';
            case 'conditioning': return 'main';
            case 'finishing':    return 'finishing';
            case 'cool_down':    return 'cooldown';
        }
        return 'main';
    }
}
