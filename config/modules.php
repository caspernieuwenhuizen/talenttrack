<?php
/**
 * TalentTrack Module Configuration
 *
 * Keys are fully-qualified class names implementing TT\Core\ModuleInterface.
 * Set value to `true` to enable, `false` to disable.
 *
 * Order in this file = registration order.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

return [
    TT\Modules\Auth\AuthModule::class                     => true,
    TT\Modules\Configuration\ConfigurationModule::class   => true,
    TT\Modules\Teams\TeamsModule::class                   => true,
    TT\Modules\Players\PlayersModule::class               => true,
    TT\Modules\People\PeopleModule::class                 => true,
    TT\Modules\Authorization\AuthorizationModule::class   => true,
    TT\Modules\Evaluations\EvaluationsModule::class       => true,
    TT\Modules\Activities\ActivitiesModule::class         => true,
    TT\Modules\Goals\GoalsModule::class                   => true,
    TT\Modules\Reports\ReportsModule::class               => true,
    TT\Modules\Stats\StatsModule::class                   => true,
    TT\Modules\Documentation\DocumentationModule::class   => true,
    TT\Modules\DemoData\DemoDataModule::class             => true,
    TT\Modules\Onboarding\OnboardingModule::class         => true,
    TT\Modules\Backup\BackupModule::class                 => true,
    TT\Modules\License\LicenseModule::class               => true,
    TT\Modules\Methodology\MethodologyModule::class       => true,
    TT\Modules\Workflow\WorkflowModule::class             => true,
    TT\Modules\Development\DevelopmentModule::class       => true,
    TT\Modules\Translations\TranslationsModule::class     => true,
    TT\Modules\Invitations\InvitationsModule::class       => true,
    TT\Modules\Pdp\PdpModule::class                       => true,
    TT\Modules\TeamDevelopment\TeamDevelopmentModule::class => true,
    TT\Modules\Trials\TrialsModule::class                 => true,
    TT\Modules\StaffDevelopment\StaffDevelopmentModule::class => true,
    TT\Modules\Wizards\WizardsModule::class               => true,
    TT\Modules\Journey\JourneyModule::class               => true,
    TT\Modules\PersonaDashboard\PersonaDashboardModule::class => true,
    TT\Modules\Spond\SpondModule::class                   => true,
    TT\Modules\Players\PlayerStatusModule::class          => true,
    TT\Modules\Threads\ThreadsModule::class               => true,
    TT\Modules\Push\PushModule::class                     => true,
    TT\Modules\CustomCss\CustomCssModule::class           => true,
    TT\Modules\AdminCenterClient\AdminCenterClientModule::class => true,
    // #0081 — Onboarding pipeline. Child 1 (this version) ships the
    // entity + retention cron only; child 2 wires the workflow templates.
    TT\Modules\Prospects\ProspectsModule::class                  => true,
    // #0086 Workstream B Child 1 — TalentTrack-native MFA.
    // Sprint 1 (v3.98.2) ships migration + domain services + repository
    // + Account-page status tab. Sprint 2 ships the 4-step enrollment
    // wizard. Sprint 3 ships the `authenticate`-filter login integration.
    TT\Modules\Mfa\MfaModule::class                              => true,
    // #0006 — Team planning calendar.
    TT\Modules\Planning\PlanningModule::class                    => true,
    // #0083 Child 1 — analytics fact registry + query engine. Owns
    // `FactRegistry` + `FactQuery` + the 8 initial fact registrations.
    // Subsequent #0083 children build on top: KPI platform, dimension
    // explorer, entity-tab + central analytics surfaces, scheduled reports.
    TT\Modules\Analytics\AnalyticsModule::class                  => true,
    // #0063 — Export module (foundation: registry + CSV/JSON/iCal renderers + REST).
    TT\Modules\Export\ExportModule::class                        => true,
    // #0066 — Communication module (foundation: channel registry +
    // Email adapter + template registry + opt-out + quiet-hours +
    // rate-limit + audit). Use cases register their own templates.
    TT\Modules\Comms\CommsModule::class                          => true,
    // #0078 Phase 1 — custom widget builder data layer. Feature-flag-
    // gated via `tt_custom_widgets_enabled` (default off; beta installs
    // opt in). Owns CustomDataSource interface + registry + 5 reference
    // sources. Phases 2-6 build the schema, REST, builder UI, rendering,
    // cap layer, and docs on top.
    TT\Modules\CustomWidgets\CustomWidgetsModule::class          => true,
    // #0090 Phase 1 — data-row i18n foundation. Owns tt_translations
    // table + TranslatableFieldRegistry + TranslationsRepository +
    // tt_edit_translations cap + translations matrix entity. Phases
    // 2-4 register specific entities (lookups, eval categories,
    // roles, functional roles) from their owning modules.
    TT\Modules\I18n\I18nModule::class                            => true,
    // #0089 follow-up — seed review (Excel export + edit + re-import
    // for tt_lookups, tt_eval_categories, tt_roles, tt_functional_roles).
    // Live-DB updates only; shipped seed PHP files unchanged.
    TT\Modules\SeedReview\SeedReviewModule::class                => true,
    // #0086 Workstream B Child 3 — login-fail tracking. Hooks
    // `wp_login_failed` and writes one `tt_audit_log` row per attempt
    // so the audit-log surface's new "Failed logins" tab can aggregate.
    // No automatic lockout in v1 — visibility only.
    TT\Modules\Security\SecurityModule::class                    => true,
    // #0016 Sprint 1 — Exercises foundation. Owns tt_exercises +
    // categories + principles M2M + team-overrides schema, the
    // ExercisesRepository (versioning + visibility), and the
    // VisionProviderInterface + 3 stub adapters (Claude Sonnet,
    // Gemini Pro, OpenAI) that Sprints 3-4 wire up to the photo-
    // capture flow. Sprint 1 ships foundation only — admin CRUD UI,
    // session linkage, photo capture UI, and AI extraction logic
    // land in Sprints 2-6.
    TT\Modules\Exercises\ExercisesModule::class                  => true,
];
