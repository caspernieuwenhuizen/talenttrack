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
 *   'routes' — registered route pattern => where this entity's rows sit in
 *              that route's response. The pattern is exactly as passed to
 *              `register_rest_route()` with the namespace in front
 *              (`WP_REST_Response::get_matched_route()`). The row path is
 *              dot-separated from the top of the response, `*` standing for
 *              every element: `data` for a single record in the standard
 *              envelope, `data.rows.*` for a list. A response from a listed
 *              route is redacted again after the controller, as the HTTP
 *              safety net behind the controller's own `redactRows()`, and
 *              only at that path, so a nested record of another kind is
 *              never read as this one.
 *   'id_key' — the key in a row that holds the player id the right is asked
 *              against.
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
 *
 * The owner's list (2 November 2026) is wider than what is registered here:
 * absence reasons, evaluation comments, and the contact details and
 * judgements on trials, prospects, scouting and potential are registered by
 * the child issue that moves their surfaces onto the redactor, so a field
 * never starts disappearing from a surface that still renders it by hand.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'players' => [
        'routes' => [
            '/talenttrack/v1/players'              => 'data.rows.*',
            '/talenttrack/v1/players/(?P<id>\d+)' => 'data',
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
];
