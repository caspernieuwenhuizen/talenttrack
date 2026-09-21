<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Exporters\PdpPdfExporter;
use TT\Modules\Pdp\Print\PdpPrintRouter;

/**
 * #3978 — the PDP print's evidence page is staff-only.
 *
 * The player and their parents may print their own PDP file; they get it
 * without the evidence page, on the print route and through the PDF
 * exporter alike. Staff who may open the file get the page. A parent whose
 * child keeps the PDP from them is refused the print outright. Every
 * refusal sits beside a grant on the same file.
 */
final class PdpEvidencePageStaffOnlyTest extends WP_UnitTestCase {

    private int $player = 0;
    private int $file   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Evidence U17' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'       => $club,
            'team_id'       => $team,
            'first_name'    => 'Evidence',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2010-03-04',
        ] );
        $this->player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => $club,
            'name'       => '2026/27 evidence',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
        ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'   => $club,
            'player_id' => $this->player,
            'season_id' => $season,
            'status'    => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $this->file, 'the fixture must write the PDP file' );
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_staff_get_the_evidence_page_and_a_parent_gets_the_print_without_it(): void {
        $hod = $this->makeUser( 'tt_head_dev', 'head_of_development' );
        wp_set_current_user( $hod );
        $this->assertTrue( PdpPrintRouter::canAccess( $this->fileRow() ) );
        $this->assertStringContainsString( 'tt-evidence', PdpPrintRouter::renderHtml( $this->fileRow(), true ), 'staff get the evidence page' );

        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent );
        wp_set_current_user( $parent );
        AuthorizationService::flushCache();

        $this->assertTrue( PdpPrintRouter::canAccess( $this->fileRow() ), 'the parent may still print their child\'s file' );
        $html = PdpPrintRouter::renderHtml( $this->fileRow(), true );
        $this->assertStringContainsString( 'Evidence Player', $html, 'the print itself is rendered' );
        $this->assertStringNotContainsString( 'tt-evidence', $html );
        $this->assertStringNotContainsString( 'include_evidence', $html, 'no control is offered that would do nothing' );
    }

    public function test_the_player_gets_their_own_print_without_the_evidence_page(): void {
        $uid = $this->makeUser( 'tt_player', 'player' );
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $uid ], [ 'id' => $this->player ] );
        AuthorizationService::flushCache();
        wp_set_current_user( $uid );

        $this->assertTrue( PdpPrintRouter::canAccess( $this->fileRow() ) );
        $html = PdpPrintRouter::renderHtml( $this->fileRow(), true );
        $this->assertStringContainsString( 'Evidence Player', $html );
        $this->assertStringNotContainsString( 'tt-evidence', $html );
    }

    public function test_the_pdf_exporter_follows_the_same_rule(): void {
        $hod = $this->makeUser( 'tt_head_dev', 'head_of_development' );
        $this->assertStringContainsString( 'tt-evidence', $this->exportHtml( $hod ), 'staff get the evidence page in the PDF' );

        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent );
        $html = $this->exportHtml( $parent );
        $this->assertStringContainsString( 'Evidence Player', $html, 'the parent still gets the PDF' );
        $this->assertStringNotContainsString( 'tt-evidence', $html );
    }

    public function test_a_parent_whose_child_keeps_the_pdp_from_them_is_refused(): void {
        $parent = $this->makeUser( 'tt_parent', 'parent' );
        $this->linkGuardian( $parent );
        wp_set_current_user( $parent );
        $this->assertTrue( PdpPrintRouter::canAccess( $this->fileRow() ), 'the grant, before the child hides it' );

        $this->assertTrue(
            ( new PlayerParentVisibilityRepository() )->setVisibility( $this->player, 'pdp', false ),
            'the fixture must write the preference'
        );
        AuthorizationService::flushCache();
        $this->assertFalse( PdpPrintRouter::canAccess( $this->fileRow() ) );
    }

    private function exportHtml( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $out = ( new PdpPdfExporter() )->collect( new ExportRequest(
            'pdp_pdf',
            'pdf',
            (int) CurrentClub::id(),
            $user_id,
            null,
            [ 'file_id' => $this->file, 'include_evidence' => true ]
        ) );
        return (string) ( $out['html'] ?? '' );
    }

    private function fileRow(): object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_pdp_files WHERE id = %d", $this->file ) );
        $this->assertNotNull( $row );
        return $row;
    }

    private function makeUser( string $wp_role, string $expected_persona ): int {
        $uid = self::factory()->user->create( [ 'role' => $wp_role ] );
        $this->assertContains( $expected_persona, PersonaResolver::personasFor( $uid ), "{$wp_role} must resolve to {$expected_persona}" );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function linkGuardian( int $user_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id'        => (int) CurrentClub::id(),
            'player_id'      => $this->player,
            'parent_user_id' => $user_id,
        ] );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canViewPlayer( $user_id, $this->player ), 'the guardian link must resolve' );
    }
}
