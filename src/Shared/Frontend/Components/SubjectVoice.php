<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * SubjectVoice (#3477) — who is reading a player-subject surface, and who the
 * subject is.
 *
 * The Me-views serve three readers. A player reading their own record, a
 * guardian reading their child's, and staff reading a player's. The copy for
 * those three is not the same copy, and until this class existed each view
 * decided the question for itself — usually as `$is_self`, a boolean, which
 * collapses the guardian into whichever of the other two the author was not
 * thinking about.
 *
 * What that produced, measured on a parent's seat before this landed: a
 * heading reading "Ontwikkeling van Bas Willems", correct, and three section
 * titles underneath it reading "Jouw focus", "Hoe je ervoor staat" and "Jouw
 * reis" — written to the child, shown to the parent. On one screen, in three
 * consecutive blocks.
 *
 * It had been fixed three times as three separate instances (#3393, #3398,
 * #3474) before it was worth fixing as a class.
 *
 * ## Using it
 *
 * ```php
 * $voice = SubjectVoice::forPlayer( $player );
 *
 * echo esc_html( $voice->pick(
 *     __( 'Your focus', 'talenttrack' ),
 *     sprintf(
 *         // translators: %s is the player's first name.
 *         __( "%s's focus", 'talenttrack' ),
 *         $voice->firstName()
 *     )
 * ) );
 * ```
 *
 * `pick()` deliberately takes **resolved strings**, not msgid templates: the
 * `__()` calls have to be literal at the call site or `makepot` cannot see
 * them, and a helper that formatted internally would hide every string in
 * this codebase from the catalogue.
 *
 * Pure resolution — no markup. The relation comes from the canonical
 * `ParentChildResolver` (§4), so "is this my child" is answered the same way
 * here as it is on the authorization path.
 */
final class SubjectVoice {

    public const SELF   = 'self';
    public const PARENT = 'parent';
    public const STAFF  = 'staff';

    private string $relation;
    private int $player_id;
    private string $name;
    private string $first_name;

    private function __construct( string $relation, int $player_id, string $name, string $first_name ) {
        $this->relation   = $relation;
        $this->player_id  = $player_id;
        $this->name       = $name;
        $this->first_name = $first_name;
    }

    /**
     * Resolve the voice for a player record and a reader.
     *
     * @param object   $player  tt_players row.
     * @param int|null $user_id Reader; defaults to the current user.
     */
    public static function forPlayer( object $player, ?int $user_id = null ): self {
        $uid       = $user_id ?? get_current_user_id();
        $player_id = (int) ( $player->id ?? 0 );

        $name = trim( (string) QueryHelpers::player_display_name( $player ) );
        if ( $name === '' ) $name = __( 'Player', 'talenttrack' );

        $first = trim( (string) ( $player->first_name ?? '' ) );
        if ( $first === '' ) $first = $name;

        if ( $uid > 0 && (int) ( $player->wp_user_id ?? 0 ) === $uid ) {
            return new self( self::SELF, $player_id, $name, $first );
        }
        if ( in_array( $player_id, ParentChildResolver::childIds( $uid ), true ) ) {
            return new self( self::PARENT, $player_id, $name, $first );
        }
        return new self( self::STAFF, $player_id, $name, $first );
    }

    public function isSelf(): bool   { return $this->relation === self::SELF; }
    public function isParent(): bool { return $this->relation === self::PARENT; }
    public function isStaff(): bool  { return $this->relation === self::STAFF; }

    /** `self` | `parent` | `staff`. */
    public function relation(): string { return $this->relation; }

    public function playerId(): int { return $this->player_id; }

    /** The subject's display name — never empty. */
    public function name(): string { return $this->name; }

    /** The subject's first name, falling back to the display name. */
    public function firstName(): string { return $this->first_name; }

    /**
     * Choose the wording for this reader.
     *
     * `$about` is the third-person form, used for a guardian and — unless
     * `$staff` says otherwise — for staff too. Most headings need only the
     * two: "Your focus" / "Bas's focus" reads correctly to a coach as well.
     * Pass `$staff` where the clinical register genuinely differs, which is
     * mostly prose rather than labels.
     */
    public function pick( string $self, string $about, ?string $staff = null ): string {
        if ( $this->isSelf() ) return $self;
        if ( $this->isStaff() && $staff !== null ) return $staff;
        return $about;
    }

    /**
     * The `player_id` a Me-view link needs to carry to keep this subject, or
     * null when the reader *is* the subject and the slug resolves it anyway.
     *
     * Mirrors what `RecordLink::meUrl()` / `meDetailUrl()` expect, so a view
     * threading a subject through its links does not re-derive the rule.
     */
    public function linkPlayerId(): ?int {
        return $this->isSelf() ? null : $this->player_id;
    }
}
