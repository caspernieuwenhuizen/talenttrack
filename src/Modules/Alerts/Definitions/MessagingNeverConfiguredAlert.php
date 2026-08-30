<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Domain\Surface;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MessagingNeverConfiguredAlert (#3139, epic #2629) — this academy sends
 * nothing, and nobody has said that on purpose.
 *
 * #3111 seeds a fresh install with every message switched off, so that
 * TalentTrack does not mail the parents of minors before anybody decided it
 * should. #3113 adds the setup step where an academy chooses what it does
 * send — and that step is skippable, deliberately, with copy that says in
 * those words that skipping means no messages will be sent.
 *
 * A club that skips it meets that decision **once**, on the day they
 * installed, and never again. The failure is silent: nothing errors, no
 * screen looks wrong, and the club finds out the day they cancel a training
 * and nobody turns up to be told. The reasonable conclusion at that point
 * is that the product is broken.
 *
 * So this is the way back that does not depend on the operator remembering.
 *
 * ## Sweep-only, on purpose (#3139 decision)
 *
 * Every other definition is about a record — a player, a team, an
 * invitation — and `subjectType()` is what #2731's event-driven
 * invalidation dispatches on. This one is about the install's
 * configuration, and it does **not** introduce a `config` subject type to
 * carry a single definition: extending a contract every other definition
 * depends on, for one row, is a bad trade.
 *
 * `messaging` is a subject nothing invalidates, so
 * `AlertEvaluator::runForSubject()` never selects this definition and the
 * hourly reconcile is the only thing that re-runs it. That is soon enough
 * for a condition which has persisted since the install was created. If a
 * second config-shaped alert ever appears, that is the moment to introduce
 * the subject type properly, with the invalidation path taught what
 * invalidates it.
 *
 * The `isFullSweep()` guard below is belt and braces against that future:
 * a narrowed run that somehow reached this definition would otherwise be
 * told "nothing is true", and the reconcile would resolve the occurrence it
 * never looked at.
 *
 * ## Why it self-resolves and why it is dismissible
 *
 * It resolves itself the moment anything is switched on — the reconcile
 * loop returns the full current truth and stamps `resolved_at` on whatever
 * is absent, so there is no explicit clear and no second stored flag saying
 * "has this been dealt with". #3111 went out of its way not to add one, and
 * a second source of truth for "has messaging been configured" would drift
 * from the first.
 *
 * An academy that genuinely wants silence mutes it through the ordinary
 * preference layer (#2632), like any other alert. The undismissable variant
 * was rejected: a club that deliberately sends nothing would be nagged
 * forever, which trains people to ignore the alert surface — the failure
 * this whole epic exists to prevent.
 *
 * ## Not an error
 *
 * A fresh install is in this state **by design**, and #3049 chose that
 * design deliberately. `info` severity, `badge` only, and copy that states
 * the situation and where to change it rather than reporting a fault.
 *
 * Account mail — the invitation email — sits outside the switch entirely
 * (#3110), so an install firing this alert can still invite people and let
 * them in. The copy must not suggest otherwise, or it sends an admin
 * looking for a problem that is not there.
 *
 * ## Which player question does this answer?
 *
 * *What do they need next?* — read from the other side. A cancelled
 * training, a new evaluation to read, a conversation to prepare for: none
 * of it reaches the player or their parents on an install in this state.
 */
final class MessagingNeverConfiguredAlert extends AbstractDataQualityAlert {

    /**
     * A subject nothing invalidates — see the class docblock. Not `config`:
     * this deliberately does not introduce a config subject type.
     */
    public const SUBJECT_TYPE = 'messaging';

    /**
     * There is one of these per install, so the subject id is a constant.
     * It only has to be stable, because `dedupeKey()` hashes it with the
     * alert key and the recipient.
     */
    private const SUBJECT_ID = 1;

    public function key(): string {
        return 'comms.messaging_never_configured';
    }

    /**
     * Grouped under Messages in the settings matrix rather than under data
     * quality, because that is where somebody looking for it would think to
     * look — and where the screen that fixes it lives.
     */
    public function module(): string {
        return 'comms';
    }

    public function label(): string {
        return __( 'No messages are being sent', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Every message this academy could send is switched off, and nobody has chosen otherwise since the install was created. Parents and players are told nothing — not even that a training was cancelled.', 'talenttrack' );
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** Never a fault, so never louder than a badge. */
    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    /** @return list<string> */
    public function defaultSurfaces(): array {
        return [ Surface::BADGE ];
    }

    /** Mutable through the ordinary preference layer (#2632). */
    public function isOperational(): bool {
        return false;
    }

    /**
     * The people who can act on it are the people who see it — the same
     * capability that opens Configuration → Messages.
     */
    public function capRequired(): string {
        return 'tt_edit_feature_toggles';
    }

    protected function titleFor( object $row ): string {
        return __( 'This academy is not sending any messages yet.', 'talenttrack' );
    }

    protected function urlFor( object $row ): string {
        return add_query_arg(
            [ 'tt_view' => 'configuration', 'config_sub' => 'messages' ],
            RecordLink::dashboardUrl()
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'switchable_count' => (int) ( $row->switchable_count ?? 0 ),
        ];
    }

    /** This alert is about the install, not about a player. */
    protected function playerIdFor( object $row ): ?int {
        return null;
    }

    /**
     * One synthetic row when every switchable template is off, none
     * otherwise. No query: the answer is already in `TemplateSwitch`, which
     * reads one config value.
     *
     * The `$switchable !== []` guard is load-bearing. An install whose
     * template registry has not booted — a partial boot, a module still
     * loading — reports zero switchable templates, and zero of zero are
     * off. Without the guard that reads as "everything is switched off" and
     * the alert fires on an install where nothing at all is known yet.
     *
     * @return list<object>
     */
    protected function rows( AlertContext $context ): array {
        // See the class docblock: sweep-only. A narrowed run must not be
        // able to reach a verdict about the whole install.
        if ( ! $context->isFullSweep() ) return [];

        $switchable = array_keys( TemplateSwitch::switchableTemplates() );
        if ( $switchable === [] ) return [];

        foreach ( $switchable as $key ) {
            if ( TemplateSwitch::isEnabled( (string) $key ) ) return [];
        }

        return [ (object) [
            'subject_id'       => self::SUBJECT_ID,
            'switchable_count' => count( $switchable ),
        ] ];
    }
}
