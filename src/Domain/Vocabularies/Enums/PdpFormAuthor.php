<?php
namespace TT\Domain\Vocabularies\Enums;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpFormAuthor (#4977, epic #4975) — whose entry a row on a PDP
 * conversation form is: the player's own, or the trainer's.
 *
 * Stored in the `author` column of `tt_pdp_form_scores` and
 * `tt_pdp_form_answers` (a `VARCHAR`, not an `ENUM`, as the repository's
 * other vocabulary columns). Code-only: the two sides are structural, the
 * privacy rules (`PdpFormAccess`) are written against them, and an academy
 * cannot add a third.
 */
final class PdpFormAuthor {

    public const PLAYER = 'player';
    public const COACH  = 'coach';

    /** @var list<string> */
    public const ALL = [ self::PLAYER, self::COACH ];

    public static function isValid( string $author ): bool {
        return in_array( $author, self::ALL, true );
    }
}
