<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisWriter;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #3091 — the + / − on a note.
 *
 * What is worth proving is the set of promises that would otherwise break
 * quietly:
 *
 *   - a mark survives a save and comes back on the same note, in order;
 *   - neutral is a real state and stays one — nothing is coerced;
 *   - a valence the server does not recognise is stored as neutral, never
 *     as itself, however it was posted;
 *   - the marks are countable in SQL, which is the whole reason this is a
 *     table rather than a prefix convention;
 *   - a player can hold a plus and a minus at once and still gets ONE
 *     timeline entry, with both notes on it;
 *   - a client that has never heard of valence can still write a note.
 */
final class MatchAnalysisNoteValenceTest extends WP_UnitTestCase {

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        do_action( 'rest_api_init' );
    }

    public function test_a_marked_bullet_comes_back_marked_and_in_order(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_MIXED,
                    'notes'  => [
                        [ 'body' => 'Snel omschakelen na balverlies', 'valence' => 'plus' ],
                        [ 'body' => 'Te weinig breedte links', 'valence' => 'minus' ],
                        [ 'body' => 'Speelde 4-3-3', 'valence' => '' ],
                    ],
                ],
            ],
        ] );

        $items = $this->sectionItems( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN );

        $this->assertSame(
            [
                [ 'valence' => 'plus',  'body' => 'Snel omschakelen na balverlies' ],
                [ 'valence' => 'minus', 'body' => 'Te weinig breedte links' ],
                [ 'valence' => '',      'body' => 'Speelde 4-3-3' ],
            ],
            $items,
            'the marks stay on their own sentences, in the order they were written'
        );
    }

    /**
     * The point of the table. A trend report that had to regex free text is
     * one nobody could trust.
     */
    public function test_the_marks_are_countable_in_sql(): void {
        global $wpdb;

        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_MIXED,
                    'notes'  => [
                        [ 'body' => 'Een', 'valence' => 'plus' ],
                        [ 'body' => 'Twee', 'valence' => 'plus' ],
                        [ 'body' => 'Drie', 'valence' => 'minus' ],
                    ],
                ],
            ],
        ] );

        $counts = $wpdb->get_results(
            "SELECT valence, COUNT(*) AS n
               FROM {$wpdb->prefix}tt_match_analysis_notes
              WHERE section_key = 'aanvallen'
              GROUP BY valence",
            OBJECT_K
        );

        $this->assertSame( 2, (int) $counts['plus']->n );
        $this->assertSame( 1, (int) $counts['minus']->n );
    }

    /** An unknown valence is neutral, not stored as itself. */
    public function test_an_unknown_valence_is_refused(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [
                    'rating' => MatchAnalysisEnums::RATING_NEEDS_WORK,
                    'notes'  => [ [ 'body' => 'Lijn zakte te vroeg', 'valence' => 'brilliant' ] ],
                ],
            ],
        ] );

        $items = $this->sectionItems( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN );

        $this->assertSame( '', $items[0]['valence'] );
        $this->assertSame( 'Lijn zakte te vroeg', $items[0]['body'] );
    }

    /**
     * A leading hyphen is a normal way to write, and used to be exactly what
     * a prefix convention would have swallowed.
     */
    public function test_a_hyphen_in_the_text_is_left_alone(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => null,
                    'notes'  => [ [ 'body' => '- niet doorgeschoven bij de tweede bal', 'valence' => 'minus' ] ],
                ],
            ],
        ] );

        $items = $this->sectionItems( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN );

        $this->assertSame( '- niet doorgeschoven bij de tweede bal', $items[0]['body'] );
        $this->assertSame( 'minus', $items[0]['valence'] );
    }

    /** Clearing the notes and the rating still leaves no section row. */
    public function test_clearing_every_note_removes_the_section_row(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [
                    'rating' => MatchAnalysisEnums::RATING_NEEDS_WORK,
                    'notes'  => [ [ 'body' => 'Iets', 'valence' => 'minus' ] ],
                ],
            ],
        ] );
        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [
                    'rating' => '',
                    'notes'  => [ [ 'body' => '', 'valence' => 'minus' ] ],
                ],
            ],
        ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );

        $this->assertSame( [], $repo->listSections( (int) $analysis->id ) );
        $this->assertSame( [], $repo->sectionNotes( (int) $analysis->id ) );
    }

    /**
     * The case this issue exists for, and the rule that keeps the timeline
     * honest: two notes, one entry.
     */
    public function test_a_player_holds_a_plus_and_a_minus_in_one_entry(): void {
        global $wpdb;

        $activity_id = $this->makeMatch( '2026-08-15' );
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [
                $player_id => [
                    'marker' => MatchAnalysisEnums::MARKER_AS_EXPECTED,
                    'notes'  => [
                        [ 'body' => 'Sterk in de duels', 'valence' => 'plus' ],
                        [ 'body' => 'Twee keer zijn man kwijt bij corners', 'valence' => 'minus' ],
                    ],
                ],
            ],
        ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );
        $notes    = $repo->playerNotes( (int) $analysis->id )[ $player_id ];

        $this->assertCount( 2, $notes );
        $this->assertSame( 'plus', $notes[0]['valence'] );
        $this->assertSame( 'minus', $notes[1]['valence'] );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            JourneyEventType::MATCH_OBSERVED
        ) );

        $this->assertCount( 1, $events, 'one match is one entry on a player timeline' );
        $this->assertStringContainsString( 'Sterk in de duels', (string) $events[0]->summary );
        $this->assertStringContainsString( 'zijn man kwijt', (string) $events[0]->summary );
    }

    /** A third note is dropped rather than trusted. */
    public function test_a_player_cannot_be_given_more_notes_than_the_surface_offers(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [
                $player_id => [
                    'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT,
                    'notes'  => [
                        [ 'body' => 'Een', 'valence' => 'plus' ],
                        [ 'body' => 'Twee', 'valence' => '' ],
                        [ 'body' => 'Drie', 'valence' => 'minus' ],
                    ],
                ],
            ],
        ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );

        $this->assertCount(
            MatchAnalysisRepository::PLAYER_NOTES,
            $repo->playerNotes( (int) $analysis->id )[ $player_id ]
        );
    }

    /**
     * A client written before this shipped sends a flat list, or a single
     * blob of text. Both still write, as unmarked notes — failing them would
     * punish a caller for not knowing about a field it cannot use.
     */
    public function test_the_older_note_shapes_still_write(): void {
        $flat = MatchAnalysisWriter::cleanNoteItems( [ 'Een', '', 'Twee' ] );
        $this->assertSame(
            [
                [ 'valence' => '', 'body' => 'Een' ],
                [ 'valence' => '', 'body' => 'Twee' ],
            ],
            $flat
        );

        $blob = MatchAnalysisWriter::cleanNoteItems( "Een\n\nTwee" );
        $this->assertSame( $flat, $blob );
    }

    // ---- helpers ----------------------------------------------------------

    /** @return list<array{valence:string, body:string}> */
    private function sectionItems( int $activity_id, string $section_key ): array {
        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );
        $this->assertNotNull( $analysis );

        return $repo->sectionNotes( (int) $analysis->id )[ $section_key ] ?? [];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function put( int $activity_id, array $body ): array {
        $r = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/analysis' );
        $r->set_header( 'content-type', 'application/json' );
        $r->set_body( (string) wp_json_encode( $body ) );

        return (array) rest_get_server()->dispatch( $r )->get_data();
    }

    private function makeMatch( string $date = '2026-08-15' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'Ajax U17 — away',
            'session_date'      => $date,
            'activity_type_key' => 'game',
            'opponent'          => 'Ajax U17',
            'home_away'         => 'away',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer( string $first = 'Sem', string $last = 'Bakker' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => $first,
            'last_name'  => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function markPlayed( int $activity_id, int $player_id, int $minutes = 60 ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'        => CurrentClub::id(),
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
            'is_guest'       => 0,
        ] );
    }
}
