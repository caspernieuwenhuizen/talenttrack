<?php
/**
 * Pro features that do not yet have a `LicenseGate` call site (#2922).
 *
 * A tier map without gates is a table nobody enforces. #2922 re-drew the
 * map for the 2026 product; wiring a gate into every newly-paid surface
 * is the second half, and it is a different kind of change — each one is
 * a decision about *where* a surface refuses, and what it says when it
 * does.
 *
 * Rather than let that gap be invisible, it is written down here and
 * enforced from the other side: `FeatureMapGateCoverageTest` fails if a
 * Pro feature is neither gated in `src/` nor listed below. So a future
 * Pro feature cannot be added to the map and quietly ship ungated, and
 * this list cannot rot — removing a gate puts its key back here or the
 * test goes red.
 *
 * Tracked in #3017.
 *
 * Delete an entry in the same commit that adds its gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    // Match day (#2704, #2892, #2855).
    'match_analysis'              => 'FrontendMatchAnalysisView + MatchAnalysisRestController',
    'match_analysis_sharing'      => 'The signed share-link router',
    'match_prep'                  => 'FrontendMatchPrepView + its REST controller',
    'match_prep_sharing'          => 'The match-prep share-link router (#2892)',
    'match_execution'             => 'The live-match surface and its REST writes',
    'export_match_analysis_pdf'   => 'MatchAnalysisPrintRouter',
    'export_match_prep_pdf'       => 'MatchPrepPrintRouter',
    'export_match_day_team_sheet' => 'The team-sheet exporter',
    'tournaments'                 => 'TournamentsRestController + the tournament views',
    'tournaments_auto_balance'    => 'The auto-balance action, not the tournament itself',

    // Training (#2493) and the exercise library (#0016).
    'training'                    => 'TrainingModule surfaces + repositories',
    'exercises'                   => 'The exercise library views + import',
    'exercises_vision_extraction' => 'The photo-capture extraction path — also an operator cost',

    // Storage and bandwidth (#2589).
    'media'                       => 'MediaRestController::create — refuse the upload, not the read',
    's3_backup'                   => 'The object-storage destination in backup settings',

    // The analytics platform (#0083, #0078).
    'analytics_explorer'          => 'FrontendExploreView',
    'custom_widgets'              => 'The widget builder surfaces',
    'persona_dashboard_editor'    => 'FrontendPersonaDashboardEditorView',

    // Outbound, per-message cost (#0066).
    'comms_scheduled_sends'       => 'CommsDispatcher, on the scheduled path only',
    'comms_sms_channel'           => 'The SMS channel adapter registration',
    'push_notifications'          => 'PushModule dispatch',

    // Third-party sync the operator runs (#2002, Spond).
    'spond_integration'           => 'The Spond connect flow',
    'strava_integration'          => 'The Strava OAuth connect flow',

    // Coach development (#2641).
    'knowledge_courses'           => 'CourseAccessResolver — the one chokepoint the module already has',

    // Squad construction. `team_chemistry` itself is gated; the sharing
    // half rides on the same view and needs its own answer.
    'team_blueprints_sharing'     => 'The blueprint share action',

    // Desktop bulk-entry grids. Single-record capture stays in Standard,
    // so these gate the grid surface only.
    'attendance_grid'             => 'FrontendAttendanceGridView',
    'minutes_grid'                => 'FrontendMinutesGridView',
    'ratings_grid'                => 'FrontendRatingsGridView',
];
