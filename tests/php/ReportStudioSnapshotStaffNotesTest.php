<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerSectionRights;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\EvidencePacket;
use TT\Modules\ReportStudio\Blocks\PlayerSectionBlock;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\CutsForReader;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\Threads\Domain\ThreadAccess;

/**
 * #5022 — the staff notes on a snapshot follow the thread's own rules for
 * whoever reads it now: none of them for a reader who may not read the
 * player's thread (a parent, an account without a role), all of them,
 * private ones included, for the taker. The frozen copy cut for each reader
 * holds exactly the notes the live section composes for them.
 */
final class ReportStudioSnapshotStaffNotesTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private const PUBLIC_NOTE  = 'Worked hard on the first touch.';
    private const PRIVATE_NOTE = 'Staff only: talk to the family about school.';

    private int $club = 1;
    private int $player = 0;
    private int $hod = 0;
    private int $parent = 0;
    private int $outsider = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Notes U13' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $this->club, 'team_id' => $team, 'first_name' => 'Noor', 'last_name' => 'Notes', 'status' => 'active' ] );
        $this->player = (int) $wpdb->insert_id;

        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->parent   = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $wpdb->insert( "{$p}tt_player_parents", [ 'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent ] );

        foreach ( [ [ self::PUBLIC_NOTE, 'public', '2020-03-15 09:00:00' ], [ self::PRIVATE_NOTE, 'private_to_coach', '2020-03-16 09:00:00' ] ] as [ $body, $visibility, $when ] ) {
            $wpdb->insert( "{$p}tt_thread_messages", [
                'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'thread_type' => 'player', 'thread_id' => $this->player,
                'author_user_id' => $this->hod, 'body' => $body, 'visibility' => $visibility, 'created_at' => $when,
            ] );
        }
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_staff_notes_on_a_snapshot_follow_the_threads_rules_for_each_reader(): void {
        $this->assertTrue( ThreadAccess::canSeePrivate( 'player', $this->player, $this->hod ), 'Precondition: the taker reads private notes.' );
        $this->assertFalse( ThreadAccess::canRead( 'player', $this->player, $this->parent ), 'Precondition: a parent holds no read on the staff notes.' );
        $this->assertFalse( ThreadAccess::canRead( 'player', $this->player, $this->outsider ), 'Precondition: the outsider reads none of it.' );

        $block = BlockRegistry::find( PlayerSectionBlock::KEYS[ PlayerReportBlock::THREAD_NOTES ] );
        $this->assertInstanceOf( CutsForReader::class, $block );

        $frozen = BlockEngine::preview( $block->key(), [ 'scope' => 'player', 'player_id' => $this->player, 'from' => self::FROM, 'to' => self::TO ], [], $this->hod );
        $this->assertIsArray( $frozen );
        $bodies = array_column( (array) $frozen['data']['items'], 'body' );
        $this->assertContains( self::PRIVATE_NOTE, $bodies, 'Precondition: the taker froze the private note.' );

        foreach ( [ 'parent' => $this->parent, 'outsider' => $this->outsider, 'taker' => $this->hod ] as $who => $reader ) {
            $cut  = (array) $block->forReader( $frozen['data'], $reader, [ 'player_id' => $this->player ] );
            $live = EvidencePacket::forReaders( $this->player, self::FROM, self::TO, ReaderSet::of( [ $reader ] ) );
            $this->assertIsArray( $live );
            $this->assertSame(
                wp_json_encode( array_values( (array) ( $live['notes'] ?? [] ) ) ),
                wp_json_encode( $cut['items'] ),
                "{$who}: the frozen notes read as the live section composes them"
            );
        }

        $cut = static fn( int $reader ): array => PlayerSectionRights::cut( PlayerReportBlock::THREAD_NOTES, $frozen['data'], ReaderSet::of( [ $reader ] ), $this->player )['items'];
        $this->assertSame( [], $cut( $this->parent ) );
        $this->assertSame( [], $cut( $this->outsider ) );
        $this->assertContains( self::PRIVATE_NOTE, array_column( $cut( $this->hod ), 'body' ), 'the taker keeps them' );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
