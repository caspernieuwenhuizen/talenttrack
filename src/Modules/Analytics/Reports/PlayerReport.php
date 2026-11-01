<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\JourneyActivityResolver;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Analytics\Reports\PlayerReportComposers\EvidenceComposer;
use TT\Modules\Analytics\Reports\PlayerReportComposers\LetterheadComposer;
use TT\Modules\Analytics\Reports\PlayerReportComposers\MinutesComposer;
use TT\Modules\Analytics\Reports\PlayerReportComposers\PlayerReportContext;
use TT\Modules\Analytics\Reports\PlayerReportComposers\RatingsComposer;
use TT\Modules\Analytics\Reports\PlayerReportComposers\TalkingPointsComposer;

/**
 * PlayerReport (#3872, epic #3871) — one player over one window, as blocks.
 *
 * Not a second assembly. Every number comes from `EvidencePacket`, the one the
 * PDP Evidence tab, the printed PDP file and the verdict screen already read
 * (#3304), so a coach and a head of academy looking at the same player on the
 * same day see the same figures whichever surface they opened. This class
 * only selects, orders and shapes; each block is computed by its composer
 * under `PlayerReportComposers\`, which the Report Studio calls too (#4590).
 *
 * The packet is built at most once per call, and not at all when the selection
 * needs nothing from it (the letterhead and the blank notes area).
 *
 * @phpstan-type Report array{player_id:int, from:string, to:string, blocks:list<string>, data:array<string,array<string,mixed>>, audience:string}
 */
final class PlayerReport {

    /** Blocks that read nothing from the packet. */
    private const WITHOUT_PACKET = [ PlayerReportBlock::LETTERHEAD, PlayerReportBlock::NOTES ];

    /**
     * Compose the report for one reader.
     *
     * @param list<string> $blocks Selected block keys; empty means the
     *        audience's default. Unknown keys throw — a typo must not quietly
     *        produce a report missing a section nobody asked to remove.
     * @param string|null  $audience Null resolves it from the viewer, which is
     *        what every reader-facing path must do (#3876). Only a surface
     *        composing *on behalf of* someone else — a coach emailing a scout,
     *        or sharing a report with the family (#3955) — names it.
     * @param array<string,array<string,mixed>> $options #3989 per-block option
     *        bags, as `PlayerReportComposition` normalises them. Forgiving:
     *        anything a block does not recognise is ignored here; a strict
     *        caller refuses it first with `PlayerReportComposition::unknownOptions()`.
     * @return Report|null Null for a player outside the current club.
     * @throws \InvalidArgumentException on unknown block keys or a malformed window.
     */
    public function forPlayer( int $player_id, string $from, string $to, array $blocks, int $viewer_user_id, ?string $audience = null, array $options = [] ): ?array {
        $unknown = PlayerReportBlock::unknown( $blocks );
        if ( $unknown !== [] ) {
            throw new \InvalidArgumentException( 'Unknown block keys: ' . implode( ', ', $unknown ) );
        }
        $ctx = PlayerReportContext::make( $player_id, $from, $to, $viewer_user_id, $audience, $options );
        if ( $ctx === null ) return null;
        $audience = $ctx->audience;

        $allowed  = PlayerReportAudience::blocksFor( $audience, $blocks );
        $selected = PlayerReportBlock::normalise( $allowed !== [] || $blocks === [] ? $allowed : [ PlayerReportBlock::LETTERHEAD ] );

        if ( array_diff( $selected, self::WITHOUT_PACKET ) !== [] && $ctx->packet() === null ) {
            return null;
        }

        $data = [];
        foreach ( $selected as $block ) {
            $data[ $block ] = self::block( $block, $ctx );
        }

        return PlayerReportAudience::apply( [
            'player_id' => $player_id,
            'from'      => $from,
            'to'        => $to,
            'blocks'    => $selected,
            'data'      => $data,
        ], $audience ) + [ 'audience' => $audience ];
    }

    /**
     * One block, by its composer (#4590). The Studio's blocks call the same
     * composers, so a figure reads the same in both.
     *
     * @return array<string,mixed>
     */
    private static function block( string $block, PlayerReportContext $ctx ): array {
        switch ( $block ) {
            case PlayerReportBlock::LETTERHEAD:     return LetterheadComposer::compose( $ctx );
            case PlayerReportBlock::MINUTES:        return MinutesComposer::compose( $ctx );
            case PlayerReportBlock::TALKING_POINTS: return TalkingPointsComposer::compose( $ctx );
            case PlayerReportBlock::RATINGS:        return RatingsComposer::compose( $ctx );
            case PlayerReportBlock::NOTES:          return [ 'lines' => 6 ];
        }
        return EvidenceComposer::compose( $block, $ctx );
    }

