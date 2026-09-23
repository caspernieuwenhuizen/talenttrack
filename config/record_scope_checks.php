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
 * A trailing `*` matches a family, because the members ask the same question
 * about different arguments: `ActivityTeamScope::coversTeam()` and
 * `coversActivity()` differ only in what they resolve first.
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
        'coach_owns_player',
        'PlayerReportAccess::canRead',
        'ScoutPlayerCard::canRead',

        // A team, and the surfaces scoped by one.
        'canManageTeam',
        'canAssignStaff',
        'canPlanForTeam',
        'get_teams_for_coach',
        'AllTeamsScope::canReadTeam',
        'ActivityTeamScope::covers',
        'TeamChemistryAccess::can',
        'TeamReportAccess::canRead',

        // Entities with an access rule of their own.
        'canViewTournament',
        'canEditTournament',
        'canDeleteTournament',
        'GoalAccess::may',
        'PdpAccess::canSeeFile',
        'TrialCaseAccessPolicy::canOpenCase',
        'MediaVisibilityService::can',
        'ScoutingVisitsAccess::canReadVisit',
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
    ],
];
