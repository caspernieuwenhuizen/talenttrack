<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Queue\DeferredSendQueue;
use TT\Modules\Comms\QuietHours\QuietHoursPolicy;
use TT\Modules\Comms\RateLimit\RateLimiter;
use TT\Modules\Comms\Recipient\RecipientReachability;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateChannels;
use TT\Modules\Comms\Template\TemplateSwitch;

/**
 * CommsService (#0066) — orchestrator for one Comms send.
 *
 * Per-recipient flow:
 *   1. Opt-out check (`OptOutPolicy::isOptedOut`). On opt-out → log
 *      `STATUS_OPTED_OUT` and skip.
 *   2. Quiet-hours check (`QuietHoursPolicy::shouldDefer`). On defer
 *      → log `STATUS_QUIET_HOURS` and hold the request for this one
 *      recipient in `Queue\DeferredSendQueue` (#3646). The first
 *      workflow heartbeat after the window ends runs
 *      `Queue\DeferredSendSweep`, which calls `deliverDeferred()`: the
 *      template switch, opt-out and the rest of this chain run again at
 *      that moment, and the outcome overwrites the same log row with
 *      `attempt` 2. A held message that has not gone within 24 hours is
 *      marked failed (`deferral_expired`). A request the queue cannot
 *      hold — one with a file attached — is logged as failed at once
 *      rather than as a hold nothing will ever send.
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
 *
 * Every per-recipient exit path also records whether the recipient was
 * reachable at all (#3383), derived from `RecipientReachability` before
 * step 1 and written to `tt_comms_log.reachable` beside the status. It is
 * a second fact, not a second status: "deferred until morning" and "no
 * contact details on file" are both true of the same row, and the sender
 * needs the one the status was never able to carry. The whole-send guards
 * in `send()` leave it NULL — they stop before any recipient is examined,
 * and NULL means *not established* rather than *reachable*.
 *
 * Every exit path writes an audit row — including the guard clauses.
 * A send that resolves to nobody, or names a template that isn't
 * registered, leaves the same evidence a delivered one does. Silence
 * is never an outcome (#2602).
 *
 * `preflight()` runs the same policy chain without rendering or
 * dispatching, so a caller can warn the user before they commit.
 */
final class CommsService {

    private OptOutPolicy $optOut;
    private QuietHoursPolicy $quietHours;
    private RateLimiter $rateLimiter;
    private CommsAuditLogger $auditLogger;
    private DeferredSendQueue $deferredQueue;

    public function __construct(
        ?OptOutPolicy $optOut = null,
        ?QuietHoursPolicy $quietHours = null,
        ?RateLimiter $rateLimiter = null,
        ?CommsAuditLogger $auditLogger = null,
        ?DeferredSendQueue $deferredQueue = null
    ) {
        $this->optOut        = $optOut        ?? new OptOutPolicy();
        $this->quietHours    = $quietHours    ?? new QuietHoursPolicy();
        $this->rateLimiter   = $rateLimiter   ?? new RateLimiter();
        $this->auditLogger   = $auditLogger   ?? new CommsAuditLogger();
        $this->deferredQueue = $deferredQueue ?? new DeferredSendQueue();
    }

