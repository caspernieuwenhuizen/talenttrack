<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RolesService — centralized role and capability management.
 *
 * v3.0.0 — capability refactor. Each cap now exists in both a VIEW
 * and an EDIT flavor, following the "medium granularity" design:
 *
 *   OLD (pre-3.0)               NEW (3.0+)
 *   tt_manage_players       →   tt_view_players + tt_edit_players
 *   tt_evaluate_players     →   tt_view_evaluations + tt_edit_evaluations
 *                           +   tt_view_activities + tt_edit_activities
 *                           +   tt_view_goals + tt_edit_goals
 *   tt_manage_settings      →   tt_view_settings + tt_edit_settings
 *   tt_view_reports         →   tt_view_reports (kept; no write companion)
 *
 * Backward compat: the OLD caps still work. They are soft aliases
 * implemented via a `map_meta_cap` filter (see CapabilityAliases in
 * src/Infrastructure/Security/CapabilityAliases.php). Any call like
 * `current_user_can('tt_manage_players')` resolves to a check for
 * `tt_edit_players` (assuming they can edit, they can also "manage").
 * This keeps all existing ~60-80 call sites working while slice 2
 * gradually rewrites them to the granular caps.
 *
 * Observer role: now has all tt_view_* caps and none of the tt_edit_*
 * caps, so it's a genuinely useful read-only role across every admin
 * and frontend surface.
 */
class RolesService {

    // Capability inventory

    /**
     * All TalentTrack capabilities, grouped by area. The right-hand
     * side is [view_cap, edit_cap] or [view_cap, null] when no write
     * companion exists (reports, debug).
     */
    public const VIEW_CAPS = [
        'tt_view_teams',
        'tt_view_players',
        'tt_view_people',
        'tt_view_evaluations',
        'tt_view_activities',
        'tt_view_goals',
        'tt_view_reports',
        'tt_view_settings',
    ];

    public const EDIT_CAPS = [
        'tt_edit_teams',
        'tt_edit_players',
        'tt_edit_people',
        'tt_edit_evaluations',
        'tt_edit_activities',
        'tt_edit_goals',
        'tt_edit_settings',
    ];

    /**
     * Legacy caps kept for backward compatibility during v3 transition.
     * These are included in role assignments so any code still checking
     * them (via current_user_can or role membership) continues to work
     * until slice 2 finishes migrating call sites.
     */
    public const LEGACY_CAPS = [
        'tt_manage_players',
        'tt_evaluate_players',
        'tt_manage_settings',
        // 'tt_view_reports' kept as-is — no change
    ];

    /**
     * #0014 Sprint 4+5 caps. Generate-report is the wizard gate;
     * generate-scout-report is the wizard's scout-delivery gate;
     * view-scout-assignments is the scout-side "My players" gate.
     */
    public const REPORT_CAPS = [
        'tt_generate_report',
        'tt_generate_scout_report',
        'tt_view_scout_assignments',
    ];

    /**
     * #0017 — Trial player module. `manage_trials` is the HoD-only
     * gate (open / extend / decide / archive cases, edit tracks +
     * letters); `submit_trial_input` is per-coach; `view_synthesis`
     * is the read gate (per-case visibility narrowed in code).
     */
    public const TRIAL_CAPS = [
        'tt_manage_trials',
        'tt_submit_trial_input',
        'tt_view_trial_synthesis',
    ];

    /**
     * #0053 — Player journey visibility caps. Both gate per-row
     * visibility on tt_player_events (medical and safeguarding events
     * only render for viewers holding the matching cap). Public +
     * coaching_staff visibility levels are gated by existing caps.
     */
    public const JOURNEY_CAPS = [
        'tt_view_player_medical',
        'tt_view_player_safeguarding',
    ];

    /**
     * #0063 — In-product mail composer. Granted to admin / head_dev /
     * club_admin / coach so they can send academy email from the People
     * page email click. Every send is audit-logged via AuditService.
     */
    public const COMMS_CAPS = [
        'tt_send_email',
    ];

