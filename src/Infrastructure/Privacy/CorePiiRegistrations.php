<?php
namespace TT\Infrastructure\Privacy;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CorePiiRegistrations (#0081 child 1) — initial set of PII-table
 * registrations against `PlayerDataMap`.
 *
 * Registered centrally rather than per-module so child 1 ships a usable
 * registry without touching ten module boot paths. Future modules
 * adding new PII columns are still expected to register from their
 * own boot path; this class is the v1 backfill.
 *
 * Coverage policy: only tables with a direct, indexed player-id FK
 * land here. Junction-style tables that link a player via two hops
 * (e.g. `tt_pdp_conversations` reaches a player only through
 * `tt_pdp_files.player_id`) are not registered — the erasure code
 * walks them via the parent-table registration. Same logic for
 * `tt_test_trainings`: it's session metadata, the link to a person
 * runs through `tt_workflow_tasks`, so it's intentionally absent.
 */
final class CorePiiRegistrations {

    public static function register(): void {
        // The player record itself.
        PlayerDataMap::register(
            'tt_players',
            'id',
            'Player identity, name, DOB, position, status, team assignment.',
            'TT\\Modules\\Players\\PlayersModule'
        );

        // Family / parent linkage. parent_user_id is also PII for the
        // parent, but the registry's surface is player-keyed (the
        // erasure flows from a player → their related rows). Register
        // the child-side join column.
        PlayerDataMap::register(
            'tt_player_parents',
            'player_id',
            'Parent / guardian linkage records.',
            'TT\\Modules\\Players\\PlayersModule'
        );

        // Player development records.
        PlayerDataMap::register(
            'tt_evaluations',
            'player_id',
            'Coach evaluations of the player across categories.',
            'TT\\Modules\\Evaluations\\EvaluationsModule'
        );
        PlayerDataMap::register(
            'tt_eval_ratings',
            'player_id',
            'Per-category ratings produced by evaluations.',
            'TT\\Modules\\Evaluations\\EvaluationsModule'
        );
        PlayerDataMap::register(
            'tt_goals',
            'player_id',
            'Development goals set with or by the player.',
            'TT\\Modules\\Goals\\GoalsModule'
        );
        PlayerDataMap::register(
            'tt_attendance',
            'player_id',
            'Attendance records for trainings, matches, and other activities.',
            'TT\\Modules\\Activities\\ActivitiesModule'
        );

        // Journey / longitudinal events.
        PlayerDataMap::register(
            'tt_player_events',
            'player_id',
            'Journey events: joined, evaluated, promoted, transitioned, injured.',
            'TT\\Modules\\Journey\\JourneyModule'
        );
        PlayerDataMap::register(
            'tt_player_injuries',
            'player_id',
            'Injury records — sensitive medical information.',
            'TT\\Modules\\Journey\\JourneyModule'
        );
        PlayerDataMap::register(
            'tt_player_team_history',
            'player_id',
            'Per-season team and age-group movement history.',
            'TT\\Modules\\Players\\PlayersModule'
        );

        // PDP — long-form development plan.
        PlayerDataMap::register(
            'tt_pdp_files',
            'player_id',
            'Personal development plan file (the season-level cycle).',
            'TT\\Modules\\Pdp\\PdpModule'
        );

        // Reports + trials.
        PlayerDataMap::register(
            'tt_player_reports',
            'player_id',
            'Generated player reports (PDF / printable artefacts).',
            'TT\\Modules\\Reports\\ReportsModule'
        );
        PlayerDataMap::register(
            'tt_trial_cases',
            'player_id',
            'Trial case lifecycle, decisions, and acceptance state.',
            'TT\\Modules\\Trials\\TrialsModule'
        );

        // #0081 — Prospect promoted to a player carries their pre-
        // promotion identity row. The link column is `promoted_to_player_id`,
        // not `player_id`, because a prospect does not have a player
        // record until promotion.
        PlayerDataMap::register(
            'tt_prospects',
            'promoted_to_player_id',
            'Original scout-time identity record for prospects who were promoted to academy players.',
            'TT\\Modules\\Prospects\\ProspectsModule'
        );
    }
}
