<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 7 — Goal nudge.
 *
 * "You set a goal 4 weeks ago; tap to update progress" (#0028
 * conversational goals). Sender: system on schedule. Recipients:
 * player (or parent for under-12s per #0042 RecipientResolver).
 *
 * Trigger: cron `tt_comms_goal_nudge_cron` daily; finds goals with
 * `created_at <= now() - 28 days` and `last_nudge_at IS NULL OR
 * last_nudge_at <= now() - 28 days` per club.
 *
 * Tokens: {player_name} {goal_title} {weeks_since_creation} {deep_link}
 */
final class GoalNudgeTemplate extends AbstractTemplate {

    public function key(): string { return 'goal_nudge'; }
    public function label(): string { return __( 'Goal nudge', 'talenttrack' ); }
    public function supportedChannels(): array { return [ 'push', 'inapp', 'email' ]; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        return self::pickLocale( [
            'en_US' => [
                __( "How's your goal going, {recipient_first_name}?", 'talenttrack' ),
                __( "You set the goal \"{goal_title}\" {weeks_since_creation} weeks ago. Tap to update your progress: {deep_link}", 'talenttrack' ),
            ],
            'nl_NL' => [
                __( "Hoe gaat het met je doel, {recipient_first_name}?", 'talenttrack' ),
                __( "Je hebt het doel \"{goal_title}\" {weeks_since_creation} weken geleden gesteld. Tik om je voortgang bij te werken: {deep_link}", 'talenttrack' ),
            ],
        ], $locale );
    }
}
