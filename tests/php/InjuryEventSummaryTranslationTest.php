<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #4321 — the injury chapter of the journey is written in the academy's
 * language.
 *
 * Every journey emitter stores its summary through `__()` at write time;
 * the injury ones stored bare English, so a Dutch journey read "Injury
 * recovered" between Dutch entries. The catalogue is compiled only at
 * release, so the test stands in for a translation with a `gettext`
 * filter: a summary that does not go through gettext never sees it.
 */
final class InjuryEventSummaryTranslationTest extends WP_UnitTestCase {

    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Summary XI' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $club,
            'team_id'    => $team,
            'first_name' => 'Summary',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        add_filter( 'gettext', [ $this, 'dutch' ], 10, 3 );
    }

    public function tear_down(): void {
        remove_filter( 'gettext', [ $this, 'dutch' ], 10 );
        parent::tear_down();
    }

    /** The two summaries this test writes, in the Dutch the fragments carry. */
    public function dutch( string $translation, string $text, string $domain ): string {
        if ( $domain !== 'talenttrack' ) return $translation;
        $nl = [
            'Injury recovered' => 'Blessure hersteld',
            'Injury started'   => 'Blessure ingetreden',
        ];
        return $nl[ $text ] ?? $translation;
    }

    public function test_the_start_and_the_recovery_are_written_in_the_academys_language(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [ 'player_id' => $this->player, 'started_on' => '2026-09-01' ] );
        $this->assertGreaterThan( 0, $id );
        $this->assertTrue( $repo->update( $id, [ 'actual_return' => '2026-09-20' ] ) );

        $this->assertSame( 'Blessure ingetreden', $this->summary( 'injury_started' ) );
        $this->assertSame( 'Blessure hersteld', $this->summary( 'injury_ended' ) );
    }

    private function summary( string $event_type ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s ORDER BY id DESC LIMIT 1",
            $this->player, $event_type
        ) );
    }
}
