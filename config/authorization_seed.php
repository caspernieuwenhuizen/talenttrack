<?php
/**
 * TalentTrack authorization seed.
 *
 * Returns the default contents of `tt_authorization_matrix` — one PHP
 * array entry per (persona, entity, activity, scope_kind) tuple the
 * persona is allowed.
 *
 * Default = no access. List only what each persona CAN do; everything
 * else is implicitly denied.
 *
 * Activities:
 *   - 'read'           — view / list / single-record display
 *   - 'change'         — edit existing rows
 *   - 'create_delete'  — create new rows + delete existing rows (one
 *                         high-blast-radius verb; no separate 'delete')
 *
 * Scope kinds:
 *   - 'global'  — applies across the whole install
 *   - 'team'    — applies only to teams the user is assigned to
 *   - 'player'  — applies only to players the user is linked to
 *   - 'self'    — applies only to the user's own user/person record
 *
 * Coverage history:
 *   - #0033 (Sprint 1) shipped a strawman seed with ~30 entities.
 *   - #0071 (this file's current state) closed the gap to the canonical
 *     matrix at docs/authorization-matrix-extended.xlsx — ~107 entities,
 *     including sensitive-data rows (player_injuries, safeguarding_notes,
 *     player_potential, pdp_evidence_packet) that were previously
 *     enforced in code but invisible to the matrix. Same epic also
 *     applied the editorial decision narrowing Head of Development to a
 *     read-mostly persona outside player-development surfaces.
 *
 * Personas (8):
 *   player, parent, assistant_coach, head_coach, team_manager, scout,
 *   head_of_development, academy_admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Module class shortcuts — keep the table below readable.
$mod_players          = TT\Modules\Players\PlayersModule::class;
$mod_teams            = TT\Modules\Teams\TeamsModule::class;
$mod_people           = TT\Modules\People\PeopleModule::class;
$mod_evals            = TT\Modules\Evaluations\EvaluationsModule::class;
$mod_activities       = TT\Modules\Activities\ActivitiesModule::class;
$mod_goals            = TT\Modules\Goals\GoalsModule::class;
$mod_methodology      = TT\Modules\Methodology\MethodologyModule::class;
$mod_documentation    = TT\Modules\Documentation\DocumentationModule::class;
$mod_reports          = TT\Modules\Reports\ReportsModule::class;
$mod_stats            = TT\Modules\Stats\StatsModule::class;
$mod_invitations      = TT\Modules\Invitations\InvitationsModule::class;
$mod_authorization    = TT\Modules\Authorization\AuthorizationModule::class;
$mod_configuration    = TT\Modules\Configuration\ConfigurationModule::class;
$mod_license          = TT\Modules\License\LicenseModule::class;
$mod_backup           = TT\Modules\Backup\BackupModule::class;
$mod_onboarding       = TT\Modules\Onboarding\OnboardingModule::class;
$mod_demo             = TT\Modules\DemoData\DemoDataModule::class;
$mod_pdp              = TT\Modules\Pdp\PdpModule::class;
$mod_team_dev         = TT\Modules\TeamDevelopment\TeamDevelopmentModule::class;
$mod_analytics        = TT\Modules\Analytics\AnalyticsModule::class;
$mod_holidays         = class_exists( '\\TT\\Modules\\Holidays\\HolidaysModule' ) ? \TT\Modules\Holidays\HolidaysModule::class : $mod_authorization;
$mod_workflow         = TT\Modules\Workflow\WorkflowModule::class;
$mod_development      = TT\Modules\Development\DevelopmentModule::class;
$mod_trials           = class_exists( '\TT\Modules\Trials\TrialsModule' )           ? \TT\Modules\Trials\TrialsModule::class           : $mod_authorization;
$mod_journey          = class_exists( '\TT\Modules\Journey\JourneyModule' )         ? \TT\Modules\Journey\JourneyModule::class         : $mod_authorization;
$mod_staff_dev        = class_exists( '\TT\Modules\StaffDevelopment\StaffDevelopmentModule' ) ? \TT\Modules\StaffDevelopment\StaffDevelopmentModule::class : $mod_authorization;
$mod_threads          = class_exists( '\TT\Modules\Threads\ThreadsModule' )         ? \TT\Modules\Threads\ThreadsModule::class         : $mod_authorization;
$mod_push             = class_exists( '\TT\Modules\Push\PushModule' )               ? \TT\Modules\Push\PushModule::class               : $mod_authorization;
$mod_spond            = class_exists( '\TT\Modules\Spond\SpondModule' )             ? \TT\Modules\Spond\SpondModule::class             : $mod_authorization;
$mod_persona_dash     = class_exists( '\TT\Modules\PersonaDashboard\PersonaDashboardModule' ) ? \TT\Modules\PersonaDashboard\PersonaDashboardModule::class : $mod_authorization;
$mod_custom_css       = class_exists( '\TT\Modules\CustomCss\CustomCssModule' )     ? \TT\Modules\CustomCss\CustomCssModule::class     : $mod_authorization;
$mod_translations     = class_exists( '\TT\Modules\Translations\TranslationsModule' ) ? \TT\Modules\Translations\TranslationsModule::class : $mod_authorization;
$mod_prospects        = class_exists( '\TT\Modules\Prospects\ProspectsModule' )       ? \TT\Modules\Prospects\ProspectsModule::class     : $mod_authorization;
$mod_custom_widgets   = class_exists( '\TT\Modules\CustomWidgets\CustomWidgetsModule' ) ? \TT\Modules\CustomWidgets\CustomWidgetsModule::class : $mod_authorization;
$mod_i18n             = class_exists( '\TT\Modules\I18n\I18nModule' )                     ? \TT\Modules\I18n\I18nModule::class                     : $mod_authorization;
// #0095 — VCT module class shortcut. The module ships later in the
// epic (VCT-5 onwards introduces VctModule); until then the class
// doesn't exist, so the seed falls back to $mod_authorization. Mirrors
// the trials/journey/scout/persona-dashboard fallback pattern above.
$mod_vct              = class_exists( '\TT\Modules\Vct\VctModule' )                       ? \TT\Modules\Vct\VctModule::class                       : $mod_authorization;

/**
 * Helper: build a rows[] array from a compact spec.
 *
 * @param string $persona
 * @param array<string, array{0:string,1:string,2:string}> $entries  entity => [activities, scope, module]
 *        activities is a string of letters: 'r'=read, 'c'=change, 'd'=create_delete
 *        e.g. 'rcd' = all three; 'r' = read-only
 * @return array<int, array<string, string>>
 */
