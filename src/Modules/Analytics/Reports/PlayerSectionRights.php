<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Pdp\PdpAccess;
use TT\Modules\Players\Services\PotentialTrajectory;
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
 * - the potential band, in its own section and in the status verdict: the
 *   sensitive-field registry (#4694);
 * - the journey: the visibility levels and each entry's own record right;
 * - tests: the measurement levels.
 */
final class PlayerSectionRights {

    /** The development plan section as a reader without the file reads it. */
    public const PDP_NONE = [ 'available' => false, 'file' => null, 'conversations' => [], 'verdict' => null, 'last_agreed_actions' => '' ];

    /**
     * Talking points whose sources no reader right narrows. A reader who
     * does not hold every right the others are drawn from keeps only these.
     */
    public const OPEN_TALKING_POINTS = [ 'status', 'attendance', 'minutes', 'not_evaluated', 'goal_past_due', 'missing_inputs' ];

    /**
     * A section composed for one reader, cut to what these readers may read
     * now: the same rights the packet composes it with.
     *
     * @param array<string,mixed> $data the section's composer payload
     * @return array<string,mixed>
     */
    public static function cut( string $section, array $data, ReaderSet $readers, int $player_id ): array {
        $items = static fn( array $d ): array => is_array( $d['items'] ?? null ) ? array_values( $d['items'] ) : [];

        switch ( $section ) {
            case PlayerReportBlock::INJURIES:
                return self::seesInjuries( $readers ) ? $data : [ 'items' => [] ] + $data;

            case PlayerReportBlock::THREAD_NOTES:
                if ( ! self::readsThread( $readers, $player_id ) ) return [ 'items' => [] ] + $data;
                if ( self::seesPrivateNotes( $readers, $player_id ) ) return $data;
                $data['items'] = array_values( array_filter(
                    $items( $data ),
                    static fn( $n ): bool => is_array( $n ) && ( $n['visibility'] ?? '' ) === self::publicNotes()
                ) );
                return $data;

            case PlayerReportBlock::PDP:
                return self::seesPdpFile( $readers, $player_id ) || empty( $data['available'] ) ? $data : self::PDP_NONE;

            case PlayerReportBlock::RATINGS:
                if ( self::seesEvaluationNotes( $readers, $player_id ) || ! is_array( $data['evaluations'] ?? null ) ) return $data;
                $data['evaluations'] = self::withoutEvaluationNotes( array_values( array_filter( $data['evaluations'], 'is_array' ) ) );
                return $data;

            case PlayerReportBlock::POTENTIAL:
                if ( self::seesPotential( $readers, $player_id ) ) return $data;
                $data['items'] = self::withoutPotentialBand( $items( $data ) );
                return $data;

            case PlayerReportBlock::STATUS:
                return self::seesPotential( $readers, $player_id ) ? $data : self::statusWithoutPotential( $data );

            case PlayerReportBlock::JOURNEY:
                $ids  = array_map( static fn( $e ): int => is_array( $e ) ? (int) ( $e['id'] ?? 0 ) : 0, $items( $data ) );
                $seen = self::readableJourneyIds( $readers, $ids, $player_id );
                $data['items'] = array_values( array_filter( $items( $data ), static fn( $e ): bool => is_array( $e ) && isset( $seen[ (int) ( $e['id'] ?? 0 ) ] ) ) );
                return $data;

            case PlayerReportBlock::TESTS:
                $tests = self::readableTests( self::testLevels( $readers ) );
                $data['items'] = array_values( array_filter( $items( $data ), static fn( $t ): bool => is_array( $t ) && isset( $tests[ (int) ( $t['definition_id'] ?? 0 ) ] ) ) );
                return $data;

            case PlayerReportBlock::TALKING_POINTS:
                if ( self::holdsEveryLevel( $readers ) ) return $data;
                $data['items'] = array_values( array_filter(
                    $items( $data ),
                    static fn( $p ): bool => is_array( $p ) && in_array( (string) ( $p['key'] ?? '' ), self::OPEN_TALKING_POINTS, true )
                ) );
                return $data;
        }
        return $data;
    }

    /**
     * Does a snapshot note on this section reach these readers? A note is
     * about what its section holds, so on the injuries, staff notes and
     * development plan sections it reaches only a reader who holds that
     * section's right, the one whose absence empties the section in `cut()`.
     * Any other section's note follows the section itself.
     */
    public static function keepsNote( string $section, ReaderSet $readers, int $player_id ): bool {
        switch ( $section ) {
            case PlayerReportBlock::INJURIES:
                return self::seesInjuries( $readers );
            case PlayerReportBlock::THREAD_NOTES:
                return self::readsThread( $readers, $player_id ) && self::seesPrivateNotes( $readers, $player_id );
            case PlayerReportBlock::PDP:
                return self::seesPdpFile( $readers, $player_id );
        }
        return true;
    }

    /**
     * Do these readers hold every level a talking point can be drawn from:
     * the medical rung, every journey level and every measurement level?
     */
    private static function holdsEveryLevel( ReaderSet $readers ): bool {
        $journey = [ RecordVisibility::LEVEL_PUBLIC, RecordVisibility::LEVEL_COACHING_STAFF, RecordVisibility::LEVEL_MEDICAL, RecordVisibility::LEVEL_SAFEGUARDING ];
        return self::seesInjuries( $readers )
            && array_diff( $journey, self::journeyLevels( $readers ) ) === []
            && array_diff( RecordVisibility::measurementLevels(), self::testLevels( $readers ) ) === [];
    }

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

    /**
     * #4694 — does every reader see this player's potential band? The
     * `potential_judgement` rule of the sensitive-field registry.
     */
    public static function seesPotential( ReaderSet $readers, int $player_id ): bool {
        return $readers->every( static fn( int $reader ): bool => ! PotentialTrajectory::bandHiddenFrom( $reader, $player_id ) );
    }

    /**
     * Potential entries as a reader the band is hidden from reads them: when
     * each was set and by whom, with `band_hidden`, and no band or notes.
     *
     * @param array<int|string,mixed> $rows repository rows, as objects or arrays
     * @return list<array<string,mixed>>
     */
    public static function withoutPotentialBand( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            if ( is_object( $row ) ) $row = get_object_vars( $row );
            if ( ! is_array( $row ) ) continue;
            $out[] = PotentialTrajectory::withoutJudgement( $row );
        }
        return $out;
    }

    /**
     * A status verdict payload (`StatusVerdict::toArray()`) as a reader the
     * band is hidden from reads it: the rule `StatusVerdict::withoutPotential()`
     * applies, on the array a section or a snapshot holds.
     *
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public static function statusWithoutPotential( array $status ): array {
        return \TT\Infrastructure\PlayerStatus\StatusVerdict::payloadWithoutPotential( $status );
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
        return PlayerEventsRepository::readableByAll( $readers->readers, $rows, $player_id );
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
