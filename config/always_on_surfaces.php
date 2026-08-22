<?php
/**
 * Routable tile surfaces that are NOT governed by any off-switch (#2599).
 *
 * `tools/check-module-toggles.php` fails when a tile registers a
 * `?tt_view=` slug that has no way to be switched off. There are three
 * ways a surface qualifies, and only the third needs this file:
 *
 *   1. A `FeatureRegistry` entry claims the slug in its `view_slugs`.
 *   2. The tile names a `module_class` an academy can switch off — the
 *      module toggle already hides it, so no feature is needed.
 *   3. It is listed here, with a sentence saying what breaks if it can
 *      be switched off.
 *
 * The point is not that every surface must be switchable — the six below
 * legitimately must not be — but that "this one is always on" should be a
 * decision somebody made and wrote down.
 *
 * **This file used to hold 54 `grandfathered` entries.** Almost all of
 * them were an artefact of the gate's first version, which only knew
 * about (1): it demanded a feature toggle for surfaces whose owning
 * module was switchable all along. Teaching it (2) removed 47 at a
 * stroke, and turned up one real bug — the Data browser tile named no
 * module, so switching that module off left its tile behind. What is
 * left is the genuine set.
 *
 * @return array<string, string> view slug => reason it must always be on
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    // Switching any of these off would remove the means of switching
    // anything back on, or of seeing why something broke.
    'configuration'    => 'The settings surface. Turning it off would remove the way back to every other toggle.',
    'features'         => 'The feature-toggle screen itself. Gating it behind a feature toggle is the obvious circular trap.',
    'migrations'       => 'Schema upgrades. An install with a pending migration and no way to run it is a broken install.',
    'audit-log'        => 'The record of who did what. An academy handling minors\' data should not be able to switch off its own audit trail.',

    // Owned by Authorization, which is always-on: functional roles are
    // how staff get their permissions in the first place, so hiding the
    // surface would leave an academy unable to grant anyone anything.
    'functional-roles' => 'Part of the permission model. Without it nobody can be assigned a role, including the role needed to turn it back on.',

    // Deliberately tied to no module — it is a link out of the product.
    'open-wp-admin'    => 'A link to wp-admin, not a TalentTrack screen. It is the escape hatch an administrator uses when something in the product is broken, so it must not depend on the product working.',
];
