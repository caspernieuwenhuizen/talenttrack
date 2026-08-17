<?php
namespace TT\Modules\DemoData;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\DemoData\Generators\ActivityGenerator;
use TT\Modules\DemoData\Generators\EvaluationGenerator;
use TT\Modules\DemoData\Generators\GoalGenerator;
use TT\Modules\DemoData\Generators\GuardianGenerator;
use TT\Modules\DemoData\Generators\InjuryGenerator;
use TT\Modules\DemoData\Generators\MeasurementGenerator;
use TT\Modules\DemoData\Generators\PdpGenerator;
use TT\Modules\DemoData\Generators\PeopleGenerator;
use TT\Modules\DemoData\Generators\PlayerGenerator;
use TT\Modules\DemoData\Generators\PlayerProfileGenerator;
use TT\Modules\DemoData\Generators\PlayerReportGenerator;
use TT\Modules\DemoData\Generators\TeamGenerator;

/**
 * DemoCoverage — the single source of truth for what demo generation covers.
 *
 * Every `tt_*` table the schema creates has exactly one entry here, in one
 * of three states:
 *
 *   - **generated** — a `written_by` producer fills it, and `entity_type` +
 *     `category` say how the cleaner reaches the rows again.
 *   - **planned** — in scope, generator not written yet; the value is the
 *     issue that will write it.
 *   - **exempt** — holds no demo-worthy records, with the reason stated.
 *
 * `tools/check-demo-coverage.php` fails when a table is in none of them, so
 * a new migration forces the decision instead of silently widening the gap.
 *
 * The cleaner derives its delete order from `depends_on` by topological
 * sort. A generator that writes rows the cleaner can't reach leaves
 * permanent orphans on the operator's install, which is why wipe reach is
 * declared here next to the generator rather than in a parallel constant.
 */
class DemoCoverage {

    /** A table whose rows a producer writes during a demo run. */
    public const STATE_GENERATED = 'generated';
    /** In scope for the coverage epic; generator not written yet. */
    public const STATE_PLANNED = 'planned';
    /** Deliberately never generated; `exempt` carries the reason. */
    public const STATE_EXEMPT = 'exempt';

    /**
     * Producer markers for rows no dedicated generator class writes.
     *
     * `hook` — written by the runtime module's own subscriber, because the
     * demo generator fires the same action the real feature fires (journey
     * events work this way, so the timeline shape can't drift from
     * production). Those rows still need tagging to be wipeable; see
     * `DemoGenerator::tagUntaggedJourneyEvents()`.
     */
    public const WRITTEN_BY_HOOK  = 'hook';
    public const WRITTEN_BY_EXCEL = 'excel';

