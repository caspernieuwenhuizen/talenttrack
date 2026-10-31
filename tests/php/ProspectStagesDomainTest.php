<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ProspectRecord;
use TT\Modules\Prospects\Domain\ProspectStages;
use TT\Modules\Prospects\Frontend\FrontendOnboardingPipelineView;

/**
 * #4447 — the prospect stage rule lives in the domain layer. The pipeline
 * board, the prospect record page and the `GET prospects/{id}` stage all
 * read `ProspectStages::computeStages()`, and neither the domain nor the
 * REST layer reaches into a Prospects view class to get it.
 */
final class ProspectStagesDomainTest extends WP_UnitTestCase {

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_view_no_longer_defines_the_stage_rule(): void {
        $this->assertFalse( method_exists( FrontendOnboardingPipelineView::class, 'computeStages' ) );
        $this->assertTrue( method_exists( ProspectStages::class, 'computeStages' ) );
    }

    public function test_domain_and_rest_reference_no_prospects_view_class(): void {
        foreach ( [ 'src/Modules/Prospects/Domain', 'src/Modules/Prospects/Rest' ] as $dir ) {
            $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) );
            foreach ( $rii as $file ) {
                if ( $file->getExtension() !== 'php' ) continue;
                $source = (string) file_get_contents( $file->getPathname() );
                $this->assertStringNotContainsString( 'Prospects\\Frontend\\', $source, $file->getFilename() . ' reaches into a Prospects view class' );
            }
        }
    }

    public function test_the_record_stage_matches_the_board(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'       => 1,
            'first_name'    => 'Stage',
            'last_name'     => 'Rulex',
            'current_club'  => 'VV Voorbeeld',
            'discovered_at' => current_time( 'Y-m-d' ),
        ] );
        $prospect = (int) $wpdb->insert_id;

        $board_key = '';
        foreach ( ProspectStages::computeStages( $this->admin ) as $column ) {
            foreach ( $column['cards'] as $card ) {
                if ( (int) ( $card['id'] ?? 0 ) === $prospect ) $board_key = (string) $column['key'];
            }
        }

        $stage = ProspectRecord::stage( $this->admin, $prospect );
        $this->assertNotNull( $stage );
        $this->assertSame( 'prospects', $board_key );
        $this->assertSame( $board_key, $stage['key'] );
    }
}
