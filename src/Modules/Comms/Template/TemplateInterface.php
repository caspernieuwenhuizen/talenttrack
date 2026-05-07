<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;

/**
 * TemplateInterface (#0066) — one template per use case.
 *
 * Templates produce a rendered subject + body for a given request +
 * recipient + locale. The dispatcher invokes the template once per
 * recipient (recipient-specific data — preferred greeting, child's
 * first name, locale — make the renderings differ even when the
 * payload is shared).
 *
 * Locale: per-recipient. The template reads `$recipient->preferredLocale`
 * (falling back to `$request->localeOverride`, then site locale) and
 * picks the right copy variant. Per spec Q7 lean: top 5 templates are
 * editable per-club via `tt_config['comms_template_<key>_<locale>']`;
 * fixed templates ignore the override and always render their hardcoded
 * copy. The base implementation is shared in `AbstractTemplate`.
 */
interface TemplateInterface {

    /** Stable template key (matches `tt_comms_log.template_key`). */
    public function key(): string;

    /** Human-readable label (translatable, for the operator-facing audit-log filter). */
    public function label(): string;

    /**
     * Channels this template ships rendered copy for. Most templates
     * support multiple channels (push + email + sms, varying length).
     * The dispatcher picks one based on recipient preference + opt-out
     * + quiet-hours; the template renders for whichever was chosen.
     *
     * @return string[]
     */
    public function supportedChannels(): array;

    /**
     * Whether this template's copy is editable per-club (one of the
     * "top 5" per spec Q7). When true, `CommsService` will look up
     * `tt_config['comms_template_<key>_<locale>_subject']` /
     * `_body` overrides before falling back to the hardcoded default.
     */
    public function isEditable(): bool;

    /**
     * Render `[ subject, body ]` for the given context. Both strings;
     * subject may be empty for push / sms (the channel adapter is
     * responsible for understanding what an empty subject means on
     * its medium).
     *
     * @return array{0: string, 1: string}
     */
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array;
}
