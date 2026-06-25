<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FeatureRegistry — sub-feature flags within a module (#1485).
 *
 * A module can be wholly disabled via ModuleRegistry. Some modules own
 * several distinct surfaces, and an academy may want one off while the
 * rest stay on — e.g. the Journey module owns the player timeline,
 * injuries, safeguarding AND the Cohort-transitions query; disabling
 * Journey wholesale would remove core player surfaces. A feature flag
 * switches off just the one surface.
 *
 * This mirrors ModuleRegistry exactly, scoped one level finer:
 *   - a static catalog declares each feature, its owning module, its
 *     default state, and which view-slugs / matrix-entities it gates;
 *   - state lives in `tt_feature_state` (club-scoped for SaaS tenancy);
 *   - `isEnabled()` is the single read API consulted by the tile gate,
 *     the dispatcher, MatrixGate, and the REST permission callbacks.
 *
 * Unknown keys default to enabled (so an entity / slug that no feature
 * claims is never gated). Catalogued features fall back to their
 * declared `default_enabled` when no state row exists yet.
 */
class FeatureRegistry {

    /**
     * Feature catalog. Keyed by the feature key (stored verbatim in
     * `tt_feature_state.feature_key`).
     *
     * Each entry:
     *   - label / description  — shown on the modules management page
     *                            and the read-only status view (#1486).
     *   - module_class         — the owning module; the feature only
     *                            appears (and only gates) while its
     *                            parent module is enabled.
     *   - default_enabled      — state for installs with no row yet.
     *   - view_slugs           — `tt_view=` routes the feature owns;
     *                            gated by `viewSlugDisabled()`.
     *   - entities             — matrix entities the feature owns;
     *                            gated by `entityDisabled()` via
     *                            MatrixGate. MUST be entities unique to
     *                            the feature — never a panel entity
     *                            shared with a sibling surface.
     *
     * @return array<string, array{
     *   label: string,
     *   description: string,
     *   module_class: string,
     *   default_enabled: bool,
     *   view_slugs: list<string>,
     *   entities: list<string>
     * }>
     */
    private static function catalog(): array {
        $catalog = [
            'cohort_transitions' => [
                'label'           => __( 'Cohort transitions', 'talenttrack' ),
                'description'     => __( 'Find players academy-wide by journey event and date range. Player timeline, injuries and safeguarding stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Journey\\JourneyModule',
                'default_enabled' => false,
                'view_slugs'      => [ 'cohort-transitions' ],
                'entities'        => [ 'cohort_transitions' ],
            ],
            'team_chemistry' => [
                'label'           => __( 'Team chemistry', 'talenttrack' ),
                'description'     => __( 'Formation board with suggested XI and chemistry scoring. The Team blueprint editor stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule',
                'default_enabled' => false,
                'view_slugs'      => [ 'team-chemistry' ],
                'entities'        => [ 'team_chemistry' ],
            ],
            // #1537 — the ad-hoc Analytics Explorer surface (#1484). The
            // Analytics engine always runs; this only governs the
            // operator-facing explorer + scheduled-reports views. Migrated
            // from the `analytics_explorer_enabled` config flag; migration
            // 0166 carries the existing on/off state forward.
            'analytics_explorer' => [
                'label'           => __( 'Analytics explorer', 'talenttrack' ),
                'description'     => __( 'The ad-hoc explorer for building KPI and dimension queries. The standard reports and the analytics engine stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Analytics\\AnalyticsModule',
                'default_enabled' => false,
                'view_slugs'      => [ 'analytics', 'explore', 'scheduled-reports' ],
                'entities'        => [],
            ],
            // #1537 — the Custom widgets builder (#0078). Migrated from the
            // `tt_custom_widgets_enabled` option; migration 0166 carries the
            // existing on/off state forward. Default off, matching the
            // module's prior behaviour.
            'custom_widgets' => [
                'label'           => __( 'Custom widgets', 'talenttrack' ),
                'description'     => __( 'The builder for bespoke dashboard widgets backed by custom data sources (beta).', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\CustomWidgets\\CustomWidgetsModule',
                'default_enabled' => false,
                'view_slugs'      => [],
                'entities'        => [ 'custom_widgets' ],
            ],
            // #1537 — photo-to-exercise AI extraction (#0016). Default ON to
            // preserve current behaviour; academies opt out. The exercise
            // library CRUD stays available when this is off.
            'exercises_vision_extraction' => [
                'label'           => __( 'Photo exercise extraction', 'talenttrack' ),
                'description'     => __( 'Read a training plan photo and turn it into exercises with AI. The exercise library stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Exercises\\ExercisesModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // #1537 — public blueprint share links (#0068). Default ON to
            // preserve current behaviour; academies opt out. Blueprint
            // editing stays available when this is off.
            'team_blueprints_sharing' => [
                'label'           => __( 'Blueprint share links', 'talenttrack' ),
                'description'     => __( 'Public read-only share links for team blueprints. Blueprint editing stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'team-blueprint-share' ],
                'entities'        => [],
            ],
            // #1644 — gates the six onboarding-pipeline workflow templates
            // (log prospect → invite → test training → trial review → team
            // offer). When off, no new pipeline tasks dispatch; the pipeline
            // view and any existing tasks stay visible. Other workflow
            // templates are unaffected — turn this on and disable the rest
            // via template config to run "workflow only for onboarding".
            'onboarding_pipeline_workflow' => [
                'label'           => __( 'Onboarding pipeline workflow', 'talenttrack' ),
                'description'     => __( 'Automatic tasks that move prospects through the recruitment funnel (log, invite, test training, trial review, team offer). The onboarding pipeline view stays available when this is off; only new task automation stops.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Workflow\\WorkflowModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // #1538 — per-academy sub-feature toggles. Each gates one
            // optional, heavy or cost/privacy-sensitive behaviour without
            // disabling the whole module. All default ON to preserve
            // current behaviour on upgrade; academies opt out. Gating
            // sites are inline `FeatureRegistry::isEnabled()` checks at
            // the owning module/repository (no unique view-slug/entity)
            // except planning_calendar_view, which auto-gates on its
            // `team-planner` slug.
            'comms_sms_channel' => [
                'label'           => __( 'SMS channel', 'talenttrack' ),
                'description'     => __( 'Offer SMS as a messaging channel. SMS delivery still needs a provider plugin. The other channels (email, push, WhatsApp link, in-app) stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Comms\\CommsModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            'comms_scheduled_sends' => [
                'label'           => __( 'Scheduled messaging', 'talenttrack' ),
                'description'     => __( 'Daily automated reminders (goal nudges, attendance flags, onboarding nudges, staff-development reminders). Event-driven messaging stays available when this is off; only the scheduled cron stops.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Comms\\CommsModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            'planning_calendar_view' => [
                'label'           => __( 'Team planner calendar', 'talenttrack' ),
                'description'     => __( 'The week-by-week team planner board. Activity creation and editing stay available when this is off; only the planner tile hides.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Planning\\PlanningModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'team-planner' ],
                'entities'        => [],
            ],
            'journey_medical_visibility' => [
                'label'           => __( 'Medical events on timeline', 'talenttrack' ),
                'description'     => __( 'Show injury and medical events on the player timeline to staff who already hold the medical-view permission. When off, medical events are hidden from the timeline even for authorised staff. The permission itself is unchanged.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Journey\\JourneyModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            'pdp_calendar_integration' => [
                'label'           => __( 'PDP calendar integration', 'talenttrack' ),
                'description'     => __( 'Write scheduled PDP conversations to the calendar feed when a development plan is created or carried over. The PDP plans, conversations and verdicts stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Pdp\\PdpModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            'persona_dashboard_editor' => [
                'label'           => __( 'Dashboard layout editor', 'talenttrack' ),
                'description'     => __( 'The drag-and-drop builder for persona dashboard layouts. The rendered dashboards keep working from their saved layouts when this is off; only the editor is hidden.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\PersonaDashboard\\PersonaDashboardModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // Keyed `export_match_prep_pdf` so ExportService::run()'s
            // `export_<exporterKey>` gate matches the exporter key
            // `match_prep_pdf`. The print router is guarded in tandem so
            // the toggle isn't bypassed by the client-side print path.
            'export_match_prep_pdf' => [
                'label'           => __( 'Match prep PDF export', 'talenttrack' ),
                'description'     => __( 'Allow the A4 match-preparation sheet to be exported/printed as a PDF. The match-prep screen stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchPrep\\MatchPrepModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
        ];

        // #1762 — one feature per bulk export tile, so an academy admin can
        // switch individual export *contents* off (e.g. Audit log, Full
        // club-data backup, Federation registration) without touching file
        // formats. Default enabled — no behaviour change until toggled. The
        // gate is consulted at two layers: tile visibility in
        // FrontendExportsView::render() and execution in ExportService::run()
        // (so a disabled tile can't be run via a direct link). Toggles
        // auto-surface on the Modules management page under the Export
        // module. Labels reuse the export tiles' own strings (already
        // translated). Keys are `export_<tile-key>`, matching the exporter
        // key the request carries.
        $export_tiles = [
            'players_list'        => __( 'Players list', 'talenttrack' ),
            'team_roster_stats'   => __( 'Team roster + season stats', 'talenttrack' ),
            'federation_json'     => __( 'Federation registration (JSON)', 'talenttrack' ),
            'attendance_register' => __( 'Attendance register', 'talenttrack' ),
            'team_activities'     => __( 'Team activity history', 'talenttrack' ),
            'team_ical'           => __( 'Team activity calendar (iCal)', 'talenttrack' ),
            'evaluations_xlsx'    => __( 'Evaluations export', 'talenttrack' ),
            'player_evaluations'  => __( 'Player evaluations (flat)', 'talenttrack' ),
            'goals_list'          => __( 'Goals list', 'talenttrack' ),
            'kpi_snapshot'        => __( 'KPI snapshot', 'talenttrack' ),
            'staff_directory'     => __( 'Coach / staff directory', 'talenttrack' ),
            'audit_log'           => __( 'Audit log', 'talenttrack' ),
            'backup_zip'          => __( 'Full club-data backup', 'talenttrack' ),
            'demo_data_xlsx'      => __( 'Demo-data round-trip', 'talenttrack' ),
        ];
        $export_toggle_desc = __( 'Show this export tile and allow it to run. When off, the tile is hidden from the Exports page and its export is rejected even via a direct link.', 'talenttrack' );
        foreach ( $export_tiles as $tile_key => $tile_label ) {
            $catalog[ 'export_' . $tile_key ] = [
                /* translators: %s = export tile name, e.g. "Players list". */
                'label'           => sprintf( __( 'Export: %s', 'talenttrack' ), $tile_label ),
                'description'     => $export_toggle_desc,
                'module_class'    => 'TT\\Modules\\Export\\ExportModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ];
        }

        return $catalog;
    }

    /** @var array<string, bool>|null per-request state cache */
    private static $stateCache = null;

    /** Whether the key names a catalogued feature. */
    public static function exists( string $key ): bool {
        return array_key_exists( $key, self::catalog() );
    }

    /**
     * Is the feature on? Unknown keys are treated as enabled so callers
     * can guard a surface unconditionally without first checking the
     * catalog. Catalogued features fall back to `default_enabled` when
     * no state row exists.
     */
    public static function isEnabled( string $key ): bool {
        $catalog = self::catalog();
        if ( ! isset( $catalog[ $key ] ) ) return true;

        // A feature whose parent module is off is implicitly off — there
        // is no surface to gate, and the management UI hides it.
        if ( ! ModuleRegistry::isEnabled( $catalog[ $key ]['module_class'] ) ) return false;

        $state = self::loadStateCache();
        if ( array_key_exists( $key, $state ) ) return $state[ $key ];
        return (bool) $catalog[ $key ]['default_enabled'];
    }

    /**
     * Persist a new enabled state. Drops the cache so the next read
     * (and the next request) sees the change.
     */
    public static function setEnabled( string $key, bool $enabled, ?int $actor_user_id = null ): void {
        if ( ! self::exists( $key ) ) return;
        global $wpdb;
        $table = $wpdb->prefix . 'tt_feature_state';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE feature_key = %s AND club_id = 1",
            $key
        ) );
        $row = [
            'enabled'    => $enabled ? 1 : 0,
            'updated_at' => current_time( 'mysql' ),
            'updated_by' => $actor_user_id !== null ? $actor_user_id : get_current_user_id(),
        ];
        if ( $existing > 0 ) {
            $wpdb->update( $table, $row, [ 'feature_key' => $key, 'club_id' => 1 ] );
        } else {
            $row['feature_key'] = $key;
            $row['club_id']     = 1;
            $wpdb->insert( $table, $row );
        }
        self::$stateCache = null;
    }

    /**
     * Every catalogued feature with its resolved state, restricted to
     * features whose parent module is enabled (a feature under a
     * disabled module is not a meaningful toggle). Used by the modules
     * management UI and the REST list.
     *
     * @return list<array{
     *   key: string, label: string, description: string,
     *   module_class: string, enabled: bool, default_enabled: bool
     * }>
     */
    public static function allWithState(): array {
        $out = [];
        foreach ( self::catalog() as $key => $meta ) {
            if ( ! ModuleRegistry::isEnabled( $meta['module_class'] ) ) continue;
            $out[] = [
                'key'             => $key,
                'label'           => (string) $meta['label'],
                'description'     => (string) $meta['description'],
                'module_class'    => (string) $meta['module_class'],
                'enabled'         => self::isEnabled( $key ),
                'default_enabled' => (bool) $meta['default_enabled'],
            ];
        }
        return $out;
    }

    /**
     * Features owned by the given module (enabled or not). Used by the
     * modules UI to nest feature toggles directly beneath their parent.
     *
     * @return list<array{key:string, label:string, description:string, enabled:bool}>
     */
    public static function forModule( string $module_class ): array {
        $module_class = ltrim( $module_class, '\\' );
        $out = [];
        foreach ( self::catalog() as $key => $meta ) {
            if ( ltrim( (string) $meta['module_class'], '\\' ) !== $module_class ) continue;
            $out[] = [
                'key'         => $key,
                'label'       => (string) $meta['label'],
                'description' => (string) $meta['description'],
                'enabled'     => self::isEnabled( $key ),
            ];
        }
        return $out;
    }

    /**
     * Is this matrix entity owned by a feature that is currently off?
     * Consulted by MatrixGate so a disabled feature's entity denies its
     * cap exactly like a disabled module's entity does.
     */
    public static function entityDisabled( string $entity ): bool {
        if ( $entity === '' ) return false;
        foreach ( self::catalog() as $key => $meta ) {
            if ( in_array( $entity, $meta['entities'], true ) && ! self::isEnabled( $key ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Is this `tt_view=` slug owned by a feature that is currently off?
     * Consulted by the dashboard dispatcher to refuse direct URLs to a
     * disabled feature's surface, mirroring
     * `TileRegistry::isViewSlugDisabled()` for modules.
     */
    public static function viewSlugDisabled( string $slug ): bool {
        if ( $slug === '' ) return false;
        foreach ( self::catalog() as $key => $meta ) {
            if ( in_array( $slug, $meta['view_slugs'], true ) && ! self::isEnabled( $key ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, bool> feature_key => enabled
     */
    private static function loadStateCache(): array {
        if ( self::$stateCache !== null ) return self::$stateCache;
        global $wpdb;
        $table = $wpdb->prefix . 'tt_feature_state';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            self::$stateCache = [];
            return self::$stateCache;
        }
        $rows = $wpdb->get_results( "SELECT feature_key, enabled FROM {$table} WHERE club_id = 1" );
        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $out[ (string) $r->feature_key ] = (bool) $r->enabled;
            }
        }
        self::$stateCache = $out;
        return $out;
    }
}