    /**
     * @return CommsResult[]   one per recipient
     */
    public function send( CommsRequest $request ): array {
        // #3576 — the demo generator is writing a fictional academy in this
        // request. Its records fire the same hooks a real club's do (on
        // purpose: the journey needs them), and nothing it creates is a
        // message anybody should get or an admin should be warned about.
        // Per request, not site-wide, so real sends by other users during
        // a long run are untouched.
        if ( \TT\Modules\DemoData\DemoGenerationContext::isActive() ) {
            return [];
        }

        $template = TemplateRegistry::get( $request->templateKey );
        if ( $template === null ) {
            // No template registered — one failure result per recipient so
            // the caller can surface the misconfiguration, AND an audit row
            // each, so a typo'd template key isn't the one send that leaves
            // no evidence anywhere.
            Logger::error( 'Comms send referenced an unregistered template', [
                'template_key'    => $request->templateKey,
                'recipient_count' => count( $request->recipients ),
            ] );

            $results = [];
            foreach ( $request->recipients as $recipient ) {
                $result = new CommsResult(
                    wp_generate_uuid4(),
                    CommsResult::STATUS_FAILED,
                    '',
                    $recipient,
                    'unknown_template'
                );
                $this->auditLogger->record( $request, $recipient, $result->uuid, '', '', $result );
                $results[] = $result;
            }
            return $results;
        }

        // #2603 — the club switched this template off. Checked before any
        // per-recipient policy so no caller can route around it, and
        // audited per recipient: the switch suppresses the message, never
        // the evidence that one was meant to go out.
        if ( ! TemplateSwitch::isEnabled( $request->templateKey ) ) {
            // With nobody to send to there is no recipient to audit
            // against, so one row stands for the suppressed send — about
            // its subject — rather than none at all.
            $recipients = $request->recipients !== [] ? $request->recipients : [ Recipient::none( $request->subjectPlayerId ) ];
            $results    = [];
            foreach ( $recipients as $recipient ) {
                $result = new CommsResult(
                    wp_generate_uuid4(),
                    CommsResult::STATUS_TEMPLATE_DISABLED,
                    '',
                    $recipient,
                    'template_disabled'
                );
                $this->auditLogger->record( $request, $recipient, $result->uuid, '', '', $result );
                $results[] = $result;
            }
            return $results;
        }

        // A send that resolved to nobody is the commonest invisible
        // failure in the wild — a team with no linked parents looks
        // exactly like a successful send. Record it. Checked after the
        // template switch (#3576): a club that switched a template off has
        // decided it should not go, and is not warned that it could not.
        //
        // #3576 — the record names what the message was about. The warning
        // used to carry only the template key, so fifty of them in the
        // error log said nothing about which families never heard.
        if ( $request->recipients === [] ) {
            $result = new CommsResult(
                wp_generate_uuid4(),
                CommsResult::STATUS_NO_RECIPIENTS,
                '',
                Recipient::none( $request->subjectPlayerId ),
                'no_recipients'
            );
            $this->auditLogger->record( $request, $result->recipient, $result->uuid, '', '', $result );
            Logger::warning( 'Comms send resolved to zero recipients', [
                'template_key' => $request->templateKey,
                'message_type' => $request->messageType,
                'club_id'      => $request->clubId,
                'player_id'    => $request->subjectPlayerId,
                'subject_type' => $request->subjectType,
                'subject_id'   => $request->subjectId,
            ] );
            return [ $result ];
        }

        $results = [];
        foreach ( $request->recipients as $recipient ) {
            $results[] = $this->sendOne( $request, $recipient, $template );
        }
        return $results;
    }

    /**
     * Dry run: evaluate the policy chain without rendering or dispatching.
     *
     * Returns the same per-recipient shape `send()` does, so a compose
     * screen can warn *before* the click — "quiet hours are active, this
     * sends at 07:00", "4 of 12 recipients are unreachable", "this
     * template is switched off". A `STATUS_QUEUED` verdict means the
     * recipient would be sent to.
     *
     * Writes no audit rows and calls no adapter's `send()`. The spec's
     * "preview-before-send mandatory" needs this; #2603's kill switch and
     * #2604's user-triggered sends both consume it.
     *
     * @return CommsResult[]   one per recipient
     */
    public function preflight( CommsRequest $request ): array {
        if ( $request->recipients === [] ) {
            return [ new CommsResult(
                '',
                CommsResult::STATUS_NO_RECIPIENTS,
                '',
                Recipient::none(),
                'no_recipients'
            ) ];
        }

        $template = TemplateRegistry::get( $request->templateKey );
        if ( $template === null ) {
            return array_map(
                fn ( Recipient $r ) => new CommsResult( '', CommsResult::STATUS_FAILED, '', $r, 'unknown_template' ),
                $request->recipients
            );
        }

        // #2603 — surfaced here so a screen can say "this message type is
        // switched off" BEFORE the user writes and sends it, rather than
        // reporting a dead send afterwards.
        if ( ! TemplateSwitch::isEnabled( $request->templateKey ) ) {
            return array_map(
                fn ( Recipient $r ) => new CommsResult( '', CommsResult::STATUS_TEMPLATE_DISABLED, '', $r, 'template_disabled' ),
                $request->recipients
            );
        }

        $results = [];
        foreach ( $request->recipients as $recipient ) {
            $results[] = $this->preflightOne( $request, $recipient, $template );
        }
        return $results;
    }

