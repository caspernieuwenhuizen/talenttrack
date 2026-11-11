<?php
/**
 * TalentTrack sensitive-field registry (#4690, epic #4687).
 *
 * Every field on a player-linked record that only some readers may see, and
 * the right that unlocks it. `RecordRedactor` reads this file and nothing
 * else: a module that adds a sensitive field registers it here, never with a
 * hand-written check of its own.
 *
 * Shape, per entity:
 *
 *   'routes' — registered route pattern => [ 'rows' => path, 'id_key' => key ].
 *              The pattern is exactly as passed to `register_rest_route()`
 *              with the namespace in front
 *              (`WP_REST_Response::get_matched_route()`). `rows` is where
 *              this entity's rows sit in that route's response, dot-separated
 *              from the top, `*` standing for every element: `data` for a
 *              single record in the standard envelope, `data.rows.*` for a
 *              list. `id_key` is the key in those rows that holds the id the
 *              rule is asked against; leave it out to use the entity's. A
 *              response from a listed route is redacted again after the
 *              controller, as the HTTP safety net behind the controller's own
 *              `redactRows()`, and only at that path, so a nested record of
 *              another kind is never read as this one.
 *   'id_key' — the entity's default row key: the player id for a player
 *              rule, the person id for `guardian_contact`.
 *   'fields' — field => rule. A reader the rule refuses gets the row without
 *              the field: left out of the REST answer, "Hidden" on screen.
 *
 * Rules (resolved in `RecordRedactor::RULES`):
 *
 *   'staff_for_player' — `AuthorizationService::isStaffForPlayer()`: a staff
 *                        role on the player's team, a role that may change
 *                        players academy-wide, or an academy-wide role
 *                        assignment that reads players.
 *   'staff_or_family'  — staff for the player, the player themselves, or a
 *                        guardian linked to them.
 *   'guardian_contact' — on a person row: the contact of a guardian of a live
 *                        linked child goes to the person themselves and to
 *                        staff for at least one of those children.
 *   'evaluation_notes' — staff for the player, or whoever may write the
 *                        player's evaluations (`evaluations` change, globally
 *                        or on the player's team).
 *   'evaluation_comment' — those, and the player and a linked guardian.
 *   'prospect_staff'   — on a prospect row: whoever may change prospects, and
 *                        whoever reads the prospect through their own squads'
 *                        age groups rather than academy-wide.
 *
 * The owner's list (2 November 2026) is wider than what is registered here:
 * the contact details and judgements on trials, prospects, scouting and
 * potential are registered by
 * the child issue that moves their surfaces onto the redactor, so a field
 * never starts disappearing from a surface that still renders it by hand.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'players' => [
        'routes' => [
            '/talenttrack/v1/players'                     => [ 'rows' => 'data.rows.*' ],
            '/talenttrack/v1/players/(?P<id>\d+)'        => [ 'rows' => 'data' ],
            '/talenttrack/v1/reports/potential-overview' => [ 'rows' => 'data.rows.*', 'id_key' => 'player_id' ],
        ],
        'id_key' => 'id',
        'fields' => [
            'guardian_name'    => 'staff_for_player',
            'guardian_email'   => 'staff_for_player',
            'guardian_phone'   => 'staff_for_player',
            // The list row's primary linked guardian ("Anna de Vries +1").
            'parent_name'      => 'staff_for_player',
            'parent_link_html' => 'staff_for_player',
            // The club's own fields, which hold whatever an academy put
            // there: guardian address and second contact included.
            'custom_fields'    => 'staff_for_player',
            // The full date. `birth_year` and the team's age group stay
            // on the record for every reader.
            'date_of_birth'    => 'staff_or_family',
        ],
    ],

    // The coach's written words about a minor. Ratings, dates, type and
    // evaluator stay on the record for every reader of the evaluation.
    'evaluations' => [
        'routes' => [
            '/talenttrack/v1/evaluations'                       => [ 'rows' => 'data.rows.*' ],
            '/talenttrack/v1/evaluations/(?P<id>\d+)'          => [ 'rows' => 'data' ],
            '/talenttrack/v1/players/(?P<id>\d+)/evaluations' => [ 'rows' => 'items.*' ],
        ],
        'id_key' => 'player_id',
        'fields' => [
            // Staff-only notes, never shown to the player or their family.
            'notes'           => 'evaluation_notes',
            'notes_excerpt'   => 'evaluation_notes',
            // Written for the player: the family keeps them.
            'player_feedback' => 'evaluation_comment',
            'category_notes'  => 'evaluation_comment',
        ],
    ],

    // Why a child missed or will miss a training or a match: free text that
    // can name an illness or a family matter. The status itself (present,
    // absent, excused) stays for every reader.
    'attendance' => [
        'routes' => [
            '/talenttrack/v1/activities/(?P<id>\d+)/planned-attendance' => [ 'rows' => 'data.roster.*' ],
        ],
        'id_key' => 'player_id',
        'fields' => [
            // The register's note, and the note beside a planned status.
            'notes'  => 'staff_or_family',
            // The coach's reason beside a match-prep availability status.
            'reason' => 'staff_or_family',
        ],
    ],

    // A guardian is a person record too: their e-mail and phone are the
    // family's contact, whichever list the person appears on.
    // #4694 — a prospect is a child the academy has not taken on: the family
    // contact and what the scout wrote stay with the people who work the
    // funnel. The id is the prospect's.
    'prospects' => [
        'routes' => [
            '/talenttrack/v1/prospects/(?P<id>\d+)' => [ 'rows' => 'data.prospect' ],
        ],
        'id_key' => 'id',
        'fields' => [
            'parent_name'    => 'prospect_staff',
            'parent_email'   => 'prospect_staff',
            'parent_phone'   => 'prospect_staff',
            'scouting_notes' => 'prospect_staff',
        ],
    ],

    // #4694 — who the academy asked for consent, and what was said.
    'prospect_consent_requests' => [
        'routes' => [
            '/talenttrack/v1/prospects/(?P<id>\d+)/consent-requests' => [ 'rows' => 'data.requests.*' ],
        ],
        'id_key' => 'prospect_id',
        'fields' => [
            'asked_of' => 'prospect_staff',
            'notes'    => 'prospect_staff',
        ],
    ],

    'people' => [
        'routes' => [
            '/talenttrack/v1/people'              => [ 'rows' => 'data.rows.*' ],
            '/talenttrack/v1/people/(?P<id>\d+)' => [ 'rows' => 'data' ],
        ],
        'id_key' => 'id',
        'fields' => [
            'email'           => 'guardian_contact',
            'email_link_html' => 'guardian_contact',
            'phone'           => 'guardian_contact',
        ],
    ],
];
