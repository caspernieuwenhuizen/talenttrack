<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Template\AbstractTemplate;

/**
 * #0066 use case 1 — Training cancelled.
 *
 * Coach taps "cancel" on an activity, parents (youth) / players (adult)
 * get a push + SMS fallback within 60s. `urgent=true` bypasses
 * quiet-hours per the operational-emergency exception in MessageType.
 *
 * Trigger: `Send\TrainingCancelledSend` listens on `tt_activity_cancelled`,
 * which `ActivitiesRepository` fires from both of its cancellation write
 * paths (the detail view's Cancel button and an edit that sets the status
 * to cancelled, whether from REST or wp-admin). The hook carries the
 * activity id; the send resolves the planned roster and its families.
 *
 * Editable per club (top-5 per spec Q7 lean) — clubs personalise copy
 * to match their tone of voice.
 *
 * Tokens: {activity_title} {date} {team_name}
 */
final class TrainingCancelledTemplate extends AbstractTemplate {

    public const KEY = 'training_cancelled';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Training cancelled', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'push', 'sms', 'email', 'inapp', 'whatsapp_link' ]; }

    public function isEditable(): bool { return true; }

    protected function defaultCopy( string $channelKey, string $locale ): array {
        $variants = [ 'en_US' => $this->englishCopy( $channelKey ) ];
        if ( $channelKey !== 'email' ) {
            // A Dutch recipient's short message has its own wording, so its
            // body is a separate msgid by context. The email copy is shared.
            $variants['nl_NL'] = [
                __( 'Training cancelled', 'talenttrack' ),
                _x( '{activity_title} on {date} is cancelled. We will follow up about a replacement.', 'short message, Dutch-language recipient', 'talenttrack' ),
            ];
        }
        return self::pickLocale( $variants, $locale );
    }

    private function englishCopy( string $ch ): array {
        if ( $ch === 'email' ) {
            return [
                __( 'Training cancelled — {activity_title}', 'talenttrack' ),
                __( "Hi {recipient_first_name},\n\nThe activity \"{activity_title}\" on {date} for {team_name} has been cancelled.\n\nWe'll be in touch about a replacement activity.\n\nThanks,\nThe coaching team", 'talenttrack' ),
            ];
        }
        // push / sms / whatsapp / inapp — concise.
        return [
            __( 'Training cancelled', 'talenttrack' ),
            __( '{activity_title} on {date} is cancelled. We will follow up about a replacement.', 'talenttrack' ),
        ];
    }
}
