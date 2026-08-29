<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * TemplateChannels (#3112) — which ways a message may reach somebody.
 *
 * Whether a message goes at all and how it is allowed to travel are two
 * decisions, and the Messages settings screen used to show only the
 * first while printing the second as unexplained text next to it. This
 * is the second decision, given its own control.
 *
 * ## What a channel list actually means
 *
 * Worth stating, because the epic that filed this assumed otherwise:
 * TalentTrack sends a message to a person on exactly **one** channel.
 * `CommsService::resolveChannel()` walks the registered adapters in
 * registration order and takes the first that both the template
 * supports and can reach that person. A template listing
 * `email, sms, whatsapp_link, inapp` does not send four messages — it
 * describes a fallback order.
 *
 * So this control is "you may not reach people this way", not "also
 * send by SMS". An academy that has not bought SMS credit, or does not
 * want school-age players messaged on WhatsApp, blocks that channel for
 * the templates it cares about and the resolver moves on to the next
 * one it can reach them on.
 *
 * ## Stored as the BLOCKED set, for the same reason the switch is
 *
 * `TemplateSwitch` stores what is off so an empty value means "nothing
 * changed" and a template shipped later lands in a defined state. Same
 * discipline here: the map holds only the channels an academy has
 * ruled out, so a channel added to a template in a later release is
 * allowed on upgrade, and an academy that has never opened the screen
 * behaves exactly as it did before.
 *
 * ## Blocking every channel is not a way to switch a message off
 *
 * It would be, mechanically — the resolver would find nothing and the
 * send would record `no_channel_available`, which reads as a fault
 * rather than as a choice. So a block set that would cover every
 * channel a template supports is dropped, on write and on read, and
 * the screen says to use the message's own switch instead. One way to
 * express one decision.
 */
final class TemplateChannels {

    public const CONFIG_KEY = 'comms_template_channels_blocked';

    /**
     * The stored map, template key => blocked channel keys.
     *
     * @return array<string, string[]>
     */
    public static function blocked(): array {
        return self::decode( (string) QueryHelpers::get_config( self::CONFIG_KEY, '' ) );
    }

    /**
     * Channels this template may use for this academy, preserving the
     * template's own order (which is the resolver's fallback order).
     *
     * @param string[] $supported
     * @return string[]
     */
    public static function allowedFor( string $templateKey, array $supported ): array {
        $blocked = self::blocked()[ $templateKey ] ?? [];
        if ( $blocked === [] ) return $supported;

        $allowed = array_values( array_diff( $supported, $blocked ) );

        // Never let a stored value strand a message with nowhere to go.
        return $allowed === [] ? $supported : $allowed;
    }

    public static function isBlocked( string $templateKey, string $channelKey ): bool {
        return in_array( $channelKey, self::blocked()[ $templateKey ] ?? [], true );
    }

    /**
     * Replace the stored map.
     *
     * @param array<string, string[]> $map
     */
    public static function setBlocked( array $map ): void {
        QueryHelpers::set_config( self::CONFIG_KEY, self::normaliseStored( (string) wp_json_encode( $map ) ) );
    }

    /**
     * Normalise a stored / submitted value to canonical JSON.
     *
     * Drops unknown templates, channels the template does not support,
     * and any entry that would block every channel a template has —
     * a malformed or stale payload cannot silently mute a message, and
     * cannot leave one failing with `no_channel_available` either.
     */
    public static function normaliseStored( string $raw ): string {
        $clean = [];

        foreach ( self::decode( $raw ) as $templateKey => $channels ) {
            $template = TemplateRegistry::get( $templateKey );
            if ( $template === null ) continue;

            $supported = $template->supportedChannels();
            $blocked   = array_values( array_intersect( $channels, $supported ) );

            if ( $blocked === [] ) continue;
            if ( count( $blocked ) >= count( $supported ) ) continue;  // use the message's own switch

            $clean[ $templateKey ] = $blocked;
        }

        ksort( $clean );
        return (string) wp_json_encode( $clean );
    }

    /**
     * @return array<string, string[]>
     */
    private static function decode( string $raw ): array {
        $raw = trim( $raw );
        if ( $raw === '' ) return [];

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];

        $out = [];
        foreach ( $decoded as $templateKey => $channels ) {
            if ( ! is_string( $templateKey ) || ! is_array( $channels ) ) continue;
            $templateKey = sanitize_key( $templateKey );
            if ( $templateKey === '' ) continue;

            $keys = [];
            foreach ( $channels as $channel ) {
                if ( ! is_string( $channel ) ) continue;
                $channel = sanitize_key( $channel );
                if ( $channel !== '' ) $keys[] = $channel;
            }
            if ( $keys !== [] ) $out[ $templateKey ] = array_values( array_unique( $keys ) );
        }
        return $out;
    }
}
