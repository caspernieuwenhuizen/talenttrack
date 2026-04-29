<?php
/**
 * TalentTrack authorization seed (#0033 Sprint 1).
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
 *   - 'create_delete'  — create new rows + delete existing rows (a single
 *                         high-blast-radius verb; no separate 'delete')
 *
 * Scope kinds:
 *   - 'global'  — applies across the whole install
 *   - 'team'    — applies only to teams the user is assigned to
 *                  (resolved at runtime via tt_user_role_scopes)
 *   - 'player'  — applies only to players the user is linked to
 *                  (their own record, their child via tt_player_parents,
 *                  or assigned trial players)
 *   - 'self'    — applies only to the user's own user/person record
 *
 * Each row also names the owning module_class. When that module is
 * disabled (Sprint 5), MatrixGate::can() returns false short-circuit.
 *
 * Sprint 1 ships a strawman; Sprint 3 admin UI lets the academy admin
 * tweak rows; Sprint 8 ships the migration preview. Per-team
 * customization of personas is out-of-scope for v1.
 *
 * Personas (8):
 *   player, parent, assistant_coach, head_coach, head_of_development,
 *   scout, team_manager, academy_admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Module class shortcuts — keep the table below readable.
$mod_players       = TT\Modules\Players\PlayersModule::class;
$mod_teams         = TT\Modules\Teams\TeamsModule::class;
$mod_people        = TT\Modules\People\PeopleModule::class;
$mod_evals         = TT\Modules\Evaluations\EvaluationsModule::class;
$mod_activities    = TT\Modules\Activities\ActivitiesModule::class;
$mod_goals         = TT\Modules\Goals\GoalsModule::class;
$mod_methodology   = TT\Modules\Methodology\MethodologyModule::class;
$mod_documentation = TT\Modules\Documentation\DocumentationModule::class;
$mod_reports       = TT\Modules\Reports\ReportsModule::class;
$mod_stats         = TT\Modules\Stats\StatsModule::class;
$mod_invitations   = TT\Modules\Invitations\InvitationsModule::class;
$mod_authorization = TT\Modules\Authorization\AuthorizationModule::class;
$mod_configuration = TT\Modules\Configuration\ConfigurationModule::class;
$mod_license       = TT\Modules\License\LicenseModule::class;
$mod_backup        = TT\Modules\Backup\BackupModule::class;
$mod_onboarding    = TT\Modules\Onboarding\OnboardingModule::class;
$mod_demo          = TT\Modules\DemoData\DemoDataModule::class;
$mod_pdp           = TT\Modules\Pdp\PdpModule::class;
$mod_team_dev      = TT\Modules\TeamDevelopment\TeamDevelopmentModule::class;
$mod_workflow      = TT\Modules\Workflow\WorkflowModule::class;
$mod_development   = TT\Modules\Development\DevelopmentModule::class;

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
    // A player viewing their own data. Everything self-scoped.
    $expand( 'player', [
        'my_card'        => [ 'r',  'self', $mod_players ],
        'my_evaluations' => [ 'r',  'self', $mod_evals ],
        'my_activities'    => [ 'r',  'self', $mod_activities ],
        'my_goals'       => [ 'r',  'self', $mod_goals ],
        'my_team'        => [ 'r',  'self', $mod_teams ],
        'my_profile'     => [ 'rc', 'self', $mod_players ],
        'documentation'  => [ 'r',  'global', $mod_documentation ],
        'pdp_file'       => [ 'r',  'self', $mod_pdp ],
        'pdp_verdict'    => [ 'r',  'self', $mod_pdp ],
    ] ),

    // ─── PARENT ─────────────────────────────────────────────────────
    // Parent of one or more players (introduced by #0032 invitations).
    // Read access scoped to their child(ren).
    $expand( 'parent', [
        'my_card'        => [ 'r',  'player', $mod_players ],
        'my_evaluations' => [ 'r',  'player', $mod_evals ],
        'my_activities'    => [ 'r',  'player', $mod_activities ],
        'my_goals'       => [ 'r',  'player', $mod_goals ],
        'my_team'        => [ 'r',  'player', $mod_teams ],
        'my_profile'     => [ 'rc', 'self',   $mod_players ],
        'players'        => [ 'r',  'player', $mod_players ],
        'evaluations'    => [ 'r',  'player', $mod_evals ],
        'goals'          => [ 'r',  'player', $mod_goals ],
        'activities'       => [ 'r',  'player', $mod_activities ],
        'attendance'     => [ 'r',  'player', $mod_activities ],
        'team'           => [ 'r',  'player', $mod_teams ],
        'documentation'  => [ 'r',  'global', $mod_documentation ],
        // Re-invite the other guardian for their child.
        'invitations'    => [ 'c',  'player', $mod_invitations ],
        'pdp_file'       => [ 'r',  'player', $mod_pdp ],
        'pdp_verdict'    => [ 'r',  'player', $mod_pdp ],
    ] ),

    // ─── ASSISTANT COACH ────────────────────────────────────────────
    // Per-team. Same WP role as head_coach; distinguished by Functional
    // Role assignment is_head_coach=0 (Sprint 7). For Sprint 1, persona
    // resolution treats all tt_coach as head_coach until Sprint 7 ships
    // the FR flag.
    $expand( 'assistant_coach', [
        'team'              => [ 'r',   'team', $mod_teams ],
        'players'           => [ 'r',   'team', $mod_players ],
        'people'            => [ 'r',   'team', $mod_people ],
        'evaluations'       => [ 'rc',  'team', $mod_evals ],
        'activities'          => [ 'rc',  'team', $mod_activities ],
        'goals'             => [ 'rc',  'team', $mod_goals ],
        'attendance'        => [ 'rc',  'team', $mod_activities ],
        'methodology'       => [ 'r',   'global', $mod_methodology ],
        'reports'           => [ 'r',   'team', $mod_reports ],
        'rate_cards'        => [ 'r',   'team', $mod_stats ],
        'compare'           => [ 'r',   'team', $mod_stats ],
        'documentation'     => [ 'r',   'global', $mod_documentation ],
        'pdp_file'          => [ 'rc',  'team', $mod_pdp ],
        'pdp_verdict'       => [ 'r',   'team', $mod_pdp ],
        'team_chemistry'    => [ 'r',   'team', $mod_team_dev ],
        'workflow_tasks'    => [ 'r',   'self', $mod_workflow ],
        'frontend_admin'    => [ 'r',   'global', $mod_authorization ],
        'dev_ideas'         => [ 'c',   'global', $mod_development ],
    ] ),

    // ─── HEAD COACH ─────────────────────────────────────────────────
    // Per-team. All assistant_coach permissions plus delete + invite +
    // edit team metadata.
    $expand( 'head_coach', [
        'team'              => [ 'rc',  'team', $mod_teams ],
        'players'           => [ 'r',   'team', $mod_players ],
        'people'            => [ 'r',   'team', $mod_people ],
        'evaluations'       => [ 'rcd', 'team', $mod_evals ],
        'activities'          => [ 'rcd', 'team', $mod_activities ],
        'goals'             => [ 'rcd', 'team', $mod_goals ],
        'attendance'        => [ 'rcd', 'team', $mod_activities ],
        'methodology'       => [ 'r',   'global', $mod_methodology ],
        'reports'           => [ 'r',   'team', $mod_reports ],
        'rate_cards'        => [ 'r',   'team', $mod_stats ],
        'compare'           => [ 'r',   'team', $mod_stats ],
        'invitations'       => [ 'c',   'team', $mod_invitations ],
        'documentation'     => [ 'r',   'global', $mod_documentation ],
        'pdp_file'          => [ 'rcd', 'team', $mod_pdp ],
        'pdp_verdict'       => [ 'rc',  'team', $mod_pdp ],
        'team_chemistry'    => [ 'rc',  'team', $mod_team_dev ],
        'workflow_tasks'    => [ 'r',   'self', $mod_workflow ],
        'frontend_admin'    => [ 'r',   'global', $mod_authorization ],
        'dev_ideas'         => [ 'c',   'global', $mod_development ],
    ] ),

    // ─── HEAD OF DEVELOPMENT ────────────────────────────────────────
    // Cross-team. Full coaching surface globally + roster admin.
    $expand( 'head_of_development', [
        'team'                       => [ 'rcd', 'global', $mod_teams ],
        'players'                    => [ 'rcd', 'global', $mod_players ],
        'people'                     => [ 'rcd', 'global', $mod_people ],
        'evaluations'                => [ 'rcd', 'global', $mod_evals ],
        'activities'                   => [ 'rcd', 'global', $mod_activities ],
        'goals'                      => [ 'rcd', 'global', $mod_goals ],
        'attendance'                 => [ 'rcd', 'global', $mod_activities ],
        'methodology'                => [ 'rcd', 'global', $mod_methodology ],
        'reports'                    => [ 'rcd', 'global', $mod_reports ],
        'rate_cards'                 => [ 'r',   'global', $mod_stats ],
        'compare'                    => [ 'r',   'global', $mod_stats ],
        'usage_stats'                => [ 'r',   'global', $mod_authorization ],
        'bulk_import'                => [ 'c',   'global', $mod_players ],
        'custom_field_values'        => [ 'rc',  'global', $mod_configuration ],
        'functional_role_assignments'=> [ 'rc',  'global', $mod_authorization ],
        'invitations'                => [ 'c',   'global', $mod_invitations ],
        'documentation'              => [ 'rc',  'global', $mod_documentation ],
        'pdp_file'                   => [ 'rcd', 'global', $mod_pdp ],
        'pdp_verdict'                => [ 'rcd', 'global', $mod_pdp ],
        'team_chemistry'             => [ 'rcd', 'global', $mod_team_dev ],
        'frontend_admin'             => [ 'r',   'global', $mod_authorization ],
        'settings'                   => [ 'rc',  'global', $mod_configuration ],
        'workflow_tasks'             => [ 'r',   'self',   $mod_workflow ],
        'tasks_dashboard'            => [ 'r',   'global', $mod_workflow ],
        'workflow_templates'         => [ 'rcd', 'global', $mod_workflow ],
        'dev_ideas'                  => [ 'rcd', 'global', $mod_development ],
    ] ),

    // ─── SCOUT (refined) ────────────────────────────────────────────
    // Read across all teams + write on assigned trial cases (#0017
    // declared but no surface yet; harmless until that ships).
    $expand( 'scout', [
        'players'       => [ 'r',  'global', $mod_players ],
        'team'          => [ 'r',  'global', $mod_teams ],
        'evaluations'   => [ 'r',  'global', $mod_evals ],
        'activities'      => [ 'r',  'global', $mod_activities ],
        'goals'         => [ 'r',  'global', $mod_goals ],
        'reports'       => [ 'r',  'global', $mod_reports ],
        'rate_cards'    => [ 'r',  'global', $mod_stats ],
        'compare'       => [ 'r',  'global', $mod_stats ],
        'methodology'   => [ 'r',  'global', $mod_methodology ],
        'trial_cases'   => [ 'rc', 'player', $mod_authorization ],
        'documentation' => [ 'r',  'global', $mod_documentation ],
        'pdp_file'      => [ 'r',  'global', $mod_pdp ],
        'pdp_verdict'   => [ 'r',  'global', $mod_pdp ],
        'team_chemistry'=> [ 'r',  'global', $mod_team_dev ],
        'workflow_tasks'=> [ 'r',  'self',   $mod_workflow ],
        'frontend_admin'=> [ 'r',  'global', $mod_authorization ],
        'dev_ideas'     => [ 'c',  'global', $mod_development ],
    ] ),

    // ─── TEAM MANAGER ───────────────────────────────────────────────
    // Per-team admin: schedule, attendance, parent comms. No coaching
    // edits (eval/goal stay read-only).
    $expand( 'team_manager', [
        'team'          => [ 'r',   'team', $mod_teams ],
        'players'       => [ 'r',   'team', $mod_players ],
        'people'        => [ 'r',   'team', $mod_people ],
        'activities'      => [ 'rcd', 'team', $mod_activities ],
        'attendance'    => [ 'rc',  'team', $mod_activities ],
        'goals'         => [ 'r',   'team', $mod_goals ],
        'evaluations'   => [ 'r',   'team', $mod_evals ],
        'invitations'   => [ 'c',   'team', $mod_invitations ],
        'documentation' => [ 'r',   'global', $mod_documentation ],
        'pdp_file'      => [ 'r',   'team', $mod_pdp ],
        'pdp_verdict'   => [ 'r',   'team', $mod_pdp ],
        'team_chemistry'=> [ 'r',   'team', $mod_team_dev ],
        'workflow_tasks'=> [ 'r',   'self', $mod_workflow ],
        'frontend_admin'=> [ 'r',   'global', $mod_authorization ],
        'dev_ideas'     => [ 'c',   'global', $mod_development ],
    ] ),

    // ─── ACADEMY ADMIN ──────────────────────────────────────────────
    // The WP `administrator`. Full access globally + module/matrix mgmt.
    $expand( 'academy_admin', [
        'team'                        => [ 'rcd', 'global', $mod_teams ],
        'players'                     => [ 'rcd', 'global', $mod_players ],
        'people'                      => [ 'rcd', 'global', $mod_people ],
        'evaluations'                 => [ 'rcd', 'global', $mod_evals ],
        'activities'                    => [ 'rcd', 'global', $mod_activities ],
        'goals'                       => [ 'rcd', 'global', $mod_goals ],
        'attendance'                  => [ 'rcd', 'global', $mod_activities ],
        'methodology'                 => [ 'rcd', 'global', $mod_methodology ],
        'reports'                     => [ 'rcd', 'global', $mod_reports ],
        'rate_cards'                  => [ 'r',   'global', $mod_stats ],
        'compare'                     => [ 'r',   'global', $mod_stats ],
        'usage_stats'                 => [ 'r',   'global', $mod_authorization ],
        'bulk_import'                 => [ 'c',   'global', $mod_players ],
        'custom_field_values'         => [ 'rcd', 'global', $mod_configuration ],
        'custom_field_definitions'    => [ 'rcd', 'global', $mod_configuration ],
        'evaluation_categories'       => [ 'rcd', 'global', $mod_evals ],
        'category_weights'            => [ 'rc',  'global', $mod_evals ],
        'lookups'                     => [ 'rcd', 'global', $mod_configuration ],
        'feature_toggles'             => [ 'rc',  'global', $mod_configuration ],
        'branding'                    => [ 'rc',  'global', $mod_configuration ],
        'functional_role_assignments' => [ 'rcd', 'global', $mod_authorization ],
        'roles'                       => [ 'rc',  'global', $mod_authorization ],
        'authorization_matrix'        => [ 'rc',  'global', $mod_authorization ],
        'module_state'                => [ 'rc',  'global', $mod_authorization ],
        'invitations'                 => [ 'rcd', 'global', $mod_invitations ],
        'license'                     => [ 'rc',  'global', $mod_license ],
        'backup'                      => [ 'rcd', 'global', $mod_backup ],
        'setup_wizard'                => [ 'rc',  'global', $mod_onboarding ],
        'demo_data'                   => [ 'rcd', 'global', $mod_demo ],
        'migrations'                  => [ 'rc',  'global', $mod_configuration ],
        'audit_log'                   => [ 'r',   'global', $mod_configuration ],
        'documentation'               => [ 'rcd', 'global', $mod_documentation ],
        'pdp_file'                    => [ 'rcd', 'global', $mod_pdp ],
        'pdp_verdict'                 => [ 'rcd', 'global', $mod_pdp ],
        'team_chemistry'              => [ 'rcd', 'global', $mod_team_dev ],
        // #0033 follow-up — meta-entities the legacy cap vocabulary
        // routes through. Without these rows an `administrator` user
        // loses the Configuration / Migrations / Audit log / Open
        // wp-admin tiles + every wp-admin sidebar entry that gates on
        // tt_view_settings / tt_edit_settings / tt_access_frontend_admin
        // when the matrix is active.
        'frontend_admin'              => [ 'r',   'global', $mod_authorization ],
        'settings'                    => [ 'rcd', 'global', $mod_configuration ],
        'workflow_tasks'              => [ 'r',   'self',   $mod_workflow ],
        'tasks_dashboard'             => [ 'r',   'global', $mod_workflow ],
        'workflow_templates'          => [ 'rcd', 'global', $mod_workflow ],
        'dev_ideas'                   => [ 'rcd', 'global', $mod_development ],
    ] )
);
