<?php
namespace TT\Modules\Comms\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CommsOutcomeSummary (#2602) — turns `CommsResult[]` into copy a human
 * can act on.
 *
 * Every user-triggered send reports through here so the wording is the
 * same everywhere: "Sent to 9. 2 opted out. 1 has no email address."
 * A bare "message sent" toast is not an acceptable outcome for a send
 * where some recipients were skipped or failed — the sender needs to
 * know who did not get it.
 *
 * Also drives the pre-send warning: run `CommsService::preflight()`,
 * pass the results here, and `warnings()` gives the caller the lines to
 * show before the user commits.
 *
 * Statuses fall into three buckets:
 *   - success  — delivered or handed to the channel.
 *   - skipped  — policy declined it. Not an error; still must be said.
 *   - failure  — something was wrong. Always surfaced.
 */
final class CommsOutcomeSummary {

    /** @param CommsResult[] $results */
    public static function sentCount( array $results ): int {
        return count( array_filter( $results, fn ( CommsResult $r ) => $r->isSuccess() ) );
    }

    /** @param CommsResult[] $results */
    public static function hasProblems( array $results ): bool {
        foreach ( $results as $result ) {
            if ( ! $result->isSuccess() ) return true;
        }
        return false;
    }

    /**
     * One human sentence per send outcome, ready to render after the act.
     *
     * @param CommsResult[] $results
     * @return string[]
     */
    public static function lines( array $results ): array {
        if ( $results === [] ) {
            return [ __( 'Nothing was sent — no recipients were resolved.', 'talenttrack' ) ];
        }

        $byStatus = [];
        foreach ( $results as $result ) {
            $key = $result->errorCode !== null && $result->status === CommsResult::STATUS_FAILED
                ? CommsResult::STATUS_FAILED . ':' . $result->errorCode
                : $result->status;
            $byStatus[ $key ] = ( $byStatus[ $key ] ?? 0 ) + 1;
        }

        $lines = [];
        foreach ( $byStatus as $key => $count ) {
            $lines[] = self::describe( $key, $count );
        }
        return $lines;
    }

    /**
     * Pre-send warnings from a `CommsService::preflight()` run. Returns
     * only the lines worth interrupting the user for — recipients that
     * would be sent to produce no warning.
     *
     * @param CommsResult[] $preflight
     * @return string[]
     */
    public static function warnings( array $preflight ): array {
        $problems = array_filter(
            $preflight,
            fn ( CommsResult $r ) => $r->status !== CommsResult::STATUS_QUEUED
        );
        return $problems === [] ? [] : self::lines( array_values( $problems ) );
    }

    private static function describe( string $key, int $count ): string {
        switch ( $key ) {
            case CommsResult::STATUS_SENT:
            case CommsResult::STATUS_DELIVERED:
                /* translators: %d: number of recipients. */
                return sprintf( _n( 'Sent to %d recipient.', 'Sent to %d recipients.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_QUEUED:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d recipient will be sent to.', '%d recipients will be sent to.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_OPTED_OUT:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d recipient has opted out of this type of message.', '%d recipients have opted out of this type of message.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_QUIET_HOURS:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d message is held until quiet hours end.', '%d messages are held until quiet hours end.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_RATE_LIMITED:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d message was held back by the sending limit.', '%d messages were held back by the sending limit.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_TEMPLATE_DISABLED:
                return __( 'This message type is switched off for your academy, so nothing was sent.', 'talenttrack' );

            case CommsResult::STATUS_NO_RECIPIENTS:
                return __( 'Nothing was sent — no recipients were resolved.', 'talenttrack' );

            case CommsResult::STATUS_BOUNCED:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d message bounced.', '%d messages bounced.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_EXCEPTION:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d message failed with an unexpected error. It has been logged.', '%d messages failed with an unexpected error. They have been logged.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_FAILED . ':no_channel_available':
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d recipient has no usable contact details.', '%d recipients have no usable contact details.', $count, 'talenttrack' ), $count );

            case CommsResult::STATUS_FAILED . ':unknown_template':
                return __( 'This message could not be built — its template is not registered. Contact your administrator.', 'talenttrack' );

            case CommsResult::STATUS_FAILED . ':no_sms_provider':
                return __( 'SMS is switched on but no SMS provider is configured, so nothing was sent.', 'talenttrack' );

            default:
                /* translators: %d: number of recipients. */
                return sprintf( _n( '%d message could not be sent. The failure has been logged.', '%d messages could not be sent. The failures have been logged.', $count, 'talenttrack' ), $count );
        }
    }
}
