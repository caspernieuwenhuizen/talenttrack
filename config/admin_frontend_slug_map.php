<?php
/**
 * wp-admin pages whose frontend port chose a different slug (#3132, epic #2874).
 *
 * `wp tt admin-routes` pairs an admin page with its frontend equivalent by
 * stripping the `tt-` prefix. That convention holds for most of the plugin and
 * for none of the ports #2874 actually commissioned: every one of them renamed
 * the slug, deliberately and correctly, so the tool reported the three pages it
 * existed to track as still unrouted — the same wrong answer the two hand-written
 * audits produced, from the tool built to stop that happening.
 *
 * The methodology row is the one no prefix rule could ever express: nine admin
 * pages collapse into a single frontend surface, which is the whole point of
 * #2976. That is a decision, and a decision belongs written down once rather
 * than re-derived wrongly.
 *
 * Adding a row here is not a formality either. The question is whether the port
 * genuinely landed under a different name — not whether you would like the tool
 * to stop complaining. A row that claims a port which does not exist turns the
 * inventory back into the thing it replaced.
 *
 * Read by `TT\Shared\Cli\AdminRoutesCommand::rows()`, which consults this map
 * before falling back to the prefix rule. The frontend slug is validated
 * against the dispatcher's real routable set, so a typo here reads as unrouted
 * rather than as a false green.
 *
 * @return array<string, array{frontend_slug: string, renamed_by: string}>
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    // ── #2977 — Category weights ───────────────────────────────────────
    // The frontend surface is namespaced `eval-` because "category" alone is
    // ambiguous once methodology categories exist.
    'tt-category-weights' => [
        'frontend_slug' => 'eval-category-weights',
        'renamed_by'    => '#2977',
    ],

    // ── #2978 — Persona dashboard editor ───────────────────────────────
    // Renamed around what the surface edits (templates) rather than around the
    // screen it used to be.
    'tt-persona-dashboard-editor' => [
        'frontend_slug' => 'persona-templates',
        'renamed_by'    => '#2978',
    ],

    // ── #2976 — Methodology vocabulary ─────────────────────────────────
    // Eight per-entity edit screens became one vocabulary surface. Each admin
    // page still exists and still routes to the same place, so each needs its
    // own row: the map is admin-page-shaped, not surface-shaped.
    'tt-methodology-primer-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-factor-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-learning-goal-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-phase-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-position-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-principle-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-set-piece-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
    'tt-methodology-vision-edit' => [
        'frontend_slug' => 'methodology-vocabulary',
        'renamed_by'    => '#2976',
    ],
];