    /**
     * The ratings arithmetic, kept here for its existing callers; it lives in
     * {@see RatingsComposer::ratings()}.
     *
     * @param array<int|string,mixed> $evaluations newest first
     * @param array<int,array{label:string, order:int}>|null $tree
     * @return array<string,mixed>
     */
    public static function ratings( array $evaluations, string $detail = RatingsBlockOptions::MAIN, ?array $tree = null ): array {
        return RatingsComposer::ratings( $evaluations, $detail, $tree );
    }

    /**
     * The minutes-share arithmetic of #3991, kept here for its existing
     * callers; it lives in {@see MinutesComposer::shareComparison()}.
     *
     * @param array<int,list<string>> $roster player id => profile position codes
     * @param array<int,int>          $played player id => minutes in the window
     * @return array{share:?int, comparison:?array{team:int, position:?array{positions:list<string>, count:int, share:int}, player_positions:list<string>}}
     */
    public static function shareComparison( int $player_id, array $roster, array $played, int $available ): array {
        return MinutesComposer::shareComparison( $player_id, $roster, $played, $available );
    }

    /**
     * #3991 — the comparison as the PDF prints it, a line each, so the printed
     * lines and the layout estimate that counts them cannot differ. Numbers
     * only: the player's own share stands in the figures right above, and a
     * half-width cell beside attendance has no room for a sentence.
     *
     * @param array<string,mixed> $m the minutes block
     * @return list<string> plain text
     */
    public static function minutesComparisonLines( array $m ): array {
        $share = $m['share'] ?? null;
        $cmp   = is_array( $m['comparison'] ?? null ) ? $m['comparison'] : null;
        if ( ! is_int( $share ) || $cmp === null ) return [];

        $out      = [];
        $position = is_array( $cmp['position'] ?? null ) ? $cmp['position'] : null;
        if ( $position !== null ) {
            $out[] = self::positionGroupLabel( $position ) . ': ' . self::percent( (int) ( $position['share'] ?? 0 ) );
        } elseif ( ( $cmp['player_positions'] ?? [] ) === [] ) {
            $out[] = __( 'No profile position to compare with.', 'talenttrack' );
        }
        $out[] = __( 'Team average', 'talenttrack' ) . ': ' . self::percent( (int) ( $cmp['team'] ?? 0 ) );
        return $out;
    }

    /** A whole percentage as the report prints it. Shared by screen and PDF. */
    public static function percent( int $value ): string {
        /* translators: %d: a whole percentage, e.g. 62 */
        return sprintf( __( '%d%%', 'talenttrack' ), $value );
    }

    /**
     * "Same position · Centre back, Right back · 4 players" — the position
     * group's label. Shared by screen and PDF.
     *
     * The group carries position codes, which stay the matching key in
     * shareComparison(); each is resolved to the academy's name for it here.
     *
     * @param array<string,mixed> $position a `comparison.position`
     */
    public static function positionGroupLabel( array $position ): string {
        $codes = array_map( 'strval', is_array( $position['positions'] ?? null ) ? $position['positions'] : [] );
        $names = array_values( array_filter(
            array_map( [ LabelTranslator::class, 'positionLabel' ], $codes ),
            static fn( string $name ): bool => $name !== ''
        ) );
        $count = (int) ( $position['count'] ?? 0 );
        return sprintf(
            /* translators: 1: position names, e.g. "Centre back, Right back"; 2: number of teammates in the group */
            _n( 'Same position · %1$s · %2$d player', 'Same position · %1$s · %2$d players', $count, 'talenttrack' ),
            implode( ', ', $names ),
            $count
        );
    }

    /**
     * Where the player's share sits against a comparison, in words. Shared
     * by screen and PDF.
     */
    public static function relativeShare( int $player_share, int $other ): string {
        $diff = $player_share - $other;
        if ( $diff > 0 ) {
            /* translators: %d: percentage points */
            return sprintf( _n( 'this player %d point above', 'this player %d points above', $diff, 'talenttrack' ), $diff );
        }
        if ( $diff < 0 ) {
            /* translators: %d: percentage points */
            return sprintf( _n( 'this player %d point below', 'this player %d points below', -$diff, 'talenttrack' ), -$diff );
        }
        return __( 'this player level with it', 'talenttrack' );
    }
    /**
     * A journey entry as the PDF prints it: the entry, then what it was about.
     * One method so the printed line and the layout estimate that measures it
     * cannot differ.
     *
     * @param array<string,mixed> $item a journey item.
     */
    public static function journeyPrintText( array $item ): string {
        $text     = (string) ( $item['summary'] ?? '' );
        $activity = is_array( $item['activity'] ?? null ) ? $item['activity'] : null;
        if ( $activity === null ) return $text;
        $label = JourneyActivityResolver::label( $activity );
        return $label === '' ? $text : $text . ' — ' . $label;
    }
}
