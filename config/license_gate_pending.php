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
    // Match day (#2704, #2892, #2855). The eight gated in #3105 are gone;
    // the sharing and export halves ride on their own keys and land in
    // slices 4 and 5.
    'match_analysis_sharing'      => 'The signed share-link router',
    'match_prep_sharing'          => 'The match-prep share-link router (#2892)',
    'export_match_analysis_pdf'   => 'MatchAnalysisPrintRouter',
    'export_match_prep_pdf'       => 'MatchPrepPrintRouter',
    'export_match_day_team_sheet' => 'The team-sheet exporter',

    // Storage and bandwidth (#2589).
    //
    // #3106 — `s3_backup` stays, and this is the reason rather than an
    // oversight: **there is no object-storage destination to gate.**
    // `BackupRunner::destinations()` returns Local and Email; the
    // `BackupDestinationInterface` docblock mentions `s3` as a future id
    // and nothing implements it. Gating a surface that does not exist
    // would be a call site pointing at nothing, and deleting the key would
    // let the destination ship ungated later, which is precisely what this
    // list exists to prevent. The gate lands in the same PR as the
    // destination.
    's3_backup'                   => 'No object-storage destination exists yet (BackupRunner::destinations() is Local + Email). The gate ships with the destination.',

    // Squad construction. `team_chemistry` itself is gated; the sharing
    // half rides on the same view and needs its own answer.
    'team_blueprints_sharing'     => 'The blueprint share action',
];
