<?php
namespace TT\Modules\Exercises\Vision;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * VisionDataRegion (#2695) — the operator must say where photographs go
 * before any are sent anywhere.
 *
 * ## Why this exists
 *
 * This feature sends photographs taken at a youth academy to a
 * third-party model. It used to have a working default endpoint, which
 * meant an install that had merely switched the feature on was already
 * sending images somewhere the operator had never consciously chosen —
 * and the DPIA claimed the opposite, that EU residency was enforced and
 * that leaving it required a deliberate opt-out.
 *
 * There is now no default. Two constants must be present, and the
 * feature reports itself unconfigured until they are:
 *
 *     define( 'TT_VISION_ENDPOINT',    'https://…' );   // where requests go
 *     define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' ); // where that processes data
 *
 * ## What this does and does not guarantee
 *
 * It cannot verify a declaration. Nothing here can tell whether the
 * endpoint an operator typed really processes data where they say it
 * does — that is a contractual fact, not a network one, and a plugin
 * that claimed to check it would be making the same false promise this
 * class exists to remove.
 *
 * What it guarantees is narrower and worth more: **the destination is
 * always a choice somebody made.** A DPIA can honestly record a
 * declaration; it cannot honestly record a default nobody read.
 *
 * The declared region is echoed in logs and in the operator-facing
 * error text so the value in wp-config and the value in the signed DPIA
 * can be compared without reading code.
 */
final class VisionDataRegion {

    /** Both halves present and non-empty. */
    public static function isDeclared(): bool {
        return self::endpoint() !== null && self::region() !== null;
    }

    /** The endpoint requests go to, or null when undeclared. */
    public static function endpoint(): ?string {
        return self::constant( 'TT_VISION_ENDPOINT' );
    }

    /**
     * The operator's statement of where that endpoint processes data.
     *
     * Free text on purpose. A fixed vocabulary would invite picking the
     * nearest-looking option from a list; writing the words out is a
     * small act of attention, and the string lands verbatim in the
     * DPIA.
     */
    public static function region(): ?string {
        return self::constant( 'TT_VISION_DATA_REGION' );
    }

    /**
     * Refuse to proceed when either half is missing.
     *
     * The message names both constants, because an operator who has set
     * one and not the other should not have to guess which.
     *
     * @throws \RuntimeException
     */
    public static function assertDeclared(): void {
        if ( self::isDeclared() ) return;

        $missing = [];
        if ( self::endpoint() === null ) $missing[] = 'TT_VISION_ENDPOINT';
        if ( self::region() === null )   $missing[] = 'TT_VISION_DATA_REGION';

        throw new \RuntimeException( sprintf(
            'Photo capture will not send anything until this install declares where the images go. '
                . 'Missing in wp-config.php: %s. There is deliberately no default — see docs/photo-capture-dpia.md.',
            implode( ' and ', $missing )
        ) );
    }

    /** @return non-empty-string|null */
    private static function constant( string $name ): ?string {
        if ( ! defined( $name ) ) return null;

        $value = trim( (string) constant( $name ) );

        return $value === '' ? null : $value;
    }
}
