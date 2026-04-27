<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ModuleSurfaceMap (#0051) — single source of truth for which module
 * owns which user-facing surface slug.
 *
 * Two surface families are mapped here:
 *
 *   1. Frontend dashboard `tt_view=<slug>` URLs.
 *   2. wp-admin `?page=<slug>` URLs.
 *
 * Both lookups return a fully-qualified module class name, or null for
 * surfaces that aren't gated by any single module (player-personal
 * landings, infrastructure surfaces, separator rows, the always-on
 * top-level dashboard, etc.). A null return means "do not filter this
 * surface" — it is a deliberate signal, not an oversight.
 *
 * Callers gate by combining the lookup with `ModuleRegistry::isEnabled`:
 *
 *   $owner = ModuleSurfaceMap::moduleForViewSlug( $slug );
 *   if ( $owner !== null && ! ModuleRegistry::isEnabled( $owner ) ) {
 *       // hide tile, refuse dispatch, skip menu registration, etc.
 *   }
 *
 * Always-on modules (Auth, Configuration, Authorization) can still be
 * declared as owners — `isEnabled` returns true unconditionally for
 * them so the filter is a no-op. Keeping the ownership data consistent
 * means call sites don't need exception cases.
 */
final class ModuleSurfaceMap {

    /**
     * Frontend `tt_view` slug → owning module class.
     *
     * Slugs not present in this map default to `null` (never gated).
     *
     * @var array<string, string>
     */
    private const VIEW_SLUG_TO_MODULE = [
        // Me-group landings — each delegates to its module's data layer.
        'overview'           => 'TT\\Modules\\Players\\PlayersModule',
        'profile'            => 'TT\\Modules\\Players\\PlayersModule',
        'my-team'            => 'TT\\Modules\\Teams\\TeamsModule',
        'teammate'           => 'TT\\Modules\\Teams\\TeamsModule',
        'my-evaluations'     => 'TT\\Modules\\Evaluations\\EvaluationsModule',
        'my-activities'      => 'TT\\Modules\\Activities\\ActivitiesModule',
        'my-goals'           => 'TT\\Modules\\Goals\\GoalsModule',
        'my-pdp'             => 'TT\\Modules\\Pdp\\PdpModule',

        // Coaching surfaces.
        'teams'              => 'TT\\Modules\\Teams\\TeamsModule',
        'players'            => 'TT\\Modules\\Players\\PlayersModule',
        'players-import'    => 'TT\\Modules\\Players\\PlayersModule',
        'people'             => 'TT\\Modules\\People\\PeopleModule',
        'functional-roles'   => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'evaluations'        => 'TT\\Modules\\Evaluations\\EvaluationsModule',
        'activities'         => 'TT\\Modules\\Activities\\ActivitiesModule',
        'goals'              => 'TT\\Modules\\Goals\\GoalsModule',
        'pdp'                => 'TT\\Modules\\Pdp\\PdpModule',
        'team-chemistry'     => 'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule',
        'podium'             => 'TT\\Modules\\Stats\\StatsModule',
        'methodology'        => 'TT\\Modules\\Methodology\\MethodologyModule',

        // Analytics surfaces.
        'rate-cards'         => 'TT\\Modules\\Stats\\StatsModule',
        'compare'            => 'TT\\Modules\\Stats\\StatsModule',

        // Admin-tier surfaces.
        'configuration'      => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'custom-fields'      => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'eval-categories'    => 'TT\\Modules\\Evaluations\\EvaluationsModule',
        'roles'              => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'migrations'         => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'usage-stats'        => 'TT\\Modules\\Stats\\StatsModule',
        'usage-stats-details' => 'TT\\Modules\\Stats\\StatsModule',
        'docs'               => 'TT\\Modules\\Documentation\\DocumentationModule',

        // Workflow surfaces.
        'my-tasks'           => 'TT\\Modules\\Workflow\\WorkflowModule',
        'tasks-dashboard'    => 'TT\\Modules\\Workflow\\WorkflowModule',
        'workflow-config'    => 'TT\\Modules\\Workflow\\WorkflowModule',

        // Development surfaces.
        'submit-idea'        => 'TT\\Modules\\Development\\DevelopmentModule',
        'ideas-board'        => 'TT\\Modules\\Development\\DevelopmentModule',
        'ideas-refine'       => 'TT\\Modules\\Development\\DevelopmentModule',
        'ideas-approval'     => 'TT\\Modules\\Development\\DevelopmentModule',
        'dev-tracks'         => 'TT\\Modules\\Development\\DevelopmentModule',

        // Invitation surfaces. `accept-invite` is intentionally absent —
        // it must keep working for not-yet-registered recipients.
        'invitations-config' => 'TT\\Modules\\Invitations\\InvitationsModule',
    ];

