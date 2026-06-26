<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LegacyCapMapper — bridges legacy `tt_*` WordPress capability strings
 * to the matrix vocabulary `(entity, activity, scope_kind)` used by
 * MatrixGate (#0033 Sprint 2).
 *
 * Sprint 2 ships the bridge but leaves it dormant. The user_has_cap
 * filter (registered in AuthorizationModule::boot) consults the
 * `tt_authorization_active` config flag — when 0 (default) the filter
 * is a no-op and native WP capability checks decide. When 1 (set by
 * Sprint 8's apply toggle), the filter intercepts every `tt_*` cap
 * lookup and routes it through MatrixGate.
 *
 * This split lets Sprint 2 land additively. No production behavior
 * changes until an admin clicks Apply in the migration preview report
 * (Sprint 8). Until then the matrix is shadow data only.
 *
 * Mapping notes:
 *   - `tt_view_*` → ('entity', 'read')
 *   - `tt_edit_*` → ('entity', 'change')   — change is the v1 vocabulary;
 *                                            create_delete is a separate verb
 *   - `tt_manage_*` → ('entity', 'create_delete')
 *   - `tt_evaluate_players` → ('evaluations', 'create_delete') — owns the
 *                              act-of-evaluation, not the player record
 *   - `tt_access_frontend_admin` → ('frontend_admin', 'read')
 *   - `tt_edit_settings` / `tt_view_settings` / `tt_manage_settings`
 *     map to a `settings` entity that any module can subgate via the
 *     matrix's per-module config_tab entities (Sprint 6).
 *
 * Caps not in the table fall through (the filter callback leaves them
 * untouched, so native WP cap checks decide).
 */
final class LegacyCapMapper {

    /**
     * @var array<string, array{0:string, 1:string}>
     *      cap_slug => [ entity, activity ]
     */
    private const MAPPING = [
        // Core domain entities
        'tt_view_teams'                  => [ 'team',           'read' ],
        'tt_edit_teams'                  => [ 'team',           'change' ],
        'tt_view_players'                => [ 'players',        'read' ],
        'tt_edit_players'                => [ 'players',        'change' ],
        'tt_manage_players'              => [ 'players',        'create_delete' ],
        'tt_view_people'                 => [ 'people',         'read' ],
        'tt_edit_people'                 => [ 'people',         'change' ],
        'tt_view_evaluations'            => [ 'evaluations',    'read' ],
        'tt_edit_evaluations'            => [ 'evaluations',    'change' ],
        'tt_evaluate_players'            => [ 'evaluations',    'create_delete' ],
        'tt_view_goals'                  => [ 'goals',          'read' ],
        'tt_edit_goals'                  => [ 'goals',          'change' ],
        'tt_view_activities'             => [ 'activities',     'read' ],
        'tt_edit_activities'             => [ 'activities',     'change' ],
        'tt_view_methodology'            => [ 'methodology',    'read' ],
        'tt_edit_methodology'            => [ 'methodology',    'change' ],
        'tt_view_reports'                => [ 'reports',        'read' ],

        // #1480 — academy-wide holidays.
        'tt_view_holidays'               => [ 'holidays',       'read' ],
        'tt_edit_holidays'               => [ 'holidays',       'change' ],
        'tt_manage_holidays'             => [ 'holidays',       'create_delete' ],

        // Settings + admin
        'tt_view_settings'               => [ 'settings',       'read' ],
        'tt_edit_settings'               => [ 'settings',       'change' ],
        'tt_manage_settings'             => [ 'settings',       'create_delete' ],
        'tt_access_frontend_admin'       => [ 'frontend_admin', 'read' ],
        'tt_manage_functional_roles'     => [ 'functional_role_assignments', 'create_delete' ],
        'tt_manage_backups'              => [ 'backup',         'create_delete' ],

        // Workflow (#0022)
        'tt_view_own_tasks'              => [ 'workflow_tasks', 'read' ],
        'tt_view_tasks_dashboard'        => [ 'tasks_dashboard','read' ],
        'tt_configure_workflow_templates'=> [ 'workflow_templates','change' ],
        'tt_manage_workflow_templates'   => [ 'workflow_templates','create_delete' ],
        // #1152 — academy_admin takeover gate. Bridged here as a
        // convenience for view code, but the AUTHORITATIVE check uses
        // `MatrixGate::can( $uid, 'task_completion', 'create', SCOPE_GLOBAL )`
        // directly because the legacy bridge resolves via
        // `canAnyScope` — that would let a coach with `task_completion
        // [rc, self]` pass too, which defeats the takeover gate. View
        // code calls the MatrixGate helper, not `current_user_can`.

        // Development management (#0009)
        'tt_submit_idea'                 => [ 'dev_ideas',      'change' ],
        'tt_refine_idea'                 => [ 'dev_ideas',      'change' ],
        'tt_view_dev_board'              => [ 'dev_ideas',      'read' ],
        'tt_promote_idea'                => [ 'dev_ideas',      'create_delete' ],

        // Invitations (#0032)
        'tt_send_invitation'             => [ 'invitations',    'create_delete' ],
        'tt_revoke_invitation'           => [ 'invitations',    'create_delete' ],
        'tt_manage_invite_messages'      => [ 'invitations_config', 'change' ],
        'tt_view_parent_dashboard'       => [ 'my_card',        'read' ],

        // Parent accounts (#1815) — admin link/unlink of parent WP accounts.
        'tt_manage_parent_accounts'      => [ 'parent_accounts', 'create_delete' ],

        // #0071 — Settings sub-cap split. Twelve cap pairs replacing
        // the over-coarse `tt_*_settings` umbrella. The umbrella caps
        // remain in the mapper above as a fall-back; new code should
        // use the specific sub-cap.
        'tt_view_lookups'                => [ 'lookups',                'read' ],
        'tt_edit_lookups'                => [ 'lookups',                'change' ],
        'tt_view_branding'               => [ 'branding',               'read' ],
        'tt_edit_branding'               => [ 'branding',               'change' ],
        'tt_view_feature_toggles'        => [ 'feature_toggles',        'read' ],
        'tt_edit_feature_toggles'        => [ 'feature_toggles',        'change' ],
        'tt_view_audit_log'              => [ 'audit_log',              'read' ],
        'tt_view_translations'           => [ 'translations_config',    'read' ],
        'tt_edit_translations'           => [ 'translations_config',    'change' ],
        'tt_view_custom_fields'          => [ 'custom_field_definitions','read' ],
        'tt_edit_custom_fields'          => [ 'custom_field_definitions','change' ],
        'tt_view_evaluation_categories'  => [ 'evaluation_categories',  'read' ],
        'tt_edit_evaluation_categories'  => [ 'evaluation_categories',  'change' ],
        'tt_view_category_weights'       => [ 'category_weights',       'read' ],
        'tt_edit_category_weights'       => [ 'category_weights',       'change' ],
        'tt_view_rating_scale'           => [ 'rating_scale',           'read' ],
        'tt_edit_rating_scale'           => [ 'rating_scale',           'change' ],
        'tt_view_migrations'             => [ 'migrations',             'read' ],
        'tt_edit_migrations'             => [ 'migrations',             'change' ],
        'tt_view_seasons'                => [ 'seasons',                'read' ],
        'tt_edit_seasons'                => [ 'seasons',                'change' ],
        'tt_view_setup_wizard'           => [ 'setup_wizard',           'read' ],
        'tt_edit_setup_wizard'           => [ 'setup_wizard',           'change' ],
        'tt_manage_authorization'        => [ 'authorization_matrix',   'create_delete' ],

        // #0071 — Round-2 coverage caps. Bridges threads / spond /
        // journey / player-status / pdp-evidence to dedicated entities.
        'tt_view_thread'                       => [ 'thread_messages',         'read' ],
        'tt_post_thread'                       => [ 'thread_messages',         'change' ],
        'tt_view_spond'                        => [ 'spond_integration',       'read' ],
        'tt_edit_spond_credentials'            => [ 'spond_integration',       'change' ],
        'tt_view_player_timeline'              => [ 'player_timeline',         'read' ],
        'tt_view_authorization_changelog'      => [ 'authorization_changelog', 'read' ],
        'tt_view_player_potential'             => [ 'player_potential',        'read' ],
        'tt_edit_player_potential'             => [ 'player_potential',        'change' ],
        // #1939 — act-cap bridge. `tt_set_player_potential` is the
        // PlayerStatus "set the potential band" act-cap (data entity is
        // `player_potential`). Raw WP grants (PlayerStatusModule: admin +
        // head_dev + club_admin) exactly match the `player_potential:change`
        // seed grantees (head_of_development + academy_admin globally; no
        // other persona has change), so routing it through the matrix is
        // access-preserving — it closes the frontend/REST divergence where
        // `tt_edit_player_potential` was matrix-aware but the act-cap was not.
        'tt_set_player_potential'              => [ 'player_potential',        'change' ],
        'tt_view_player_behaviour_ratings'     => [ 'player_behaviour_ratings','read' ],
        'tt_edit_player_behaviour_ratings'     => [ 'player_behaviour_ratings','change' ],
        // #1939 — `tt_rate_player_behaviour` (the behaviour-rating act-cap)
        // deliberately NOT bridged: its raw WP grant includes
        // tt_assistant_coach, but the `player_behaviour_ratings` matrix seed
        // has NO assistant_coach row (#1060 removed it). Bridging would
        // revoke AC access — an effective-access change, not enforcement-
        // only. Flagged on #1939 for a product decision (the #1922 lesson).
        'tt_view_player_status'                => [ 'player_status',           'read' ],
        'tt_view_player_status_breakdown'      => [ 'player_status_breakdown', 'read' ],
        'tt_view_pdp_evidence_packet'          => [ 'pdp_evidence_packet',     'read' ],
        'tt_view_pdp_planning'                 => [ 'pdp_planning',            'read' ],
        'tt_view_player_status_methodology'    => [ 'player_status_methodology','read' ],
        'tt_edit_player_status_methodology'    => [ 'player_status_methodology','change' ],
        'tt_view_functional_roles'             => [ 'functional_role_definitions','read' ],
        'tt_manage_functional_roles_admin'     => [ 'functional_role_definitions','create_delete' ],

        // #0071 — Already-declared module caps that finally have a
        // matrix entity to bridge to.
        'tt_view_player_medical'         => [ 'player_injuries',       'read' ],
        'tt_view_player_safeguarding'    => [ 'safeguarding_notes',    'read' ],
        'tt_manage_trials'               => [ 'trial_cases',           'create_delete' ],
        'tt_submit_trial_input'          => [ 'trial_inputs',          'change' ],
        'tt_view_trial_synthesis'        => [ 'trial_synthesis',       'read' ],
        'tt_view_staff_development'      => [ 'staff_development',     'read' ],
        'tt_view_staff_certifications_expiry' => [ 'staff_overview',   'read' ],
        'tt_admin_styling'               => [ 'custom_css',            'create_delete' ],
        'tt_edit_persona_templates'      => [ 'persona_templates',     'change' ],
        // #0078 Phase 5 — custom widget builder. Authoring + delete map
        // to the same matrix entity; HoD holds RC, academy_admin RCD.
        'tt_author_custom_widgets'       => [ 'custom_widgets',        'change' ],
        'tt_manage_custom_widgets'       => [ 'custom_widgets',        'create_delete' ],
        // #0090 Phase 1 — data-row i18n. One cap covers per-club
        // translation editing. Granted to HoD + admin via ensureCapabilities().
        'tt_edit_translations'           => [ 'translations',          'change' ],
        'tt_generate_scout_report'       => [ 'scout_access',          'create_delete' ],
        'tt_view_pdp'                    => [ 'pdp_file',              'read' ],
        'tt_edit_pdp'                    => [ 'pdp_file',              'change' ],
        'tt_edit_pdp_verdict'            => [ 'pdp_verdict',           'change' ],
        // #1274 PR1 — un-archive (restore) is a distinct privilege from
        // edit. Seeded admin-only; bridges to pdp_file:create_delete
        // because un-archiving is morally a "bring back a deleted row"
        // operation and should require the same trust as create/delete.
        'tt_unarchive_pdp'               => [ 'pdp_file',              'create_delete' ],
        // #1274 PR3 — hard delete for data-retention cases (GDPR
        // erasure, parental request, player aged out). Same matrix
        // tuple as unarchive (admin-only via create_delete) but a
        // distinct cap so views can gate the destructive surface
        // separately and the audit trail names the act precisely.
        'tt_delete_pdp'                  => [ 'pdp_file',              'create_delete' ],

        // #0071 — Impersonation. The act-cap. Cross-club guard +
        // admin-on-admin block enforced in ImpersonationService.
        'tt_impersonate_users'           => [ 'impersonation_action',  'create_delete' ],

        // #0081 — Onboarding pipeline (child 1: prospects entity).
        'tt_view_prospects'              => [ 'prospects',             'read' ],
        'tt_edit_prospects'              => [ 'prospects',             'change' ],
        'tt_manage_prospects'            => [ 'prospects',             'create_delete' ],
        'tt_view_test_trainings'         => [ 'test_trainings',        'read' ],
        'tt_edit_test_trainings'         => [ 'test_trainings',        'change' ],
        'tt_manage_test_trainings'       => [ 'test_trainings',        'create_delete' ],
        // #0081 — Onboarding pipeline (child 2: workflow templates).
        // Bridges to test_trainings.change because inviting is materially
        // a write to the test-training schedule (picking the session +
        // composing the parent-facing message). HoD + Admin hold this
        // grant globally; Scout has only R on test_trainings (cannot
        // invite).
        'tt_invite_prospects'            => [ 'test_trainings',        'change' ],

        // #0085 — player notes (staff-only running log on the player file).
        'tt_view_player_notes'           => [ 'player_notes',          'read' ],
        'tt_edit_player_notes'           => [ 'player_notes',          'change' ],
        'tt_manage_player_notes'         => [ 'player_notes',          'create_delete' ],

        // #0083 Child 5 — central analytics surface. Cap bridges to the
        // new `analytics` matrix entity. Default seed grants HoD + Admin
        // read globally; coaches reach analytics through per-entity tabs
        // (Child 4) on the players + teams + activities they have access to.
        'tt_view_analytics'              => [ 'analytics',             'read' ],

        // #0081 — Onboarding pipeline (children 2b + 4: late-stage
        // decision caps). Both bridge to `prospects.create_delete`
        // because deciding a test-training outcome or a trial-group
        // outcome materially commits the prospect's lifecycle (admit
        // → trial case + player promotion; decline → archive). HoD
        // + Admin only.
        'tt_decide_test_training_outcome' => [ 'prospects',            'create_delete' ],
        'tt_decide_trial_outcome'         => [ 'prospects',            'create_delete' ],

        // #0006 — Team planning module. The planner is a calendar
        // view onto `tt_activities` with plan-state filtering, so the
        // caps bridge to the existing activities entity rather than
        // introducing a parallel matrix entity.
        'tt_view_plan'                   => [ 'activities',           'read' ],
        'tt_manage_plan'                 => [ 'activities',           'change' ],

        // #0095 — VCT (Voetbal Conditionele Training) module.
        // Each cap bridges to its entity at `read` activity — the
        // coarsest "does this user participate in this entity at all?"
        // check. The spec describes per-cap activities `rcdp` (read /
        // create / delete / publish), but LegacyCapMapper's schema is
        // one cap → one (entity, activity) tuple, so the activity
        // letters live in the matrix seed instead: coaches + HoD +
        // admin all get `r` on the entity (via the rcd seed grants for
        // `vct` / `vct_library`, and `r`-only for `vct_workload`), so
        // they all pass the cap-layer check. VCT-6's per-endpoint
        // permission_callback enforces the granular activity required
        // by each route as the second layer (cap + scope).
        'tt_vct_plan'                    => [ 'vct',                  'read' ],
        'tt_vct_admin_library'           => [ 'vct_library',          'read' ],
        'tt_vct_view_load'               => [ 'vct_workload',         'read' ],
    ];

    public static function isKnown( string $cap ): bool {
        return isset( self::MAPPING[ $cap ] );
    }

    /**
     * Translate a legacy `tt_*` capability into the matrix tuple that
     * represents it. Returns null for unknown caps so the filter
     * callback can leave them untouched.
     *
     * @return array{0:string, 1:string}|null  [entity, activity] or null
     */
    public static function tupleFor( string $cap ): ?array {
        return self::MAPPING[ $cap ] ?? null;
    }

    /**
     * The filter entry point. Given a cap + user, returns:
     *   - true  — the matrix grants the cap
     *   - false — the matrix denies it
     *   - null  — the cap is unknown to the mapper; caller should fall
     *             back to native WP cap evaluation
     *
     * @param array<int,mixed> $args raw $args from the user_has_cap filter
     */
    public static function evaluate( string $cap, \WP_User $user, array $args ): ?bool {
        $tuple = self::tupleFor( $cap );
        if ( $tuple === null ) return null;

        // #0075 / matrix-active fix — WP administrator unconditionally
        // passes any tt_* capability check, mirroring the bypass already
        // present in AuthorizationService::canDo (where it lands for
        // matrix tuple lookups bypassing user_has_cap). Without this,
        // an admin with no explicit academy_admin functional role gets
        // denied access to admin surfaces (e.g. ?tt_view=custom-css)
        // when tt_authorization_active=1, even though the install has
        // granted them the WP administrator role with full caps. The
        // matrix is the persona vocabulary; administrator is the
        // emergency-override role for the human running the WP install.
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return true;

        [ $entity, $activity ] = $tuple;

        // #0033 follow-up — answer the cap with `canAnyScope`. The
        // legacy `tt_*` cap vocabulary asks "does this user have this
        // ability anywhere?", which maps to "in any scope where they
        // hold a matching assignment". Returning the global-only check
        // here would silently revoke every team-scoped permission
        // (head_coach with `evaluations [rcd, team]` would fail
        // `tt_view_evaluations` because they have no global row), even
        // though the runtime gate at the actual write site would let
        // them through. `canAnyScope` keeps the matrix view trustworthy:
        // green ticks at any scope = true, no scope = false.
        return MatrixGate::canAnyScope( (int) $user->ID, $entity, $activity );
    }

    /**
     * Reverse lookup — return every cap slug that maps to a given
     * entity. Used by the matrix admin UI to show "this entity is
     * consumed by tiles gated on caps X, Y, Z".
     *
     * @return list<string>
     */
    public static function capsForEntity( string $entity ): array {
        $out = [];
        foreach ( self::MAPPING as $cap => $tuple ) {
            if ( ( $tuple[0] ?? '' ) === $entity ) {
                $out[] = (string) $cap;
            }
        }
        return $out;
    }

    /**
     * @return list<string> all known cap slugs — used by tests / preview report.
     */
    public static function knownCaps(): array {
        return array_keys( self::MAPPING );
    }
}
