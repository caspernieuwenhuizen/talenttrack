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
    TT\Modules\Wizards\WizardsModule::class               => true,
    TT\Modules\Journey\JourneyModule::class               => true,
    TT\Modules\PersonaDashboard\PersonaDashboardModule::class => true,
];