    /**
     * Note the deliberate ordering difference from `sendOne()`.
     *
     * Delivery checks quiet hours before resolving a channel, because a
     * deferred send never needs a channel. A *warning* surface must do
     * the opposite: problems intrinsic to the recipient (opted out, no
     * usable contact details) are reported ahead of purely temporal ones
     * (quiet hours, rate limit), because the first kind is what the
     * sender can actually act on before committing.
     *
     * Ordered the other way, a preflight run at 22:00 would report
     * "held until quiet hours end" for everyone and never mention the
     * four recipients who have no email address at all.
     */
    private function preflightOne( CommsRequest $request, Recipient $recipient, $template ): CommsResult {
        // #3383 — established once, at the top, and carried onto every
        // verdict below. `sendOne()` derives it from the same helper, so a
        // warning and the row it becomes cannot describe the same person
        // differently.
        $reachable = RecipientReachability::isReachable( $recipient );

        if ( $this->optOut->isOptedOut( $recipient->userId, $request->messageType ) ) {
            return new CommsResult( '', CommsResult::STATUS_OPTED_OUT, '', $recipient, null, null, $reachable );
        }

        $channelKey = $this->resolveChannel( $request, $recipient, $template->supportedChannels() );
        if ( $channelKey === null ) {
            return new CommsResult( '', CommsResult::STATUS_FAILED, '', $recipient, 'no_channel_available', null, $reachable );
        }
        if ( ChannelAdapterRegistry::get( $channelKey ) === null ) {
            return new CommsResult( '', CommsResult::STATUS_FAILED, '', $recipient, 'adapter_missing', null, $reachable );
        }

        if ( $this->quietHours->shouldDefer( $request ) ) {
            return new CommsResult( '', CommsResult::STATUS_QUIET_HOURS, $channelKey, $recipient, null, null, $reachable );
        }
        if ( $this->rateLimiter->wouldExceed( $request->senderUserId, $request->messageType ) ) {
            return new CommsResult( '', CommsResult::STATUS_RATE_LIMITED, $channelKey, $recipient, null, null, $reachable );
        }

        // Would be sent to. Not yet sent — hence queued, not sent.
        return new CommsResult( '', CommsResult::STATUS_QUEUED, $channelKey, $recipient, null, null, $reachable );
    }

    /**
     * Send a message quiet hours held, against the log row it already has
     * (#3646). Called by `Queue\DeferredSendSweep` once the window has
     * ended; `$request` carries the one recipient the row was written for.
     *
     * Runs the template switch check `send()` does and then the rest of
     * `sendOne()`'s chain, because a night is long enough for a family to
     * opt out or a club to switch the template off, and neither should be
     * overridden by a decision taken the evening before. Quiet hours are
     * not checked again: the sweep has just established the window is
     * over. Nothing here writes a second log row: every outcome updates
     * `$logUuid`, moving its `attempt` to 2.
     */
    public function deliverDeferred( CommsRequest $request, Recipient $recipient, string $logUuid ): CommsResult {
        $template = TemplateRegistry::get( $request->templateKey );
        if ( $template === null ) {
            Logger::error( 'Comms held message referenced an unregistered template', [
                'template_key' => $request->templateKey,
                'uuid'         => $logUuid,
            ] );
            $result = new CommsResult( $logUuid, CommsResult::STATUS_FAILED, '', $recipient, 'unknown_template' );
            $this->auditLogger->recordAttempt( $logUuid, '', '', $result );
            return $result;
        }

        if ( ! TemplateSwitch::isEnabled( $request->templateKey ) ) {
            $result = new CommsResult( $logUuid, CommsResult::STATUS_TEMPLATE_DISABLED, '', $recipient, 'template_disabled' );
            $this->auditLogger->recordAttempt( $logUuid, '', '', $result );
            return $result;
        }

        return $this->sendOne( $request, $recipient, $template, $logUuid );
    }

