<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #3853 — a note that does not fit is refused, never shortened.
 *
 * The note store is one row per bullet and its `body` column is
 * `VARCHAR(255)`. The repository used to write `mb_substr( $body, 0, 255 )`
 * and answer 200, so a coach who typed a paragraph instead of short points
 * lost the tail mid-word with nothing on screen to say so — and the trial or
 * selection call that later quoted the observation was quoting half a
 * sentence.
 *
 * The limit itself is deliberate and stays: a note is a bullet, countable in
 * SQL for the season trend (#2725), and every input on every surface caps
 * itself well under 255. What changed is only that going over is said out
 * loud, and that an over-long line is never split for the coach — where a
 * bullet ends is their judgement to make.
 */
final class MatchAnalysisNoteLengthTest extends WP_UnitTestCase {

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function makeMatch(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'PSV U17 — away',
            'session_date'      => '2026-09-12',
            'activity_type_key' => 'game',
            'opponent'          => 'PSV U17',
            'home_away'         => 'away',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => 'Sem',
            'last_name'  => 'Bakker',
        ] );

        return (int) $wpdb->insert_id;
    }

    /** The player has to be on the roster the analysis composes from. */
    private function markPlayed( int $activity_id, int $player_id ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'        => CurrentClub::id(),
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'record_type'    => 'actual',
            'minutes_played' => 60,
            'is_guest'       => 0,
        ] );
    }

    /** A single line of exactly `$length` characters, with no line breaks. */
    private function line( int $length ): string {
        return str_repeat( 'a', $length );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function dispatch( string $method, string $path, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $path );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /** @param array<string,mixed> $data */
    private function firstError( array $data ): array {
        $decoded = json_decode( (string) wp_json_encode( $data ), true );

        return is_array( $decoded ) ? (array) ( $decoded['errors'][0] ?? [] ) : [];
    }

    /**
     * Every note row stored for one player, in position order.
     *
     * @return list<string>
     */
    private function playerNotes( int $activity_id, int $player_id ): array {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );

        foreach ( (array) $payload['players'] as $row ) {
            if ( (int) $row['player_id'] !== $player_id ) continue;

            return array_map(
                static fn( array $item ): string => (string) $item['body'],
                array_values( (array) $row['note_items'] )
            );
        }

        return [];
    }

    /** @return list<string> */
    private function sectionNotes( int $activity_id, string $key ): array {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );

        $section = (array) ( ( (array) $payload['sections'] )[ $key ] ?? [] );

        return array_map(
            static fn( array $item ): string => (string) $item['body'],
            array_values( (array) ( $section['note_items'] ?? [] ) )
        );
    }

    // ---- the reported bug --------------------------------------------------

    /**
     * The Academy HQ's reproduction: persona marco, one 400-character
     * observation with no line breaks, answered 200 and came back ending
     * mid-token at exactly 255.
     */
    public function test_a_four_hundred_character_player_note_is_refused(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        [ $data, $status ] = $this->dispatch(
            'PUT',
            'activities/' . $activity_id . '/analysis/players/' . $player_id,
            [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT, 'note' => $this->line( 400 ) ]
        );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame( [ 'notes[0]' ], $error['details']['fields'] ?? null );
        $this->assertSame( MatchAnalysisWriter::NOTE_MAX, $error['details']['max_length'] ?? null );
        $this->assertStringContainsString( '255', (string) ( $error['message'] ?? '' ) );

        $this->assertSame( [], $this->playerNotes( $activity_id, $player_id ) );
    }

    /**
     * Nothing at all is stored — not the short notes that shared the
     * request with the long one. Half a write-up is worse than none,
     * because nothing on screen says which half landed.
     */
    public function test_a_refused_player_write_stores_none_of_its_notes(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        [ , $status ] = $this->dispatch(
            'PUT',
            'activities/' . $activity_id . '/analysis/players/' . $player_id,
            [
                'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT,
                'notes'  => [
                    [ 'body' => 'Sterk aan de bal.' ],
                    [ 'body' => $this->line( 300 ) ],
                ],
            ]
        );

        $this->assertSame( 400, $status );
        $this->assertSame( [], $this->playerNotes( $activity_id, $player_id ) );
    }

    public function test_a_note_of_exactly_the_maximum_is_stored_whole(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $note = $this->line( MatchAnalysisWriter::NOTE_MAX );

        [ , $status ] = $this->dispatch(
            'PUT',
            'activities/' . $activity_id . '/analysis/players/' . $player_id,
            [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT, 'note' => $note ]
        );

        $this->assertSame( 200, $status );

        $stored = $this->playerNotes( $activity_id, $player_id );
        $this->assertSame( [ $note ], $stored );
        $this->assertSame( MatchAnalysisWriter::NOTE_MAX, mb_strlen( $stored[0] ) );
    }

    // ---- the per-section route ---------------------------------------------

    public function test_a_long_section_bullet_is_refused_and_nothing_is_written(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->dispatch(
            'PUT',
            'activities/' . $activity_id . '/analysis/sections/' . MethodologyEnums::FUNCTION_AANVALLEN,
            [
                'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                'notes'  => [ 'Derde man liep goed door.', $this->line( 400 ) ],
            ]
        );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame( [ 'notes[1]' ], $error['details']['fields'] ?? null );

        $this->assertSame( [], $this->sectionNotes( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN ) );
    }

    // ---- the whole-document PUT --------------------------------------------

    /**
     * The autosaving form posts the whole document, so the refusal has to
     * reach it too — this is the route a coach's phone actually calls.
     */
    public function test_the_whole_document_put_refuses_a_long_bullet_by_path(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->dispatch( 'PUT', 'activities/' . $activity_id . '/analysis', [
            'summary'  => 'Analysetest HQ',
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [
                    'rating' => MatchAnalysisEnums::RATING_MIXED,
                    'notes'  => [ [ 'body' => $this->line( 400 ) ] ],
                ],
            ],
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame(
            [ 'sections[' . MethodologyEnums::FUNCTION_VERDEDIGEN . '].notes[0]' ],
            $error['details']['fields'] ?? null
        );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );
        $this->assertSame( '', (string) $payload['summary'], 'the summary is not written either' );
    }

    public function test_the_whole_document_put_names_a_long_player_note_by_path(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        [ $data, $status ] = $this->dispatch( 'PUT', 'activities/' . $activity_id . '/analysis', [
            'players' => [
                (string) $player_id => [
                    'marker' => MatchAnalysisEnums::MARKER_AS_EXPECTED,
                    'notes'  => [ [ 'body' => $this->line( 256 ) ] ],
                ],
            ],
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame(
            [ 'players[' . $player_id . '].notes[0]' ],
            $this->firstError( $data )['details']['fields'] ?? null
        );
    }

    // ---- line splitting is unchanged ---------------------------------------

    /**
     * A blob with line breaks is still one note per line, and each line is
     * measured on its own. The coach chose where the bullets end; the
     * server does not get to choose for them, either by splitting a long
     * line or by cutting it.
     */
    public function test_a_multi_line_note_is_split_and_each_line_is_measured(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        [ , $status ] = $this->dispatch(
            'PUT',
            'activities/' . $activity_id . '/analysis/players/' . $player_id,
            [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT, 'note' => "Eerste punt.\nTweede punt." ]
        );

        $this->assertSame( 200, $status );
        $this->assertSame( [ 'Eerste punt.', 'Tweede punt.' ], $this->playerNotes( $activity_id, $player_id ) );
    }

    public function test_only_the_line_that_is_too_long_is_named(): void {
        $this->assertSame(
            [ 1 ],
            MatchAnalysisWriter::overlongNotes( "Kort.\n" . $this->line( 400 ) . "\nOok kort." )
        );
    }

    public function test_a_blank_line_is_dropped_before_the_lines_are_numbered(): void {
        $this->assertSame(
            [ 1 ],
            MatchAnalysisWriter::overlongNotes( "Kort.\n\n" . $this->line( 300 ) )
        );
    }
}
