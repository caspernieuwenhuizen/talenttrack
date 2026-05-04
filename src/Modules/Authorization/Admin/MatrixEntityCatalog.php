<?php
namespace TT\Modules\Authorization\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\LegacyCapMapper;
use TT\Shared\Admin\AdminMenuRegistry;
use TT\Shared\Tiles\TileRegistry;

/**
 * MatrixEntityCatalog — discoverability bridge for the auth matrix admin.
 *
 * Two responsibilities:
 *   1. Translate matrix entity slugs (e.g. `audit_log`, `dev_ideas`) into
 *      a human label in the operator's locale, so the matrix doesn't show
 *      raw slugs that nobody can map back to a UI surface.
 *   2. Compute the reverse index "which tiles + admin pages consume this
 *      entity?", so an admin clearing R/C/D for a persona on entity X can
 *      see at a glance what UI that decision will hide.
 *
 * Consumers are derived by walking every registered tile / admin menu
 * entry / dashboard tile, taking its `cap` string, and looking the cap
 * up in `LegacyCapMapper::tupleFor()` to see if it points at the entity.
 * Tiles that gate on a `cap_callback` (closure) instead of a string cap
 * are reported as `callback_gated` — the matrix has no visibility into
 * them, and a follow-up refactor needs to migrate them to either
 * declared entities or matrix-aware callbacks.
 */
final class MatrixEntityCatalog {

    /**
     * Slug → translatable human label. Keep this in sync with the
     * authorization seed entities; a new entity that lands in
     * `config/authorization_seed.php` should also get a label here so
     * the matrix shows it in the operator's language.
     *
     * @return array<string,string>
     */
    private static function labelMap(): array {
        return [
            // Core domain
            'players'                       => __( 'Players', 'talenttrack' ),
            'team'                          => __( 'Teams', 'talenttrack' ),
            'people'                        => __( 'People', 'talenttrack' ),
            'evaluations'                   => __( 'Evaluations', 'talenttrack' ),
            'evaluation_categories'         => __( 'Evaluation categories', 'talenttrack' ),
            'category_weights'              => __( 'Category weights', 'talenttrack' ),
            'rating_scale'                  => __( 'Rating scale', 'talenttrack' ),
            'goals'                         => __( 'Goals', 'talenttrack' ),
            'activities'                    => __( 'Activities', 'talenttrack' ),
            'methodology'                   => __( 'Methodology', 'talenttrack' ),
            'reports'                       => __( 'Reports', 'talenttrack' ),
            // Settings + admin
            'settings'                      => __( 'Settings (umbrella)', 'talenttrack' ),
            'lookups'                       => __( 'Lookups', 'talenttrack' ),
            'branding'                      => __( 'Branding', 'talenttrack' ),
            'feature_toggles'               => __( 'Feature toggles', 'talenttrack' ),
            'audit_log'                     => __( 'Audit log', 'talenttrack' ),
            'translations_config'           => __( 'Translations', 'talenttrack' ),
            'custom_field_definitions'      => __( 'Custom fields', 'talenttrack' ),
            'migrations'                    => __( 'Migrations', 'talenttrack' ),
            'seasons'                       => __( 'Seasons', 'talenttrack' ),
            'setup_wizard'                  => __( 'Setup wizard', 'talenttrack' ),
            'frontend_admin'                => __( 'Frontend admin', 'talenttrack' ),
            'authorization_matrix'          => __( 'Authorization matrix', 'talenttrack' ),
            'authorization_changelog'       => __( 'Authorization changelog', 'talenttrack' ),
            'functional_role_definitions'   => __( 'Functional roles (definitions)', 'talenttrack' ),
            'functional_role_assignments'   => __( 'Functional roles (assignments)', 'talenttrack' ),
            'backup'                        => __( 'Backup', 'talenttrack' ),
            // Workflow + invitations + dev
            'workflow_tasks'                => __( 'Workflow tasks', 'talenttrack' ),
            'tasks_dashboard'               => __( 'Tasks dashboard', 'talenttrack' ),
            'workflow_templates'            => __( 'Workflow templates', 'talenttrack' ),
            'invitations'                   => __( 'Invitations', 'talenttrack' ),
            'invitations_config'            => __( 'Invitation messages', 'talenttrack' ),
            'dev_ideas'                     => __( 'Development ideas', 'talenttrack' ),
            'my_card'                       => __( 'My card', 'talenttrack' ),
            // Trials + staff
            'trial_cases'                   => __( 'Trial cases', 'talenttrack' ),
            'trial_inputs'                  => __( 'Trial inputs', 'talenttrack' ),
            'trial_synthesis'               => __( 'Trial synthesis', 'talenttrack' ),
            'staff_development'             => __( 'Staff development', 'talenttrack' ),
            'staff_overview'                => __( 'Staff overview', 'talenttrack' ),
            // Player-scoped surfaces
            'thread_messages'               => __( 'Threads', 'talenttrack' ),
            'spond_integration'             => __( 'Spond integration', 'talenttrack' ),
            'player_timeline'               => __( 'Player timeline', 'talenttrack' ),
            'player_potential'              => __( 'Player potential', 'talenttrack' ),
            'player_behaviour_ratings'      => __( 'Player behaviour ratings', 'talenttrack' ),
            'player_status'                 => __( 'Player status', 'talenttrack' ),
            'player_status_breakdown'       => __( 'Player status breakdown', 'talenttrack' ),
            'player_status_methodology'     => __( 'Player status methodology', 'talenttrack' ),
            'player_injuries'               => __( 'Player injuries', 'talenttrack' ),
            'safeguarding_notes'            => __( 'Safeguarding notes', 'talenttrack' ),
            'pdp_file'                      => __( 'PDP file', 'talenttrack' ),
            'pdp_planning'                  => __( 'PDP planning', 'talenttrack' ),
            'pdp_evidence_packet'           => __( 'PDP evidence packet', 'talenttrack' ),
            'pdp_verdict'                   => __( 'PDP verdict', 'talenttrack' ),
            // Branding / personalisation
            'custom_css'                    => __( 'Custom CSS', 'talenttrack' ),
            'persona_templates'             => __( 'Persona templates', 'talenttrack' ),
            'scout_access'                  => __( 'Scout access', 'talenttrack' ),
            'impersonation_action'          => __( 'Impersonation', 'talenttrack' ),
        ];
    }

