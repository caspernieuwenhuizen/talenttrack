<?php
/**
 * Routable tile surfaces that are NOT governed by a feature toggle (#2599).
 *
 * `tools/check-module-toggles.php` fails when a tile registers a
 * `?tt_view=` slug that no `FeatureRegistry` entry claims and that is not
 * listed here. The point is not that every surface must be switchable —
 * plenty legitimately must not be — but that "this one is always on" is a
 * decision somebody made and wrote down, rather than something nobody got
 * round to.
 *
 * Two kinds of entry:
 *
 *   'slug' => 'grandfathered'
 *       Predates the gate. Not a judgement that it should be always-on —
 *       nobody has looked. Replacing one of these with a real reason, or
 *       moving the slug into a feature's `view_slugs`, is a small, welcome
 *       change to make while you are in the area.
 *
 *   'slug' => 'a sentence explaining why it must always be on'
 *       A deliberate decision. Say what breaks if it can be switched off.
 *
 * The 54 grandfathered entries below are the state of the tree when the
 * gate landed. They were generated from what the gate reported rather
 * than typed from memory, so the list is exactly the backlog and nothing
 * more. Same diff-only spirit as the inline-style gate: the backlog is
 * not this issue's problem; the next addition is.
 *
 * @return array<string, string> view slug => reason
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    // ── Deliberately always-on ────────────────────────────────────────
    //
    // These four are the ones worth stating outright, because switching
    // any of them off would take away the means of switching anything
    // back on — or of seeing why it broke.
    'configuration'                 => 'The settings surface. Turning it off would remove the way back to every other toggle.',
    'features'                      => 'The feature-toggle screen itself. Gating it behind a feature toggle is the obvious circular trap.',
    'migrations'                    => 'Schema upgrades. An install with a pending migration and no way to run it is a broken install.',
    'audit-log'                     => 'The record of who did what. An academy handling minors\' data should not be able to switch off its own audit trail.',

    // ── Grandfathered — predate the gate, nobody has decided yet ──────
    'accounts'                      => 'grandfathered',
    'activities'                    => 'grandfathered',
    'data-browser'                  => 'grandfathered',
    'dev-tracks'                    => 'grandfathered',
    'evaluations'                   => 'grandfathered',
    'exports'                       => 'grandfathered',
    'functional-roles'              => 'grandfathered',
    'goals'                         => 'grandfathered',
    'holidays'                      => 'grandfathered',
    'ideas-approval'                => 'grandfathered',
    'ideas-board'                   => 'grandfathered',
    'injuries'                      => 'grandfathered',
    'invitations-config'            => 'grandfathered',
    'measurement-tests'             => 'grandfathered',
    'measurements'                  => 'grandfathered',
    'measurements-coverage'         => 'grandfathered',
    'measurements-entry'            => 'grandfathered',
    'methodology'                   => 'grandfathered',
    'my-development'                => 'grandfathered',
    'my-staff-certifications'       => 'grandfathered',
    'my-staff-evaluations'          => 'grandfathered',
    'my-staff-goals'                => 'grandfathered',
    'my-staff-pdp'                  => 'grandfathered',
    'my-tasks'                      => 'grandfathered',
    'onboarding-pipeline'           => 'grandfathered',
    'open-wp-admin'                 => 'grandfathered',
    'overview'                      => 'grandfathered',
    'pdp'                           => 'grandfathered',
    'pdp-planning'                  => 'grandfathered',
    'people'                        => 'grandfathered',
    'players'                       => 'grandfathered',
    'reports'                       => 'grandfathered',
    'scouting-visits'               => 'grandfathered',
    'season-rollover'               => 'grandfathered',
    'staff-overview'                => 'grandfathered',
    'submit-idea'                   => 'grandfathered',
    'tasks-dashboard'               => 'grandfathered',
    'team-blueprints'               => 'grandfathered',
    'teams'                         => 'grandfathered',
    'test-results'                  => 'grandfathered',
    'test-trends'                   => 'grandfathered',
    'tournaments'                   => 'grandfathered',
    'training-plans'                => 'grandfathered',
    'trial-letter-templates-editor' => 'grandfathered',
    'trial-tracks-editor'           => 'grandfathered',
    'trials'                        => 'grandfathered',
    'usage-stats'                   => 'grandfathered',
    'vct-planner'                   => 'grandfathered',
    'wizards-admin'                 => 'grandfathered',
    'workflow-config'               => 'grandfathered',
];
