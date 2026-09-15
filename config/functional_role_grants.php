<?php
/**
 * TalentTrack functional-role grants (#3257).
 *
 * The authorization matrix keys on `(persona, entity, activity,
 * scope_kind)`. A physio and a kit manager are the SAME persona —
 * `staff`, from the one `tt_staff` WordPress role — so no matrix row can
 * tell them apart, and #3232 consequently handed injury records about
 * minors to every Staff account, including ones issued to move shirts.
 *
 * What actually separates those two people is the job they do on a
 * squad: the functional role held in `tt_team_people.functional_role_id`.
 * This file is that fourth axis, resolved at authorization time by
 * `FunctionalRoleGrants` and unioned into `MatrixGate` — in one place,
 * so a call site cannot answer differently from the gate.
 *
 * Two sections:
 *
 *   'grants'     — functional role key => entity => [activities, module].
 *                  Every grant here is TEAM-scoped by construction: a
 *                  functional role is held on a team, so the grant
 *                  reaches that team and no other. There is no scope
 *                  column because there is no other scope to pick.
 *
 *   'supersedes' — persona => entities whose answer the functional-role
 *                  layer OWNS for that persona. For a user who holds at
 *                  least one functional role, the persona's own matrix
 *                  row on a superseded entity is skipped and the
 *                  functional-role grants decide alone. A user who holds
 *                  NO functional role is untouched — their matrix row
 *                  answers as it always did. That asymmetry is the whole
 *                  upgrade story (#3257 decision, 2026-09-15): an
 *                  existing Staff account keeps today's access until an
 *                  academy assigns it a functional role, which is an act
 *                  and not a default.
 *
 * Activities use the same compact letters as `authorization_seed.php`:
 * 'r' = read, 'c' = change, 'd' = create_delete.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$mod_authorization = TT\Modules\Authorization\AuthorizationModule::class;
$mod_teams         = TT\Modules\Teams\TeamsModule::class;
$mod_players       = TT\Modules\Players\PlayersModule::class;
$mod_people        = TT\Modules\People\PeopleModule::class;
$mod_activities    = TT\Modules\Activities\ActivitiesModule::class;
$mod_journey       = class_exists( '\TT\Modules\Journey\JourneyModule' )
    ? \TT\Modules\Journey\JourneyModule::class
    : $mod_authorization;

return [

    'grants' => [

        // ─── PHYSIO ─────────────────────────────────────────────────
        //
        // The one grant that moves. `player_injuries [rc, team]` was on
        // the `staff` persona (#3232) because the physio is the obviously
        // correct holder of an injury record — and it reached the kit
        // manager beside them because the persona could not tell the two
        // apart. It now reaches a person on the teams where they are the
        // physio, and nowhere else.
        //
        // `create_delete` is deliberately absent, exactly as it was on
        // the persona row: deleting a minor's medical record is not a
        // touchline decision, and stays with head of development and
        // academy admin.
        //
        // `measurements` is NOT listed here. It stays on the `staff`
        // persona: height, weight and sprint times are what every staff
        // member records, `team_manager` already holds them at team
        // scope, and #3232 called that half uncontroversial on the
        // record. One entity, one home.
        'physio' => [
            'player_injuries' => [ 'rc', $mod_journey ],
        ],

        // ─── KIT MANAGER ────────────────────────────────────────────
        //
        // Written as its own positive list, not as "Staff minus
        // injuries". A definition by subtraction ages badly: the next
        // sensitive entity added to the persona would land on this seat
        // silently, which is precisely how #3257 happened.
        //
        // What the job needs: to know who is in the squad, how to reach
        // the people around it, and when the squad is playing. Read
        // only, team scope, nothing medical and nothing evaluative.
        //
        // These rows are a floor, not a ceiling — they union with the
        // `staff` persona's own grants, which already reach players and
        // people at team scope. The kit manager is listed anyway so this
        // file says what the job is, rather than leaving it implied by
        // the absence of anything.
        'kit_manager' => [
            'team'       => [ 'r', $mod_teams ],
            'players'    => [ 'r', $mod_players ],
            'people'     => [ 'r', $mod_people ],
            'activities' => [ 'r', $mod_activities ],
        ],
    ],

    /**
     * Only the `staff` persona is superseded, and only on
     * `player_injuries`.
     *
     * Head coaches hold `player_injuries [rc, team]` on their own
     * reasoning — they are the person on the pitch when the hamstring
     * goes — and most of them also hold the `head_coach` functional
     * role. Superseding their persona too would take injuries away from
     * every head coach on the install the moment this shipped. That is a
     * different decision from the one #3257 makes, so it is not made
     * here.
     */
    'supersedes' => [
        'staff' => [ 'player_injuries' ],
    ],
];
