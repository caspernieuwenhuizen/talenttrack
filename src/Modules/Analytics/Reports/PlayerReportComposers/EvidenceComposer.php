<?php
namespace TT\Modules\Analytics\Reports\PlayerReportComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportBlock;

/**
 * The player report blocks that are a part of the evidence packet as it
 * stands (#4590): status, attendance, goals, PDP, matches, tests, journey,
 * injuries, behaviour, potential and thread notes. The packet is the one the
 * PDP Evidence tab reads, so these cannot disagree with it.
 */
final class EvidenceComposer {

    /** The blocks this composer answers for. */
    public const BLOCKS = [
        PlayerReportBlock::STATUS,
        PlayerReportBlock::ATTENDANCE,
        PlayerReportBlock::GOALS,
        PlayerReportBlock::PDP,
        PlayerReportBlock::MATCHES,
        PlayerReportBlock::TESTS,
        PlayerReportBlock::JOURNEY,
        PlayerReportBlock::INJURIES,
        PlayerReportBlock::BEHAVIOUR,
        PlayerReportBlock::POTENTIAL,
        PlayerReportBlock::THREAD_NOTES,
    ];

    /** @return array<string,mixed> */
    public static function compose( string $block, PlayerReportContext $ctx ): array {
        $packet = $ctx->packet() ?? [];

        switch ( $block ) {
            case PlayerReportBlock::STATUS:       return (array) ( $packet['status'] ?? [] );
            case PlayerReportBlock::ATTENDANCE:   return (array) ( $packet['attendance'] ?? [] );
            case PlayerReportBlock::GOALS:        return [ 'items' => array_values( (array) ( $packet['goals'] ?? [] ) ) ];
            case PlayerReportBlock::PDP:          return (array) ( $packet['pdp'] ?? [] );
            case PlayerReportBlock::MATCHES:      return [ 'items' => array_values( (array) ( $packet['minutes']['breakdown'] ?? [] ) ) ];
            case PlayerReportBlock::TESTS:        return [ 'items' => array_values( (array) ( $packet['tests'] ?? [] ) ) ];
            case PlayerReportBlock::JOURNEY:      return [ 'items' => self::journey( (array) ( $packet['recent_journey'] ?? [] ) ) ];
            case PlayerReportBlock::INJURIES:     return [ 'items' => array_values( (array) ( $packet['injuries'] ?? [] ) ) ];
            case PlayerReportBlock::BEHAVIOUR:    return [ 'items' => self::rows( (array) ( $packet['behaviour'] ?? [] ) ) ];
            case PlayerReportBlock::POTENTIAL:    return [ 'items' => self::rows( (array) ( $packet['potential'] ?? [] ) ) ];
            case PlayerReportBlock::THREAD_NOTES: return [ 'items' => array_values( (array) ( $packet['notes'] ?? [] ) ) ];
        }
        return [];
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
}