    /**
     * table => entry. See the class docblock for the three states.
     *
     * @var array<string, array<string,mixed>>
     */
    public const MANIFEST = [

        // ===== Core domain =====

        'tt_people' => [
            'entity_type' => 'person',
            'category'    => 'people',
            'written_by'  => PeopleGenerator::class,
            'depends_on'  => [],
        ],
        'tt_teams' => [
            'entity_type' => 'team',
            'category'    => 'teams',
            'written_by'  => TeamGenerator::class,
            'depends_on'  => [],
        ],
        'tt_team_people' => [
            'entity_type' => 'team_person',
            'category'    => 'teams',
            'written_by'  => TeamGenerator::class,
            'depends_on'  => [ 'team', 'person' ],
        ],
        'tt_players' => [
            'entity_type' => 'player',
            'category'    => 'players',
            'written_by'  => PlayerGenerator::class,
            'depends_on'  => [ 'team' ],
        ],

        // ===== Activities + attendance =====

        'tt_activities' => [
            'entity_type' => 'activity',
            'category'    => 'activities',
            'written_by'  => ActivityGenerator::class,
            'depends_on'  => [ 'team' ],
        ],
        'tt_attendance' => [
            'entity_type' => 'attendance',
            'category'    => 'activities',
            'written_by'  => ActivityGenerator::class,
            'depends_on'  => [ 'activity', 'player' ],
        ],

        // ===== Evaluations =====

        'tt_evaluations' => [
            'entity_type' => 'evaluation',
            'category'    => 'evaluations',
            'written_by'  => EvaluationGenerator::class,
            'depends_on'  => [ 'player', 'team' ],
        ],
        'tt_eval_ratings' => [
            'entity_type' => 'eval_rating',
            'category'    => 'evaluations',
            'written_by'  => EvaluationGenerator::class,
            'depends_on'  => [ 'evaluation' ],
        ],

        // ===== Goals =====

        'tt_goals' => [
            'entity_type' => 'goal',
            'category'    => 'goals',
            'written_by'  => GoalGenerator::class,
            'depends_on'  => [ 'player' ],
        ],

        // ===== Journey =====

        // Written by JourneyEventSubscriber, not by a generator: the player
        // and evaluation generators fire `tt_player_created` /
        // `tt_evaluation_saved` so demo timelines are byte-identical in
        // shape to production ones. Tagged in a post-pass.
        'tt_player_events' => [
            'entity_type' => 'player_event',
            'category'    => 'journey',
            'written_by'  => self::WRITTEN_BY_HOOK,
            'depends_on'  => [ 'player' ],
        ],

        // ===== Trials =====

        // The Excel importer writes trial cases today; the procedural
        // generator does not (#2467). Listed as generated because rows
        // exist after an Excel run and must be wipeable.
        'tt_trial_cases' => [
            'entity_type' => 'trial_case',
            'category'    => 'trials',
            'written_by'  => self::WRITTEN_BY_EXCEL,
            'depends_on'  => [ 'player' ],
        ],
        'tt_trial_tracks'            => [ 'planned' => '#2467' ],
        'tt_trial_case_staff'        => [ 'planned' => '#2467' ],
        'tt_trial_case_staff_inputs' => [ 'planned' => '#2467' ],
        'tt_trial_extensions'        => [ 'planned' => '#2467' ],
        'tt_test_trainings'          => [ 'planned' => '#2465' ],

        // ===== Player spine =====

        // No surrogate id — PK is (player_id, parent_user_id). See
        // TABLE_QUIRKS for how the wipe reaches it.
        'tt_player_parents' => [
            'entity_type' => 'player_parent',
            'category'    => 'guardians',
            'written_by'  => GuardianGenerator::class,
            'depends_on'  => [ 'player' ],
        ],
        'tt_player_parent_visibility' => [
            'entity_type' => 'player_parent_visibility',
            'category'    => 'guardians',
            'written_by'  => GuardianGenerator::class,
            'depends_on'  => [ 'player' ],
        ],
        'tt_player_injuries' => [
            'entity_type' => 'player_injury',
            'category'    => 'injuries',
            'written_by'  => InjuryGenerator::class,
            'depends_on'  => [ 'player' ],
        ],
        'tt_player_team_history' => [
            'entity_type' => 'player_team_history',
            'category'    => 'player_profile',
            'written_by'  => PlayerProfileGenerator::class,
            'depends_on'  => [ 'player', 'team' ],
        ],
        'tt_player_attribute_values' => [
            'entity_type' => 'player_attribute_value',
            'category'    => 'player_profile',
            'written_by'  => PlayerProfileGenerator::class,
            'depends_on'  => [ 'player' ],
        ],
        'tt_custom_fields' => [
            'entity_type' => 'custom_field',
            'category'    => 'player_profile',
            'written_by'  => PlayerProfileGenerator::class,
            'depends_on'  => [],
        ],
        'tt_custom_values' => [
            'entity_type' => 'custom_value',
            'category'    => 'player_profile',
            'written_by'  => PlayerProfileGenerator::class,
            'depends_on'  => [ 'custom_field', 'player' ],
        ],
        'tt_goal_links' => [
            'entity_type' => 'goal_link',
            'category'    => 'player_profile',
            'written_by'  => PlayerProfileGenerator::class,
            'depends_on'  => [ 'goal', 'evaluation' ],
        ],
        'tt_player_reports' => [
            'entity_type' => 'player_report',
            'category'    => 'reports',
            'written_by'  => PlayerReportGenerator::class,
            'depends_on'  => [ 'player' ],
        ],

        // ===== Measurements =====

        'tt_measurement_definitions' => [
            'entity_type' => 'measurement_definition',
            'category'    => 'measurements',
            'written_by'  => MeasurementGenerator::class,
            'depends_on'  => [],
        ],
        'tt_measurement_targets' => [
            'entity_type' => 'measurement_target',
            'category'    => 'measurements',
            'written_by'  => MeasurementGenerator::class,
            'depends_on'  => [ 'measurement_definition' ],
        ],
        'tt_measurement_sessions' => [
            'entity_type' => 'measurement_session',
            'category'    => 'measurements',
            'written_by'  => MeasurementGenerator::class,
            'depends_on'  => [ 'measurement_definition', 'team' ],
        ],
        'tt_measurement_results' => [
            'entity_type' => 'measurement_result',
            'category'    => 'measurements',
            'written_by'  => MeasurementGenerator::class,
            'depends_on'  => [ 'measurement_session', 'measurement_definition', 'player' ],
        ],

        // ===== Seasons + PDP =====

        'tt_seasons' => [
            'entity_type' => 'season',
            'category'    => 'pdp',
            'written_by'  => PdpGenerator::class,
            'depends_on'  => [],
        ],
        'tt_pdp_files' => [
            'entity_type' => 'pdp_file',
            'category'    => 'pdp',
            'written_by'  => PdpGenerator::class,
            'depends_on'  => [ 'player', 'season' ],
        ],
        'tt_pdp_conversations' => [
            'entity_type' => 'pdp_conversation',
            'category'    => 'pdp',
            'written_by'  => PdpGenerator::class,
            'depends_on'  => [ 'pdp_file' ],
        ],
        'tt_pdp_verdicts' => [
            'entity_type' => 'pdp_verdict',
            'category'    => 'pdp',
            'written_by'  => PdpGenerator::class,
            'depends_on'  => [ 'pdp_file' ],
        ],
        'tt_pdp_calendar_links' => [
            'entity_type' => 'pdp_calendar_link',
            'category'    => 'pdp',
            'written_by'  => PdpGenerator::class,
            'depends_on'  => [ 'pdp_conversation' ],
        ],

        // ===== Activity content + match day (#2465) =====

        'tt_exercises'                        => [ 'planned' => '#2465' ],
        'tt_exercise_team_overrides'          => [ 'planned' => '#2465' ],
        'tt_activity_exercises'               => [ 'planned' => '#2465' ],
        'tt_activity_principles'              => [ 'planned' => '#2465' ],
        'tt_match_prep'                       => [ 'planned' => '#2465' ],
        'tt_match_prep_availability'          => [ 'planned' => '#2465' ],
        'tt_match_prep_lineup'                => [ 'planned' => '#2465' ],
        'tt_match_prep_player_goals'          => [ 'planned' => '#2465' ],
        'tt_match_prep_roles'                 => [ 'planned' => '#2465' ],
        'tt_match_execution'                  => [ 'planned' => '#2465' ],
        'tt_match_execution_goal_events'      => [ 'planned' => '#2465' ],
        'tt_match_execution_substitutions'    => [ 'planned' => '#2465' ],
        'tt_match_execution_tracked_events'   => [ 'planned' => '#2465' ],
        'tt_holidays'                         => [ 'planned' => '#2465' ],

        // ===== Team development (#2466) =====

        'tt_formations'                 => [ 'planned' => '#2466' ],
        'tt_formation_positions'        => [ 'planned' => '#2466' ],
        'tt_team_formations'            => [ 'planned' => '#2466' ],
        'tt_team_playing_styles'        => [ 'planned' => '#2466' ],
        'tt_set_pieces'                 => [ 'planned' => '#2466' ],
        'tt_team_blueprints'            => [ 'planned' => '#2466' ],
        'tt_team_blueprint_assignments' => [ 'planned' => '#2466' ],
        'tt_team_chemistry_snapshots'   => [ 'planned' => '#2466' ],
        'tt_team_chemistry_pairings'    => [ 'planned' => '#2466' ],

        // ===== Pipeline (#2467) =====

        'tt_prospects'             => [ 'planned' => '#2467' ],
        'tt_scouting_plan_visits'  => [ 'planned' => '#2467' ],
        'tt_tournaments'           => [ 'planned' => '#2467' ],
        'tt_tournament_matches'    => [ 'planned' => '#2467' ],
        'tt_tournament_squad'      => [ 'planned' => '#2467' ],
        'tt_tournament_assignments' => [ 'planned' => '#2467' ],

        // ===== Staff, comms, operator records (#2468) =====

        'tt_staff_evaluations'    => [ 'planned' => '#2468' ],
        'tt_staff_eval_ratings'   => [ 'planned' => '#2468' ],
        'tt_staff_goals'          => [ 'planned' => '#2468' ],
        'tt_staff_pdp'            => [ 'planned' => '#2468' ],
        'tt_staff_certifications' => [ 'planned' => '#2468' ],
        'tt_staff_mentorships'    => [ 'planned' => '#2468' ],
        'tt_thread_messages'      => [ 'planned' => '#2468' ],
        'tt_thread_reads'         => [ 'planned' => '#2468' ],
        'tt_saved_filters'        => [ 'planned' => '#2468' ],
        'tt_report_presets'       => [ 'planned' => '#2468' ],
        'tt_workflow_tasks'       => [ 'planned' => '#2468' ],
        'tt_invitations'          => [ 'planned' => '#2468' ],

        // ===== Exempt — plugin infrastructure =====

        'tt_migrations'  => [ 'exempt' => 'Migration ledger. Schema bookkeeping, not club content.' ],
        'tt_demo_tags'   => [ 'exempt' => 'The demo registry itself. Tagging the tags would recurse.' ],
        'tt_audit_log'   => [ 'exempt' => 'Audit trail of real operator actions. Fabricating entries would corrupt the record a real audit reads.' ],
        'tt_error_log'   => [ 'exempt' => 'Error log. Synthetic errors would send operators chasing bugs that never happened.' ],
        'tt_usage_events' => [ 'exempt' => 'Telemetry of real usage. Seeded rows would skew the adoption stats the academy reads.' ],
        'tt_module_state' => [ 'exempt' => 'Per-club module enable/disable state. Operator configuration.' ],
        'tt_feature_state' => [ 'exempt' => 'Per-feature under-development flags. Operator configuration.' ],
        'tt_wizard_drafts' => [ 'exempt' => 'In-flight wizard drafts, per user session. Nothing to demonstrate.' ],
        'tt_user_mfa'    => [ 'exempt' => 'MFA secrets for real accounts. Never fabricated.' ],
        'tt_push_subscriptions' => [ 'exempt' => 'Browser push endpoints tied to real devices.' ],
        'tt_custom_css_history' => [ 'exempt' => 'Operator CSS revision history.' ],
        'tt_custom_widgets' => [ 'exempt' => 'Operator-authored dashboard widgets.' ],
        'tt_dev_ideas'   => [ 'exempt' => 'Internal development tracker, not academy data.' ],
        'tt_dev_tracks'  => [ 'exempt' => 'Internal development tracker, not academy data.' ],

        // ===== Exempt — configuration + authorization =====

        'tt_config'  => [ 'exempt' => 'Per-club configuration singletons. The generate form writes the few it needs directly.' ],
        'tt_lookups' => [ 'exempt' => 'Admin-editable vocabularies, seeded by migrations. Generators consume lookup ids, never invent rows.' ],
        'tt_authorization_matrix'    => [ 'exempt' => 'Capability matrix, seeded by migrations. Demo runs must not alter who can do what.' ],
        'tt_authorization_changelog' => [ 'exempt' => 'Audit trail of capability changes.' ],
        'tt_roles'                     => [ 'exempt' => 'Role definitions, seeded by migrations.' ],
        'tt_role_permissions'          => [ 'exempt' => 'Role/permission mapping, seeded by migrations.' ],
        'tt_functional_roles'          => [ 'exempt' => 'Functional-role vocabulary, seeded by migrations.' ],
        'tt_functional_role_auth_roles' => [ 'exempt' => 'Functional-role to auth-role mapping, seeded by migrations.' ],
        'tt_user_role_scopes'          => [ 'exempt' => 'Per-user role scoping, written by the invite/assignment flows.' ],

        // ===== Exempt — i18n =====

        'tt_translations'            => [ 'exempt' => 'Translation store. Generators embed per-language content pools instead.' ],
        'tt_translations_cache'      => [ 'exempt' => 'Derived cache of tt_translations.' ],
        'tt_translations_usage'      => [ 'exempt' => 'Translation-usage telemetry.' ],
        'tt_translation_source_meta' => [ 'exempt' => 'Translation source bookkeeping.' ],

        // ===== Exempt — reference data seeded by migrations =====

        'tt_player_attribute_defs' => [ 'exempt' => 'The 23 chemistry attribute definitions are seeded by migration 0178; #2463 fills values against them rather than inventing more.' ],
        'tt_eval_categories'      => [ 'exempt' => 'Evaluation category tree, seeded by migrations and admin-editable. The Excel path documents it as a reference sheet.' ],
        'tt_eval_type_categories' => [ 'exempt' => 'Evaluation-type to category mapping, seeded by migrations.' ],
        'tt_category_weights'     => [ 'exempt' => 'Per-age-group category weights, seeded by migrations and admin-editable.' ],
        'tt_measurement_levels'   => [ 'exempt' => 'Measurement status levels, seeded by migration 0192.' ],
        'tt_exercise_categories'  => [ 'exempt' => 'Exercise category vocabulary, seeded by migrations.' ],
        'tt_exercise_principles'  => [ 'exempt' => 'Exercise/principle reference mapping, seeded by migrations.' ],
        'tt_football_actions'     => [ 'exempt' => 'Football-action vocabulary, seeded by migrations.' ],
        'tt_principles'           => [ 'exempt' => 'Methodology principles, seeded by migrations (JO13 set in 0206).' ],
        'tt_methodologies'                    => [ 'exempt' => 'Methodology sets, seeded by migrations.' ],
        'tt_methodology_assets'               => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_framework_primers'    => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_influence_factors'    => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_learning_goals'       => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_phases'               => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_principle_links'      => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_sub_principles'       => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_tactical_scenes'      => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_methodology_visions'              => [ 'exempt' => 'Methodology reference content, seeded by migrations.' ],
        'tt_formation_templates'  => [ 'exempt' => 'Four 4-3-3 templates seeded by migration 0032; #2466 assigns them to teams rather than duplicating shapes.' ],
        'tt_chemistry_position_matrix' => [ 'exempt' => 'Position-affinity reference matrix, seeded by migrations.' ],
        'tt_trial_letter_templates'    => [ 'exempt' => 'Trial letter templates, seeded by migrations and admin-editable.' ],

        // ===== Exempt — external integrations and side effects =====

        'tt_player_strava_connections' => [ 'exempt' => 'Requires real Strava OAuth tokens; a fabricated connection cannot sync and renders as broken.' ],
        'tt_player_activities'         => [ 'exempt' => 'Strava-imported activities, downstream of an exempt OAuth connection. Demo Strava data needs its own issue.' ],
        'tt_scheduled_reports'         => [ 'exempt' => 'A demo row would dispatch real email on the next cron run.' ],
        'tt_workflow_triggers'         => [ 'exempt' => 'Workflow trigger configuration, seeded by migrations.' ],
        'tt_workflow_template_config'  => [ 'exempt' => 'Workflow template configuration, seeded by migrations.' ],
        'tt_workflow_event_log'        => [ 'exempt' => 'Log of real workflow-engine events.' ],
    ];

