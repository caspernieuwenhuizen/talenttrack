<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * TeamScheduleDigestTemplate (#3811) — what moved in this team's week.
 *
 * The daily roll-up of every activity created, moved, re-located or
 * cancelled for one team, addressed to the staff who run it. One message
 * per edit would punish the role it is meant to help: a team manager who
 * gets six e-mails because a coach fixed six kick-off times in one sitting
 * stops reading the seventh.
 *
 * Also carries the single-activity case for a cancellation inside the
 * 48-hour window, because {@see ScheduleChangeFromSpondTemplate} says an
 * activity "has been rescheduled" and a cancelled one has not.
 *
 * The lines themselves are composed by the sender, which is the only thing
 * that knows how many there are and what changed. The template supplies the
 * frame.
 *
 * Message type: `schedule_change_from_spond` — the opt-out a recipient
 * already has for "an activity changes time or place" governs this too. A
 * second toggle would ask the same question twice and let a reader mute
 * half of one answer.
 *
 * Tokens: {team_name} {change_count} {change_list} {deep_link}
 */
final class TeamScheduleDigestTemplate extends AbstractTemplate {

    public const KEY = 'team_schedule_digest';

    public function key(): string { return self::KEY; }
    public function label(): string { return __( 'Team schedule digest', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'email', 'push', 'inapp' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Calendar changes — {team_name}', 'talenttrack' ),
                __( "{change_count} change(s) to the {team_name} calendar:\n\n{change_list}\n\nOpen the calendar: {deep_link}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Agendawijzigingen — {team_name}', 'talenttrack' ),
                __( "{change_count} wijziging(en) in de agenda van {team_name}:\n\n{change_list}\n\nOpen de agenda: {deep_link}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