    /**
     * Localized label for an entity slug. Falls back to a humanised
     * version of the slug when no explicit label is registered, so a
     * new entity never silently displays as a raw machine slug.
     */
    public static function entityLabel( string $slug ): string {
        $map = self::labelMap();
        if ( isset( $map[ $slug ] ) ) {
            return $map[ $slug ];
        }
        // Fallback: snake_case → "Snake case"
        $humanised = ucfirst( str_replace( [ '_', '-' ], ' ', $slug ) );
        return $humanised;
    }

    /**
     * Reverse index: which surfaces (frontend tiles + admin menu items
     * + admin dashboard tiles) consume this entity? A surface is
     * counted when its declared `cap` string maps to the entity in
     * LegacyCapMapper. Surfaces gated on a `cap_callback` are not
     * traversed (the closure is opaque).
     *
     * @return list<array{type:string, label:string, cap:string}>
     */
    public static function consumersOf( string $entity ): array {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\LegacyCapMapper' ) ) {
            return [];
        }
        $caps = LegacyCapMapper::capsForEntity( $entity );
        if ( empty( $caps ) ) return [];
        $caps_set = array_flip( $caps );

        $out = [];

        if ( class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' ) ) {
            foreach ( TileRegistry::allRegistered() as $tile ) {
                $cap = (string) ( $tile['cap'] ?? '' );
                if ( $cap === '' || ! isset( $caps_set[ $cap ] ) ) continue;
                $label = self::resolveTileLabel( $tile );
                if ( $label === '' ) continue;
                $out[] = [
                    'type'  => 'tile',
                    'label' => $label,
                    'cap'   => $cap,
                ];
            }
        }

        if ( class_exists( '\\TT\\Shared\\Admin\\AdminMenuRegistry' ) ) {
            foreach ( AdminMenuRegistry::allEntries() as $entry ) {
                if ( ! empty( $entry['is_separator'] ) ) continue;
                $cap = (string) ( $entry['cap'] ?? '' );
                if ( $cap === '' || ! isset( $caps_set[ $cap ] ) ) continue;
                $title = (string) ( $entry['title'] ?? '' );
                if ( $title === '' ) continue;
                $out[] = [
                    'type'  => 'admin_menu',
                    'label' => $title,
                    'cap'   => $cap,
                ];
            }
            foreach ( AdminMenuRegistry::allDashboardTiles() as $tile ) {
                $cap = (string) ( $tile['cap'] ?? '' );
                if ( $cap === '' || ! isset( $caps_set[ $cap ] ) ) continue;
                $label = (string) ( $tile['label'] ?? '' );
                if ( $label === '' ) continue;
                $out[] = [
                    'type'  => 'admin_dashboard',
                    'label' => $label,
                    'cap'   => $cap,
                ];
            }
        }

