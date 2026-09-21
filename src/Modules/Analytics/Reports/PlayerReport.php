<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\EvalCoverageService;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\Players\Services\PlayerPhoto;

/**
 * PlayerReport (#3872, epic #3871) — one player over one window, as blocks.
 *
 * Not a second assembly. Every number comes from `EvidencePacket`, the one the
 * PDP Evidence tab, the printed PDP file and the verdict screen already read
 * (#3304), so a coach and a head of academy looking at the same player on the
 * same day see the same figures whichever surface they opened. This class
 * only selects, orders and shapes.
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
     * @return Report|null Null for a player outside the current club.
     * @throws \InvalidArgumentException on unknown block keys or a malformed window.
     */
    public function forPlayer( int $player_id, string $from, string $to, array $blocks, int $viewer_user_id, ?string $audience = null ): ?array {
        $unknown = PlayerReportBlock::unknown( $blocks );
        if ( $unknown !== [] ) {
            throw new \InvalidArgumentException( 'Unknown block keys: ' . implode( ', ', $unknown ) );
        }
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

        $allowed  = PlayerReportAudience::blocksFor( $audience, $blocks );
        $selected = PlayerReportBlock::normalise( $allowed !== [] || $blocks === [] ? $allowed : [ PlayerReportBlock::LETTERHEAD ] );

        $packet = null;
        if ( array_diff( $selected, self::WITHOUT_PACKET ) !== [] ) {
            // An external audience reads tests at the public level whoever
            // composed it: a coach sending a scout or a family the report must
            // not hand over a test the academy keeps to its staff.
            $tests_viewer = PlayerReportAudience::isExternal( $audience ) ? 0 : $viewer_user_id;
            $packet = EvidencePacket::forPlayer( $player_id, $from, $to, $viewer_user_id, $tests_viewer );
            if ( $packet === null ) return null;
        }

        $data = [];
        foreach ( $selected as $block ) {
            if ( $block === PlayerReportBlock::LETTERHEAD ) {
                $data[ $block ] = $this->letterhead( $player, $from, $to );
            } elseif ( $block === PlayerReportBlock::TALKING_POINTS ) {
                $data[ $block ] = [ 'items' => PlayerTalkingPoints::derive( $packet ?? [], (int) ( $player->team_id ?? 0 ), $from, $to ) ];
            } else {
                $data[ $block ] = self::block( $block, $packet ?? [] );
            }
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
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    private static function block( string $block, array $packet ): array {
        switch ( $block ) {
            case PlayerReportBlock::STATUS:         return (array) ( $packet['status'] ?? [] );
            case PlayerReportBlock::RATINGS:        return self::ratings( (array) ( $packet['evaluations'] ?? [] ) );
            case PlayerReportBlock::ATTENDANCE:     return (array) ( $packet['attendance'] ?? [] );
            case PlayerReportBlock::MINUTES:        return self::minutes( (array) ( $packet['minutes'] ?? [] ) );
            case PlayerReportBlock::GOALS:          return [ 'items' => array_values( (array) ( $packet['goals'] ?? [] ) ) ];
            case PlayerReportBlock::PDP:            return (array) ( $packet['pdp'] ?? [] );
            case PlayerReportBlock::NOTES:          return [ 'lines' => 6 ];
            case PlayerReportBlock::MATCHES:        return [ 'items' => array_values( (array) ( $packet['minutes']['breakdown'] ?? [] ) ) ];
            case PlayerReportBlock::TESTS:          return [ 'items' => array_values( (array) ( $packet['tests'] ?? [] ) ) ];
            case PlayerReportBlock::JOURNEY:        return [ 'items' => self::journey( (array) ( $packet['recent_journey'] ?? [] ) ) ];
            case PlayerReportBlock::INJURIES:       return [ 'items' => array_values( (array) ( $packet['injuries'] ?? [] ) ) ];
            case PlayerReportBlock::BEHAVIOUR:      return [ 'items' => self::rows( (array) ( $packet['behaviour'] ?? [] ) ) ];
            case PlayerReportBlock::POTENTIAL:      return [ 'items' => self::rows( (array) ( $packet['potential'] ?? [] ) ) ];
            case PlayerReportBlock::THREAD_NOTES:   return [ 'items' => array_values( (array) ( $packet['notes'] ?? [] ) ) ];
        }
        return [];
    }

    /** @return array<string,mixed> */
    private function letterhead( object $player, string $from, string $to ): array {
        $team_id = (int) ( $player->team_id ?? 0 );
        $team    = $team_id > 0 ? QueryHelpers::get_team( $team_id ) : null;
        $dob     = (string) ( $player->date_of_birth ?? '' );
        $jersey  = $player->jersey_number ?? null;

        return [
            'player_id'     => (int) ( $player->id ?? 0 ),
            'name'          => QueryHelpers::player_display_name( $player ),
            'photo_url'     => PlayerPhoto::url( $player ),
            'team_id'       => $team_id,
            'team_name'     => $team ? (string) ( $team->name ?? '' ) : '',
            'age_group'     => $team ? (string) ( $team->age_group ?? '' ) : '',
            'head_coach'    => $team_id > 0 ? ( new EvalCoverageService() )->headCoachNameForTeam( $team_id ) : '',
            'jersey_number' => $jersey !== null && $jersey !== '' ? (int) $jersey : null,
            'birth_year'    => $dob !== '' ? (int) substr( $dob, 0, 4 ) : null,
            'from'          => $from,
            'to'            => $to,
            'generated_at'  => gmdate( 'Y-m-d H:i:s' ),
        ];
    }

    /**
     * Headline numbers and a per-category picture over the window, from the
     * packet's evaluations — the same rows the Evidence tab lists, so the
     * averages here cannot disagree with the evaluations underneath them.
     *
     * @param array<int|string,mixed> $evaluations newest first, as the packet orders them
     * @return array<string,mixed>
     */
    private static function ratings( array $evaluations ): array {
        $overall = [];
        $by_cat  = [];
        foreach ( $evaluations as $eval ) {
            if ( ! is_array( $eval ) ) continue;
            if ( isset( $eval['rating'] ) ) {
                $overall[] = [ 'date' => (string) ( $eval['eval_date'] ?? '' ), 'value' => (float) $eval['rating'] ];
            }
            foreach ( (array) ( $eval['categories'] ?? [] ) as $cat ) {
                if ( ! is_array( $cat ) || empty( $cat['is_main'] ) ) continue;
                $id = (int) ( $cat['category_id'] ?? 0 );
                if ( $id <= 0 ) continue;
                if ( ! isset( $by_cat[ $id ] ) ) {
                    // The first seen is the newest: the packet is newest-first.
                    $by_cat[ $id ] = [ 'category_id' => $id, 'label' => (string) ( $cat['label'] ?? '' ), 'latest' => (float) ( $cat['rating'] ?? 0 ), 'values' => [] ];
                }
                $by_cat[ $id ]['values'][] = (float) ( $cat['rating'] ?? 0 );
            }
        }

        $categories = [];
        foreach ( $by_cat as $cat ) {
            $categories[] = [
                'category_id' => $cat['category_id'],
                'label'       => $cat['label'],
                'latest'      => $cat['latest'],
                'average'     => round( array_sum( $cat['values'] ) / count( $cat['values'] ), 1 ),
                'count'       => count( $cat['values'] ),
            ];
        }

        $values = array_column( $overall, 'value' );

        return [
            'evaluation_count' => count( $evaluations ),
            'latest'           => $overall[0]['value'] ?? null,
            'latest_date'      => $overall[0]['date'] ?? null,
            'average'          => $values !== [] ? round( array_sum( $values ) / count( $values ), 1 ) : null,
            // Oldest first, so a trend line reads left to right.
            'series'           => array_reverse( $overall ),
            'categories'       => $categories,
            'evaluations'      => array_values( $evaluations ),
        ];
    }

    /**
     * @param array<string,mixed> $minutes
     * @return array<string,mixed>
     */
    private static function minutes( array $minutes ): array {
        $breakdown = (array) ( $minutes['breakdown'] ?? [] );
        return [
            'apps'    => (int) ( $minutes['apps'] ?? 0 ),
            'minutes' => (int) ( $minutes['minutes'] ?? 0 ),
            'matches' => count( $breakdown ),
        ];
    }

    /**
     * @param array<int|string,mixed> $events
     * @return list<array<string,mixed>>
     */
    private static function journey( array $events ): array {
        $out = [];
        foreach ( $events as $e ) {
            if ( ! is_object( $e ) ) continue;
            $out[] = [
                'id'         => (int) ( $e->id ?? 0 ),
                'event_type' => (string) ( $e->event_type ?? '' ),
                'date'       => substr( (string) ( $e->event_date ?? '' ), 0, 10 ),
                'summary'    => (string) ( $e->summary ?? '' ),
                'activity'   => isset( $e->activity ) && is_array( $e->activity ) ? $e->activity : null,
            ];
        }
        return $out;
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
        $label = self::activityLabel( $activity );
        return $label === '' ? $text : $text . ' — ' . $label;
    }

    /**
     * An activity in one line, the way a coach names it: its type, who it was
     * against (or its title when there was no opponent), and the day. Shared by
     * the screen and the PDF so they name the same match the same way.
     *
     * @param array<string,mixed> $activity a journey item's `activity`.
     */
    public static function activityLabel( array $activity ): string {
        $opponent = trim( (string) ( $activity['opponent'] ?? '' ) );
        $what     = $opponent !== ''
            /* translators: %s: the opponent of a match */
            ? sprintf( __( 'against %s', 'talenttrack' ), $opponent )
            : trim( (string) ( $activity['title'] ?? '' ) );
        $parts = array_filter( [
            trim( (string) ( $activity['type'] ?? '' ) ),
            $what,
            (string) ( $activity['date'] ?? '' ) !== '' ? \TT\Shared\Dates\TTDate::date( (string) $activity['date'] ) : '',
        ], static fn( string $s ): bool => $s !== '' );
        return implode( ' · ', $parts );
    }

    /**
     * Repository rows as plain arrays, so the payload is the same JSON whether
     * it is read by REST or a view.
     *
     * @param array<int|string,mixed> $rows
     * @return list<array<string,mixed>>
     */
    private static function rows( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            if ( is_object( $row ) ) $out[] = get_object_vars( $row );
            elseif ( is_array( $row ) ) $out[] = $row;
        }
        return $out;
    }

    private static function isDate( string $value ): bool {
        return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
    }
}
