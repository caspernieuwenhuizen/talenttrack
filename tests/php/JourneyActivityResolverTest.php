<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\JourneyActivityResolver;
use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Infrastructure\REST\PlayerJourneyRestController;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\JourneyActivityLink;

/**
 * #4181 — one resolver says which activity a journey entry was about, and one
 * label names it, on every surface that shows the entry.
 *
 * Pinned: a match observation resolves through its payload and carries its
 * analysis; an evaluation resolves through the evaluation row; an activity in
 * the recycle bin resolves to nothing while an archived one stays; another
 * club's activity never resolves; the timeline and its REST payload carry the
 * activity; and a reader who may open neither the analysis nor the activity
 * gets the label without a link.
 */
final class JourneyActivityResolverTest extends WP_UnitTestCase {

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'first_name' => 'Journey', 'last_name' => 'Player', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_references_are_read_from_the_row(): void {
        $refs = JourneyActivityResolver::references( [
            (object) [ 'event_type' => 'match_observed', 'payload' => '{"activity_id":7,"analysis_id":3}' ],
            (object) [ 'event_type' => 'evaluation_completed', 'payload' => '[]', 'source_entity_type' => 'evaluation', 'source_entity_id' => 11 ],
            (object) [ 'event_type' => 'evaluation_completed', 'payload' => '{"evaluation_id":12}', 'source_entity_type' => 'other', 'source_entity_id' => 99 ],
            (object) [ 'event_type' => 'note_added', 'payload' => '{"activity_id":8}' ],
            (object) [ 'event_type' => 'match_observed', 'payload' => 'not json' ],
        ] );

        $this->assertSame( [ 0 => 7 ], $refs['activity'], 'only a match observation names its activity in the payload' );
        $this->assertSame( [ 0 => 3 ], $refs['analysis'] );
        $this->assertSame( [ 1 => 11, 2 => 12 ], $refs['evaluation'], 'the source entity first, the payload when the source is something else' );
    }

    public function test_the_label_names_the_opponent_or_the_title(): void {
        $match = JourneyActivityResolver::label( [ 'type' => 'Match', 'title' => 'Cup round', 'opponent' => 'Willem II', 'date' => '' ] );
        $this->assertSame( 'Match · against Willem II', $match );

        $training = JourneyActivityResolver::label( [ 'type' => 'Training', 'title' => 'Finishing', 'opponent' => '', 'date' => '' ] );
        $this->assertSame( 'Training · Finishing', $training );

        $this->assertSame( '', JourneyActivityResolver::label( [] ) );
    }

    public function test_rows_carry_their_activity_and_analysis(): void {
        $match      = $this->activity( 'Cup round', 'Willem II' );
        $training   = $this->activity( 'Finishing', '' );
        $evaluation = $this->evaluation( $training );

        $rows = JourneyActivityResolver::withActivities( [
            (object) [ 'id' => 1, 'event_type' => 'match_observed', 'payload' => (string) wp_json_encode( [ 'activity_id' => $match, 'analysis_id' => 5 ] ) ],
            (object) [ 'id' => 2, 'event_type' => 'evaluation_completed', 'payload' => '[]', 'source_entity_type' => 'evaluation', 'source_entity_id' => $evaluation ],
            (object) [ 'id' => 3, 'event_type' => 'note_added', 'payload' => '[]' ],
        ], CurrentClub::id() );

        $this->assertSame( $match, $rows[0]->activity['id'] ?? null );
        $this->assertSame( 'Willem II', $rows[0]->activity['opponent'] ?? null );
        $this->assertSame( 5, $rows[0]->activity['analysis_id'] ?? null, 'an observation carries the analysis it came from' );
        $this->assertSame( $training, $rows[1]->activity['id'] ?? null, 'an evaluation names the activity it was made for' );
        $this->assertNull( $rows[1]->activity['analysis_id'] ?? null );
        $this->assertNull( $rows[2]->activity, 'an entry about no activity carries none' );
        $this->assertSame( '[]', $rows[2]->payload, 'the rows keep their own columns' );
    }

