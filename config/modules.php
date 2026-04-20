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
    TT\Modules\Sessions\SessionsModule::class             => true,
    TT\Modules\Goals\GoalsModule::class                   => true,
    TT\Modules\Reports\ReportsModule::class               => true,
    TT\Modules\Documentation\DocumentationModule::class   => true,
];
