<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #4874 — a scheduled report was not sent, because it refers to a field
 * that no longer exists (#4860). Sent to the schedule's owner, once per
 * breakage, from `ScheduledReportsRunner`; the reason also stays on the
 * schedule in the schedules list.
 *
 * Tokens: {schedule_name} {field_name} {schedule_url}
 */
final class ScheduledReportNotSentTemplate extends AbstractTemplate {

    public const KEY = 'scheduled_report_not_sent';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Scheduled report not sent', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'inapp', 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( "Your scheduled report wasn't sent", 'talenttrack' ),
                __( "Your scheduled report \"{schedule_name}\" was not sent, because it refers to a field that no longer exists: {field_name}. Nothing is sent until the schedule is fixed.\n\nOpen the schedule: {schedule_url}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
