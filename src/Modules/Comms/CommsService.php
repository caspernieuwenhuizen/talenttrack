<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\QuietHours\QuietHoursPolicy;
use TT\Modules\Comms\RateLimit\RateLimiter;
use TT\Modules\Comms\Template\TemplateRegistry;

/**
 * CommsService (#0066) — orchestrator for one Comms send.
 *
 * Per-recipient flow:
 *   1. Opt-out check (`OptOutPolicy::isOptedOut`). On opt-out → log
 *      `STATUS_OPTED_OUT` and skip.
 *   2. Quiet-hours check (`QuietHoursPolicy::shouldDefer`). On defer
 *      → log `STATUS_QUIET_HOURS` and skip. (Future: re-enqueue for
 *      morning send via Action Scheduler — lands when the async layer
 *      ships, see #0063 spec Q2.)
 *   3. Rate-limit check (`RateLimiter::wouldExceed`). On exceed →
 *      log `STATUS_RATE_LIMITED` and skip. Counter increments only on
 *      sends that proceed.
 *   4. Channel resolution: caller's `forceChannel` wins; otherwise
 *      pick the first registered adapter that `canReach()` the
 *      recipient. (Channel preference per-recipient lands when push
 *      ships and recipients have a stable preference column; today
 *      the order is registration order.)
 *   5. Template render via `TemplateRegistry::get($key)->render(...)`.
 *      Editable templates consult `tt_config` overrides; fixed
 *      templates ignore them.
 *   6. Adapter `send()`. Result returned per-recipient.
 *   7. Audit row written via `AuditLogger`.
 *
 * The whole flow short-circuits with the appropriate result status if
 * the template / channel adapter / recipient is unresolvable; nothing
 * here throws. Callers get one `CommsResult` per recipient; the
 * dispatcher itself returns the full list.
 */
final class CommsService {

    private OptOutPolicy $optOut;
    private QuietHoursPolicy $quietHours;
    private RateLimiter $rateLimiter;
    private CommsAuditLogger $auditLogger;

    public function __construct(
        ?OptOutPolicy $optOut = null,
        ?QuietHoursPolicy $quietHours = null,
        ?RateLimiter $rateLimiter = null,
        ?CommsAuditLogger $auditLogger = null
    ) {
        $this->optOut      = $optOut      ?? new OptOutPolicy();
        $this->quietHours  = $quietHours  ?? new QuietHoursPolicy();
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
        $this->auditLogger = $auditLogger ?? new CommsAuditLogger();
    }

    /**
     * @return CommsResult[]   one per recipient
     */
    public function send( CommsRequest $request ): array {
        $template = TemplateRegistry::get( $request->templateKey );
        if ( $template === null ) {
            // No template registered — return one failure result per
            // recipient so the caller can surface the misconfiguration.
            return array_map(
                fn ( Recipient $r ) => new CommsResult(
                    wp_generate_uuid4(),
                    CommsResult::STATUS_FAILED,
                    '',
                    $r,
                    'unknown_template'
                ),
                $request->recipients
            );
        }

        $results = [];
        foreach ( $request->recipients as $recipient ) {
            $results[] = $this->sendOne( $request, $recipient, $template );
        }
        return $results;
    }

    private function sendOne( CommsRequest $request, Recipient $recipient, $template ): CommsResult {
        $uuid = wp_generate_uuid4();

        // 1. Opt-out
        if ( $this->optOut->isOptedOut( $recipient->userId, $request->messageType ) ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_OPTED_OUT, '', $recipient );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        // 2. Quiet hours
        if ( $this->quietHours->shouldDefer( $request ) ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_QUIET_HOURS, '', $recipient );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        // 3. Rate limit
        if ( $this->rateLimiter->wouldExceed( $request->senderUserId, $request->messageType ) ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_RATE_LIMITED, '', $recipient );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        // 4. Channel resolution
        $channelKey = $this->resolveChannel( $request, $recipient, $template->supportedChannels() );
        if ( $channelKey === null ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', $recipient, 'no_channel_available' );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        $adapter = ChannelAdapterRegistry::get( $channelKey );
        if ( $adapter === null ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', $recipient, 'adapter_missing' );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        // 5. Template render
        $locale = $recipient->preferredLocale !== ''
            ? $recipient->preferredLocale
            : ( $request->localeOverride ?? get_locale() );
        [ $subject, $body ] = $template->render( $channelKey, $request, $recipient, $locale );

        // 6. Dispatch
        $result = $adapter->send( $request, $recipient, $uuid, $subject, $body );

        // 7. Audit + rate-limit accounting
        if ( $result->isSuccess() ) {
            $this->rateLimiter->record( $request->senderUserId );
        }
        $this->auditLogger->record( $request, $recipient, $uuid, $subject, $body, $result );
        return $result;
    }

    /**
     * Channel resolution. `forceChannel` wins when set and reachable.
     * Otherwise the first registered adapter that
     * (a) appears in `$templateChannels` and
     * (b) `canReach($recipient)`
     * wins — registration order = preference order.
     *
     * @param string[] $templateChannels
     */
    private function resolveChannel( CommsRequest $request, Recipient $recipient, array $templateChannels ): ?string {
        if ( $request->forceChannel !== null ) {
            $adapter = ChannelAdapterRegistry::get( $request->forceChannel );
            if ( $adapter !== null && $adapter->canReach( $recipient ) ) {
                return $request->forceChannel;
            }
            return null;  // forced channel unavailable — explicit failure
        }

        foreach ( ChannelAdapterRegistry::keys() as $key ) {
            if ( ! in_array( $key, $templateChannels, true ) ) continue;
            $adapter = ChannelAdapterRegistry::get( $key );
            if ( $adapter !== null && $adapter->canReach( $recipient ) ) {
                return $key;
            }
        }
        return null;
    }
}