    /**
     * Operator-facing wipe/generate categories.
     *
     * `cascade` is the ordered (children-first) entity-type list a category
     * expands to. It encodes FK dependency *and* operator preference: wiping
     * teams also drops the activities and evaluations tied to them, but not
     * the players — an operator rebuilding a team structure keeps the squad.
     * That asymmetry is why cascades are declared rather than derived from
     * `depends_on`.
     *
     * `tier` splits the generate + wipe forms into "master data" (rows other
     * categories hang off) and "dependent entities".
     *
     * @var array<string, array{tier:string, cascade:string[]}>
     */
    public const CATEGORIES = [
        'teams' => [
            'tier'    => 'master',
            'cascade' => [
                'eval_rating', 'evaluation', 'attendance', 'activity',
                'measurement_result', 'measurement_session',
                'team_person', 'team',
            ],
        ],
        'people' => [
            'tier'    => 'master',
            'cascade' => [ 'team_person', 'person' ],
        ],
        'players' => [
            'tier'    => 'master',
            // goal_link references both a goal and an evaluation, so it has
            // to go before either of them.
            'cascade' => [
                'goal_link', 'eval_rating', 'evaluation', 'attendance', 'goal',
                'player_event', 'trial_case', 'player_report', 'player_attribute_value',
                'player_team_history', 'player_injury', 'player_parent_visibility',
                'custom_value', 'player_parent',
                'measurement_result', 'pdp_calendar_link', 'pdp_verdict',
                'pdp_conversation', 'pdp_file',
                'player',
            ],
        ],
        // `run_order` fixes the sequence dependent generators run in. All of
        // them draw from one seeded MT stream, so reordering them changes
        // every value downstream — the same (seed, preset) would stop
        // reproducing. These three numbers preserve the pre-#2462 order
        // (evaluations, activities, goals); a new wave appends rather than
        // inserting, unless it deliberately accepts a fingerprint change.
        'evaluations' => [
            'tier'        => 'dependent',
            'run_order'   => 10,
            'cascade'     => [ 'eval_rating', 'evaluation' ],
            'excel_sheet' => 'evaluations',
        ],
        'activities' => [
            'tier'        => 'dependent',
            'run_order'   => 20,
            'cascade'     => [ 'attendance', 'activity' ],
            'excel_sheet' => 'sessions',
        ],
        'goals' => [
            'tier'        => 'dependent',
            'run_order'   => 30,
            'cascade'     => [ 'goal' ],
            'excel_sheet' => 'goals',
        ],
        'guardians' => [
            'tier'      => 'dependent',
            'run_order' => 40,
            'cascade'   => [ 'player_parent_visibility', 'player_parent' ],
        ],
        'injuries' => [
            'tier'      => 'dependent',
            'run_order' => 50,
            'cascade'   => [ 'player_injury' ],
        ],
        'player_profile' => [
            'tier'      => 'dependent',
            'run_order' => 60,
            'cascade'   => [ 'goal_link', 'custom_value', 'custom_field', 'player_attribute_value', 'player_team_history' ],
        ],
        'reports' => [
            'tier'      => 'dependent',
            'run_order' => 70,
            'cascade'   => [ 'player_report' ],
        ],
        'measurements' => [
            'tier'      => 'dependent',
            'run_order' => 80,
            'cascade'   => [ 'measurement_result', 'measurement_session', 'measurement_target', 'measurement_definition' ],
        ],
        'pdp' => [
            'tier'      => 'dependent',
            'run_order' => 90,
            'cascade'   => [ 'pdp_calendar_link', 'pdp_verdict', 'pdp_conversation', 'pdp_file', 'season' ],
        ],
        'journey' => [
            'tier'    => 'dependent',
            'cascade' => [ 'player_event' ],
        ],
        'trials' => [
            'tier'    => 'dependent',
            'cascade' => [ 'trial_case' ],
        ],
    ];

