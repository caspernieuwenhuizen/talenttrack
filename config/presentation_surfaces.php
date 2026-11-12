<?php
/**
 * Routable surfaces that may present as a room display (#5088).
 *
 * A room display is one record on a shared screen: a talk on the TV in the
 * room, with the player, a parent and the trainer looking at it together.
 * The app's own chrome has no place there. The search box, the user name,
 * the navigation and the help drawer belong to the person holding the
 * laptop, not to the room.
 *
 * Listing a slug here means only that the surface **may** present. Nothing
 * changes until the view, on its success path, after every access and
 * record check has passed, declares that it is presenting through
 * `PresentationSurfaces::present()`. A request that does
 * not declare, which includes every refusal, renders as a normal view in
 * the shell with its breadcrumb chain.
 *
 * **This is a §5a exception with a contract.** A slug belongs here only
 * when all three hold, and all three are on the view, not on this file:
 *
 *   1. The view shows exactly one record.
 *   2. It emits one affordance, the close control `present()` draws, back
 *      to that record's page, instead of the chain and the pill.
 *   3. On every refused path it renders as a normal view, in the shell,
 *      with the chain. It simply does not call `present()` there.
 *
 * An entry without a reason is malformed and is not a claim.
 *
 * @return array<string, string> view slug => why it may present
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'pdp-conversation-mode' => 'A PDP talk on the screen in the room, for the player, a parent and the trainer together. One talk; one close back to its page, on the conversation tab; the normal screen with breadcrumbs for anyone the view refuses.',
];
