<?php
/**
 * The per-record checks `tools/check-record-scope.php` recognises (#4004).
 *
 * Every capability in this plugin is club-wide. `tt_view_activities` says the
 * caller reads activities; it never says whose. A surface that takes a record
 * id and asks only a capability has therefore asked the wrong question, and
 * this file is the list of functions that ask the right one.
 *
 * **Adding a name here is a reviewable act, and that is the point.** The gate
 * cannot tell a real check from a plausible-looking call, so the list is where
 * somebody decides that a given function actually answers "may you do this to
 * THIS record". A name that does not — a capability roll-up, an `isEnabled()`,
 * a feature gate — does not belong here however convenient it would be.
 *
 * A name matches only itself: `TeamChemistryAccess::canReadForTeam` does not
 * admit `canRead()`, which asks a capability and no record. A trailing `*`
 * matches a family, and is only right when every member, present and future,
 * takes a record; so list the methods by name unless that is certain.
 *
 * @return array{
 *   checks:list<string>,
 *   capability_gates:list<string>,
 *   exempt_directories:list<string>,
 *   id_params:list<string>,
 *   loaders:list<string>
 * }
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [

    /**
     * The checks themselves. Grouped by what they are about, because the
     * grouping is the argument for each one being here.
     */
    'checks' => [

        // A player. The academy's records about a named child, so this is the
        // longest group and the one worth being strictest about.
        'canViewPlayer',
        'canEditPlayer',
        'canEvaluatePlayer',
        'canReadPlayerSection',
        'parentCanViewSection',
        'isStaffForPlayer',
        'isHeadCoachOfPlayer',
        'canRecordInjury',
        // #4103 — the `player_suspensions` entity asked about one player:
        // global, that player's team, self, or a linked parent.
        'canAccessSuspensions',
        'coach_owns_player',
        // May this user write an evaluation for this player: `evaluations`
        // change at global scope or on the player's team.
        'EvaluationInserter::mayWriteFor',
        'PlayerReportAccess::canRead',
        // Calls canRead on the same player first, then asks the create right.
        'PlayerReportAccess::canCreate',
        'ScoutPlayerCard::canRead',

        // A prospect: a child the academy is looking at, not a player yet.
        // Both ask `ProspectScope::canSee()` on the same id the list narrows
        // by (#4257).
        'ProspectRecord::canOpen',
        'ProspectRecord::canLogConsent',

        // A team, and the surfaces scoped by one.
        'canManageTeam',
        'canAssignStaff',
        'canPlanForTeam',
        'get_teams_for_coach',
        // #4448 — the PDP planning team scope: academy-wide PDP read, else
        // `get_teams_for_coach()`. Moved out of the planning view, whose
        // block detail the gate used to pass one level deep.
        'PdpPlanningService::accessibleTeamIds',
        'AllTeamsScope::canReadTeam',
        // The same question asked about a named entity's grant on the team.
        'AllTeamsScope::canReadTeamFor',
        // A team's activities: read or write, asked about the team, an
        // activity, or the activity a request names.
        'ActivityTeamScope::coversTeam',
        'ActivityTeamScope::coversActivity',
        'ActivityTeamScope::coversTeamForWrite',
        'ActivityTeamScope::coversActivityForWrite',
        'ActivityTeamScope::coversActivityForRequest',
        // The write scope on an activity's team: `activities` change at
        // global scope, or at team scope on that team. `writableTeamIds()` is
        // the same answer for a list of teams, which the grid writes narrow to.
        'ActivityAccess::canWriteTeam',
        'ActivityAccess::canWriteActivity',
        'ActivityAccess::writableTeamIds',
        // The register on an activity's team: the write scope above, or
        // `attendance` change at global scope or on that team.
        'ActivityAccess::canTakeRegisterForTeam',
        'ActivityAccess::canTakeRegister',
        'ActivityAccess::registerTeamIds',
        // #4643 — a match's prep: read is an activities capability and the
        // activity's team in read scope, write is `tt_edit_activities` and the
        // team in write scope, both through `ActivityTeamScope::covers*`.
        'MatchPrepAccess::canRead',
        'MatchPrepAccess::canWrite',
        // The same two rules asked about an activity id: they resolve the
        // activity's own team first, so they still check the record.
        'MatchPrepAccess::canReadActivity',
        'MatchPrepAccess::canWriteActivity',
        // The team-taking forms only. `canRead()`, `canManage()` and their
        // `Chemistry` twins are capability roll-ups that check no team.
        'TeamChemistryAccess::canReadForTeam',
        'TeamChemistryAccess::canManageForTeam',
        'TeamChemistryAccess::canReadChemistryForTeam',
        'TeamChemistryAccess::canManageChemistryForTeam',
        // Change authority on `spond_integration` for that exact team.
        'TeamSpondAccess::canManage',
        'TeamReportAccess::canRead',
        // #4569 — a stored Studio report: edit asks the builder's own scope
        // over the report's subject, read asks that or a persona the report
        // is published to.
        'ReportAccess::canEdit',
        'ReportAccess::canRead',
        // #4568 — a Studio block's subject, on every call: the player and team
        // report rules above, each group player through canViewPlayer, the
        // academy through club-wide analytics read.
        'ReportSubjectAccess::check',
        'ReportSubjectAccess::canRead',

        // Entities with an access rule of their own.
        'canViewTournament',
        'canEditTournament',
        'canDeleteTournament',
        'GoalAccess::mayRead',
        'GoalAccess::mayChange',
        'PdpAccess::canSeeFile',
        // #4977 — a PDP conversation form: the conversation loaded in the
        // caller's club, then which part this user may read or write.
        'PdpFormAccess::forConversation',
        'TrialCaseAccessPolicy::canOpenCase',
        'MediaVisibilityService::can',
        'ScoutingVisitsAccess::canReadVisit',
        // A staff member's development record: their own, or every record for
        // a global grant. `canManageAll()` is that global grant itself, the
        // only answer for the routes that name a goal or certification by id.
        'StaffDevelopmentAccess::canRead',
        'StaffDevelopmentAccess::canWrite',
        'StaffDevelopmentAccess::canManageAll',
    ],

    /**
     * Capabilities that ARE the per-record answer, because reaching past every
     * per-record rule is what they exist for.
     *
     * `tt_manage_recycle_bin` is the only one: the bin's `/permanent` routes
     * are a deliberate admin escape hatch (see `docs/access-control.md` §
     * Recycle-bin management), granted to nobody a per-record rule would
     * protect a record from. Anything else added here is a hole, not an
     * exemption.
     */
    'capability_gates' => [
        'tt_manage_recycle_bin',
    ],

    /**
     * Directories with no player or team dimension at all, exempted wholesale
     * rather than one marker per method.
     *
     * The methodology controllers serve the academy's own coaching model —
     * principles, functions, exercise vocabulary. There is no record a child
     * or a squad owns anywhere in them, so a per-record check would have
     * nothing to check.
     */
    'exempt_directories' => [
        'src/Modules/Methodology/Rest/',
    ],

    /**
     * Request parameters that name a record. A `slug`, a `section`, a `key` or
     * a `type` is a lookup, not a record somebody owns, so they are absent on
     * purpose.
     */
    'id_params' => [
        'id',
        'player_id',
        'team_id',
        'activity_id',
        'prep_id',
        'person_id',
        'goal_id',
        'eval_id',
        'evaluation_id',
        'file_id',
        'case_id',
        'tournament_id',
        'run_id',
        'plan_id',
        'uuid',
    ],

    /**
     * Loading one record by that id — as opposed to passing it to a list
     * filter, which is narrowing rather than opening.
     */
    'loaders' => [
        '->find(',
        '->findById(',
        '->findByIdIncludingArchived(',
        '->findByUuid(',
        '->findAssignment(',
        'QueryHelpers::get_player(',
        'QueryHelpers::get_team(',
        'ArchivedDetailCard::resolve(',
        // #4590 — the report block composers read one team's or one player's
        // records from the context they are handed. Building a context from a
        // request id opens that record, so the surface doing it owes the
        // `TeamReportAccess` / `PlayerReportAccess` check above.
        'new TeamReportContext(',
        'PlayerReportContext::make(',
    ],
];
