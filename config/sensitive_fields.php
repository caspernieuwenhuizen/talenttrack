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
 *   'routes' — patterns matched against the REST request path. A response
 *              from a matching route is redacted below the serializer, so a
 *              controller cannot forget to.
 *   'id_key' — the key in a returned row that holds the player id the
 *              right is asked against.
 *   'fields' — field => rule. A reader the rule refuses gets the row without
 *              the field: left out of the REST answer, "Hidden" on screen.
 *
 * Rules (resolved in `RecordRedactor::RULES`):
 *
 *   'staff_for_player' — `AuthorizationService::isStaffForPlayer()`: a staff
 *                        role on the player's team, a role that may change
 *                        players academy-wide, or an academy-wide role
 *                        assignment that reads players.
 *
 * The owner's list (2 November 2026) is wider than what is registered here:
 * absence reasons, evaluation comments, date of birth, and the contact
 * details and judgements on trials, prospects, scouting and potential are
 * registered by the child issue that moves their surfaces onto the redactor,
 * so a field never starts disappearing from a surface that still renders it
 * by hand.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'players' => [
        'routes' => [
            '#^/talenttrack/v1/players(?:/\d+)?$#',
        ],
        'id_key' => 'id',
        'fields' => [
            'guardian_name'  => 'staff_for_player',
            'guardian_email' => 'staff_for_player',
            'guardian_phone' => 'staff_for_player',
            // The club's own fields, which hold whatever an academy put
            // there: guardian address and second contact included.
            'custom_fields'  => 'staff_for_player',
        ],
    ],
];
