<?php
namespace TT\Modules\Comms\Dispatch;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
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
 */
final class CommsDispatcher {

    public const ACTION_HOOK = 'tt_comms_dispatch';

    public static function init(): void {
        add_action( self::ACTION_HOOK, [ __CLASS__, 'handle' ], 10, 4 );
    }

    /**
     * @param string $template_key
     * @param array<string, scalar|null> $payload
     * @param Recipient[] $recipients
     * @param array<string, mixed> $options
     */
    public static function handle( string $template_key, array $payload = [], array $recipients = [], array $options = [] ): void {
        if ( $template_key === '' ) return;
        if ( $recipients === [] ) return;

        $request = new CommsRequest(
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

        try {
            ( new CommsService() )->send( $request );
        } catch ( \Throwable $e ) {
            // Comms is best-effort; an exception here mustn't break
            // the caller's flow. The CommsService writes a failure
            // audit row internally for any per-recipient failure.
        }
    }
}