    /**
     * Tables whose rows lack a surrogate `id`, or whose `id` column is named
     * something else. `delete_by` deletes rows whose column matches the
     * wiped id set of another entity type — the only way to reach a pivot
     * table keyed on its two foreign keys.
     *
     * @var array<string, array{id_column?:string, delete_by?:array{column:string, entity_type:string}, club_scoped?:bool}>
     */
    public const TABLE_QUIRKS = [
        // PRIMARY KEY (player_id, parent_user_id) — migration 0025, no id.
        // The generator tags one row per player (entity_id = player_id), so
        // the wipe deletes every guardian link for the wiped players.
        'tt_player_parents' => [
            'delete_by' => [ 'column' => 'player_id', 'entity_type' => 'player_parent' ],
        ],
    ];

    /** @return array<string,mixed>|null */
    public static function entry( string $table ): ?array {
        return self::MANIFEST[ $table ] ?? null;
    }

    public static function stateOf( string $table ): ?string {
        $entry = self::entry( $table );
        if ( $entry === null ) return null;
        if ( isset( $entry['exempt'] ) ) return self::STATE_EXEMPT;
        if ( isset( $entry['planned'] ) ) return self::STATE_PLANNED;
        return self::STATE_GENERATED;
    }

    /**
     * Tables in the generated state, keyed by table name.
     *
     * @return array<string, array<string,mixed>>
     */
    public static function generatedTables(): array {
        $out = [];
        foreach ( self::MANIFEST as $table => $entry ) {
            if ( self::stateOf( $table ) === self::STATE_GENERATED ) {
                $out[ $table ] = $entry;
            }
        }
        return $out;
    }