    /**
     * #0071 — Settings sub-cap split. Twelve cap pairs that replace the
     * over-coarse `tt_*_settings` family. The umbrella caps stay
     * registered as roll-ups (a user "has" tt_edit_settings iff they
     * hold all twelve `tt_edit_*` sub-caps; see CapabilityAliases).
     */
    public const SETTINGS_SUBCAPS = [
        'tt_view_lookups', 'tt_edit_lookups',
        'tt_view_branding', 'tt_edit_branding',
        'tt_view_feature_toggles', 'tt_edit_feature_toggles',
        'tt_view_audit_log', // no edit — audit is read-only
        'tt_view_translations', 'tt_edit_translations',
        'tt_view_custom_fields', 'tt_edit_custom_fields',
        'tt_view_evaluation_categories', 'tt_edit_evaluation_categories',
        'tt_view_category_weights', 'tt_edit_category_weights',
        'tt_view_rating_scale', 'tt_edit_rating_scale',
        'tt_view_migrations', 'tt_edit_migrations',
        'tt_view_seasons', 'tt_edit_seasons',
        'tt_view_setup_wizard', 'tt_edit_setup_wizard',
        // Authorization-management write cap, replacing the security
        // smell where RolesPage::handleGrant gated on `tt_view_settings`.
        'tt_manage_authorization',
    ];

    /**
     * #0071 — additional caps surfaced by the round-2 audit. Bridges
     * threads / spond / journey / player-status to dedicated caps so
     * they no longer piggy-back on `tt_edit_evaluations` / `tt_view_settings` /
     * `tt_edit_teams`.
     */
    public const COVERAGE_CAPS = [
        'tt_view_thread', 'tt_post_thread',
        'tt_view_spond', 'tt_edit_spond_credentials',
        'tt_view_player_timeline',
        'tt_view_authorization_changelog',
        'tt_view_player_potential', 'tt_edit_player_potential',
        'tt_view_player_behaviour_ratings', 'tt_edit_player_behaviour_ratings',
        'tt_view_player_status', 'tt_view_player_status_breakdown',
        'tt_view_pdp_evidence_packet',
        'tt_view_pdp_planning',
        'tt_view_player_status_methodology', 'tt_edit_player_status_methodology',
        'tt_view_functional_roles', 'tt_manage_functional_roles_admin',
    ];

    /**
     * #0071 — Impersonation. The act-cap. Granted to administrator and
     * tt_club_admin only by default; never to HoD or any other persona.
     * Cross-club is guarded separately in ImpersonationService.
     */
    public const IMPERSONATION_CAPS = [
        'tt_impersonate_users',
    ];

