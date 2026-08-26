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
            // #1987 — player dashboard tiles modelled as per-academy features
            // so an academy admin can switch any of them off from the feature
            // toggle UI. Default on; turning one off hides the tile AND blocks
            // its ?tt_view route (viewSlugDisabled(), consulted by the
            // dashboard dispatcher). The always-on player profile/anchor is
            // deliberately not listed — it can't be disabled.
            // #2574 — behaviour rating as a switchable sub-feature of
            // evaluations. Not every academy scores behaviour; those that
            // don't were still shown a capture button on every player, a
            // bulk action on every team, and a wizard step they always
            // skipped. Default on, so nothing changes until an academy turns
            // it off. Turning it off stops new capture and hides the entry
            // points; existing behaviour rows are untouched and reappear if
            // it is switched back on.
            'behaviour_rating' => [
                'label'           => __( 'Behaviour rating', 'talenttrack' ),
                'description'     => __( 'Capturing behaviour scores for players: the evaluation wizard step, the player capture screen and the team bulk-capture screen. Turn off for academies that do not score behaviour. Existing records are kept.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayerStatusModule',
                'default_enabled' => true,
                // Only the behaviour-only route. `player-status-capture`
                // deliberately is NOT listed: that view also captures
                // potential bands, which are a separate act behind
                // `tt_set_player_potential`. Gating the slug would take
                // potential down with behaviour, so the view gates its
                // behaviour half internally instead.
                'view_slugs'      => [ 'team-behaviour-capture' ],
                // No matrix entity: behaviour capture rides on the shared
                // evaluations vocabulary, and the catalog contract is
                // explicit that `entities` must never name an entity a
                // sibling surface also uses. The REST callbacks gate
                // explicitly instead.
                'entities'        => [],
            ],
            'player_journey' => [
                'label'           => __( 'Player tile: My journey', 'talenttrack' ),
                'description'     => __( 'The player\'s journey timeline tile and view. Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-journey' ],
                'entities'        => [],
            ],
            'player_team' => [
                'label'           => __( 'Player tile: My team', 'talenttrack' ),
                'description'     => __( 'The player\'s team tile and view (teammates and podium). Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-team' ],
                'entities'        => [],
            ],
            'player_evaluations' => [
                'label'           => __( 'Player tile: My evaluations', 'talenttrack' ),
                'description'     => __( 'The player\'s evaluations tile and view (ratings and feedback). Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-evaluations' ],
                'entities'        => [],
            ],
            'player_activities' => [
                'label'           => __( 'Player tile: My activities', 'talenttrack' ),
                'description'     => __( 'The player\'s activities tile and view (trainings and games attended). Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-activities' ],
                'entities'        => [],
            ],
            'player_goals' => [
                'label'           => __( 'Player tile: My goals', 'talenttrack' ),
                'description'     => __( 'The player\'s goals tile and view. Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-goals' ],
                'entities'        => [],
            ],
            'player_pdp' => [
                'label'           => __( 'Player tile: My PDP', 'talenttrack' ),
                'description'     => __( 'The player\'s PDP tile and view (talks, reflections, season verdict). Turn off to hide it from players in this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Players\\PlayersModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'my-pdp' ],
                'entities'        => [],
            ],
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
                'view_slugs'      => [ 'team-chemistry', 'chemistry-config' ],
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
            // #2128 — per-tile toggles for the two HoD analytics surfaces.
            // Both share the `analytics` entity + `tt_view_analytics` cap with
            // the central Analytics tile, so gating MUST be by view-slug only
            // (never the shared entity — see the entities-uniqueness rule
            // above). Default OFF: an academy opts each one in. The central
            // Analytics surface and the engine are unaffected.
            'analytics_eval_coverage' => [
                'label'           => __( 'Evaluation coverage', 'talenttrack' ),
                'description'     => __( 'The Evaluation coverage tile and view (which players are unevaluated this window, and which coach owns the gap). The central Analytics surface and the analytics engine stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Analytics\\AnalyticsModule',
                'default_enabled' => false,
                'view_slugs'      => [ 'eval-coverage' ],
                'entities'        => [],
            ],
            'analytics_cohort_board' => [
                'label'           => __( 'Cohort decision board', 'talenttrack' ),
                'description'     => __( 'The Cohort decision board tile and view (end-of-season rating, trend, attendance and verdict per player). The central Analytics surface and the analytics engine stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Analytics\\AnalyticsModule',
                'default_enabled' => false,
                'view_slugs'      => [ 'cohort-board' ],
                'entities'        => [],
            ],
            // #2302 — per-tile toggles for the two Stats analytics surfaces
            // that were always-on until now (Player comparison, Podium).
            // Default ON, so existing installs are unchanged; an academy
            // admin can now switch either off, which hides the tile AND
            // blocks its ?tt_view route (viewSlugDisabled()). Gated by
            // view-slug only — each tile keeps its existing entity + cap.
            'analytics_player_compare' => [
                'label'           => __( 'Player comparison', 'talenttrack' ),
                'description'     => __( 'The Player comparison tile and view (compare up to 4 players side-by-side). Turn off to hide it from this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Stats\\StatsModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'compare' ],
                'entities'        => [],
            ],
            'analytics_podium' => [
                'label'           => __( 'Podium', 'talenttrack' ),
                'description'     => __( 'The Podium tile and view (team rankings and top performers). Turn off to hide it from this academy.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Stats\\StatsModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'podium' ],
                'entities'        => [],
            ],
            // #2590 (epic #2589) — the media library's surfaces. Registered
            // with the foundation slice rather than with the first view, so
            // the module is switchable before anything depends on it: each
            // later slice adds its own slug here in the same PR that adds
            // the surface, instead of six merged PRs later needing a
            // retrofit.
            //
            // Two levels of off-switch, deliberately. Disabling the MODULE
            // (config/modules.php) stops media existing at all — an academy
            // that does not want photographs of minors in the system. This
            // FEATURE toggle is the softer one: keep the module and its
            // stored files, hide the surfaces.
            //
            // Default ON: media is core to the player-centric picture, and an
            // academy that does not want it simply never uploads. Making an
            // operator find a toggle before the feature exists is a support
            // ticket, not a safeguard — the safeguard is the module switch.
            //
            // `view_slugs` carries the media surfaces that are routes of
            // their own. The tabs and sections on players, teams and
            // activities are not — they live on views the media feature
            // does not own, and are gated by the `media` entity instead.
            'media' => [
                'label'           => __( 'Media library', 'talenttrack' ),
                'description'     => __( 'Photos and video attached to players, teams and activities. Turn off to hide the media tabs and uploads across the academy; stored files are kept and reappear if it is switched back on.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Media\\MediaModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'media-retention' ],
                'entities'        => [ 'media' ],
            ],
            // #2382 (epic #2381) — the desktop attendance-entry grid, the
            // Excel-familiar alternative to the mark-attendance wizard.
            // Default ON: it's the power-entry path an academy uses instead
            // of (or alongside) the wizard. Off hides the grid affordance AND
            // blocks the ?tt_view=attendance-grid route (viewSlugDisabled()).
            // Gated by view-slug only — it reuses the activities entity + the
            // tt_edit_activities cap the write endpoint enforces.
            'attendance_grid' => [
                'label'           => __( 'Attendance grid', 'talenttrack' ),
                'description'     => __( 'The desktop attendance-entry grid (players × activities) — a spreadsheet alternative to the step-by-step attendance wizard. The wizard stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Activities\\ActivitiesModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'attendance-grid' ],
                'entities'        => [],
            ],
            // #2386 (epic #2381) — the sibling desktop minutes grid (players ×
            // match activities). Default ON; off hides the affordance + blocks
            // the ?tt_view=minutes-grid route. Gated by view-slug only; reuses
            // the activities entity + tt_edit_activities cap.
            'minutes_grid' => [
                'label'           => __( 'Minutes grid', 'talenttrack' ),
                'description'     => __( 'The desktop minutes-entry grid (players × matches) — a spreadsheet way to record and correct match minutes across a period. The per-match minutes editor stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Activities\\ActivitiesModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'minutes-grid' ],
                'entities'        => [],
            ],
            // #2414 (epic #2381) — the ratings grid. Unlike its two siblings
            // this one is per-activity: a rating is N category scores per
            // player, so the columns are categories, not activities. Default
            // ON; off hides the affordance + blocks ?tt_view=ratings-grid.
            'ratings_grid' => [
                'label'           => __( 'Ratings grid', 'talenttrack' ),
                'description'     => __( 'The desktop ratings-entry grid (players × evaluation categories, one activity) — a spreadsheet alternative to rating players one card at a time. The evaluation wizard and the flat evaluation form stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Activities\\ActivitiesModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'ratings-grid' ],
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
            // the owning module/repository (no unique view-slug/entity).
            // #2603 — default OFF. TalentTrack ships no SMS provider, so
            // with this on the channel advertises itself and then fails
            // every send with `no_sms_provider`. Turn it on only once a
            // provider plugin has registered the `tt_comms_sms_send`
            // filter. Existing installs that explicitly enabled it keep
            // their stored value; only fresh installs see the new default.
            'comms_sms_channel' => [
                'label'           => __( 'SMS channel', 'talenttrack' ),
                'description'     => __( 'Offer SMS as a messaging channel. TalentTrack does not send SMS by itself — this needs a provider plugin, and without one every SMS fails. The other channels (email, push, WhatsApp link, in-app) are unaffected.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Comms\\CommsModule',
                'default_enabled' => false,
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
            // #1538 — the week-by-week Team planner calendar. Default on (no
            // behaviour change on upgrade); an academy that works activity by
            // activity can switch it off. The Activities log — the backward-
            // looking surface — stays available when this is off.
            'planning_calendar_view' => [
                'label'           => __( 'Team planner', 'talenttrack' ),
                'description'     => __( 'The week-by-week team-planning calendar. The Activities log stays available when this is off.', 'talenttrack' ),
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
            // #2709 (epic #2704) — the staff share link for a match
            // analysis. Gated separately from the module because the
            // document and the link are different decisions: an academy
            // may well want the review surface without URLs that name
            // minors travelling outside the app. The dispatcher refuses
            // the slug when off, and the view re-checks so a direct call
            // cannot bypass it.
            'match_analysis_sharing' => [
                'label'           => __( 'Match analysis share links', 'talenttrack' ),
                'description'     => __( 'Signed staff-only links to a match analysis. Writing and printing the analysis stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchAnalysis\\MatchAnalysisModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'match-analysis-share' ],
                'entities'        => [],
            ],
            // #2892 — the same switch for match preparation, and separate
            // from the analysis one on purpose: an academy may be happy for
            // a plan to travel to an assistant before kick-off while
            // preferring the post-match judgement of individual players to
            // stay inside the app, or the reverse. One flag for both would
            // force a choice nobody asked for.
            'match_prep_sharing' => [
                'label'           => __( 'Match preparation share links', 'talenttrack' ),
                'description'     => __( 'Signed staff-only links to a match preparation. Writing and printing the plan stay available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchPrep\\MatchPrepModule',
                'default_enabled' => true,
                'view_slugs'      => [ 'match-prep-share' ],
                'entities'        => [],
            ],
            // #2709 — keyed `export_match_analysis_pdf` to match the
            // `export_<key>` gate convention. The print router checks it
            // in tandem so the toggle isn't bypassed by the print URL.
            'export_match_analysis_pdf' => [
                'label'           => __( 'Match analysis PDF export', 'talenttrack' ),
                'description'     => __( 'Allow a match analysis to be printed or saved as an A4 PDF. The analysis screen stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchAnalysis\\MatchAnalysisModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            'export_match_prep_pdf' => [
                'label'           => __( 'Match prep PDF export', 'talenttrack' ),
                'description'     => __( 'Allow the A4 match-preparation sheet to be exported/printed as a PDF. The match-prep screen stays available when this is off. The referee\'s team sheet is a separate setting.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchPrep\\MatchPrepModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // #2769 — the referee's team sheet, split off from the coach's
            // own export above. One flag used to gate both, so an academy
            // that files match forms digitally could only hide the referee
            // sheet by also losing the sheet the coach takes to the
            // touchline. Same split, and the same reasoning, as
            // `match_analysis_sharing` versus `export_match_analysis_pdf`.
            //
            // The key is load-bearing: `ExportService::run()` gates on
            // `export_<exporterKey>`, and MatchDayTeamSheetPdfExporter::key()
            // is `match_day_team_sheet` — so naming it this way makes the
            // server-side export on ?tt_view=exports honour the toggle with
            // no code there. It ran ungated before, because an unknown key
            // reads as enabled.
            'export_match_day_team_sheet' => [
                'label'           => __( 'Match day team sheet', 'talenttrack' ),
                'description'     => __( 'The referee/opposition team sheet (Starting XI, Bench, Squad + signature lines). Switch off if match forms are filed digitally. The coach\'s own match-prep PDF is a separate setting.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\MatchPrep\\MatchPrepModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // #1979 — the greedy fair-share auto-planner. Gated at two
            // layers: the Auto-balance button in FrontendTournamentsManageView
            // and the `auto-plan` REST route's permission_callback (so a
            // direct POST can't bypass the toggle). Manual click-to-swap
            // planning stays available when this is off.
            'tournaments_auto_balance' => [
                'label'           => __( 'Tournament auto-balance', 'talenttrack' ),
                'description'     => __( 'The greedy fair-share auto-planner that fills a match grid by eligibility, equal-share minutes and starts distribution. Manual click-to-swap planning stays available when this is off.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Tournaments\\TournamentsModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ],
            // #2642 (epic #2641) — the shipped course curriculum, as
            // distinct from the library module itself. A course declares
            // `feature: knowledge_courses` in its manifest, so switching
            // this off takes the courses down while leaving the module
            // free to carry other material later. No `view_slugs` yet:
            // the reader lands in #2646 and registers its own routes
            // against this key then.
            'knowledge_courses' => [
                'label'           => __( 'Courses', 'talenttrack' ),
                'description'     => __( 'The coach-development courses shipped with the plugin: the library, the reader and each person\'s learning record. Turn off for an academy that runs its coach education elsewhere. Progress already recorded is kept.', 'talenttrack' ),
                'module_class'    => 'TT\\Modules\\Knowledge\\KnowledgeModule',
                'default_enabled' => true,
                // #2646 — the four reader surfaces, plus the review queue
                // from #2648. Switching the feature off takes the routes
                // down as well as the tiles, so a bookmarked lesson URL
                // stops resolving rather than rendering a surface the
                // academy turned off.
                'view_slugs'      => [ 'courses', 'course', 'lesson', 'my-learning', 'submission-review' ],
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

        // #1995 — per-report toggles, mirroring the export tiles above. Keys
        // are `report_<key>` (the 8 frontend standard-report slugs with `-`
        // → `_`, plus the 2 wp-admin reports). #2126 — the 5 launcher tiles
        // added after #1995 (3 attendance reports, minutes-per-team, rate
        // cards) join the catalog too, so all 15 reports get a toggle.
        // Default on, so a fresh upgrade shows every report; the report
        // views/dispatch guard on these keys.
        $report_tiles = [
            'player_minutes_played'         => __( 'Player · Minutes played', 'talenttrack' ),
            'team_minutes_distribution'     => __( 'Team · Minutes distribution', 'talenttrack' ),
            // #2835 — share of the available minutes, against the academy target.
            'minutes_share'                 => __( 'Team · Minutes share', 'talenttrack' ),
            'team_squad_evaluation_summary' => __( 'Team · Squad evaluation summary', 'talenttrack' ),
            'season_summary'                => __( 'Season · Summary', 'talenttrack' ),
            'season_trial_funnel'           => __( 'Season · Trial funnel', 'talenttrack' ),
            'scout_report_card'             => __( 'Scout · Report card', 'talenttrack' ),
            'coach_evaluation_quality'      => __( 'Coach · Evaluation quality', 'talenttrack' ),
            'player_progress_radar'         => __( 'Player · Progress & radar', 'talenttrack' ),
            'team_ratings'                  => __( 'Team rating averages', 'talenttrack' ),
            'coach_activity'                => __( 'Coach activity', 'talenttrack' ),
            'attendance_report_team'        => __( 'Team · Attendance statistics', 'talenttrack' ),
            'attendance_report_player'      => __( 'Player · Attendance statistics', 'talenttrack' ),
            'attendance_leaderboard'        => __( 'Attendance leaderboard', 'talenttrack' ),
            'minutes_report_team'           => __( 'Minutes played per team', 'talenttrack' ),
            'minutes_audit'                 => __( 'Minutes audit', 'talenttrack' ),
            'rate_cards'                    => __( 'Rate cards', 'talenttrack' ),
            // #2537 — one test, every player, over the season.
            'test_trends'                   => __( 'Test trends', 'talenttrack' ),
            // #2650 — knowledge-library completion, three lenses.
            'learning_courses'              => __( 'Learning · Course completion', 'talenttrack' ),
            'learning_people'               => __( 'Learning · Per person', 'talenttrack' ),
            'learning_teams'                => __( 'Learning · Staff coverage per team', 'talenttrack' ),
        ];
        $report_toggle_desc = __( 'Show this report and allow it to open. When off, the report tile is hidden and the report is rejected even via a direct link.', 'talenttrack' );
        foreach ( $report_tiles as $tile_key => $tile_label ) {
            $catalog[ 'report_' . $tile_key ] = [
                /* translators: %s = report name, e.g. "Player · Minutes played". */
                'label'           => sprintf( __( 'Report: %s', 'talenttrack' ), $tile_label ),
                'description'     => $report_toggle_desc,
                'module_class'    => 'TT\\Modules\\Reports\\ReportsModule',
                'default_enabled' => true,
                'view_slugs'      => [],
                'entities'        => [],
            ];
        }

        return $catalog;
    }

    /** @var array<string, bool>|null per-request enabled-state cache */
    private static $stateCache = null;

    /** @var array<string, bool>|null per-request under-development cache (#2387) */
    private static $devStateCache = null;

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
        self::upsertState( $key, [ 'enabled' => $enabled ? 1 : 0 ], $actor_user_id );
    }

    /**
     * #2387 — is the feature marked "under development"? A cosmetic flag,
     * separate from enabled: an under-development feature is still fully
     * live; the flag only drives an informational pill on the feature's
     * views. Unknown keys are never under development. Falls back to false
     * (not-under-development) when no state row exists.
     */
    public static function isUnderDevelopment( string $key ): bool {
        if ( ! isset( self::catalog()[ $key ] ) ) return false;
        $dev = self::loadDevStateCache();
        return $dev[ $key ] ?? false;
    }

    /**
     * #2387 — persist the under-development flag. Independent of enabled;
     * flipping it never changes whether the feature is on.
     */
    public static function setUnderDevelopment( string $key, bool $flag, ?int $actor_user_id = null ): void {
        if ( ! self::exists( $key ) ) return;
        self::upsertState( $key, [ 'under_development' => $flag ? 1 : 0 ], $actor_user_id );
    }

    /**
     * Upsert a subset of state columns for a feature, preserving the
     * columns not being written. On INSERT the untouched column is seeded
     * from the feature's currently-resolved state (never the raw column
     * default), so toggling one flag can't silently flip the other — e.g.
     * marking a default-off feature "under development" must not enable it.
     *
     * @param array<string,int> $fields subset of { enabled, under_development }
     */
    private static function upsertState( string $key, array $fields, ?int $actor_user_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_feature_state';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $fields['updated_at'] = current_time( 'mysql' );
        $fields['updated_by'] = $actor_user_id !== null ? $actor_user_id : get_current_user_id();

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE feature_key = %s AND club_id = 1",
            $key
        ) );
        if ( $existing > 0 ) {
            $wpdb->update( $table, $fields, [ 'feature_key' => $key, 'club_id' => 1 ] );
        } else {
            if ( ! array_key_exists( 'enabled', $fields ) ) {
                $fields['enabled'] = self::isEnabled( $key ) ? 1 : 0;
            }
            if ( ! array_key_exists( 'under_development', $fields ) ) {
                $fields['under_development'] = self::isUnderDevelopment( $key ) ? 1 : 0;
            }
            $fields['feature_key'] = $key;
            $fields['club_id']     = 1;
            $wpdb->insert( $table, $fields );
        }
        self::$stateCache   = null;
        self::$devStateCache = null;
    }

    /**
     * Every catalogued feature with its resolved state, restricted to
     * features whose parent module is enabled (a feature under a
     * disabled module is not a meaningful toggle). Used by the modules
     * management UI and the REST list.
     *
     * @return list<array{
     *   key: string, label: string, description: string,
     *   module_class: string, enabled: bool, default_enabled: bool,
     *   under_development: bool, view_slugs: list<string>
     * }>
     */
    public static function allWithState(): array {
        $out = [];
        foreach ( self::catalog() as $key => $meta ) {
            if ( ! ModuleRegistry::isEnabled( $meta['module_class'] ) ) continue;
            $out[] = [
                'key'               => $key,
                'label'             => (string) $meta['label'],
                'description'       => (string) $meta['description'],
                'module_class'      => (string) $meta['module_class'],
                'enabled'           => self::isEnabled( $key ),
                'default_enabled'   => (bool) $meta['default_enabled'],
                'under_development' => self::isUnderDevelopment( $key ),
                // #2540 — the surfaces this feature gates. Consumers of
                // the configuration export need to know what turning a
                // feature off actually removes, not just that it is off.
                'view_slugs'        => array_values( $meta['view_slugs'] ),
            ];
        }
        return $out;
    }

    /**
     * Features owned by the given module (enabled or not). Used by the
     * modules UI to nest feature toggles directly beneath their parent.
     *
     * @return list<array{key:string, label:string, description:string, enabled:bool, under_development:bool}>
     */
    public static function forModule( string $module_class ): array {
        $module_class = ltrim( $module_class, '\\' );
        $out = [];
        foreach ( self::catalog() as $key => $meta ) {
            if ( ltrim( (string) $meta['module_class'], '\\' ) !== $module_class ) continue;
            $out[] = [
                'key'               => $key,
                'label'             => (string) $meta['label'],
                'description'       => (string) $meta['description'],
                'enabled'           => self::isEnabled( $key ),
                'under_development' => self::isUnderDevelopment( $key ),
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
            self::$stateCache   = [];
            self::$devStateCache = [];
            return self::$stateCache;
        }
        // #2387 — read enabled + under_development in one pass. The
        // under_development column arrives with migration 0207; during the
        // narrow upgrade window before it runs the wider SELECT errors, so
        // fall back to the enabled-only shape and treat dev as all-false.
        $rows = $wpdb->get_results( "SELECT feature_key, enabled, under_development FROM {$table} WHERE club_id = 1" );
        if ( $rows === null ) {
            $rows = $wpdb->get_results( "SELECT feature_key, enabled FROM {$table} WHERE club_id = 1" );
        }
        $enabled = [];
        $dev     = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $enabled[ (string) $r->feature_key ] = (bool) $r->enabled;
                $dev[ (string) $r->feature_key ]     = (bool) ( $r->under_development ?? 0 );
            }
        }
        self::$stateCache   = $enabled;
        self::$devStateCache = $dev;
        return $enabled;
    }

    /**
     * @return array<string, bool> feature_key => under_development
     */
    private static function loadDevStateCache(): array {
        if ( self::$devStateCache === null ) self::loadStateCache();
        return self::$devStateCache ?? [];
    }

    /**
     * #2387 — is the `tt_view=` slug owned by a feature currently marked
     * under development? Consulted by the dashboard dispatcher to render
     * the informational pill above the view. A slug owned by no feature,
     * or by one not flagged, returns false.
     */
    public static function underDevelopmentForViewSlug( string $slug ): bool {
        if ( $slug === '' ) return false;
        foreach ( self::catalog() as $key => $meta ) {
            if ( in_array( $slug, $meta['view_slugs'], true ) && self::isUnderDevelopment( $key ) ) {
                return true;
            }
        }
        return false;
    }
}