    /**
     * entity_type => [ table, id_column ], the cleaner's lookup.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function tableMap(): array {
        $out = [];
        foreach ( self::generatedTables() as $table => $entry ) {
            $type   = (string) $entry['entity_type'];
            $quirks = self::TABLE_QUIRKS[ $table ] ?? [];
            $out[ $type ] = [ $table, (string) ( $quirks['id_column'] ?? 'id' ) ];
        }
        return $out;
    }

    /**
     * The Excel sheet whose presence means the workbook already covered this
     * category, so the procedural generator should stand down. Null when no
     * sheet maps to it.
     */
    public static function excelSheetFor( string $category ): ?string {
        $sheet = self::CATEGORIES[ $category ]['excel_sheet'] ?? null;
        return $sheet !== null ? (string) $sheet : null;
    }

    /** @return array{column:string, entity_type:string}|null */
    public static function deleteBy( string $table ): ?array {
        $quirks = self::TABLE_QUIRKS[ $table ] ?? [];
        return isset( $quirks['delete_by'] ) ? $quirks['delete_by'] : null;
    }

    public static function isClubScoped( string $table ): bool {
        $quirks = self::TABLE_QUIRKS[ $table ] ?? [];
        return ! isset( $quirks['club_scoped'] ) || (bool) $quirks['club_scoped'];
    }

