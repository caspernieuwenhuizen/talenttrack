<?php
namespace TT\Modules\Comms\Dispatch;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\CommsAuditLogger;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * CommsDispatcher (#0066, v3.110.18) — event-driven action hook.
 *
 * Owning modules fire:
 *   do_action( 'tt_comms_dispatch', $template_key, $payload, $recipients, $options );
 *
 * and the dispatcher builds a `CommsRequest` + calls `CommsService::send()`.
 * Saves every owning module from importing the full Comms domain
 * when all they want is "send template X with payload Y to recipient
 * list Z."
 *
 * Argument shape:
 *   - `$template_key` (string) — must match a registered template's
 *     `key()`, e.g. 'training_cancelled'.
 *   - `$payload` (array<string, scalar|null>) — token bag, used for
 *     `{token}` substitution in the template's copy.
 *   - `$recipients` (array<Recipient>) — already-resolved recipients
 *     (the owning module is responsible for invoking
 *     `RecipientResolver::forPlayer()` or building a `Recipient[]`
 *     directly for non-player audiences like coaches / HoD).
 *   - `$options` (array<string, mixed>) — optional overrides:
 *     - `message_type` (string, defaults to template_key) — used as
 *       the `tt_comms_log.message_type` discriminator.
 *     - `sender_user_id` (int, default current user)
 *     - `force_channel` (string|null)
 *     - `urgent` (bool)
 *     - `attached_export_id` (int|null)
 *     - `locale_override` (string|null)
 *
 * Returns are non-blocking — failures audit-log without throwing so
 * the caller's UX flow (e.g. activity-cancelled save) never depends
 * on the comms layer succeeding.
 *
 * ## Two entry points, deliberately (#2602)
 *
 * `do_action()` has no return value, so a caller on the hook cannot
 * learn what happened — by construction, not by oversight. That is
 * correct for system sends and wrong for anything a human triggered.
 *
 *   - **System / event-driven** — fire the action hook. Fire and
 *     forget; the obligation is the audit trail.
 *   - **User-triggered** — call `dispatchSync()`, which takes the same
 *     four arguments and returns `CommsResult[]` so the surface can
 *     report per-recipient outcomes. Pair it with
 *     `CommsService::preflight()` to warn before the click.
 *
 * Either way nothing is silent: every guard clause below writes an
 * audit row before returning.
 */
final class CommsDispatcher {

    public const ACTION_HOOK = 'tt_comms_dispatch';

    public static function init(): void {
        add_action( self::ACTION_HOOK, [ __CLASS__, 'handle' ], 10, 4 );
    }

    /**
     * Action-hook entry point for system / event-driven sends.
     *
     * Discards the results — `do_action()` has nowhere to return them.
     * Use `dispatchSync()` when a human is waiting on the answer.
     *
     * @param string $template_key
     * @param array<string, scalar|null> $payload
     * @param Recipient[] $recipients
     * @param array<string, mixed> $options
     */
    public static function handle( string $template_key, array $payload = [], array $recipients = [], array $options = [] ): void {
        self::dispatchSync( $template_key, $payload, $recipients, $options );
    }

    /**
     * Synchronous entry point for user-triggered sends.
     *
     * Same four arguments as the action hook, but returns one
     * `CommsResult` per recipient so the calling surface can report what
     * actually happened. Never throws — an exception becomes an
     * `exception`-status result, audit-logged, so the caller's flow
     * survives but the failure stays visible.
     *
     * @param string $template_key
     * @param array<string, scalar|null> $payload
     * @param Recipient[] $recipients
     * @param array<string, mixed> $options
     * @return CommsResult[]
     */
    public static function dispatchSync( string $template_key, array $payload = [], array $recipients = [], array $options = [] ): array {
        // An empty template key can't build a meaningful audit row —
        // there's no template to attribute it to — so it's logged and
        // refused. This is caller error, not a delivery outcome.
        if ( $template_key === '' ) {
            Logger::error( 'Comms dispatch called with an empty template key', [
                'recipient_count' => count( $recipients ),
                'message_type'    => (string) ( $options['message_type'] ?? '' ),
            ] );
            return [];
        }

        $request = self::buildRequest( $template_key, $payload, $recipients, $options );

        try {
            return ( new CommsService() )->send( $request );
        } catch ( \Throwable $e ) {
            // Comms is best-effort — an exception here mustn't break the
            // caller's flow. But a Throwable escaping CommsService means
            // it never reached the audit path, so this is the one case
            // that would otherwise vanish. Log it and record a row per
            // intended recipient.
            Logger::error( 'Comms dispatch threw', [
                'template_key'    => $template_key,
                'recipient_count' => count( $recipients ),
                'exception'       => $e->getMessage(),
            ] );

            $logger  = new CommsAuditLogger();
            $results = [];
            foreach ( ( $recipients === [] ? [ Recipient::none() ] : $recipients ) as $recipient ) {
                $result = new CommsResult(
                    wp_generate_uuid4(),
                    CommsResult::STATUS_EXCEPTION,
                    '',
                    $recipient,
                    'dispatch_exception',
                    $e->getMessage()
                );
                try {
                    $logger->record( $request, $recipient, $result->uuid, '', '', $result );
                } catch ( \Throwable $inner ) {
                    Logger::error( 'Comms audit write failed while recording a dispatch exception', [
                        'template_key' => $template_key,
                        'exception'    => $inner->getMessage(),
                    ] );
                }
                $results[] = $result;
            }
            return $results;
        }
    }

    /**
     * @param array<string, scalar|null> $payload
     * @param Recipient[] $recipients
     * @param array<string, mixed> $options
     */
    private static function buildRequest( string $template_key, array $payload, array $recipients, array $options ): CommsRequest {
        return new CommsRequest(
            $template_key,
            (string) ( $options['message_type'] ?? $template_key ),
            (int) ( $options['club_id'] ?? CurrentClub::id() ),
            (int) ( $options['sender_user_id'] ?? get_current_user_id() ),
            $recipients,
            $payload,
            isset( $options['force_channel'] ) ? (string) $options['force_channel'] : null,
            (bool) ( $options['urgent'] ?? false ),
            isset( $options['attached_export_id'] ) ? (int) $options['attached_export_id'] : null,
            isset( $options['locale_override'] ) ? (string) $options['locale_override'] : null
        );
    }
}
