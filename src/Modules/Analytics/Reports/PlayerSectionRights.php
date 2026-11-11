<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Pdp\PdpAccess;
use TT\Modules\Threads\Domain\ThreadAccess;
use TT\Modules\Threads\Domain\ThreadVisibility;

/**
 * PlayerSectionRights (#4961) — the per-reader rights inside the player
 * report's sections, in one place: the evidence packet asks them when it
 * composes a section for its readers, and a Studio snapshot asks the same
 * ones when it shows a section frozen for someone else. Snapshots show each
 * reader only the sections they may read now.
 *
 * - injuries: the medical rung;
 * - staff notes: the thread's read, and its private notes;
 * - the development plan: the PDP file;
 * - evaluation notes: the sensitive-field registry;
 * - the journey: the visibility levels and each entry's own record right;
 * - tests: the measurement levels.
 */
final class PlayerSectionRights {

    /** The development plan section as a reader without the file reads it. */
    public const PDP_NONE = [ 'available' => false, 'file' => null, 'conversations' => [], 'verdict' => null, 'last_agreed_actions' => '' ];

    public static function seesInjuries( ReaderSet $readers ): bool {
        return $readers->seesMedical();
    }

    public static function readsThread( ReaderSet $readers, int $player_id ): bool {
        return $readers->every( static fn( int $reader ): bool => ThreadAccess::canRead( 'player', $player_id, $reader ) );
    }

    public static function seesPrivateNotes( ReaderSet $readers, int $player_id ): bool {
        return $readers->every( static fn( int $reader ): bool => ThreadAccess::canSeePrivate( 'player', $player_id, $reader ) );
    }

    public static function seesPdpFile( ReaderSet $readers, int $player_id ): bool {
        return $readers->every( static fn( int $reader ): bool => PdpAccess::canSeeFile( $reader, $player_id ) );
    }

    public static function seesEvaluationNotes( ReaderSet $readers, int $player_id ): bool {
        return $readers->every( static fn( int $reader ): bool => ! RecordRedactor::isMasked( 'evaluations', 'notes', $reader, $player_id ) );
    }

    /**
     * Evaluations without the coach's notes, saying where there were some,
     * as a reader who may not see them reads them.
     *
     * @param list<array<string,mixed>> $evaluations
     * @return list<array<string,mixed>>
     */
    public static function withoutEvaluationNotes( array $evaluations ): array {
        $out = [];
        foreach ( $evaluations as $evaluation ) {
            $had = trim( (string) ( $evaluation['notes'] ?? '' ) ) !== '';
            unset( $evaluation['notes'] );
            if ( $had ) $evaluation['notes_hidden'] = true;
            $out[] = $evaluation;
        }
        return $out;
    }

    /** @return list<string> the journey levels every reader holds */
    public static function journeyLevels( ReaderSet $readers ): array {
        return $readers->journeyLevels();
    }

    /**
     * Journey entries every reader may read: their level is one each reader
     * holds, and each passes its own record's right.
     *
     * @param list<object> $rows player event rows with `visibility` and `event_type`
     * @return list<object>
     */
    public static function readableJourney( ReaderSet $readers, array $rows, int $player_id ): array {
        $levels = self::journeyLevels( $readers );
        $rows   = array_values( array_filter( $rows, static fn( object $row ): bool => in_array( (string) ( $row->visibility ?? '' ), $levels, true ) ) );
        return array_values( PlayerEventsRepository::readableByAll( $readers->readers, $rows, $player_id ) );
    }

    /**
     * The journey entries of these ids every reader may read now, by id.
     *
     * @param list<int> $ids
     * @return array<int,true>
     */
    public static function readableJourneyIds( ReaderSet $readers, array $ids, int $player_id ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_type, visibility, source_entity_type, source_entity_id FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND club_id = %d AND id IN ($in)",
            ...array_merge( [ $player_id, CurrentClub::id() ], $ids )
        ) );
        $out = [];
        foreach ( self::readableJourney( $readers, is_array( $rows ) ? array_values( $rows ) : [], $player_id ) as $row ) {
            $out[ (int) ( $row->id ?? 0 ) ] = true;
        }
        return $out;
    }

    /** @return list<string> the measurement levels every reader holds */
    public static function testLevels( ReaderSet $readers ): array {
        return $readers->testLevels();
    }

    /**
     * The tests a reader at these levels reads, by definition id: the
     * definitions the profile lists at them.
     *
     * @param list<string> $levels
     * @return array<int,true>
     */
    public static function readableTests( array $levels ): array {
        $out = [];
        foreach ( ( new MeasurementDefinitionsRepository() )->listActiveForProfile( $levels ) as $definition ) {
            $vars = get_object_vars( $definition );
            if ( is_numeric( $vars['id'] ?? null ) ) $out[ (int) $vars['id'] ] = true;
        }
        return $out;
    }

    /** The notes visibility every reader of the thread reads. */
    public static function publicNotes(): string {
        return ThreadVisibility::PUBLIC_LEVEL;
    }
}
