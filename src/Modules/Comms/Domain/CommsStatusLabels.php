<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CommsStatusLabels (#2606, Gate C) — the send outcome in words.
 *
 * `tt_comms_log.status` and `error_code` are machine keys chosen for a
 * database column, and a log that renders `quiet_hours` at an operator is
 * a log that needs explaining before it can be used. This turns the Gate E
 * status vocabulary into the sentence a reader actually needs — and does
 * it in the domain layer, so the rendered surface and any future front end
 * describe the same row the same way.
 *
 * The error code wins where it is more specific than the status:
 * "Failed" tells nobody anything, "No email address on file" tells them
 * what to fix.
 */
final class CommsStatusLabels {

    /**
     * Outcomes that mean the message reached the transport.
     *
     * @var list<string>
     */
    private const DELIVERED = [ 'sent', 'delivered' ];

    /**
     * Outcomes where the product chose not to send. Not failures — a
     * surface that paints an honoured opt-out red teaches operators to
     * ignore red.
     *
     * @var list<string>
     */
    private const WITHHELD = [ 'opted_out', 'quiet_hours', 'rate_limited', 'template_disabled' ];

    public static function label( string $status, string $error_code = '' ): string {
        if ( $error_code !== '' ) {
            $specific = self::forErrorCode( $error_code );
            if ( $specific !== '' ) return $specific;
        }

        // `_x()` throughout: these are one-word labels whose English is
        // ambiguous out of context, and a translator handed a bare "Sent"
        // has no way to know it describes a message rather than an action.
        switch ( $status ) {
            case 'queued':            return _x( 'Queued', 'message send outcome', 'talenttrack' );
            case 'sent':              return _x( 'Sent', 'message send outcome', 'talenttrack' );
            case 'delivered':         return _x( 'Delivered', 'message send outcome', 'talenttrack' );
            case 'bounced':           return _x( 'Bounced', 'message send outcome', 'talenttrack' );
            case 'failed':            return _x( 'Failed', 'message send outcome', 'talenttrack' );
            case 'opted_out':         return _x( 'Opted out', 'message send outcome', 'talenttrack' );
            case 'quiet_hours':       return _x( 'Held until morning', 'message send outcome', 'talenttrack' );
            case 'rate_limited':      return _x( 'Held — sending limit reached', 'message send outcome', 'talenttrack' );
            case 'no_recipients':     return _x( 'Nobody to send to', 'message send outcome', 'talenttrack' );
            case 'template_disabled': return _x( 'Template switched off', 'message send outcome', 'talenttrack' );
            case 'exception':         return _x( 'Error while sending', 'message send outcome', 'talenttrack' );
        }
        return $status !== '' ? $status : _x( 'Unknown', 'message send outcome', 'talenttrack' );
    }

    /**
     * The short explanation under the label, or '' when the label says it
     * all. This is where "what do I do about it" lives.
     */
    public static function hint( string $status, string $error_code = '' ): string {
        if ( $error_code === 'no_address' || $status === 'no_recipients' ) {
            return __( 'Nobody on the player record had an address this channel could reach.', 'talenttrack' );
        }
        switch ( $status ) {
            case 'opted_out':
                return __( 'The recipient asked not to receive this kind of message.', 'talenttrack' );
            case 'quiet_hours':
                return __( 'It fell inside the quiet-hours window and was not urgent.', 'talenttrack' );
            case 'template_disabled':
                return __( 'This kind of message is switched off for the academy.', 'talenttrack' );
            case 'rate_limited':
                return __( 'One sender passed the hourly limit.', 'talenttrack' );
            case 'bounced':
            case 'failed':
            case 'exception':
                return __( 'The message could not be delivered. Check the address on the record.', 'talenttrack' );
        }
        return '';
    }

    /**
     * A coarse tone for the surface to colour on: `ok`, `withheld` or
     * `problem`. Deliberately three and not two — an honoured opt-out and
     * a bounced address are both "not delivered" and want opposite
     * reactions from the reader.
     */
    public static function tone( string $status ): string {
        if ( in_array( $status, self::DELIVERED, true ) ) return 'ok';
        if ( in_array( $status, self::WITHHELD, true ) )  return 'withheld';
        if ( $status === 'queued' )                       return 'withheld';
        return 'problem';
    }

    private static function forErrorCode( string $error_code ): string {
        switch ( $error_code ) {
            case 'no_address':           return __( 'No email address on file', 'talenttrack' );
            case 'no_recipients':        return _x( 'Nobody to send to', 'message send outcome', 'talenttrack' );
            case 'no_channel_available': return __( 'No usable channel', 'talenttrack' );
            case 'adapter_missing':      return __( 'Channel not configured', 'talenttrack' );
            case 'no_user_id':           return __( 'Recipient has no account', 'talenttrack' );
            case 'inbox_table_missing':  return __( 'In-app inbox unavailable', 'talenttrack' );
            case 'dispatch_exception':   return _x( 'Error while sending', 'message send outcome', 'talenttrack' );
        }
        return '';
    }
}
