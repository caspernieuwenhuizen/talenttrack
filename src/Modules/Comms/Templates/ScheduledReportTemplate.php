<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #2604 — the scheduled analytics export, delivered with the file
 * attached.
 *
 * Fired from `ScheduledReportsRunner` on the daily cron. Recipients are
 * whoever the operator named on the schedule, which may include plain
 * email addresses that belong to no account.
 *
 * The CSV itself travels on the request's `attachmentPaths`, not in the
 * copy.
 *
 * Tokens: {schedule_name} {kpi_label}
 */
final class ScheduledReportTemplate extends AbstractTemplate {

    public const KEY = 'scheduled_report';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Scheduled report delivery', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Scheduled report: {schedule_name} ({kpi_label})', 'talenttrack' ),
                __( "Your scheduled report \"{schedule_name}\" is attached.\n\nSee the dashboard for the live view.", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Gepland rapport: {schedule_name} ({kpi_label})', 'talenttrack' ),
                __( "Je geplande rapport \"{schedule_name}\" zit als bijlage bij deze e-mail.\n\nBekijk het dashboard voor de actuele cijfers.", 'talenttrack' ),
            ],
        ], $locale );
    }
}