    /**
     * Entity types in dependency-safe delete order: a type is listed before
     * every type it depends on, so children go first. Ties break on the
     * manifest's own order for a stable result across runs.
     *
     * @return string[]
     */
    public static function deleteOrder(): array {
        $depends = [];
        foreach ( self::generatedTables() as $entry ) {
            $type = (string) $entry['entity_type'];
            $deps = array_map( 'strval', (array) ( $entry['depends_on'] ?? [] ) );
            $depends[ $type ] = array_values( array_unique( array_merge( $depends[ $type ] ?? [], $deps ) ) );
        }

        // Depth = longest dependency chain below a type. Deeper types are
        // dependents, so they get deleted first.
        $depth = [];
        $resolve = function ( string $type, array $seen ) use ( &$resolve, $depends, &$depth ): int {
            if ( isset( $depth[ $type ] ) ) return $depth[ $type ];
            if ( isset( $seen[ $type ] ) ) return 0;   // cycle guard
            $seen[ $type ] = true;
            $max = 0;
            foreach ( $depends[ $type ] ?? [] as $dep ) {
                if ( ! isset( $depends[ $dep ] ) ) continue;
                $max = max( $max, 1 + $resolve( $dep, $seen ) );
            }
            $depth[ $type ] = $max;
            return $max;
        };

        $types = array_keys( $depends );
        foreach ( $types as $type ) {
            $resolve( $type, [] );
        }

        $order = $types;
        usort( $order, static function ( string $a, string $b ) use ( $depth, $types ): int {
            $byDepth = ( $depth[ $b ] ?? 0 ) <=> ( $depth[ $a ] ?? 0 );
            if ( $byDepth !== 0 ) return $byDepth;
            return array_search( $a, $types, true ) <=> array_search( $b, $types, true );
        } );

        return $order;
    }