        // Dedupe by (type|label) — the same surface may be registered
        // both as a frontend tile and an admin menu item.
        $seen = [];
        $deduped = [];
        foreach ( $out as $row ) {
            $key = $row['type'] . '|' . $row['label'];
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ]  = true;
            $deduped[]     = $row;
        }
        return $deduped;
    }

    /**
     * Tiles whose visibility cannot be controlled through the matrix.
     *
     * v3.87.0 introduced the `entity` field on `TileRegistry`: when a
     * tile declares it AND `tt_authorization_active = 1`, the matrix
     * is the sole source of truth. So the v3.88.0 definition of
     * "matrix-controlled" is *anything that has an `entity`* — string
     * caps that map cleanly through `LegacyCapMapper` are also
     * matrix-aware, but only when the cap maps to an entity the
     * operator can actually find on the matrix page.
     *
     * A tile lands in this list only when none of the gates can be
     * resolved to a matrix entity:
     *   - no `entity` declared
     *   - no `cap` declared, OR `cap` is unknown to LegacyCapMapper
     *
     * After the v3.87 sweep + v3.88 patch this list should be empty
     * on a stock install. It stays for forward-compatibility: a
     * future module that registers a tile without thinking about the
     * matrix shows up here so the operator notices.
     *
     * @return list<array{label:string, view_slug:string}>
     */
    public static function callbackGatedTiles(): array {
        if ( ! class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' ) ) return [];
        $out = [];
        foreach ( TileRegistry::allRegistered() as $tile ) {
            // Entity-declared tiles are matrix-controlled directly.
            if ( ! empty( $tile['entity'] ) ) continue;

            // String-cap tiles are matrix-aware iff the cap maps to
            // an entity in LegacyCapMapper. Unknown caps fall through
            // to native WP cap evaluation and the matrix has no view.
            $cap = (string) ( $tile['cap'] ?? '' );
            if ( $cap !== '' && class_exists( '\\TT\\Modules\\Authorization\\LegacyCapMapper' ) ) {
                if ( LegacyCapMapper::tupleFor( $cap ) !== null ) continue;
            }

            $label = self::resolveTileLabel( $tile );
            if ( $label === '' ) continue;
            $out[] = [
                'label'     => $label,
                'view_slug' => (string) ( $tile['view_slug'] ?? '' ),
            ];
        }
        return $out;
    }

    /**
     * Locale-aware group label for an entity, derived from the
     * frontend tile registry: which tile-group does an entity sit
     * under on the persona dashboard? Picks the first matching tile,
     * preferring a tile that *declares* `entity` directly over a
     * tile that maps to the entity through `cap` → `LegacyCapMapper`.
     *
     * Returns null when no tile or admin surface consumes this
     * entity — caller falls back to a module-class-based bucket.
     */
    public static function groupForEntity( string $entity ): ?string {
        if ( ! class_exists( '\\TT\\Shared\\Tiles\\TileRegistry' ) ) return null;

        // Pass 1 — preferred: a tile that explicitly declares this entity.
        foreach ( TileRegistry::allRegistered() as $tile ) {
            if ( ( $tile['entity'] ?? '' ) === $entity ) {
                $group = (string) ( $tile['group'] ?? '' );
                if ( $group !== '' ) return $group;
            }
        }

        // Pass 2 — fallback: a tile whose `cap` maps to this entity.
        if ( class_exists( '\\TT\\Modules\\Authorization\\LegacyCapMapper' ) ) {
            $caps = array_flip( LegacyCapMapper::capsForEntity( $entity ) );
            if ( ! empty( $caps ) ) {
                foreach ( TileRegistry::allRegistered() as $tile ) {
                    $cap = (string) ( $tile['cap'] ?? '' );
                    if ( $cap !== '' && isset( $caps[ $cap ] ) ) {
                        $group = (string) ( $tile['group'] ?? '' );
                        if ( $group !== '' ) return $group;
                    }
                }
            }
        }

        return null;
    }

    private static function resolveTileLabel( array $tile ): string {
        if ( ! empty( $tile['label'] ) ) return (string) $tile['label'];
        $labels = $tile['labels'] ?? [];
        if ( is_array( $labels ) && isset( $labels['*'] ) ) return (string) $labels['*'];
        if ( is_array( $labels ) ) {
            foreach ( $labels as $v ) {
                if ( is_string( $v ) && $v !== '' && $v !== '__hidden__' ) return $v;
            }
        }
        return '';
    }
}
