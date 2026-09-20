<?php
namespace TT\Modules\Alerts\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Modules\Alerts\Contracts\AlertInterface;
use TT\Modules\Alerts\Contracts\AudienceAwareAlert;

/**
 * AlertAudience (#3795) — who a definition is allowed to address, and
 * which of those audiences a given person belongs to.
 *
 * ## Why this is not a capability filter
 *
 * The obvious way to stop a parent's alert settings screen listing twenty
 * staff conditions is to hide anything whose `capRequired()` they do not
 * hold. It is also wrong, and wrong in the direction that removes the
 * feature: the four conditions a family most wants to hear about — their
 * child has not been evaluated for eleven weeks, their goal sailed past its
 * date, their PDP cycle has had no conversation, their evaluation was
 * written but never shared — all declare a staff capability, because staff
 * are who *fixes* them. Filtering on the capability to act would hide
 * exactly the rows the family asked for.
 *
 * So eligibility is decided on the **subject**, not the verb. A definition
 * is eligible for a person when the record it is about is inside that
 * person's scope. For a parent that scope is their own linked children,
 * resolved through `ParentChildResolver` over the `tt_player_parents`
 * pivot — the same pair `Comms\Recipient\RecipientResolver::forPlayer()`
 * uses, so there is one answer to "is this my child" rather than two.
 *
 * ## Staff is the default, and deliberately so
 *
 * `AlertInterface` does not declare an audience. A definition that says
 * nothing is staff-only, which is what every definition written before this
 * shipped meant. Only a definition that opts in — by implementing
 * `AudienceAwareAlert`, normally via `Definitions\FamilyAudienceTrait` —
 * can reach a family. A new definition therefore cannot leak a minor's data
 * to a parent by forgetting to say something.
 */
final class AlertAudience {

    /** Coaches, scouts, admins — anyone acting on the academy's behalf. */
    public const STAFF = 'staff';

    /** A parent or guardian, scoped to their own linked children. */
    public const PARENT = 'parent';

    /**
     * Linked child ids per parent, for the length of one request.
     *
     * The evaluator re-checks guardianship for every occurrence it is about
     * to write, and a sweep writes hundreds. `ParentChildResolver::childIds()`
     * is a database round trip each time, which is the per-row query shape
     * `AlertInterface` forbids elsewhere and should not be smuggled into the
     * evaluator either.
     *
     * @var array<int,list<int>>
     */
    private static $childCache = [];

    /**
     * Resolved audiences per user, for the length of one request.
     *
     * `isEligible()` is asked once per definition, and the settings matrix
     * asks it for all twenty-odd of them in a row. Without this, drawing one
     * preferences screen would re-run the guardian lookup and the persona
     * resolution twenty times over for the same person.
     *
     * @var array<int,list<string>>
     */
    private static $audienceCache = [];

    /**
     * The audiences a definition may address.
     *
     * @return list<string>
     */
    public static function forDefinition( AlertInterface $definition ): array {
        if ( ! $definition instanceof AudienceAwareAlert ) {
            return [ self::STAFF ];
        }

        $declared = [];
        foreach ( $definition->audiences() as $audience ) {
            $audience = (string) $audience;
            if ( $audience === self::STAFF || $audience === self::PARENT ) {
                $declared[ $audience ] = true;
            }
        }
        // A definition declaring nothing usable is staff-only rather than
        // nobody's: silently disappearing from every matrix is harder to
        // notice than appearing in the one it always appeared in.
        return empty( $declared ) ? [ self::STAFF ] : array_keys( $declared );
    }

    /** Whether a definition addresses one named audience. */
    public static function definitionAddresses( AlertInterface $definition, string $audience ): bool {
        return in_array( $audience, self::forDefinition( $definition ), true );
    }

    /**
     * The audiences this person belongs to.
     *
     * Note what is deliberately NOT here: a rule that derives `staff` from
     * a capability or a persona list. A user is staff unless they reach the
     * app *purely* as a guardian, which is the question
     * `ParentChildResolver::isParentViewer()` already answers for the
     * dashboard. Anything else would change what staff see, and a staff
     * matrix that quietly loses rows is a regression dressed as a fix.
     *
     * A coach whose own child plays at the academy is both, and gets both —
     * their coaching alerts and their child's.
     *
     * @return list<string>
     */
    public static function forUser( int $userId ): array {
        if ( $userId <= 0 ) return [];
        if ( isset( self::$audienceCache[ $userId ] ) ) return self::$audienceCache[ $userId ];

        $audiences = [];
        if ( ! ParentChildResolver::isParentViewer( $userId ) ) {
            $audiences[] = self::STAFF;
        }
        if ( ! empty( self::childIds( $userId ) ) ) {
            $audiences[] = self::PARENT;
        }

        self::$audienceCache[ $userId ] = $audiences;
        return $audiences;
    }

    /**
     * Whether this definition may appear on this person's settings matrix.
     */
    public static function isEligible( int $userId, AlertInterface $definition ): bool {
        $mine = self::forUser( $userId );
        if ( empty( $mine ) ) return false;

        foreach ( self::forDefinition( $definition ) as $audience ) {
            if ( in_array( $audience, $mine, true ) ) return true;
        }
        return false;
    }

    /**
     * Whether this person may receive a parent-audience occurrence about
     * this player.
     *
     * Re-asked on every sweep rather than trusted from the definition's own
     * recipient resolution, for the same reason the capability gate is: a
     * guardian link can be removed, and a released child ends guardian
     * access entirely (`ParentChildResolver::childIds()` filters to active
     * players). The next tick must stop delivering, not the next release.
     */
    public static function isGuardianOf( int $userId, int $playerId ): bool {
        if ( $userId <= 0 || $playerId <= 0 ) return false;
        return in_array( $playerId, self::childIds( $userId ), true );
    }

    /** Drop the per-request caches. Call after linking or unlinking a parent. */
    public static function flush(): void {
        self::$childCache    = [];
        self::$audienceCache = [];
    }

    /** @return list<int> */
    private static function childIds( int $userId ): array {
        if ( ! isset( self::$childCache[ $userId ] ) ) {
            self::$childCache[ $userId ] = ParentChildResolver::childIds( $userId );
        }
        return self::$childCache[ $userId ];
    }
}
