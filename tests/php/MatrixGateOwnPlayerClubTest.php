<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * The `player` scope's "is this the account's own player?" branch asks the
 * current academy only. An account with a player record in another academy
 * does not hold that record as its own here.
 */
final class MatrixGateOwnPlayerClubTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new MatrixRepository() )->setRow( 'player', 'players', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, \TT\Modules\Players\PlayersModule::class );
        $this->flush();
    }

    public function tear_down(): void {
        ( new MatrixRepository() )->removeRow( 'player', 'players', MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        $this->flush();
        parent::tear_down();
    }

    public function test_the_own_player_is_looked_up_in_the_current_academy(): void {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 2, 'first_name' => 'Other', 'last_name' => 'Academy', 'status' => 'active', 'wp_user_id' => $uid,
        ] );
        $elsewhere = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $elsewhere, 'fixture: the player in academy 2 was written' );

        $there = ClubIteration::withClub( 2, function () use ( $uid, $elsewhere ): bool {
            $this->flush();
            return MatrixGate::can( $uid, 'players', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $elsewhere );
        } );
        $this->assertTrue( $there, 'precondition: in academy 2 the record is the account\'s own' );

        $this->flush();
        $this->assertFalse(
            MatrixGate::can( $uid, 'players', MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $elsewhere ),
            'in academy 1 a record from academy 2 is not the account\'s own player'
        );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        AuthorizationService::flushCache();
    }
}