    public function test_a_trashed_activity_resolves_to_nothing_and_an_archived_one_stays(): void {
        global $wpdb;
        $trashed  = $this->activity( 'Binned', 'Ajax' );
        $archived = $this->activity( 'Old', 'PSV' );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'trashed_at' => '2020-01-01 00:00:00' ], [ 'id' => $trashed ] );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'archived_at' => '2020-01-01 00:00:00' ], [ 'id' => $archived ] );

        $rows = JourneyActivityResolver::withActivities( [
            (object) [ 'event_type' => 'match_observed', 'payload' => (string) wp_json_encode( [ 'activity_id' => $trashed ] ) ],
            (object) [ 'event_type' => 'match_observed', 'payload' => (string) wp_json_encode( [ 'activity_id' => $archived ] ) ],
        ], CurrentClub::id() );

        $this->assertNull( $rows[0]->activity );
        $this->assertSame( $archived, $rows[1]->activity['id'] ?? null );
    }

    public function test_another_clubs_activity_never_resolves(): void {
        global $wpdb;
        $foreign = $this->activity( 'Elsewhere', 'NAC' );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'club_id' => CurrentClub::id() + 1 ], [ 'id' => $foreign ] );

        $rows = JourneyActivityResolver::withActivities( [
            (object) [ 'event_type' => 'match_observed', 'payload' => (string) wp_json_encode( [ 'activity_id' => $foreign ] ) ],
        ], CurrentClub::id() );

        $this->assertNull( $rows[0]->activity );
    }

    public function test_the_timeline_and_its_rest_payload_carry_the_activity(): void {
        $match = $this->activity( 'Cup round', 'Willem II' );
        $this->event( 'match_observed', 'Scans before receiving.', (string) wp_json_encode( [ 'activity_id' => $match, 'analysis_id' => 9 ] ) );
        $this->event( 'note_added', 'A plain note.', '[]' );

        $events = ( new PlayerEventsRepository() )->timelineForPlayer( $this->player, [], [ 'public' ] )['events'];
        $by     = [];
        foreach ( $events as $event ) $by[ $event->event_type ] = PlayerJourneyRestController::formatEvent( $event );

        $this->assertSame( $match, $by['match_observed']['activity']['id'] ?? null );
        $this->assertSame( 9, $by['match_observed']['activity']['analysis_id'] ?? null );
        $this->assertArrayHasKey( 'activity', $by['note_added'] );
        $this->assertNull( $by['note_added']['activity'] );
    }

    public function test_a_reader_who_may_open_nothing_gets_plain_text(): void {
        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $outsider );

        $activity = [ 'id' => 12, 'type' => 'Match', 'title' => '', 'opponent' => 'Willem II', 'date' => '', 'analysis_id' => 4 ];

        $this->assertSame( '', JourneyActivityLink::url( 'match_observed', $activity ) );
        $html = JourneyActivityLink::html( 'match_observed', $activity );
        $this->assertStringContainsString( 'Willem II', $html );
        $this->assertStringNotContainsString( '<a', $html );
        $this->assertSame( '', JourneyActivityLink::html( 'match_observed', null ) );
    }

    public function test_an_observation_opens_its_analysis_before_the_activity(): void {
        $admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        wp_set_current_user( $admin );

        $activity = [ 'id' => 12, 'type' => 'Match', 'title' => '', 'opponent' => 'Willem II', 'date' => '' ];
        $observed = JourneyActivityLink::url( 'match_observed', $activity );
        $evaluated = JourneyActivityLink::url( 'evaluation_completed', $activity );

        if ( CrossViewLink::allows( 'match-analysis' ) ) {
            $this->assertStringContainsString( 'tt_view=match-analysis', $observed );
            $this->assertStringContainsString( 'activity_id=12', $observed );
        } else {
            $this->assertSame( $evaluated, $observed, 'without the analysis the observation falls back to the activity' );
        }
        $this->assertStringNotContainsString( 'match-analysis', $evaluated, 'an evaluation never opens a match analysis' );
        if ( CrossViewLink::allows( 'activities' ) ) {
            $this->assertStringContainsString( 'id=12', $evaluated );
        }
    }

    private function activity( string $title, string $opponent ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => CurrentClub::id(), 'title' => $title, 'session_date' => '2020-03-12',
            'activity_type_key' => $opponent !== '' ? 'game' : 'training', 'opponent' => $opponent,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation( int $activity ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id' => CurrentClub::id(), 'player_id' => $this->player, 'coach_id' => 1,
            'eval_date' => '2020-03-12', 'activity_id' => $activity,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function event( string $type, string $summary, string $payload ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id' => CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'player_id' => $this->player,
            'event_type' => $type, 'event_date' => '2020-03-12 12:00:00', 'summary' => $summary, 'payload' => $payload,
            'visibility' => 'public', 'source_module' => 'tests', 'source_entity_type' => 'test', 'source_entity_id' => 1,
        ] );
    }
}
