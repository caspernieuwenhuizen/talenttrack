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
 * current academy only, in all three places the gate asks it: the
 * specific-target check (`can()`), the any-scope check (`canAnyScope()`) and
 * the explanation (`describeAccess()`). An account with a player record in
 * another academy does not hold that record as its own here.
 *
 * A probe entity granted to the player persona at `player` scope only, so
 * the own-player branch is the one answering.
 */
final class MatrixGateOwnPlayerClubTest extends WP_UnitTestCase {

    private const ENTITY = 'own_club_probe';

    private int $uid = 0;
    private int $elsewhere = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        ( new RolesService() )->installRoles();
        ( new MatrixRepository() )->setRow( 'player', self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_PLAYER, \TT\Modules\Players\PlayersModule::class );

        $this->uid = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => 2, 'first_name' => 'Other', 'last_name' => 'Academy', 'status' => 'active', 'wp_user_id' => $this->uid,
        ] );
        $this->elsewhere = (int) $wpdb->insert_id;
        $this->flush();
    }

    public function tear_down(): void {
        ( new MatrixRepository() )->removeRow( 'player', self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_PLAYER );
        $this->flush();
        parent::tear_down();
    }

    public function test_the_specific_target_check_asks_the_current_academy(): void {
        $this->assertGreaterThan( 0, $this->elsewhere, 'fixture: the player in academy 2 was written' );
        $this->assertTrue(
            $this->inAcademyTwo( fn(): bool => MatrixGate::can( $this->uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->elsewhere ) ),
            'precondition: in academy 2 the record is the account\'s own'
        );
        $this->assertFalse(
            MatrixGate::can( $this->uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_PLAYER, $this->elsewhere ),
            'in academy 1 a record from academy 2 is not the account\'s own player'
        );
    }

    public function test_the_any_scope_check_asks_the_current_academy(): void {
        $this->assertTrue(
            $this->inAcademyTwo( fn(): bool => MatrixGate::canAnyScope( $this->uid, self::ENTITY, MatrixGate::READ ) ),
            'precondition: in academy 2 the account holds player scope through its own record'
        );
        $this->assertFalse(
            MatrixGate::canAnyScope( $this->uid, self::ENTITY, MatrixGate::READ ),
            'in academy 1 a player record in academy 2 gives no player scope'
        );
    }

    public function test_the_explanation_asks_the_current_academy(): void {
        $there = $this->inAcademyTwo( fn(): array => MatrixGate::describeAccess( $this->uid, self::ENTITY, MatrixGate::READ ) );
        $this->assertTrue( $there['allowed'], 'precondition: in academy 2 access is explained by the own record' );
        $this->assertSame( $this->elsewhere, $there['scope_value'] );

        $here = MatrixGate::describeAccess( $this->uid, self::ENTITY, MatrixGate::READ );
        $this->assertFalse( $here['allowed'], 'in academy 1 the record in academy 2 explains nothing' );
        $this->assertNull( $here['scope_value'] );
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inAcademyTwo( callable $fn ) {
        $out = ClubIteration::withClub( 2, function () use ( $fn ) {
            $this->flush();
            return $fn();
        } );
        $this->flush();
        return $out;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        AuthorizationService::flushCache();
    }
}
