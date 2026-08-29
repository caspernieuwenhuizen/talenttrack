<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #2604 — the reminder to assigned staff that their trial input is still
 * outstanding.
 *
 * Fired from `TrialReminderScheduler` on the daily cron and from the
 * "Send reminders now" button on the trial case editor. Addressed to the
 * staff member, about a player.
 *
 * The copy names the end date rather than a countdown: the scheduler's
 * last reminder bucket fires *after* the trial has ended, and "ending in
 * -4 days" is not a sentence.
 *
 * Tokens: {player_name} {end_date} {case_url} {club_name}
 */
final class TrialInputReminderTemplate extends AbstractTemplate {

    public const KEY = 'trial_input_reminder';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Trial input reminder', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( 'Trial input needed: {player_name}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nThe trial period for {player_name} runs to {end_date}, and your input on the case is still missing.\n\nGo to the case here: {case_url}\n\nThanks,\n{club_name}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( 'Stage-input gevraagd: {player_name}', 'talenttrack' ),
                __( "Hoi {recipient_first_name},\n\nDe stageperiode van {player_name} loopt tot {end_date} en jouw input op het dossier ontbreekt nog.\n\nGa naar het dossier: {case_url}\n\nDank,\n{club_name}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
