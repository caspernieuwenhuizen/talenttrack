<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #4963 — a scheduled Report Studio report was paused instead of sent: the
 * report was archived or unpublished, its subject is gone, or its owner may
 * no longer change it. Sent to the schedule's owner once per breakage, from
 * `ScheduledReportsRunner`, under the same opt-out as #4874's message; the
 * reason also stays on the schedule in the schedules list.
 *
 * Tokens: {schedule_name} {reason} {schedule_url}
 */
final class ScheduledReportPausedTemplate extends AbstractTemplate {

    public const KEY = 'scheduled_report_paused';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Scheduled report paused', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'inapp', 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Your scheduled report is paused', 'talenttrack' ),
                __( "Your scheduled report \"{schedule_name}\" was not sent and is paused: {reason} Nothing is sent until someone who may change the report resumes it.\n\nOpen the schedule: {schedule_url}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
