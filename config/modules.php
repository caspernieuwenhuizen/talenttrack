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
];
