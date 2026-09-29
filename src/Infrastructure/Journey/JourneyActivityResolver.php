<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Shared\Dates\TTDate;

/**
 * JourneyActivityResolver — the activity a journey entry was written about.
 *
 * A match-analysis observation names its match in the payload, and an
 * evaluation made for a training or a match carries it on the evaluation
 * row. Every surface that shows such an entry says which activity it was
 * about, so the lookup and the wording live here, once: the timeline and
 * its REST payload, the PDP evidence panel and the player report all read
 * the same answer.
 *
 * Each row gains `activity`, `{ id, type, title, opponent, date, analysis_id }`
 * or null. `analysis_id` is set on a match observation only, from its
 * payload. An activity in the recycle bin is left out; an archived one still
 * happened, so it stays.
 */
final class JourneyActivityResolver {

    /**
     * Rows with their activity attached. Two lookups for the whole list, not
     * one per entry. The rows keep every column they came with.
     *
     * @param list<object> $rows journey rows carrying `event_type`, `payload`
     *        and, for evaluations, `source_entity_type` / `source_entity_id`.
     * @return list<object>
     */
    public static function withActivities( array $rows, int $club_id ): array {
        $refs = self::references( $rows );

        $activity_of = $refs['activity'];
        if ( $refs['evaluation'] !== [] ) {
            $by_eval = self::activitiesOfEvaluations( array_values( array_unique( $refs['evaluation'] ) ), $club_id );
            foreach ( $refs['evaluation'] as $i => $eval_id ) {
                if ( isset( $by_eval[ $eval_id ] ) ) $activity_of[ $i ] = $by_eval[ $eval_id ];
            }
        }

        $activities = $activity_of !== []
            ? self::activities( array_values( array_unique( $activity_of ) ), $club_id )
            : [];

        $out = [];
        foreach ( array_values( $rows ) as $i => $row ) {
            $fields   = (array) $row;
            $activity = isset( $activity_of[ $i ] ) ? ( $activities[ $activity_of[ $i ] ] ?? null ) : null;
            if ( $activity !== null ) {
                $activity['analysis_id'] = $refs['analysis'][ $i ] ?? null;
            }
            $fields['activity'] = $activity;
            $out[] = (object) $fields;
        }
        return $out;
    }

    /**
     * What each row points at, read from the row alone: the activity a match
     * observation names, the evaluation an evaluation entry was written for,
     * and the analysis an observation came from. Keyed by the row's position.
     *
     * @param list<object> $rows
     * @return array{activity: array<int,int>, evaluation: array<int,int>, analysis: array<int,int>}
     */
    public static function references( array $rows ): array {
        $activity   = [];
        $evaluation = [];
        $analysis   = [];
        foreach ( array_values( $rows ) as $i => $row ) {
            $payload = json_decode( (string) ( $row->payload ?? '' ), true );
            $payload = is_array( $payload ) ? $payload : [];
            $type    = (string) ( $row->event_type ?? '' );

            if ( $type === JourneyEventType::MATCH_OBSERVED ) {
                if ( (int) ( $payload['activity_id'] ?? 0 ) > 0 ) $activity[ $i ] = (int) $payload['activity_id'];
                if ( (int) ( $payload['analysis_id'] ?? 0 ) > 0 ) $analysis[ $i ] = (int) $payload['analysis_id'];
            } elseif ( $type === JourneyEventType::EVALUATION_COMPLETED ) {
                $eval_id = (string) ( $row->source_entity_type ?? '' ) === 'evaluation'
                    ? (int) ( $row->source_entity_id ?? 0 )
                    : (int) ( $payload['evaluation_id'] ?? 0 );
                if ( $eval_id > 0 ) $evaluation[ $i ] = $eval_id;
            }
        }
        return [ 'activity' => $activity, 'evaluation' => $evaluation, 'analysis' => $analysis ];
    }

    /**
     * An activity in one line, the way a coach names it: its type, who it was
     * against (or its title when there was no opponent), and the day. Every
     * surface, the PDF included, names the same match the same way.
     *
     * @param array<string,mixed> $activity an entry's `activity`.
     */
    public static function label( array $activity ): string {
        $opponent = trim( (string) ( $activity['opponent'] ?? '' ) );
        $what     = $opponent !== ''
            /* translators: %s: the opponent of a match */
            ? sprintf( __( 'against %s', 'talenttrack' ), $opponent )
            : trim( (string) ( $activity['title'] ?? '' ) );
        $parts = array_filter( [
            trim( (string) ( $activity['type'] ?? '' ) ),
            $what,
            (string) ( $activity['date'] ?? '' ) !== '' ? TTDate::date( (string) $activity['date'] ) : '',
        ], static fn( string $s ): bool => $s !== '' );
        return implode( ' · ', $parts );
    }

    /**
     * @param list<int> $ids
     * @return array<int,int> evaluation id => activity id
     */
    private static function activitiesOfEvaluations( array $ids, int $club_id ): array {
        global $wpdb;
        $in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $pairs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, activity_id FROM {$wpdb->prefix}tt_evaluations WHERE club_id = %d AND id IN ($in)",
            ...array_merge( [ $club_id ], $ids )
        ) );
        $out = [];
        foreach ( is_array( $pairs ) ? $pairs : [] as $pair ) {
            if ( (int) ( $pair->activity_id ?? 0 ) > 0 ) $out[ (int) $pair->id ] = (int) $pair->activity_id;
        }
        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{id:int, type:string, title:string, opponent:string, date:string}>
     */
    private static function activities( array $ids, int $club_id ): array {
        global $wpdb;
        $in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $list = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, session_date, activity_type_key, opponent
               FROM {$wpdb->prefix}tt_activities
              WHERE club_id = %d AND trashed_at IS NULL AND id IN ($in)",
            ...array_merge( [ $club_id ], $ids )
        ) );
        $out = [];
        foreach ( is_array( $list ) ? $list : [] as $a ) {
            $key   = (string) ( $a->activity_type_key ?? '' );
            $label = $key !== '' ? (string) LabelTranslator::activityType( $key ) : '';
            // A type the operator added without a translation still reads,
            // as it does in the activity reader.
            if ( $label === '' && $key !== '' ) $label = ucfirst( str_replace( '_', ' ', $key ) );
            $out[ (int) $a->id ] = [
                'id'       => (int) $a->id,
                'type'     => $label,
                'title'    => (string) ( $a->title ?? '' ),
                'opponent' => (string) ( $a->opponent ?? '' ),
                'date'     => (string) ( $a->session_date ?? '' ),
            ];
        }
        return $out;
    }
}