    /**
     * Category keys, master tier first, in manifest declaration order.
     *
     * @return string[]
     */
    public static function categoryKeys( ?string $tier = null ): array {
        $out = [];
        foreach ( self::CATEGORIES as $key => $cfg ) {
            if ( $tier !== null && (string) $cfg['tier'] !== $tier ) continue;
            $out[] = $key;
        }
        return $out;
    }

    /**
     * The entity types a category expands to, ordered children-first.
     *
     * @return string[]
     */
    public static function cascade( string $category ): array {
        $cascade = self::CATEGORIES[ $category ]['cascade'] ?? [];
        $known   = self::tableMap();
        // Drop types whose generator hasn't landed yet, so a cascade can
        // name a future entity type without breaking today's wipe.
        return array_values( array_filter(
            array_map( 'strval', $cascade ),
            static function ( string $type ) use ( $known ): bool {
                return isset( $known[ $type ] );
            }
        ) );
    }

    /**
     * Generator classes that write dependent-tier categories, ordered by
     * `run_order`. The order is part of the reproducibility contract — see
     * the note on `CATEGORIES`.
     *
     * @return array<string, class-string> category => generator class
     */
    public static function dependentGenerators(): array {
        $out = [];
        foreach ( self::generatedTables() as $entry ) {
            $category = (string) $entry['category'];
            $writer   = (string) $entry['written_by'];
            if ( ( self::CATEGORIES[ $category ]['tier'] ?? '' ) !== 'dependent' ) continue;
            if ( $writer === self::WRITTEN_BY_HOOK || $writer === self::WRITTEN_BY_EXCEL ) continue;
            if ( isset( $out[ $category ] ) ) continue;
            $out[ $category ] = $writer;
        }

        uksort( $out, static function ( string $a, string $b ): int {
            $oa = (int) ( self::CATEGORIES[ $a ]['run_order'] ?? PHP_INT_MAX );
            $ob = (int) ( self::CATEGORIES[ $b ]['run_order'] ?? PHP_INT_MAX );
            return $oa <=> $ob;
        } );

        return $out;
    }

