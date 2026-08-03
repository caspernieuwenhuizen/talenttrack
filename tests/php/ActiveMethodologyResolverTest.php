<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Methodology\ActiveMethodologyResolver;

/**
 * #2318 (epic #2316) — ActiveMethodologyResolver.
 *
 * Resolution order: team override → install default (tt_config) → the
 * club's default set → 0. The bootstrap has already run migrations, so
 * the shipped default set (0200) and `tt_teams.methodology_id` (0201)
 * exist. Each test seeds a second set / team to exercise the fallbacks.
 */
final class ActiveMethodologyResolverTest extends WP_UnitTestCase {

    private int $defaultSetId = 0;
    private int $secondSetId  = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $this->defaultSetId = (int) $wpdb->get_var(
            "SELECT id FROM {$wpdb->prefix}tt_methodologies WHERE club_id = 1 AND is_default = 1 LIMIT 1"
        );

        $wpdb->insert( $wpdb->prefix . 'tt_methodologies', [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'slug'       => 'jo13-1-hedel',
            'name_json'  => wp_json_encode( [ 'nl' => 'JO13-1 Hedel', 'en' => 'JO13-1 Hedel' ] ),
            'is_default' => 0,
            'is_shipped' => 1,
        ] );
        $this->secondSetId = (int) $wpdb->insert_id;

        // Clear any install default from a previous test.
        QueryHelpers::set_config( ActiveMethodologyResolver::CONFIG_KEY, '0' );
    }

    public function test_forInstall_returns_default_set_when_unconfigured(): void {
        $this->assertSame( $this->defaultSetId, ActiveMethodologyResolver::forInstall() );
    }

    public function test_forInstall_honours_valid_config(): void {
        ActiveMethodologyResolver::setInstallDefault( $this->secondSetId );
        $this->assertSame( $this->secondSetId, ActiveMethodologyResolver::forInstall() );
    }

    public function test_forInstall_ignores_invalid_config(): void {
        ActiveMethodologyResolver::setInstallDefault( 999999 );
        $this->assertSame( $this->defaultSetId, ActiveMethodologyResolver::forInstall(),
            'an unknown configured id must fall back to the club default' );
    }

    public function test_forTeam_uses_override_when_valid(): void {
        $team_id = $this->seedTeam( $this->secondSetId );
        $this->assertSame( $this->secondSetId, ActiveMethodologyResolver::forTeam( $team_id ) );
    }

    public function test_forTeam_falls_back_to_install_default_without_override(): void {
        $team_id = $this->seedTeam( null );
        $this->assertSame( $this->defaultSetId, ActiveMethodologyResolver::forTeam( $team_id ) );
    }

    public function test_forTeam_ignores_invalid_override(): void {
        $team_id = $this->seedTeam( 999999 );
        $this->assertSame( $this->defaultSetId, ActiveMethodologyResolver::forTeam( $team_id ),
            'a dangling team override must fall back, not return a ghost set' );
    }

    private function seedTeam( ?int $methodology_id ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'        => 1,
            'name'           => 'Test Team',
            'methodology_id' => $methodology_id,
        ] );
        return (int) $wpdb->insert_id;
    }
}