    /** @return array<string, array<string, string|array<string,bool>>> */
    public function roleDefinitions(): array {
        return [
            'tt_head_dev' => [
                'label' => __( 'Head of Development', 'talenttrack' ),
                // #0071 — narrowed to development-focused, read-mostly outside
                // player-development surfaces. Drops `tt_edit_settings` and
                // the new `tt_edit_*` sub-caps; keeps the view counterparts
                // so HoD can still inspect Configuration. Migration 0051
                // handles existing installs (with TT_HOD_KEEP_LEGACY_CAPS
                // opt-out).
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allViewCapsTrue(),                        // view everything
                    // v3.84.3 — was array_fill_keys( SETTINGS_SUBCAPS, true )
                    // which granted ALL subcaps (view AND edit). The
                    // CapabilityAliases roll-up then promoted the full
                    // edit set back to tt_edit_settings, unlocking the
                    // Wizards + Open wp-admin tiles for HoD post-#0071
                    // narrowing. View-only subset now matches the
                    // narrowing intent and migration 0054's stripped
                    // cap list. tt_edit_settings stays out → tiles
                    // gated on it correctly hide.
                    self::viewOnlySettingsSubcapsTrue(),
                    array_fill_keys( self::COVERAGE_CAPS, true ),   // new round-2 caps (mostly views)
                    [
                        // Player-development write caps HoD keeps:
                        'tt_edit_teams'         => true,
                        'tt_edit_players'       => true,
                        'tt_edit_people'        => true,
                        'tt_edit_evaluations'   => true,
                        'tt_edit_activities'    => true,
                        'tt_edit_goals'         => true,
                        'tt_evaluate_players'   => true,
                        'tt_manage_players'     => true,
                        // NOT tt_edit_settings — HoD is read-only on config now.
                        'tt_access_frontend_admin' => true,
                        'tt_generate_report'       => true,
                        'tt_generate_scout_report' => true,
                        'tt_send_email'            => true,
                    ],
                    array_fill_keys( self::TRIAL_CAPS,    true ),  // full trials
                    array_fill_keys( self::JOURNEY_CAPS,  true )   // medical + safeguarding (sensitive)
                ),
            ],
            'tt_club_admin' => [
                'label' => __( 'Club Admin', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allViewCapsTrue(),
                    array_fill_keys( self::SETTINGS_SUBCAPS, true ),  // #0071: full per-area control
                    array_fill_keys( self::COVERAGE_CAPS,    true ),
                    array_fill_keys( self::IMPERSONATION_CAPS, true ),// #0071: only admin + club_admin
                    [
                        'tt_edit_teams'      => true,
                        'tt_edit_players'    => true,
                        'tt_edit_people'     => true,
                        'tt_edit_activities' => true,
                        'tt_edit_goals'      => true,
                        'tt_edit_settings'   => true,
                        // NOT tt_edit_evaluations — Club Admin doesn't evaluate
                    ],
                    [
                        'tt_manage_players'  => true,
                        'tt_manage_settings' => true,
                        'tt_access_frontend_admin' => true,
                    ]
                ),
            ],
            'tt_coach' => [
                'label' => __( 'Coach', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'       => true,
                        'tt_view_players'     => true,
                        'tt_view_people'      => true,
                        'tt_view_evaluations' => true,
                        'tt_view_activities'    => true,
                        'tt_view_goals'       => true,
                        'tt_view_reports'     => true,
                        // NOT tt_view_settings — coaches don't touch config
                    ],
                    [
                        'tt_edit_evaluations' => true,
                        'tt_edit_activities'    => true,
                        'tt_edit_goals'       => true,
                        // NOT tt_edit_players/teams/people/settings
                    ],
                    [
                        'tt_evaluate_players' => true,
                    ],
                    // #0014 Sprint 4 — coaches can generate reports for
                    // players on their own teams. Per-player gating
                    // happens in FrontendReportWizardView.
                    [ 'tt_generate_report' => true ],
                    // #0063 — in-product mail composer.
                    [ 'tt_send_email' => true ]
                ),
            ],
            'tt_scout' => [
                'label' => __( 'Scout', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'       => true,
                        'tt_view_players'     => true,
                        'tt_view_evaluations' => true,
                    ],
                    [
                        'tt_edit_evaluations' => true,
                    ],
                    [
                        'tt_evaluate_players' => true,
                    ],
                    // #0014 Sprint 5 — scout-side "My players" gate.
                    // Scout users still go through the assignment check
                    // in FrontendScoutMyPlayersView (assignment lives in
                    // user meta), so this cap alone doesn't grant
                    // visibility into any specific player.
                    [ 'tt_view_scout_assignments' => true ]
                ),
            ],
            'tt_staff' => [
                'label' => __( 'Staff', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'     => true,
                        'tt_view_players'   => true,
                        'tt_view_people'    => true,
                    ],
                    [
                        'tt_edit_players'   => true,
                        'tt_edit_people'    => true,
                    ],
                    [
                        'tt_manage_players' => true,
                    ]
                ),
            ],
            'tt_player' => [
                'label' => __( 'Player', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            'tt_parent' => [
                'label' => __( 'Parent', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            // v3.0.0 — now a meaningful role: view EVERYTHING, edit NOTHING.
            'tt_readonly_observer' => [
                'label' => __( 'Read-Only Observer', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allViewCapsTrue()
                    // No edit caps. No legacy caps. Pure view.
                ),
            ],
        ];
    }

    // Role + cap installation

    /**
     * Install all TT roles + ensure administrator has every TT cap.
     * Safe to call on every activation — add_role is a no-op if the
     * role exists. WordPress's native behaviour when add_role is
     * called on an existing role is to leave caps alone; our
     * ensureCapabilities() covers the re-grant case.
     */
    public function installRoles(): void {
        foreach ( $this->roleDefinitions() as $slug => $def ) {
            add_role( $slug, (string) $def['label'], (array) $def['caps'] );
        }
        $this->ensureCapabilities();
    }

    /**
     * Grant all TT caps to administrator + re-assert role caps. Called
     * as part of every runMigrations() so role definition changes
     * (new caps added in new releases) propagate without manual
     * intervention.
     */
    public function ensureCapabilities(): void {
        $all = array_merge(
            self::VIEW_CAPS,
            self::EDIT_CAPS,
            self::LEGACY_CAPS,
            self::REPORT_CAPS,
            self::TRIAL_CAPS,
            self::JOURNEY_CAPS,
            self::COMMS_CAPS,
            self::SETTINGS_SUBCAPS,
            self::COVERAGE_CAPS,
            self::IMPERSONATION_CAPS,
            [ 'tt_view_reports', 'tt_access_frontend_admin' ]
        );

        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            foreach ( array_unique( $all ) as $cap ) {
                if ( ! $administrator->has_cap( $cap ) ) {
                    $administrator->add_cap( $cap );
                }
            }
        }

        foreach ( $this->roleDefinitions() as $slug => $def ) {
            $role = get_role( $slug );
            if ( ! $role ) continue;
            foreach ( (array) $def['caps'] as $cap => $granted ) {
                if ( $granted && ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }

        // v3.84.3 — strip the specific edit subcaps that #0071 narrowed
        // off HoD. ensureCapabilities only ADDED missing caps in the
        // pre-fix definition, so the per-area tt_edit_* subcaps stayed
        // on the role from prior versions even though the definition
        // stopped granting them. CapabilityAliases then promoted the
        // held set back to tt_edit_settings, unlocking the Wizards +
        // Open wp-admin tiles. Strip the exact deprecated list so HoD
        // converges on the narrowed permission set. Other modules'
        // ensureCaps() grants stay intact.
        self::stripDeprecatedHodEditSubcaps();
    }

    /**
     * Remove the per-area edit subcaps that #0071 narrowed off HoD
     * but were never stripped from the role's stored cap list. The
     * list mirrors migration 0054's strip — so re-running this on a
     * site that already ran 0054 is a no-op. Idempotent.
     */
    private static function stripDeprecatedHodEditSubcaps(): void {
        $hod = get_role( 'tt_head_dev' );
        if ( ! $hod ) return;
        $deprecated = [
            'tt_edit_settings',
            'tt_edit_lookups', 'tt_edit_branding', 'tt_edit_feature_toggles',
            'tt_edit_translations', 'tt_edit_custom_fields',
            'tt_edit_evaluation_categories', 'tt_edit_category_weights',
            'tt_edit_rating_scale', 'tt_edit_migrations', 'tt_edit_seasons',
            'tt_edit_setup_wizard',
            'tt_manage_settings',
        ];
        foreach ( $deprecated as $cap ) {
            if ( $hod->has_cap( $cap ) ) {
                $hod->remove_cap( $cap );
            }
        }
    }

    /**
     * Remove TT roles (used by a future cleanup/uninstall path).
     */
    public function uninstallRoles(): void {
        foreach ( array_keys( $this->roleDefinitions() ) as $slug ) {
            remove_role( $slug );
        }
    }

    // Helpers

    /** @return array<string,bool> */
    private static function allViewCapsTrue(): array {
        return array_fill_keys( self::VIEW_CAPS, true );
    }

    /** @return array<string,bool> */
    private static function allEditCapsTrue(): array {
        return array_fill_keys( self::EDIT_CAPS, true );
    }

    /**
     * v3.84.3 — view-only subset of SETTINGS_SUBCAPS. HoD gets this,
     * not the full list, so the CapabilityAliases roll-up does NOT
     * grant `tt_edit_settings` (which would otherwise unlock the
     * Wizards / Open wp-admin tiles HoD shouldn't see).
     *
     * Mirrors what migration 0054 strips on existing-install HoD
     * users; the role definition was still granting the full set so
     * ensureCapabilities re-added them on every activation, undoing
     * the migration. Same fix applied at the role layer permanently.
     *
     * @return array<string,bool>
     */
    private static function viewOnlySettingsSubcapsTrue(): array {
        $view_only = array_filter( self::SETTINGS_SUBCAPS, static function ( string $cap ): bool {
            return strpos( $cap, 'tt_view_' ) === 0;
        } );
        return array_fill_keys( $view_only, true );
    }

    /** @return array<string,bool> */
    private static function allCapsTrue(): array {
        return array_merge(
            self::allViewCapsTrue(),
            self::allEditCapsTrue(),
            // #0063 — fold COMMS_CAPS into the everything-bundle so
            // tt_send_email lands on tt_head_dev / administrator
            // automatically.
            array_fill_keys( self::COMMS_CAPS, true )
        );
    }

    /** @return array<string,bool> */
    private static function legacyCapsTrue(): array {
        return array_fill_keys( self::LEGACY_CAPS, true );
    }
}
