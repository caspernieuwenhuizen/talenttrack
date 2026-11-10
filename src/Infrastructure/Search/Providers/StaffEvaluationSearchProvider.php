<?php
namespace TT\Infrastructure\Search\Providers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Search\SearchText;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Modules\Activities\ActivityAccess;

/**
 * Evaluations for staff (#4936): the evaluations of the players the viewer
 * reads them for, academy-wide, by team, or a scout's linked players
 * (`sectionScope( …, 'evaluations' )`). Confirmed by the evaluation
 * detail's own pair, `canViewPlayer()` and the evaluations section read.
 *
 * Found by the player's name, the evaluation type in the viewer's language
 * or as stored, the opponent, the date, and the staff notes. The notes
 * follow the sensitive-field registry (`RecordRedactor`, `evaluation_notes`):
 * they are matched only on the players whose notes the viewer reads, and a
 * hit carries an excerpt of them only then. The read-only observer finds an
 * evaluation by player, type and date, never by a word in its notes.
 */
final class StaffEvaluationSearchProvider extends StaffRecordSearchProvider {

    /** @var array<int, array{sql:string, params:list<mixed>}> user id => where the notes may be matched */
    private array $notes = [];

    public function kind(): string { return 'staff_evaluation'; }

    protected function alias(): string { return 'e'; }

    protected function from(): string {
        global $wpdb;
        return "FROM {$wpdb->prefix}tt_evaluations e
                JOIN {$wpdb->prefix}tt_players p ON p.id = e.player_id";
    }

    protected function columns(): string {
        return 'e.id, e.player_id, e.eval_date, e.eval_type_id, e.opponent, e.notes, p.first_name, p.last_name, p.team_id, p.wp_user_id';
    }

    protected function live(): string {
        return ArchiveRepository::filterClause( 'active', 'e' );
    }

    protected function dateColumn(): string { return 'e.eval_date'; }

    protected function tieBreak(): string { return 'e.eval_date DESC, e.id DESC'; }

    protected function scope( int $user_id ): ?array {
        return self::sectionScope( $user_id, 'evaluations' );
    }

    protected function tokenText( string $token, int $user_id ): array {
        $parts = [];
        $types = [];
        foreach ( EvaluationSearchProvider::typeNames() as $id => $names ) {
            if ( strpos( SearchText::fold( $names[0] . ' ' . $names[1] ), $token ) !== false ) $types[] = (int) $id;
        }
        if ( $types !== [] ) {
            $parts[] = [ 'sql' => 'e.eval_type_id IN (' . implode( ',', array_fill( 0, count( $types ), '%d' ) ) . ')', 'params' => $types ];
        }
        $parts[] = self::likeSql( 'e.opponent', $token );

        $notes = $this->notesScope( $user_id );
        if ( $notes['sql'] !== '1=0' ) {
            $like    = self::likeSql( 'e.notes', $token );
            $parts[] = [ 'sql' => "( {$notes['sql']} AND {$like['sql']} )", 'params' => array_merge( $notes['params'], $like['params'] ) ];
        }
        return self::anyOf( $parts );
    }

    /**
     * Where the viewer reads the staff notes, as a condition on `p`: every
     * player for a reader of them everywhere; otherwise the teams on which
     * they read them (`RecordRedactor::readsOnTeams()`) and the linked
     * players they read them on. Nothing for anyone else.
     *
     * @return array{sql:string, params:list<mixed>}
     */
    private function notesScope( int $user_id ): array {
        if ( isset( $this->notes[ $user_id ] ) ) return $this->notes[ $user_id ];
        if ( RecordRedactor::readsEverywhere( 'evaluations', 'notes', $user_id ) ) {
            return $this->notes[ $user_id ] = [ 'sql' => '1=1', 'params' => [] ];
        }

        $teams = [];
        $candidates = array_unique( array_merge( AuthorizationService::playerReadScope( $user_id )['team_ids'], ActivityAccess::coachedTeamIds( $user_id ) ) );
        foreach ( $candidates as $team_id ) {
            if ( RecordRedactor::readsOnTeams( 'evaluations', 'notes', $user_id, [ (int) $team_id ] ) ) $teams[] = (int) $team_id;
        }
        $players = [];
        foreach ( AuthorizationService::scoutLinkedReadablePlayerIds( $user_id, 'evaluations' ) as $player_id ) {
            if ( ! RecordRedactor::isMasked( 'evaluations', 'notes', $user_id, (int) $player_id ) ) $players[] = (int) $player_id;
        }

        $parts = [];
        if ( $teams !== [] ) {
            $parts[] = [ 'sql' => 'p.team_id IN (' . implode( ',', array_fill( 0, count( $teams ), '%d' ) ) . ')', 'params' => $teams ];
        }
        if ( $players !== [] ) {
            $parts[] = [ 'sql' => 'p.id IN (' . implode( ',', array_fill( 0, count( $players ), '%d' ) ) . ')', 'params' => $players ];
        }
        return $this->notes[ $user_id ] = self::anyOf( $parts );
    }

    protected function toHit( object $row, int $user_id ): array {
        $r         = (array) $row;
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $team_id   = (int) ( $r['team_id'] ?? 0 );
        $names     = EvaluationSearchProvider::typeNames()[ (int) ( $r['eval_type_id'] ?? 0 ) ] ?? [ '', '' ];
        $type      = $names[0] !== '' ? $names[0] : __( 'Evaluation', 'talenttrack' );
        $date      = (string) ( $r['eval_date'] ?? '' );
        $hit       = [
            'type'      => 'staff_evaluation',
            'id'        => (int) ( $r['id'] ?? 0 ),
            'label'     => self::playerName( $row ),
            'sublabel'  => implode( ' · ', array_filter( [ $type, $date !== '' ? \TT\Shared\Dates\TTDate::date( $date ) : '', (string) ( $r['opponent'] ?? '' ) ] ) ),
            'url'       => $this->detailUrl( 'evaluations', (int) ( $r['id'] ?? 0 ) ),
            'score'     => 0,
            'date'      => $date,
            'player_id' => $player_id,
            'actions'   => [],
            // Internal: read by confirm(), never part of an item.
            'tt_row'    => [ 'team_id' => $team_id, 'wp_user_id' => (int) ( $r['wp_user_id'] ?? 0 ), 'notes' => (string) ( $r['notes'] ?? '' ) ],
        ];
        if ( $team_id > 0 ) $hit['team_id'] = $team_id;
        return $hit;
    }

    protected function confirmHits( int $user_id, array $hits ): array {
        $kept = self::keepReadable( $user_id, $hits, 'evaluations' );
        if ( $kept === [] ) return [];

        $team_by_player = [];
        foreach ( $kept as $hit ) $team_by_player[ (int) $hit['player_id'] ] = (int) ( $hit['tt_row']['team_id'] ?? 0 );
        RecordRedactor::prime( $user_id, $team_by_player );

        foreach ( $kept as $i => $hit ) {
            $notes = (string) ( $hit['tt_row']['notes'] ?? '' );
            if ( $notes === '' || RecordRedactor::isMasked( 'evaluations', 'notes', $user_id, (int) $hit['player_id'] ) ) continue;
            $excerpt = self::excerpt( $notes );
            if ( $excerpt !== '' ) $kept[ $i ]['excerpt'] = $excerpt;
        }
        return $kept;
    }
}
