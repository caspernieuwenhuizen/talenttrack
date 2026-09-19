<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Shared\Club\ClubIdentity;

/**
 * #3662 — whose name is on the letter.
 *
 * A trial letter is the formal record a family keeps of where their
 * child is going next, so the letterhead has to name the academy. The
 * engine was reading a `club_name` config key nothing in the plugin
 * ever writes, so the lookup always missed and the letter went out
 * under the WordPress site title — the name of the software install.
 */
final class TrialLetterClubNameTest extends WP_UnitTestCase {

    private int $case_id = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'first_name' => 'Nora',
            'last_name'  => 'Trialist',
            'status'     => 'trial',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => $club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'decided',
            'decision'   => 'admit',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case_id = (int) $wpdb->insert_id;

        update_option( 'blogname', 'TalentTrack Local' );
    }

    public function tear_down(): void {
        QueryHelpers::set_config( 'academy_name', '' );
        QueryHelpers::set_config( 'tt_trial_acceptance_club_address', '' );
        parent::tear_down();
    }

    private function case(): object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d",
            $this->case_id
        ) );
        $this->assertNotNull( $row );
        return $row;
    }

    public function test_the_letterhead_carries_the_academy_name(): void {
        QueryHelpers::set_config( 'academy_name', 'Test Academy' );

        $html = ( new LetterTemplateEngine() )->render( AudienceType::TRIAL_ADMITTANCE, $this->case() );

        $this->assertStringContainsString( '<h1>Test Academy</h1>', $html );
        $this->assertStringNotContainsString( 'TalentTrack Local', $html );
    }

    public function test_the_body_signs_off_with_the_academy_name(): void {
        QueryHelpers::set_config( 'academy_name', 'Test Academy' );

        $body = LetterTemplateEngine::apply(
            'Met vriendelijke groet, {club_name}',
            $this->contextFor()
        );

        $this->assertSame( 'Met vriendelijke groet, Test Academy', $body );
    }

    public function test_an_unconfigured_academy_still_falls_back_to_the_site_title(): void {
        QueryHelpers::set_config( 'academy_name', '' );

        $html = ( new LetterTemplateEngine() )->render( AudienceType::TRIAL_ADMITTANCE, $this->case() );

        $this->assertStringContainsString( '<h1>TalentTrack Local</h1>', $html );
    }

    public function test_the_resolver_prefers_the_academy_over_the_site(): void {
        QueryHelpers::set_config( 'academy_name', '  Voetbalacademie De Maasoever  ' );

        $this->assertSame( 'Voetbalacademie De Maasoever', ClubIdentity::name() );
    }

    /**
     * Adjacent to the same miss: the engine read `club_address`, while the
     * letter-template editor saves the return address under
     * `tt_trial_acceptance_club_address`. The acceptance slip therefore
     * always said "the club office".
     */
    public function test_the_acceptance_slip_uses_the_saved_return_address(): void {
        QueryHelpers::set_config( 'academy_name', 'Test Academy' );
        QueryHelpers::set_config( 'tt_trial_acceptance_club_address', 'Sportpark De Maas 3, Hedel' );

        $address = $this->contextFor()['club_address'] ?? '';

        $this->assertSame( 'Sportpark De Maas 3, Hedel', $address );
    }

    /**
     * `buildContext()` is private — it is an implementation detail of
     * `render()` — so the assertions above reach it the way the engine
     * does, through a reflection call rather than a second copy of the
     * substitution logic.
     *
     * @return array<string,string>
     */
    private function contextFor(): array {
        $engine = new LetterTemplateEngine();
        $method = new \ReflectionMethod( LetterTemplateEngine::class, 'buildContext' );
        $method->setAccessible( true );
        /** @var array<string,string> $context */
        $context = $method->invoke( $engine, $this->case(), [] );
        return $context;
    }
}
