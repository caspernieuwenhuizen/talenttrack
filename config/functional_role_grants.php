<?php
/**
 * TalentTrack functional-role grants (#3257, extended by #3433).
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
$mod_measurements  = class_exists( '\TT\Modules\Measurements\MeasurementsModule' )
    ? \TT\Modules\Measurements\MeasurementsModule::class
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
        // #3433 adds `measurements [r]`. A physio reads the growth and
        // physical-testing figures of the squads they look after, for
        // the same reason they read the injuries: it is the job. Read
        // only — see the `head_coach` note below for why no functional
        // role carries the write half.
        'physio' => [
            'player_injuries' => [ 'rc', $mod_journey ],
            'measurements'    => [ 'r',  $mod_measurements ],
        ],

        // ─── HEAD COACH / ASSISTANT COACH ───────────────────────────
        //
        // #3433. `measurements [rc, team]` sat on the `staff` persona
        // because height, weight and sprint times are what a staff
        // member records — and it reached the kit manager beside them
        // for exactly the reason the injury grant did: `tt_staff` is one
        // persona covering every job on the touchline, and the matrix
        // keys on `(persona, entity, activity, scope_kind)`.
        //
        // The 2026-09-16 decision on #3433 names three functional roles
        // that read measurements — physio, head coach, assistant coach —
        // and one that does not. The decision names READ and nothing
        // else, so `change` is not granted here: recording a measurement
        // is a `head_coach` / `coach` / `team_manager` PERSONA grant
        // (`authorization_seed.php`), and those personas are not
        // superseded, so nobody who records measurements today through
        // one of them loses it. Widening past what the decision says is
        // the failure mode this whole layer exists to prevent, so if the
        // write half is wanted on a functional role it is a decision and
        // a one-letter change, not an inference.
        //
        // These two roles hold no other entity here on purpose: the
        // `staff` persona already reaches team, players, people and
        // player notes at team scope, and unioning a duplicate of that
        // list would make this file look like the source of a grant it
        // does not own.
        //
        // One consequence worth stating, because `grants` is additive
        // across EVERY persona and these two role keys are the most
        // widely assigned ones in the product: somebody whose persona
        // carries no `measurements` row at all — a scout, an observer, a
        // parent who volunteers as the assistant coach of their child's
        // team — now reads that team's measurements if the academy has
        // recorded them in one of these roles. In practice the functional
        // role → WordPress role mapping usually gives such a person a
        // coach account anyway, and "the assistant coach of this squad
        // reads this squad's test results" is the sentence the #3433
        // decision asks for. It is a widening for that one shape, and it
        // is named here rather than discovered later.
        'head_coach' => [
            'measurements' => [ 'r', $mod_measurements ],
        ],
        'assistant_coach' => [
            'measurements' => [ 'r', $mod_measurements ],
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
        // only, team scope, nothing medical and nothing evaluative —
        // which as of #3433 means no `measurements` either. Shirt sizes
        // are not sprint times, and a kit manager reading a minor's
        // growth curve is the defect that issue is named for.
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

        // ─── MANAGER ────────────────────────────────────────────────
        //
        // #3567. The role an academy picks for its team manager, seeded
        // as "handles logistics, roster, activities", and until this
        // entry it granted nothing: a Staff account assigned as Manager
        // read the roster (through the persona) and was refused the
        // schedule, while the kit manager beside them read it.
        //
        // What the job needs, per the 2026-09-19 decision: to read the
        // schedule, to take the register, and to know who is available.
        // So `activities [r]` — reading the schedule, not writing it;
        // creating and editing activities stays with the coaches —
        // `attendance [rc]` and `player_status [r]`.
        //
        // Deliberately NOT here: injuries. A manager who also does first
        // aid is given Physio as a second functional role on the team,
        // which is what #3257 decided medical data about minors follows.
        // Moving team managers onto the `team_manager` persona instead
        // was considered and declined: that persona reads PDP files,
        // evaluations, media and behaviour ratings, far past a logistics
        // seat.
        'manager' => [
            'team'          => [ 'r',  $mod_teams ],
            'players'       => [ 'r',  $mod_players ],
            'people'        => [ 'r',  $mod_people ],
            'activities'    => [ 'r',  $mod_activities ],
            'attendance'    => [ 'rc', $mod_activities ],
            'player_status' => [ 'r',  $mod_players ],
        ],
    ],

    /**
     * Only the `staff` persona is superseded, on `player_injuries`
     * (#3257) and `measurements` (#3433).
     *
     * Supersession is per ENTITY, not per activity: for a user holding a
     * functional role, the `staff` row on these entities is skipped
     * entirely and the grants above answer alone. That is what makes
     * `measurements` read-only for a functional-role holder — see the
     * `head_coach` note above, where the decision to grant read and
     * nothing else is recorded.
     *
     * The `head_coach` and `coach` PERSONAS are deliberately not listed.
     * They hold `player_injuries` and `measurements` on their own
     * reasoning — they are the person on the pitch when the hamstring
     * goes, and the person running the testing session — and most of
     * them also hold a functional role. Superseding those personas would
     * take both entities away from every head coach on the install the
     * moment this shipped. #3257 declined that for injuries, #3433
     * declines it again for measurements, and changing it is its own
     * decision.
     */
    'supersedes' => [
        'staff' => [ 'player_injuries', 'measurements' ],
    ],
];
