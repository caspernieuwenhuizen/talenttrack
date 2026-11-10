<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Pdp\EvidencePacket;

/**
 * PlayerReportContext (#4590) — one player, one window, one audience: what
 * every player block composer is handed.
 *
 * The evidence packet is built at most once per context, and only when a
 * composer asks for it, so a caller composing several blocks for the same
 * player and window passes one context to all of them. The audience gate
 * stays `PlayerReportAudience::apply()` on the composed payload; a composer
 * adds none of its own. Access is the caller's to check (`PlayerReportAccess`)
 * before a context is built.
 */
final class PlayerReportContext {

    public readonly object $player;
    public readonly int $player_id;
    public readonly string $from;
    public readonly string $to;
    public readonly int $viewer_user_id;
    public readonly string $audience;

    /**
     * #4991 — everyone the report is read by, the viewer first. One reader on
     * screen; a scheduled PDF's owner and recipients together. Every
     * reader-dependent read in the packet asks against all of them.
     *
     * @var list<int>
     */
    public readonly array $readers;

    /** @var array<string,array<string,mixed>> block key => option bag */
    private array $options;

    private bool $packet_loaded = false;

    /** @var array<string,mixed>|null */
    private ?array $packet = null;

    /**
     * @param array<string,array<string,mixed>> $options
     * @param list<int>                         $readers
     */
    private function __construct( object $player, string $from, string $to, int $viewer_user_id, string $audience, array $options, array $readers ) {
        $this->player         = $player;
        $this->player_id      = (int) ( $player->id ?? 0 );
        $this->from           = $from;
        $this->to             = $to;
        $this->viewer_user_id = $viewer_user_id;
        $this->audience       = $audience;
        $this->options        = $options;
        $this->readers        = $readers;
    }

    /**
     * The context for a player of the current club, or null for anyone else.
     *
     * @param string|null $audience Null resolves it from the readers, which
     *        is what every reader-facing path must do (#3876): the narrowest
     *        audience any of them reads as.
     * @param array<string,array<string,mixed>> $options per-block option bags.
     * @param list<int> $other_readers #4991 — who else reads this same copy;
     *        0 is a reader with no rights.
     * @throws \InvalidArgumentException on a malformed window.
     */
    public static function make( int $player_id, string $from, string $to, int $viewer_user_id, ?string $audience = null, array $options = [], array $other_readers = [] ): ?self {
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player || (int) ( $player->club_id ?? CurrentClub::id() ) !== (int) CurrentClub::id() ) {
            return null;
        }

        $readers = array_values( array_unique( array_merge( [ $viewer_user_id ], array_map( 'intval', $other_readers ) ) ) );

        if ( $audience === null || ! PlayerReportAudience::isValid( $audience ) ) {
            $audience = PlayerReportAudience::INTERNAL;
            foreach ( $readers as $reader ) {
                $as = PlayerReportAudience::forReader( $reader );
                if ( $as !== PlayerReportAudience::INTERNAL ) {
                    $audience = $as;
                    break;
                }
            }
        }

        return new self( $player, $from, $to, $viewer_user_id, $audience, $options, $readers );
    }

    /**
     * One block's options, as `PlayerReportComposition` normalises them.
     *
     * @return array<string,mixed>
     */
    public function optionsFor( string $block ): array {
        return PlayerReportComposition::optionsFor( $this->options, $block );
    }

    /**
     * The player's evidence packet over the window, or null when there is
     * none to build.
     *
     * An external audience reads tests at the public level whoever composed
     * it: a coach sending a scout or a family the report must not hand over a
     * test the academy keeps to its staff.
     *
     * @return array<string,mixed>|null
     */
    public function packet(): ?array {
        if ( ! $this->packet_loaded ) {
            $tests_readers       = PlayerReportAudience::isExternal( $this->audience ) ? [ 0 ] : $this->readers;
            $this->packet        = EvidencePacket::forReaders( $this->player_id, $this->from, $this->to, $this->readers, $tests_readers );
            $this->packet_loaded = true;
        }
        return $this->packet;
    }

    private static function isDate( string $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }
}
