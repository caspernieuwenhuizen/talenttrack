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

    /** @var array<string,array<string,mixed>> block key => option bag */
    private array $options;

    private bool $packet_loaded = false;

    /** @var array<string,mixed>|null */
    private ?array $packet = null;

    /**
     * @param array<string,array<string,mixed>> $options
     */
    private function __construct( object $player, string $from, string $to, int $viewer_user_id, string $audience, array $options ) {
        $this->player         = $player;
        $this->player_id      = (int) ( $player->id ?? 0 );
        $this->from           = $from;
        $this->to             = $to;
        $this->viewer_user_id = $viewer_user_id;
        $this->audience       = $audience;
        $this->options        = $options;
    }

    /**
     * The context for a player of the current club, or null for anyone else.
     *
     * @param string|null $audience Null resolves it from the viewer, which is
     *        what every reader-facing path must do (#3876).
     * @param array<string,array<string,mixed>> $options per-block option bags.
     * @throws \InvalidArgumentException on a malformed window.
     */
    public static function make( int $player_id, string $from, string $to, int $viewer_user_id, ?string $audience = null, array $options = [] ): ?self {
        if ( ! self::isDate( $from ) || ! self::isDate( $to ) || $from > $to ) {
            throw new \InvalidArgumentException( 'from and to must be Y-m-d dates with from <= to.' );
        }

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player || (int) ( $player->club_id ?? CurrentClub::id() ) !== (int) CurrentClub::id() ) {
            return null;
        }

        $audience = $audience !== null && PlayerReportAudience::isValid( $audience )
            ? $audience
            : PlayerReportAudience::forReader( $viewer_user_id );

        return new self( $player, $from, $to, $viewer_user_id, $audience, $options );
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
            $tests_viewer        = PlayerReportAudience::isExternal( $this->audience ) ? 0 : $this->viewer_user_id;
            $this->packet        = EvidencePacket::forPlayer( $this->player_id, $this->from, $this->to, $this->viewer_user_id, $tests_viewer );
            $this->packet_loaded = true;
        }
        return $this->packet;
    }

    private static function isDate( string $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }
}
