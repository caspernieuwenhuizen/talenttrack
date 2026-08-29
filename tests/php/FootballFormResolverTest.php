<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Teams\FootballFormResolver;

/**
 * #3044 — FootballFormResolver.
 *
 * Resolution order: the team's own column → the operator's age-group map in
 * `tt_config` → the shipped bands read off the number in the group's name.
 * The bootstrap has already run migration 0242, so the `football_form`
 * vocabulary and both columns exist.
 *
 * Every fixture uses an age-group name no seed uses ("QA17", "QA8") so the
 * assertions cannot collide with whatever this install was seeded with.
 */
final class FootballFormResolverTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        QueryHelpers::set_config( FootballFormResolver::CONFIG_KEY, '' );
    }

    public function test_band_fallback_reads_the_number_in_the_group_name(): void {
        $this->assertSame( '6v6', FootballFormResolver::bandFor( 'QA8' ) );
        $this->assertSame( '8v8', FootballFormResolver::bandFor( 'QA11' ) );
        $this->assertSame( '11v11', FootballFormResolver::bandFor( 'QA17' ) );
    }

    public function test_band_fallback_handles_a_group_with_no_number(): void {
        $this->assertSame( '11v11', FootballFormResolver::bandFor( 'Seniors' ) );
    }

    public function test_configured_map_wins_over_the_band(): void {
        QueryHelpers::set_config(
            FootballFormResolver::CONFIG_KEY,
            (string) wp_json_encode( [ 'QA17' => '8v8' ] )
        );
        $this->assertSame( '8v8', FootballFormResolver::forAgeGroup( 'QA17' ),
            'an operator who says their U17 plays eight a side must be believed' );
        $this->assertSame( '6v6', FootballFormResolver::forAgeGroup( 'QA8' ),
            'a group absent from the map still resolves through the band' );
    }

    public function test_team_column_wins_over_the_age_group(): void {
        $team_id = $this->seedTeam( 'QA8', '11v11' );
        $this->assertSame( '11v11', FootballFormResolver::forTeam( $team_id ) );
    }

    public function test_team_without_an_override_follows_its_age_group(): void {
        $team_id = $this->seedTeam( 'QA8', null );
        $this->assertSame( '6v6', FootballFormResolver::forTeam( $team_id ) );
    }

    public function test_unknown_team_falls_back(): void {
        $this->assertSame( FootballFormResolver::FALLBACK_FORM, FootballFormResolver::forTeam( 0 ) );
    }

    public function test_players_a_side_reads_off_the_form_name(): void {
        $this->assertSame( 6, FootballFormResolver::playersASide( '6v6' ) );
        $this->assertSame( 8, FootballFormResolver::playersASide( '8v8' ) );
        $this->assertSame( 11, FootballFormResolver::playersASide( '11v11' ) );
        $this->assertSame( 7, FootballFormResolver::playersASide( '7v7' ),
            'a form a club added itself must be understood without an entry anywhere' );
        $this->assertSame( 11, FootballFormResolver::playersASide( 'kwart veld' ) );
    }

    public function test_vocabulary_is_seeded(): void {
        $forms = FootballFormResolver::forms();
        foreach ( [ '6v6', '8v8', '11v11' ] as $expected ) {
            $this->assertContains( $expected, $forms );
        }
    }

    public function test_normalise_drops_unknown_groups_and_forms(): void {
        $raw = (string) wp_json_encode( [
            'QA8'                  => '6v6',   // group does not exist as a lookup
            'no-such-age-group'    => '8v8',
            'another'              => 'kwart veld',
        ] );
        $this->assertSame( '', FootballFormResolver::normaliseStored( $raw ),
            'nothing in this payload names a real age group, so nothing survives' );
    }

    private function seedTeam( string $age_group, ?string $football_form ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'       => 1,
            'name'          => 'QA ' . $age_group . ' ' . wp_generate_password( 6, false ),
            'age_group'     => $age_group,
            'football_form' => $football_form,
        ] );
        return (int) $wpdb->insert_id;
    }
}
