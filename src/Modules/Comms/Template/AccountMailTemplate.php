<?php
namespace TT\Modules\Comms\Template;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AccountMailTemplate (#3110) — marker for mail that exists to make an
 * account work, rather than to tell a family something.
 *
 * A template carrying this marker is outside `TemplateSwitch` entirely:
 * it is not switchable, it is not listed on the Messages settings screen,
 * and `TemplateSwitch::isEnabled()` answers `true` for it whatever the
 * stored disabled set says.
 *
 * ## Why this is a separate line and not just "on by default"
 *
 * The invitation email is how a person gets a login. An academy that
 * unticks it has not made a messaging decision — it has broken its own
 * onboarding, and nobody connects "we switched off a message" to "new
 * parents cannot log in", because those do not look like the same thing.
 *
 * The distinction became load-bearing with #3111, which seeds a fresh
 * install with everything switched off. That default is only safe while
 * account mail sits outside the switch; otherwise a new install cannot
 * invite anybody until someone finds and unticks a box whose purpose
 * they have to infer.
 *
 * ## What it does NOT change
 *
 * Everything else about the template stays: it goes through
 * `CommsService::send()`, writes its `tt_comms_log` row, resolves
 * recipients through `RecipientResolver`, and honours opt-out wherever
 * opt-out legally applies. This marker removes a template from **one**
 * switch, not from the module.
 *
 * A marker interface rather than a method on `TemplateInterface`: two
 * templates implement that interface directly rather than extending
 * `AbstractTemplate`, so a new required method would be a breaking
 * change for a distinction that applies to one template in twenty-three.
 */
interface AccountMailTemplate extends TemplateInterface {
}
