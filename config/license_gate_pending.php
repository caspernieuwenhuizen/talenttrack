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

/*
 * #3108 closed the epic. Twenty-nine of the thirty Pro features are gated
 * in `src/`; one entry remains, and it is here because the surface it names
 * does not exist rather than because anybody forgot it.
 *
 * The list has done its job: from here `FeatureMapGateCoverageTest` is
 * carrying the whole load on its own, which was the point. A new Pro
 * feature either arrives with a gate or fails the build.
 */
return [
    // Storage and bandwidth (#2589).
    //
    // `s3_backup` stays, and this is the reason rather than an oversight:
    // **there is no object-storage destination to gate.**
    // `BackupRunner::destinations()` returns Local and Email; the
    // `BackupDestinationInterface` docblock mentions `s3` as a future id
    // and nothing implements it. Gating a surface that does not exist
    // would be a call site pointing at nothing, and deleting the key would
    // let the destination ship ungated later, which is precisely what this
    // list exists to prevent. The gate lands in the same PR as the
    // destination — `OperatorCostGateTest` fails the day one appears.
    's3_backup'                   => 'No object-storage destination exists yet (BackupRunner::destinations() is Local + Email). The gate ships with the destination.',
];