    /**
     * @param string|null $heldUuid The log row of a message quiet hours
     *                              held, when this is its deferred send;
     *                              null for a first attempt.
     */
    private function sendOne( CommsRequest $request, Recipient $recipient, $template, ?string $heldUuid = null ): CommsResult {
        $uuid = $heldUuid ?? wp_generate_uuid4();

        // #3383 — the second fact every row below carries. Contact details
        // only: no channel resolution, no send work, so the ordering under
        // it is untouched. A message deferred to tomorrow morning still
        // never resolves a channel — it just stops claiming, by omission,
        // that the family it was meant for could have been reached.
        $reachable = RecipientReachability::isReachable( $recipient );

        // 1. Opt-out
        if ( $this->optOut->isOptedOut( $recipient->userId, $request->messageType ) ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_OPTED_OUT, '', $recipient, null, null, $reachable );
            $this->audit( $request, $recipient, $uuid, '', '', $result, $heldUuid !== null );
            return $result;
        }

        // 2. Quiet hours. A held message is only sent once the sweep has
        // seen the window close, so its second pass skips this.
        if ( $heldUuid === null && $this->quietHours->shouldDefer( $request ) ) {
            $refusal = $this->deferredQueue->enqueue( $request, $recipient, $uuid );
            $result  = $refusal === null
                ? new CommsResult( $uuid, CommsResult::STATUS_QUIET_HOURS, '', $recipient, null, null, $reachable )
                : new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', $recipient, $refusal, null, $reachable );
            $this->auditLogger->record( $request, $recipient, $uuid, '', '', $result );
            return $result;
        }

        // 3. Rate limit
        if ( $this->rateLimiter->wouldExceed( $request->senderUserId, $request->messageType ) ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_RATE_LIMITED, '', $recipient, null, null, $reachable );
            $this->audit( $request, $recipient, $uuid, '', '', $result, $heldUuid !== null );
            return $result;
        }

        // 4. Channel resolution
        $channelKey = $this->resolveChannel( $request, $recipient, $template->supportedChannels() );
        if ( $channelKey === null ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', $recipient, 'no_channel_available', null, $reachable );
            $this->audit( $request, $recipient, $uuid, '', '', $result, $heldUuid !== null );
            return $result;
        }

        $adapter = ChannelAdapterRegistry::get( $channelKey );
        if ( $adapter === null ) {
            $result = new CommsResult( $uuid, CommsResult::STATUS_FAILED, '', $recipient, 'adapter_missing', null, $reachable );
            $this->audit( $request, $recipient, $uuid, '', '', $result, $heldUuid !== null );
            return $result;
        }

        // 5. Template render
        $locale = $recipient->preferredLocale !== ''
            ? $recipient->preferredLocale
            : ( $request->localeOverride ?? get_locale() );
        [ $subject, $body ] = $template->render( $channelKey, $request, $recipient, $locale );

        // 6. Dispatch
        $result = $adapter->send( $request, $recipient, $uuid, $subject, $body )
            ->withReachable( $reachable );

        // 7. Audit + rate-limit accounting
        if ( $result->isSuccess() ) {
            $this->rateLimiter->record( $request->senderUserId );
        }
        $this->audit( $request, $recipient, $uuid, $subject, $body, $result, $heldUuid !== null );
        return $result;
    }

    /**
     * A first attempt writes a row; a held message's send updates the one
     * it already has.
     */
    private function audit(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $subject,
        string $body,
        CommsResult $result,
        bool $held
    ): void {
        if ( $held ) {
            $this->auditLogger->recordAttempt( $uuid, $subject, $body, $result );
            return;
        }
        $this->auditLogger->record( $request, $recipient, $uuid, $subject, $body, $result );
    }

    /**
     * Channel resolution. `forceChannel` wins when set and reachable.
     * Otherwise the first registered adapter that
     * (a) appears in `$templateChannels` and
     * (b) `canReach($recipient)`
     * wins — registration order = preference order.
     *
     * #3112 — `$templateChannels` is narrowed to what the academy allows
     * for this template first, so a club that has ruled out SMS falls
     * through to the next channel it can reach the person on rather than
     * texting them anyway. `TemplateChannels` never returns an empty set,
     * so this cannot turn a channel preference into a dead send.
     *
     * `forceChannel` is deliberately NOT narrowed: it is set by a caller
     * that has already decided (a WhatsApp share link, a preview send),
     * and silently redirecting it would be worse than the explicit
     * failure the caller already handles.
     *
     * @param string[] $templateChannels
     */
    private function resolveChannel( CommsRequest $request, Recipient $recipient, array $templateChannels ): ?string {
        $templateChannels = TemplateChannels::allowedFor( $request->templateKey, $templateChannels );

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
