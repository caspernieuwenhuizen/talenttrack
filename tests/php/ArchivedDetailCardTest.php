<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Shared\Frontend\Components\ArchivedDetailCard;

/**
 * #2022 (epic #2018) — read-only archived/trashed detail card.
 *
 * Security-critical (Bug 1 fix): the detail views retry through this card's
 * resolve() when their active lookup returns null. resolve() delegates to the
 * #2021 visibility gate, so these tests pin the two outcomes the gate exposes:
 *
 *   - an archived record resolves to a read-only card (NOT "does not exist").
 *   - a trashed record resolves for an admin, but a non-admin gets null — the
 *     view must then render a clean 404, NEVER a permission-denied page that
 *     would confirm the trashed minor's record exists. [#2 CRITICAL]
 *
 * Plus the render contract: the card carries the status banner + lifecycle
 * actions and NO Edit affordance (restore first, then edit).
 */
final class ArchivedDetailCardTest extends WP_UnitTestCase {

    private string $p;
    private ArchiveRepository $repo;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;
        $this->repo = new ArchiveRepository();
        // Default actor is an academy admin (holds tt_manage_recycle_bin via
        // the administrator bypass). Individual tests downgrade.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    // ---- Bug 1: archived record opens read-only, not "does not exist" ----

    public function test_resolve_returns_archived_record_instead_of_not_found(): void {
        $id = $this->insertTeam( 'Archived squad' );
        $this->archiveRow( 'tt_teams', $id );

        $resolved = ArchivedDetailCard::resolve( 'team', $id );

        $this->assertNotNull( $resolved, 'an archived record resolves — the view no longer renders "does not exist"' );
        $this->assertSame( 'archived', $resolved['state'] );
        $this->assertSame( $id, (int) $resolved['row']->id );
    }

    public function test_resolve_returns_null_for_a_genuinely_absent_record(): void {
        $this->assertNull( ArchivedDetailCard::resolve( 'team', 9_999_999 ) );
        $this->assertNull( ArchivedDetailCard::resolve( 'team', 0 ), 'a 0 id is never a lookup' );
    }

    // ---- #2 CRITICAL: trashed record visibility → clean 404 for non-admin -

    public function test_admin_resolves_a_trashed_record(): void {
        $id = $this->insertTeam( 'Binned squad' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );

        $resolved = ArchivedDetailCard::resolve( 'team', $id );

        $this->assertNotNull( $resolved, 'an admin (tt_manage_recycle_bin) sees the trashed record' );
        $this->assertSame( 'trashed', $resolved['state'] );
    }

    public function test_non_admin_gets_null_for_a_trashed_record(): void {
        $id = $this->insertTeam( 'Binned squad hidden from coach' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );

        // Downgrade to a user without tt_manage_recycle_bin.
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $resolved = ArchivedDetailCard::resolve( 'team', $id );

        $this->assertNull(
            $resolved,
            'a non-admin gets null for a trashed record → the detail view renders a clean 404, '
            . 'never a permission-denied page that confirms the record exists'
        );
    }

    // ---- render contract: banner present, no Edit affordance ------------

    public function test_archived_card_renders_warning_banner_and_no_edit_affordance(): void {
        $id = $this->insertTeam( 'Render archived' );
        $this->archiveRow( 'tt_teams', $id );
        $resolved = ArchivedDetailCard::resolve( 'team', $id );

        $html = $this->captureRender( 'team', $resolved, [
            'title'    => 'Render archived',
            'fields'   => [ [ 'Age group', 'U17' ] ],
            'list_url' => 'https://example.test/?tt_view=teams',
        ] );

        $this->assertStringContainsString( 'tt-notice-warning', $html, 'archived → amber warning banner' );
        $this->assertStringContainsString( 'is archived', $html );
        $this->assertStringContainsString( '/restore', $html, 'Restore action wired' );
        $this->assertStringContainsString( '/trash', $html, 'Move to recycle bin action wired' );
        $this->assertStringNotContainsStringIgnoringCase(
            'action=edit',
            $html,
            'no Edit affordance on a non-active record — restore first, then edit'
        );
    }

    public function test_trashed_card_renders_danger_banner_with_bin_actions(): void {
        $id = $this->insertTeam( 'Render trashed' );
        $this->archiveRow( 'tt_teams', $id );
        $this->repo->trash( 'team', [ $id ], get_current_user_id() );
        $resolved = ArchivedDetailCard::resolve( 'team', $id );

        $html = $this->captureRender( 'team', $resolved, [
            'title'    => 'Render trashed',
            'list_url' => 'https://example.test/?tt_view=teams',
        ] );

        $this->assertStringContainsString( 'tt-notice-danger', $html, 'trashed → red danger banner' );
        $this->assertStringContainsString( 'recycle bin', $html );
        $this->assertStringContainsString( 'recycle-bin/team/' . $id . '/restore', $html, 'restore-to-archive wired to bin route' );
        $this->assertStringContainsString( 'recycle-bin/team/' . $id . '"', $html, 'delete-permanently wired to bin route' );
    }

    public function test_render_ignores_an_active_state(): void {
        $id = $this->insertTeam( 'Still active' );
        $resolved = ArchivedDetailCard::resolve( 'team', $id );
        $this->assertSame( 'active', $resolved['state'] );

        $html = $this->captureRender( 'team', $resolved, [ 'title' => 'Still active' ] );
        $this->assertSame( '', trim( $html ), 'the card renders nothing for an active row — that path uses the normal detail surface' );
    }

    // ---- helpers --------------------------------------------------------

    /** @param array<string,mixed> $summary */
    private function captureRender( string $entity, array $resolved, array $summary ): string {
        ob_start();
        ArchivedDetailCard::render( $entity, $resolved, $summary );
        return (string) ob_get_clean();
    }

    private function insertTeam( string $name, int $club = null ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $club ?? CurrentClub::id(),
            'name'    => $name,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function archiveRow( string $table, int $id ): void {
        global $wpdb;
        $wpdb->update( "{$this->p}{$table}", [
            'archived_at' => current_time( 'mysql' ),
            'archived_by' => get_current_user_id(),
        ], [ 'id' => $id ] );
    }
}