    /**
     * wp-admin `?page=` slug → owning module class.
     *
     * Slugs not present default to `null` (never gated). Methodology
     * uses a prefix match to cover the family of edit subpages without
     * listing each one.
     *
     * @var array<string, string>
     */
    private const ADMIN_SLUG_TO_MODULE = [
        // Onboarding.
        'tt-welcome'              => 'TT\\Modules\\Onboarding\\OnboardingModule',

        // License module surfaces.
        'tt-account'              => 'TT\\Modules\\License\\LicenseModule',
        'tt-dev-license'          => 'TT\\Modules\\License\\LicenseModule',

        // People-group.
        'tt-teams'                => 'TT\\Modules\\Teams\\TeamsModule',
        'tt-players'              => 'TT\\Modules\\Players\\PlayersModule',
        'tt-people'               => 'TT\\Modules\\People\\PeopleModule',

        // Performance-group.
        'tt-evaluations'          => 'TT\\Modules\\Evaluations\\EvaluationsModule',
        'tt-activities'           => 'TT\\Modules\\Activities\\ActivitiesModule',
        'tt-goals'                => 'TT\\Modules\\Goals\\GoalsModule',
        'tt-seasons'              => 'TT\\Modules\\Pdp\\PdpModule',
        'tt-football-actions'     => 'TT\\Modules\\Methodology\\MethodologyModule',
        'tt-football-action-edit' => 'TT\\Modules\\Methodology\\MethodologyModule',

        // Analytics-group.
        'tt-reports'              => 'TT\\Modules\\Reports\\ReportsModule',
        'tt-rate-cards'           => 'TT\\Modules\\Stats\\StatsModule',
        'tt-compare'              => 'TT\\Modules\\Stats\\StatsModule',
        'tt-usage-stats'          => 'TT\\Modules\\Stats\\StatsModule',
        'tt-usage-stats-details'  => 'TT\\Modules\\Stats\\StatsModule',

        // Configuration-group.
        'tt-config'               => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'tt-custom-fields'        => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'tt-migrations'           => 'TT\\Modules\\Configuration\\ConfigurationModule',
        'tt-eval-categories'      => 'TT\\Modules\\Evaluations\\EvaluationsModule',
        'tt-category-weights'     => 'TT\\Modules\\Evaluations\\EvaluationsModule',

        // Access Control / Authorization-group (all always-on).
        'tt-roles'                => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'tt-functional-roles'     => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'tt-roles-debug'          => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'tt-matrix'               => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'tt-matrix-preview'       => 'TT\\Modules\\Authorization\\AuthorizationModule',
        'tt-modules'              => 'TT\\Modules\\Authorization\\AuthorizationModule',

        // Help.
        'tt-docs'                 => 'TT\\Modules\\Documentation\\DocumentationModule',

        // Demo data tools.
        'tt-demo-data'            => 'TT\\Modules\\DemoData\\DemoDataModule',
    ];

    /**
     * Prefix-match families. Listed slug-prefix → module class. Used
     * for groups of programmatically-named subpages (e.g. all the
     * methodology entity edit pages share the `tt-methodology` prefix).
     *
     * @var array<string, string>
     */
    private const ADMIN_SLUG_PREFIX_TO_MODULE = [
        'tt-methodology' => 'TT\\Modules\\Methodology\\MethodologyModule',
    ];

    public static function moduleForViewSlug( string $slug ): ?string {
        return self::VIEW_SLUG_TO_MODULE[ $slug ] ?? null;
    }

    public static function moduleForAdminSlug( string $slug ): ?string {
        if ( isset( self::ADMIN_SLUG_TO_MODULE[ $slug ] ) ) {
            return self::ADMIN_SLUG_TO_MODULE[ $slug ];
        }
        foreach ( self::ADMIN_SLUG_PREFIX_TO_MODULE as $prefix => $class ) {
            if ( strpos( $slug, $prefix ) === 0 ) return $class;
        }
        return null;
    }

    /**
     * Convenience predicate: should this view slug be hidden from the
     * current request? True iff a single module owns the slug AND that
     * module is currently disabled.
     */
    public static function isViewSlugDisabled( string $slug ): bool {
        $owner = self::moduleForViewSlug( $slug );
        if ( $owner === null ) return false;
        return ! ModuleRegistry::isEnabled( $owner );
    }

    public static function isAdminSlugDisabled( string $slug ): bool {
        $owner = self::moduleForAdminSlug( $slug );
        if ( $owner === null ) return false;
        return ! ModuleRegistry::isEnabled( $owner );
    }
}