$expand = function ( string $persona, array $entries ): array {
    $rows = [];
    $activity_map = [ 'r' => 'read', 'c' => 'change', 'd' => 'create_delete' ];
    foreach ( $entries as $entity => $spec ) {
        [ $activities, $scope_kind, $module_class ] = $spec;
        foreach ( str_split( $activities ) as $letter ) {
            if ( ! isset( $activity_map[ $letter ] ) ) continue;
            $rows[] = [
                'persona'      => $persona,
                'entity'       => $entity,
                'activity'     => $activity_map[ $letter ],
                'scope_kind'   => $scope_kind,
                'module_class' => $module_class,
            ];
        }
    }
    return $rows;
};

return array_merge(
    // ─── PLAYER ─────────────────────────────────────────────────────
    $expand( 'player', [
        'my_card'                 => [ 'r',   'self',   $mod_players ],
        'my_profile'              => [ 'rc',  'self',   $mod_players ],
        'my_team'                 => [ 'r',   'self',   $mod_teams ],
        'my_evaluations'          => [ 'r',   'self',   $mod_evals ],
        // #1482 — tile-visibility entity for the Me-group "My evaluations"
        // tile, distinct from the `my_evaluations` data entity (which AC /
        // HC also hold at self scope for their authored-evals feed). Keeps
        // the player tile off a coach's dashboard under matrix-as-truth.
        'my_evaluations_panel'    => [ 'r',   'self',   $mod_evals ],
        'my_activities'           => [ 'r',   'self',   $mod_activities ],
        'my_goals'                => [ 'r',   'self',   $mod_goals ],
        'my_journey'              => [ 'r',   'self',   $mod_journey ],
        // v3.92.0 — tile-visibility entity for the Me-group "My PDP"
        // tile (matrix-only, no cap bridge). Distinct from `pdp_file`
        // which is the data entity coaches/HoD/scout legitimately read
        // at team/global scope. Without this disambiguation a coach
        // sees "Mijn POP" on their dashboard via the data-entity grant.
        'my_pdp_panel'            => [ 'r',   'self',   $mod_pdp ],
        'player_status'           => [ 'r',   'self',   $mod_players ],
        'pdp_file'                => [ 'r',   'self',   $mod_pdp ],
        'pdp_verdict'             => [ 'r',   'self',   $mod_pdp ],
        'pdp_conversations'       => [ 'r',   'self',   $mod_pdp ],
        'pdp_calendar_export'     => [ 'rc',  'self',   $mod_pdp ],
        'pdp_evidence_packet'     => [ 'r',   'self',   $mod_pdp ],
        'thread_messages'         => [ 'rc',  'self',   $mod_threads ],
        'documentation'           => [ 'r',   'global', $mod_documentation ],
        'persona_templates'       => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'      => [ 'rcd', 'self',   $mod_push ],
        'task_completion'         => [ 'rc',  'self',   $mod_workflow ],
        'seasons'                 => [ 'r',   'self',   $mod_pdp ],
        'player_injuries'         => [ 'r',   'self',   $mod_journey ],
        'player_timeline'         => [ 'r',   'self',   $mod_journey ],
    ] ),

    // ─── PARENT ─────────────────────────────────────────────────────
    $expand( 'parent', [
        'my_card'                 => [ 'r',   'player', $mod_players ],
        'my_profile'              => [ 'rc',  'self',   $mod_players ],
        'my_team'                 => [ 'r',   'player', $mod_teams ],
        'my_evaluations'          => [ 'r',   'player', $mod_evals ],
        // #1482 — see player block: parent reads the child's "My
        // evaluations" tile via this dedicated tile-visibility entity.
        'my_evaluations_panel'    => [ 'r',   'player', $mod_evals ],
        'my_activities'           => [ 'r',   'player', $mod_activities ],
        'my_goals'                => [ 'r',   'player', $mod_goals ],
        'my_journey'              => [ 'r',   'player', $mod_journey ],
        // v3.92.0 — see player block; Me-group "My PDP" tile entity.
        'my_pdp_panel'            => [ 'r',   'player', $mod_pdp ],
        'players'                 => [ 'r',   'player', $mod_players ],
        'team'                    => [ 'r',   'player', $mod_teams ],
        'evaluations'             => [ 'r',   'player', $mod_evals ],
        'goals'                   => [ 'r',   'player', $mod_goals ],
        'activities'              => [ 'r',   'player', $mod_activities ],
        'attendance'              => [ 'r',   'player', $mod_activities ],
        'player_status'           => [ 'r',   'player', $mod_players ],
        'documentation'           => [ 'r',   'global', $mod_documentation ],
        'invitations'             => [ 'c',   'player', $mod_invitations ],
        'pdp_file'                => [ 'r',   'player', $mod_pdp ],
        'pdp_verdict'             => [ 'r',   'player', $mod_pdp ],
        'pdp_conversations'       => [ 'r',   'player', $mod_pdp ],
        'pdp_calendar_export'     => [ 'rc',  'self',   $mod_pdp ],
        'pdp_evidence_packet'     => [ 'r',   'player', $mod_pdp ],
        'thread_messages'         => [ 'rc',  'player', $mod_threads ],
        'persona_templates'       => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'      => [ 'rcd', 'self',   $mod_push ],
        'task_completion'         => [ 'rc',  'self',   $mod_workflow ],
        'seasons'                 => [ 'r',   'player', $mod_pdp ],
        'player_injuries'         => [ 'r',   'player', $mod_journey ],
        'player_timeline'         => [ 'r',   'player', $mod_journey ],
        'trial_letters_generated' => [ 'r',   'player', $mod_trials ],
    ] ),

    // ─── ASSISTANT COACH ────────────────────────────────────────────
    // #1060 — AC is operational, HC is development. AC entries below
    // exclude every per-player development-data entity (evaluations,
    // pdp_*, behaviour_ratings, team_chemistry, dev_ideas) and their
    // tile-visibility counterparts. AC sees aggregates only (per team)
    // and runs the operational surfaces (activities, attendance,
    // match prep/execution, VCT). HC's per-player notes still flow
    // through to AC INSIDE the match-prep / match-execution surfaces
    // because those gate on `match_prep` / `match_execution` (not on
    // the development entities); the AC-with-kid case is handled by
    // the existing parent path.
    $expand( 'assistant_coach', [
        'holidays'                => [ 'r',   'global', $mod_holidays ],
        'team'                       => [ 'r',   'team',   $mod_teams ],
        'players'                    => [ 'r',   'team',   $mod_players ],
        'people'                     => [ 'r',   'team',   $mod_people ],
        'my_person'                  => [ 'rc',  'self',   $mod_people ],
        // #1060 — `evaluations` removed (HC professional-judgment data).
        'activities'                 => [ 'rc',  'team',   $mod_activities ],
        'goals'                      => [ 'rc',  'team',   $mod_goals ],
        'attendance'                 => [ 'rc',  'team',   $mod_activities ],
        'methodology'                => [ 'r',   'global', $mod_methodology ],
        'football_actions'           => [ 'r',   'global', $mod_methodology ],
        'reports'                    => [ 'r',   'team',   $mod_reports ],
        // #1106 — `rate_cards` + `compare` removed. Both aggregate
        // evaluation data #1060 stripped from AC; same loophole #1105
        // closed for `podium_panel`. `reports` stays — it's a surface
        // gate that upstreams per-report cap checks at the next layer.
        'documentation'              => [ 'r',   'global', $mod_documentation ],
        // #1060 — PDP entities (`pdp_file`, `pdp_verdict`, `pdp_conversations`)
        // removed. Safeguarding territory. `pdp_calendar_export` stays
        // because it's `self`-scoped (AC exports their OWN calendar slots).
        'pdp_calendar_export'        => [ 'rc',  'self',   $mod_pdp ],
        // #1060 — `team_chemistry` removed (development analytics).
        'workflow_tasks'             => [ 'r',   'self',   $mod_workflow ],
        'task_completion'            => [ 'rc',  'self',   $mod_workflow ],
        'frontend_admin'             => [ 'r',   'global', $mod_authorization ],
        // #1060 — `dev_ideas` removed (development authoring).
        'thread_messages'            => [ 'rc',  'team',   $mod_threads ],
        'staff_development'          => [ 'rc',  'self',   $mod_staff_dev ],
        'staff_certifications'       => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_pdp'               => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'             => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'       => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'    => [ 'rc',  'self',   $mod_staff_dev ],
        // v3.110.215 (#846) — coach reads their OWN authored evaluations
        // via the My-group "evaluations this week" KPI tile. Scope `self`
        // = filter by coach_id = current user. Kept for AC (operational
        // signal — when did I rate, not what's in the eval).
        'my_evaluations'             => [ 'r',   'self',   $mod_evals ],
        'persona_templates'          => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'         => [ 'rcd', 'self',   $mod_push ],
        'player_status'              => [ 'r',   'team',   $mod_players ],
        'player_status_breakdown'    => [ 'r',   'team',   $mod_players ],
        // #1060 — `player_behaviour_ratings` removed (development data).
        'trial_inputs'               => [ 'c',   'team',   $mod_trials ],
        'player_timeline'            => [ 'r',   'team',   $mod_journey ],
        'invitations'                => [ 'c',   'team',   $mod_invitations ],
        // #0079 — tile-visibility entities. Distinct from the data
        // entities above so the matrix can answer "see this tile" and
        // "read this data" separately. All matrix-only (no cap bridge).
        // #1060 — the tile-visibility panels for removed data entities
        // (`evaluations_panel`, `team_chemistry_panel`, `pdp_panel`)
        // are also removed so the AC dashboard doesn't render empty
        // tiles linking to surfaces they can't access.
        'team_roster_panel'          => [ 'r',   'team',   $mod_teams ],
        'coach_player_list_panel'    => [ 'r',   'team',   $mod_players ],
        'people_directory_panel'     => [ 'r',   'team',   $mod_people ],
        'activities_panel'           => [ 'r',   'team',   $mod_activities ],
        'goals_panel'                => [ 'r',   'team',   $mod_goals ],
        // #1105 — podium_panel removed. Podium surfaces top-rated players
        // across the team — a development-judgment artefact built off the
        // same evaluation data #1060 stripped from AC. Keeping the panel
        // entity without the underlying `evaluations` read makes the tile
        // link to a surface AC can't populate (and exposes leaderboard
        // signal AC isn't trusted with). Operators who explicitly granted
        // podium_panel via the Authorization admin (`is_default = 0`)
        // keep the override; migration 0138 only deletes the seeded row.
        // #0085 — player notes (staff-only running log on the player
        // file). #1060 — kept for AC: notes are operational (logistics,
        // safeguarding flags handled separately), not development data.
        'player_notes'               => [ 'rc',  'team',   $mod_threads ],
        // #0095 — VCT module. Assistant coach plans/edits/publishes
        // VCT sessions on their team scope (same as head coach — both
        // share the team scope). Matrix-only cap.
        'vct'                        => [ 'rcd', 'team',   $mod_vct ],
    ] ),

    // ─── HEAD COACH ─────────────────────────────────────────────────
    $expand( 'head_coach', [
        'holidays'                => [ 'r',   'global', $mod_holidays ],
        'team'                       => [ 'rc',  'team',   $mod_teams ],
        'players'                    => [ 'r',   'team',   $mod_players ],
        'people'                     => [ 'r',   'team',   $mod_people ],
        'my_person'                  => [ 'rc',  'self',   $mod_people ],
        'evaluations'                => [ 'rcd', 'team',   $mod_evals ],
        'activities'                 => [ 'rcd', 'team',   $mod_activities ],
        'goals'                      => [ 'rcd', 'team',   $mod_goals ],
        'attendance'                 => [ 'rcd', 'team',   $mod_activities ],
        'methodology'                => [ 'r',   'global', $mod_methodology ],
        'football_actions'           => [ 'r',   'global', $mod_methodology ],
        'player_status_methodology'  => [ 'r',   'global', $mod_methodology ],
        'reports'                    => [ 'r',   'team',   $mod_reports ],
        'rate_cards'                 => [ 'r',   'team',   $mod_stats ],
        'compare'                    => [ 'r',   'team',   $mod_stats ],
        'invitations'                => [ 'c',   'team',   $mod_invitations ],
        'documentation'              => [ 'r',   'global', $mod_documentation ],
        'pdp_file'                   => [ 'rcd', 'team',   $mod_pdp ],
        'pdp_verdict'                => [ 'rc',  'team',   $mod_pdp ],
        'pdp_conversations'          => [ 'rcd', 'team',   $mod_pdp ],
        'pdp_calendar_export'        => [ 'rc',  'self',   $mod_pdp ],
        'pdp_evidence_packet'        => [ 'rc',  'team',   $mod_pdp ],
        'pdp_planning'               => [ 'r',   'team',   $mod_pdp ],
        'seasons'                    => [ 'r',   'team',   $mod_pdp ],
        'team_chemistry'             => [ 'rc',  'team',   $mod_team_dev ],
        'workflow_tasks'             => [ 'r',   'self',   $mod_workflow ],
        'task_completion'            => [ 'rc',  'self',   $mod_workflow ],
        'frontend_admin'             => [ 'r',   'global', $mod_authorization ],
        'dev_ideas'                  => [ 'c',   'global', $mod_development ],
        'thread_messages'            => [ 'rc',  'team',   $mod_threads ],
        'staff_development'          => [ 'rc',  'team',   $mod_staff_dev ],
        'staff_certifications'       => [ 'r',   'team',   $mod_staff_dev ],
        'staff_mentorships'          => [ 'r',   'team',   $mod_staff_dev ],
        'my_staff_pdp'               => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'             => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'       => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'    => [ 'rc',  'self',   $mod_staff_dev ],
        // v3.110.215 (#846) — head coach reads their OWN authored
        // evaluations via the My-group KPI tile. Same scope as
        // assistant_coach.
        'my_evaluations'             => [ 'r',   'self',   $mod_evals ],
        // #0081 — Head coach reads prospects + test trainings at team
        // scope (their own age group's funnel). No write — prospects
        // are HoD/scout territory.
        'prospects'                  => [ 'r',   'team',   $mod_prospects ],
        'test_trainings'             => [ 'r',   'team',   $mod_prospects ],
        'persona_templates'          => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'         => [ 'rcd', 'self',   $mod_push ],
        'player_status'              => [ 'r',   'team',   $mod_players ],
        'player_status_breakdown'    => [ 'r',   'team',   $mod_players ],
        'player_potential'           => [ 'r',   'team',   $mod_players ],
        'player_behaviour_ratings'   => [ 'rc',  'team',   $mod_players ],
        'player_injuries'            => [ 'r',   'team',   $mod_journey ],
        'player_timeline'            => [ 'r',   'team',   $mod_journey ],
        'spond_integration'          => [ 'rc',  'team',   $mod_spond ],
        'trial_cases'                => [ 'rc',  'team',   $mod_trials ],
        'trial_inputs'               => [ 'c',   'team',   $mod_trials ],
        'trial_synthesis'            => [ 'r',   'team',   $mod_trials ],
        'trial_decisions'            => [ 'r',   'team',   $mod_trials ],
        'trial_letters_generated'    => [ 'r',   'team',   $mod_trials ],
        'trial_case_staff'           => [ 'r',   'team',   $mod_trials ],
        // #0079 — tile-visibility entities (matrix-only).
        'team_roster_panel'          => [ 'r',   'team',   $mod_teams ],
        'coach_player_list_panel'    => [ 'r',   'team',   $mod_players ],
        'people_directory_panel'     => [ 'r',   'team',   $mod_people ],
        'evaluations_panel'          => [ 'r',   'team',   $mod_evals ],
        'activities_panel'           => [ 'r',   'team',   $mod_activities ],
        'goals_panel'                => [ 'r',   'team',   $mod_goals ],
        'podium_panel'               => [ 'r',   'team',   $mod_stats ],
        'team_chemistry_panel'       => [ 'r',   'team',   $mod_team_dev ],
        'pdp_panel'                  => [ 'r',   'team',   $mod_pdp ],
        // #0085 — player notes (staff-only running log on the player file).
        'player_notes'               => [ 'rc',  'team',   $mod_threads ],
        // #0095 — VCT module. Head coach plans/edits/publishes VCT
        // sessions on their team scope. Spec letter mapping: rcdp
        // (read/create/delete/publish) → codebase rcd (read + change
        // for publish + create_delete for c+d). Matrix-only cap;
        // bridged via LegacyCapMapper as tt_vct_plan → (vct, read).
        'vct'                        => [ 'rcd', 'team',   $mod_vct ],
    ] ),

    // ─── TEAM MANAGER ───────────────────────────────────────────────
    $expand( 'team_manager', [
        'holidays'                => [ 'rcd', 'global', $mod_holidays ],
        'team'                       => [ 'r',   'team',   $mod_teams ],
        'players'                    => [ 'r',   'team',   $mod_players ],
        'people'                     => [ 'r',   'team',   $mod_people ],
        'my_person'                  => [ 'rc',  'self',   $mod_people ],
        'activities'                 => [ 'rcd', 'team',   $mod_activities ],
        'attendance'                 => [ 'rc',  'team',   $mod_activities ],
        'goals'                      => [ 'r',   'team',   $mod_goals ],
        'evaluations'                => [ 'r',   'team',   $mod_evals ],
        'invitations'                => [ 'c',   'team',   $mod_invitations ],
        'documentation'              => [ 'r',   'global', $mod_documentation ],
        'pdp_file'                   => [ 'r',   'team',   $mod_pdp ],
        'pdp_verdict'                => [ 'r',   'team',   $mod_pdp ],
        'pdp_conversations'          => [ 'r',   'team',   $mod_pdp ],
        'pdp_calendar_export'        => [ 'rc',  'self',   $mod_pdp ],
        'seasons'                    => [ 'r',   'team',   $mod_pdp ],
        'pdp_planning'               => [ 'r',   'team',   $mod_pdp ],
        'team_chemistry'             => [ 'r',   'team',   $mod_team_dev ],
        'workflow_tasks'             => [ 'r',   'self',   $mod_workflow ],
        'task_completion'            => [ 'rc',  'self',   $mod_workflow ],
        'frontend_admin'             => [ 'r',   'global', $mod_authorization ],
        'dev_ideas'                  => [ 'c',   'global', $mod_development ],
        'thread_messages'            => [ 'rc',  'team',   $mod_threads ],
        'football_actions'           => [ 'r',   'global', $mod_methodology ],
        'staff_development'          => [ 'rc',  'self',   $mod_staff_dev ],
        'staff_certifications'       => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_pdp'               => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'             => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'       => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'    => [ 'rc',  'self',   $mod_staff_dev ],
        'persona_templates'          => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'         => [ 'rcd', 'self',   $mod_push ],
        'player_status'              => [ 'r',   'team',   $mod_players ],
        'player_behaviour_ratings'   => [ 'r',   'team',   $mod_players ],
        'player_timeline'            => [ 'r',   'team',   $mod_journey ],
        // #0079 — tile-visibility entities (matrix-only). Team manager
        // sees coach-side tiles read-only; their write surface is
        // activities + attendance, gated by the data entities above.
        'team_roster_panel'          => [ 'r',   'team',   $mod_teams ],
        'coach_player_list_panel'    => [ 'r',   'team',   $mod_players ],
        'people_directory_panel'     => [ 'r',   'team',   $mod_people ],
        'evaluations_panel'          => [ 'r',   'team',   $mod_evals ],
        'activities_panel'           => [ 'r',   'team',   $mod_activities ],
        'goals_panel'                => [ 'r',   'team',   $mod_goals ],
        'podium_panel'               => [ 'r',   'team',   $mod_stats ],
        'team_chemistry_panel'       => [ 'r',   'team',   $mod_team_dev ],
        'pdp_panel'                  => [ 'r',   'team',   $mod_pdp ],
        // #0085 — player notes (staff-only running log on the player file).
        'player_notes'               => [ 'rc',  'team',   $mod_threads ],
    ] ),

    // ─── SCOUT ──────────────────────────────────────────────────────
    $expand( 'scout', [
        'players'                    => [ 'r',   'global', $mod_players ],
        'team'                       => [ 'r',   'global', $mod_teams ],
        // #1378 — was global. Scouts read evaluations only for players
        // they're linked to (trial/prospect assignment via scout_access);
        // full development-judgment history academy-wide was the widest
        // sensitive-data grant in the matrix. Mirrors the #1060 AC
        // tightening; migration 0154 backfills existing installs.
        'evaluations'                => [ 'r',   'player', $mod_evals ],
        'activities'                 => [ 'r',   'global', $mod_activities ],
        'goals'                      => [ 'r',   'global', $mod_goals ],
        'reports'                    => [ 'r',   'global', $mod_reports ],
        'rate_cards'                 => [ 'r',   'global', $mod_stats ],
        'compare'                    => [ 'r',   'global', $mod_stats ],
        'methodology'                => [ 'r',   'global', $mod_methodology ],
        'football_actions'           => [ 'r',   'global', $mod_methodology ],
        'trial_cases'                => [ 'rc',  'player', $mod_trials ],
        'trial_inputs'               => [ 'c',   'player', $mod_trials ],
        'trial_synthesis'            => [ 'r',   'player', $mod_trials ],
        'trial_letters_generated'    => [ 'r',   'player', $mod_trials ],
        'documentation'              => [ 'r',   'global', $mod_documentation ],
        // #1378 — pdp_file + pdp_verdict dropped entirely: promote/
        // release deliberations are not scouting inputs.
        'pdp_calendar_export'        => [ 'rc',  'self',   $mod_pdp ],
        'team_chemistry'             => [ 'r',   'global', $mod_team_dev ],
        'workflow_tasks'             => [ 'r',   'self',   $mod_workflow ],
        'task_completion'            => [ 'rc',  'self',   $mod_workflow ],
        // #0079 — `frontend_admin` removed. Scout never reaches admin-tier
        // surfaces (Configuration / Migrations / Application KPIs / Open
        // wp-admin). The grant was a v3.39.0 strawman holdover.
        'dev_ideas'                  => [ 'c',   'global', $mod_development ],
        'thread_messages'            => [ 'r',   'global', $mod_threads ],
        'staff_development'          => [ 'rc',  'self',   $mod_staff_dev ],
        'staff_certifications'       => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_pdp'               => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'             => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'       => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'    => [ 'rc',  'self',   $mod_staff_dev ],
        'persona_templates'          => [ 'r',   'global', $mod_persona_dash ],
        'push_subscriptions'         => [ 'rcd', 'self',   $mod_push ],
        'my_person'                  => [ 'rc',  'self',   $mod_people ],
        'player_status'              => [ 'r',   'global', $mod_players ],
        'scout_my_players'           => [ 'r',   'self',   $mod_reports ],
        // v3.110.154 — Scout × prospects now R/C/D global. Two scouts
        // working the same age group / regional pool need to see each
        // other's prospects so they don't duplicate visits or step on
        // each other's outreach. Personal dashboard widgets
        // (`MyRecentProspectsSource`, `MyProspects*` KPIs,
        // `AddProspectHeroWidget`) stay scoped to
        // `discovered_by_user_id = self` because those answer "what's
        // in MY funnel" — list/overview surfaces show all per the new
        // policy. test_trainings was already global.
        // Previous policy (#0081): `'rcd', 'self'`. Migration 0104
        // upgrades existing matrix rows in-place where the operator
        // hasn't customised them.
        'prospects'                  => [ 'rcd', 'global', $mod_prospects ],
        'test_trainings'             => [ 'r',   'global', $mod_prospects ],
        // #0085 — scouts write notes about players they observe across
        // the academy (cross-team scouting workflow), so RC[global].
        'player_notes'               => [ 'rc',  'global', $mod_threads ],
    ] ),

    // ─── HEAD OF DEVELOPMENT (post-#0071 narrowing) ─────────────────
    // Development-focused, read-mostly outside player-development surfaces.
    // Editor edits applied per the canonical matrix:
    //   - bulk_import: removed entirely (admin-only)
    //   - reports / workflow_templates / team_chemistry / spond_integration /
    //     persona_templates / translations_config / settings / lookups /
    //     custom_field_values / branding / feature_toggles / rating_scale:
    //     dropped to R-only (was RC/RCD)
    //   - dev_ideas: dropped from RCD to C (HoD submits + refines, only Admin
    //     promotes / deletes)
    //   - documentation: dropped to R only
    // What HoD keeps: full RCD on player-development surfaces (players, team,
    // people, evaluations, activities, goals, attendance, methodology,
    // pdp_*, trial_*, staff_development, etc.). audit_log + tasks_dashboard +
    // usage_stats remain R for governance visibility.
    $expand( 'head_of_development', [
        'holidays'                => [ 'rcd', 'global', $mod_holidays ],
        'team'                          => [ 'rcd', 'global', $mod_teams ],
        'players'                       => [ 'rcd', 'global', $mod_players ],
        'people'                        => [ 'rcd', 'global', $mod_people ],
        'my_person'                     => [ 'rc',  'self',   $mod_people ],
        'evaluations'                   => [ 'rcd', 'global', $mod_evals ],
        'activities'                    => [ 'rcd', 'global', $mod_activities ],
        'goals'                         => [ 'rcd', 'global', $mod_goals ],
        'attendance'                    => [ 'rcd', 'global', $mod_activities ],
        'methodology'                   => [ 'rcd', 'global', $mod_methodology ],
        'football_actions'              => [ 'rcd', 'global', $mod_methodology ],
        'player_status_methodology'     => [ 'rc',  'global', $mod_methodology ],
        // ── narrowed to R ──
        'reports'                       => [ 'r',   'global', $mod_reports ],
        'rate_cards'                    => [ 'r',   'global', $mod_stats ],
        'compare'                       => [ 'r',   'global', $mod_stats ],
        'usage_stats'                   => [ 'r',   'global', $mod_authorization ],
        'usage_stats_details'           => [ 'r',   'global', $mod_stats ],
        // #0083 Child 5 — central analytics surface (R global).
        'analytics'                     => [ 'r',   'global', $mod_analytics ],
        // #0078 Phase 5 — custom widget builder (RC global; HoD can author).
        'custom_widgets'                => [ 'rc',  'global', $mod_custom_widgets ],
        // #0090 Phase 1 — data-row translations (RC global; HoD can edit).
        'translations'                  => [ 'rc',  'global', $mod_i18n ],
        // bulk_import removed for HoD
        'evaluation_categories'         => [ 'r',   'global', $mod_evals ],
        'category_weights'              => [ 'r',   'global', $mod_evals ],
        // narrowed to R ↓
        'custom_field_values'           => [ 'r',   'global', $mod_configuration ],
        'feature_toggles'               => [ 'r',   'global', $mod_configuration ],
        'branding'                      => [ 'r',   'global', $mod_configuration ],
        'lookups'                       => [ 'r',   'global', $mod_configuration ],
        'rating_scale'                  => [ 'r',   'global', $mod_configuration ],
        'audit_log'                     => [ 'r',   'global', $mod_configuration ],
        'authorization_changelog'       => [ 'r',   'global', $mod_authorization ],
        'permission_debug'              => [ 'r',   'global', $mod_authorization ],
        'functional_role_assignments'   => [ 'rc',  'global', $mod_authorization ],
        'functional_role_definitions'   => [ 'r',   'global', $mod_authorization ],
        'invitations'                   => [ 'c',   'global', $mod_invitations ],
        'invitations_config'            => [ 'r',   'global', $mod_invitations ],
        // narrowed to R ↓
        'documentation'                 => [ 'r',   'global', $mod_documentation ],
        // ── kept full RCD ──
        'pdp_file'                      => [ 'rcd', 'global', $mod_pdp ],
        'pdp_verdict'                   => [ 'rcd', 'global', $mod_pdp ],
        'pdp_conversations'             => [ 'rcd', 'global', $mod_pdp ],
        'pdp_calendar_export'           => [ 'rc',  'self',   $mod_pdp ],
        'pdp_evidence_packet'           => [ 'rcd', 'global', $mod_pdp ],
        'pdp_planning'                  => [ 'r',   'global', $mod_pdp ],
        'seasons'                       => [ 'rc',  'global', $mod_pdp ],
        // narrowed to R ↓
        'team_chemistry'                => [ 'r',   'global', $mod_team_dev ],
        'spond_integration'             => [ 'r',   'global', $mod_spond ],
        'persona_templates'             => [ 'r',   'global', $mod_persona_dash ],
        'translations_config'           => [ 'r',   'global', $mod_translations ],
        // narrowed to C only (write but no delete) ↓
        'dev_ideas'                     => [ 'c',   'global', $mod_development ],
        // governance R reads ↓
        'frontend_admin'                => [ 'r',   'global', $mod_authorization ],
        'workflow_tasks'                => [ 'r',   'self',   $mod_workflow ],
        'tasks_dashboard'               => [ 'r',   'global', $mod_workflow ],
        'workflow_templates'            => [ 'r',   'global', $mod_workflow ],
        'task_completion'               => [ 'rc',  'self',   $mod_workflow ],
        'thread_messages'               => [ 'rcd', 'global', $mod_threads ],
        'staff_overview'                => [ 'r',   'global', $mod_staff_dev ],
        'staff_development'             => [ 'rcd', 'global', $mod_staff_dev ],
        'staff_certifications'          => [ 'r',   'global', $mod_staff_dev ],
        'staff_mentorships'             => [ 'rcd', 'global', $mod_staff_dev ],
        'my_staff_pdp'                  => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'                => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'          => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'      => [ 'rc',  'self',   $mod_staff_dev ],
        'push_subscriptions'            => [ 'rcd', 'self',   $mod_push ],
        // #0081 — Onboarding pipeline entities. HoD owns the funnel
        // globally; lifecycle is workflow-task-driven, not status-on-row.
        'prospects'                     => [ 'rcd', 'global', $mod_prospects ],
        'test_trainings'                => [ 'rcd', 'global', $mod_prospects ],
        // sensitive player data
        'player_status'                 => [ 'r',   'global', $mod_players ],
        'player_status_breakdown'       => [ 'r',   'global', $mod_players ],
        'player_potential'              => [ 'rcd', 'global', $mod_players ],
        'player_behaviour_ratings'      => [ 'rc',  'global', $mod_players ],
        'player_injuries'               => [ 'rc',  'global', $mod_journey ],
        'safeguarding_notes'            => [ 'rc',  'global', $mod_journey ],
        'cohort_transitions'            => [ 'r',   'global', $mod_journey ],
        'player_timeline'               => [ 'r',   'global', $mod_journey ],
        // trials full RCD
        'trial_cases'                   => [ 'rcd', 'global', $mod_trials ],
        'trial_inputs'                  => [ 'rcd', 'global', $mod_trials ],
        'trial_synthesis'               => [ 'r',   'global', $mod_trials ],
        'trial_decisions'               => [ 'rcd', 'global', $mod_trials ],
        'trial_letter_templates'        => [ 'rcd', 'global', $mod_trials ],
        'trial_letters_generated'       => [ 'rcd', 'global', $mod_trials ],
        'trial_tracks'                  => [ 'rcd', 'global', $mod_trials ],
        'trial_case_staff'              => [ 'rcd', 'global', $mod_trials ],
        'trial_extensions'              => [ 'rcd', 'global', $mod_trials ],
        'trial_reminders'               => [ 'rc',  'global', $mod_trials ],
        // scout sub-system (HoD assigns players to scouts)
        'scout_access'                  => [ 'rcd', 'global', $mod_reports ],
        'scout_history'                 => [ 'r',   'global', $mod_reports ],
        // impersonation: HoD reads the audit, cannot impersonate
        'impersonation_log'             => [ 'r',   'global', $mod_authorization ],
        // #0079 — tile-visibility entities (matrix-only). HoD's lens is
        // academy-wide; all coach panels at global scope. wp_admin_portal
        // intentionally NOT granted — admin-tier portal is academy_admin
        // only.
        'team_roster_panel'             => [ 'r',   'global', $mod_teams ],
        'coach_player_list_panel'       => [ 'r',   'global', $mod_players ],
        'people_directory_panel'        => [ 'r',   'global', $mod_people ],
        'evaluations_panel'             => [ 'r',   'global', $mod_evals ],
        'activities_panel'              => [ 'r',   'global', $mod_activities ],
        'goals_panel'                   => [ 'r',   'global', $mod_goals ],
        'podium_panel'                  => [ 'r',   'global', $mod_stats ],
        'team_chemistry_panel'          => [ 'r',   'global', $mod_team_dev ],
        'pdp_panel'                     => [ 'r',   'global', $mod_pdp ],
        // #0085 — HoD reads + writes + deletes notes across the academy.
        'player_notes'                  => [ 'rcd', 'global', $mod_threads ],
        // #0095 — VCT module. HoD owns the academy-wide VCT lens:
        // plans/edits/publishes any session, curates the shared
        // exercise catalogue + age profiles + macro-blocks, reads
        // workload aggregates. All matrix-only caps.
        'vct'                           => [ 'rcd', 'global', $mod_vct ],
        'vct_library'                   => [ 'rcd', 'global', $mod_vct ],
        'vct_workload'                  => [ 'r',   'global', $mod_vct ],
    ] ),

    // ─── ACADEMY ADMIN ──────────────────────────────────────────────
    $expand( 'academy_admin', [
        'holidays'                => [ 'rcd', 'global', $mod_holidays ],
        'team'                          => [ 'rcd', 'global', $mod_teams ],
        'players'                       => [ 'rcd', 'global', $mod_players ],
        'people'                        => [ 'rcd', 'global', $mod_people ],
        'my_person'                     => [ 'rc',  'self',   $mod_people ],
        'evaluations'                   => [ 'rcd', 'global', $mod_evals ],
        'activities'                    => [ 'rcd', 'global', $mod_activities ],
        'goals'                         => [ 'rcd', 'global', $mod_goals ],
        'attendance'                    => [ 'rcd', 'global', $mod_activities ],
        'methodology'                   => [ 'rcd', 'global', $mod_methodology ],
        'football_actions'              => [ 'rcd', 'global', $mod_methodology ],
        'player_status_methodology'     => [ 'rcd', 'global', $mod_methodology ],
        'reports'                       => [ 'rcd', 'global', $mod_reports ],
        'rate_cards'                    => [ 'r',   'global', $mod_stats ],
        'compare'                       => [ 'r',   'global', $mod_stats ],
        'usage_stats'                   => [ 'r',   'global', $mod_authorization ],
        'usage_stats_details'           => [ 'r',   'global', $mod_stats ],
        // #0083 Child 5 — central analytics surface (R global).
        'analytics'                     => [ 'r',   'global', $mod_analytics ],
        // #0078 Phase 5 — custom widget builder (RCD global; admin can author + delete).
        'custom_widgets'                => [ 'rcd', 'global', $mod_custom_widgets ],
        // #0090 Phase 1 — data-row translations (RCD global; admin can edit + delete).
        'translations'                  => [ 'rcd', 'global', $mod_i18n ],
        'bulk_import'                   => [ 'c',   'global', $mod_players ],
        'custom_field_values'           => [ 'rcd', 'global', $mod_configuration ],
        'custom_field_definitions'      => [ 'rcd', 'global', $mod_configuration ],
        'evaluation_categories'         => [ 'rcd', 'global', $mod_evals ],
        'category_weights'              => [ 'rc',  'global', $mod_evals ],
        'lookups'                       => [ 'rcd', 'global', $mod_configuration ],
        'feature_toggles'               => [ 'rc',  'global', $mod_configuration ],
        'branding'                      => [ 'rc',  'global', $mod_configuration ],
        'rating_scale'                  => [ 'rcd', 'global', $mod_configuration ],
        'functional_role_assignments'   => [ 'rcd', 'global', $mod_authorization ],
        'functional_role_definitions'   => [ 'rcd', 'global', $mod_authorization ],
        'roles'                         => [ 'rc',  'global', $mod_authorization ],
        'authorization_matrix'          => [ 'rc',  'global', $mod_authorization ],
        'authorization_changelog'       => [ 'rcd', 'global', $mod_authorization ],
        'permission_debug'              => [ 'r',   'global', $mod_authorization ],
        'matrix_preview_apply'          => [ 'rcd', 'global', $mod_authorization ],
        'module_state'                  => [ 'rc',  'global', $mod_authorization ],
        'invitations'                   => [ 'rcd', 'global', $mod_invitations ],
        'invitations_config'            => [ 'rcd', 'global', $mod_invitations ],
        'license'                       => [ 'rc',  'global', $mod_license ],
        'backup'                        => [ 'rcd', 'global', $mod_backup ],
        'setup_wizard'                  => [ 'rc',  'global', $mod_onboarding ],
        'demo_data'                     => [ 'rcd', 'global', $mod_demo ],
        'migrations'                    => [ 'rc',  'global', $mod_configuration ],
        'audit_log'                     => [ 'r',   'global', $mod_configuration ],
        'documentation'                 => [ 'rcd', 'global', $mod_documentation ],
        'pdp_file'                      => [ 'rcd', 'global', $mod_pdp ],
        'pdp_verdict'                   => [ 'rcd', 'global', $mod_pdp ],
        'pdp_conversations'             => [ 'rcd', 'global', $mod_pdp ],
        'pdp_calendar_export'           => [ 'rc',  'self',   $mod_pdp ],
        'pdp_evidence_packet'           => [ 'rcd', 'global', $mod_pdp ],
        'pdp_planning'                  => [ 'r',   'global', $mod_pdp ],
        'seasons'                       => [ 'rcd', 'global', $mod_pdp ],
        'team_chemistry'                => [ 'rcd', 'global', $mod_team_dev ],
        'frontend_admin'                => [ 'r',   'global', $mod_authorization ],
        'settings'                      => [ 'rcd', 'global', $mod_configuration ],
        // #1152 — academy_admin can view + complete tasks assigned to
        // others. Operational continuity when the assignee is ill or
        // unresponsive. Other personas stay at `self` scope; only
        // academy_admin gets the global takeover affordance.
        'workflow_tasks'                => [ 'r',   'global', $mod_workflow ],
        'tasks_dashboard'               => [ 'r',   'global', $mod_workflow ],
        'workflow_templates'            => [ 'rcd', 'global', $mod_workflow ],
        'task_completion'               => [ 'rc',  'global', $mod_workflow ],
        'dev_ideas'                     => [ 'rcd', 'global', $mod_development ],
        'thread_messages'               => [ 'rcd', 'global', $mod_threads ],
        'staff_overview'                => [ 'r',   'global', $mod_staff_dev ],
        'staff_development'             => [ 'rcd', 'global', $mod_staff_dev ],
        'staff_certifications'          => [ 'rcd', 'global', $mod_staff_dev ],
        'staff_mentorships'             => [ 'rcd', 'global', $mod_staff_dev ],
        'my_staff_pdp'                  => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_goals'                => [ 'rc',  'self',   $mod_staff_dev ],
        'my_staff_evaluations'          => [ 'r',   'self',   $mod_staff_dev ],
        'my_staff_certifications'      => [ 'rc',  'self',   $mod_staff_dev ],
        'spond_integration'             => [ 'rcd', 'global', $mod_spond ],
        'persona_templates'             => [ 'rcd', 'global', $mod_persona_dash ],
        'custom_css'                    => [ 'rcd', 'global', $mod_custom_css ],
        'translations_config'           => [ 'rcd', 'global', $mod_translations ],
        'push_subscriptions'            => [ 'rcd', 'self',   $mod_push ],
        'player_status'                 => [ 'r',   'global', $mod_players ],
        'player_status_breakdown'       => [ 'r',   'global', $mod_players ],
        'player_potential'              => [ 'rcd', 'global', $mod_players ],
        'player_behaviour_ratings'      => [ 'rcd', 'global', $mod_players ],
        'player_injuries'               => [ 'rcd', 'global', $mod_journey ],
        'safeguarding_notes'            => [ 'rcd', 'global', $mod_journey ],
        'cohort_transitions'            => [ 'r',   'global', $mod_journey ],
        'player_timeline'               => [ 'rcd', 'global', $mod_journey ],
        'trial_cases'                   => [ 'rcd', 'global', $mod_trials ],
        'trial_inputs'                  => [ 'rcd', 'global', $mod_trials ],
        'trial_synthesis'               => [ 'r',   'global', $mod_trials ],
        'trial_decisions'               => [ 'rcd', 'global', $mod_trials ],
        'trial_letter_templates'        => [ 'rcd', 'global', $mod_trials ],
        'trial_letters_generated'       => [ 'rcd', 'global', $mod_trials ],
        'trial_tracks'                  => [ 'rcd', 'global', $mod_trials ],
        'trial_case_staff'              => [ 'rcd', 'global', $mod_trials ],
        'trial_extensions'              => [ 'rcd', 'global', $mod_trials ],
        'trial_reminders'               => [ 'rcd', 'global', $mod_trials ],
        'scout_access'                  => [ 'rcd', 'global', $mod_reports ],
        'scout_history'                 => [ 'rcd', 'global', $mod_reports ],
        // Impersonation: only Academy Admin holds the act-cap; the
        // tenant guard in ImpersonationService enforces single-club.
        'impersonation_action'          => [ 'c',   'global', $mod_authorization ],
        'impersonation_log'             => [ 'rcd', 'global', $mod_authorization ],
        // #0079 — tile-visibility entities (matrix-only). Admin sees
        // every coach panel at global scope and is the sole holder of
        // the wp_admin_portal grant.
        'team_roster_panel'             => [ 'r',   'global', $mod_teams ],
        'coach_player_list_panel'       => [ 'r',   'global', $mod_players ],
        'people_directory_panel'        => [ 'r',   'global', $mod_people ],
        'evaluations_panel'             => [ 'r',   'global', $mod_evals ],
        'activities_panel'              => [ 'r',   'global', $mod_activities ],
        'goals_panel'                   => [ 'r',   'global', $mod_goals ],
        'podium_panel'                  => [ 'r',   'global', $mod_stats ],
        'team_chemistry_panel'          => [ 'r',   'global', $mod_team_dev ],
        'pdp_panel'                     => [ 'r',   'global', $mod_pdp ],
        'wp_admin_portal'               => [ 'r',   'global', $mod_authorization ],
        // #0081 — Onboarding pipeline. Admin RCD global; same as HoD.
        'prospects'                     => [ 'rcd', 'global', $mod_prospects ],
        'test_trainings'                => [ 'rcd', 'global', $mod_prospects ],
        // #0085 — Admin reads + writes + deletes notes across the academy.
        'player_notes'                  => [ 'rcd', 'global', $mod_threads ],
        // #0095 — VCT module. Admin parity with HoD on VCT surfaces:
        // full session participation, library admin, workload read.
        // All matrix-only caps.
        'vct'                           => [ 'rcd', 'global', $mod_vct ],
        'vct_library'                   => [ 'rcd', 'global', $mod_vct ],
        'vct_workload'                  => [ 'r',   'global', $mod_vct ],
    ] )
);