    /** Human-readable category label. Translatable; not stored. */
    public static function categoryLabel( string $category ): string {
        $labels = [
            'teams'       => __( 'Teams', 'talenttrack' ),
            'people'      => __( 'People', 'talenttrack' ),
            'players'     => __( 'Players', 'talenttrack' ),
            'activities'  => __( 'Activities', 'talenttrack' ),
            'evaluations' => __( 'Evaluations', 'talenttrack' ),
            'goals'       => __( 'Goals', 'talenttrack' ),
            'journey'     => __( 'Journey events', 'talenttrack' ),
            'trials'      => __( 'Trial cases', 'talenttrack' ),
            'guardians'   => __( 'Guardians', 'talenttrack' ),
            'injuries'    => __( 'Injuries', 'talenttrack' ),
            'player_profile' => __( 'Player profile', 'talenttrack' ),
            'reports'     => __( 'Player reports', 'talenttrack' ),
            'measurements' => __( 'Measurements', 'talenttrack' ),
            'pdp'         => __( 'PDP cycle', 'talenttrack' ),
        ];
        return $labels[ $category ] ?? $category;
    }

    /** One-line description of what a category's cascade reaches. */
    public static function categoryHint( string $category ): string {
        $hints = [
            'teams'       => __( 'Also wipes team_person, activities, attendance, evaluations and eval_ratings on those teams.', 'talenttrack' ),
            'people'      => __( 'Also wipes team_person assignments. The matching WP users stay — use "Wipe demo users" below for those.', 'talenttrack' ),
            'players'     => __( 'Also wipes attendance, evaluations, eval_ratings, goals, journey events and trial cases tied to those players.', 'talenttrack' ),
            'activities'  => __( 'Also wipes attendance for those activities.', 'talenttrack' ),
            'evaluations' => __( 'Also wipes per-category eval_ratings.', 'talenttrack' ),
            'goals'       => __( 'Per-player development goals.', 'talenttrack' ),
            'journey'     => __( 'Timeline events written by the journey subscriber during generation.', 'talenttrack' ),
            'trials'      => __( 'Trial cases imported from an Excel workbook.', 'talenttrack' ),
            'guardians'   => __( 'Guardian links to the demo parent accounts, plus each player\'s parent-visibility grants.', 'talenttrack' ),
            'injuries'    => __( 'Injury records with their return-to-play dates and the journey events they raise.', 'talenttrack' ),
            'player_profile' => __( 'Age-group history, attribute values, the club\'s custom fields and their values, and goal-to-evaluation links.', 'talenttrack' ),
            'reports'     => __( 'Generated player reports. No share links or recipients are created.', 'talenttrack' ),
            'measurements' => __( 'The testing battery, its per-age-group target bands, team testing sessions and one result per player.', 'talenttrack' ),
            'pdp'         => __( 'The season, one development dossier per player, its conversation cycle, calendar links and verdicts.', 'talenttrack' ),
        ];
        return $hints[ $category ] ?? '';
    }
}
