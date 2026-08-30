<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\LegacyCapMapper;
use TT\Modules\Authorization\PersonaResolver;
use WP_UnitTestCase;

/**
 * #3177 — the two roles that reached no persona rows.
 *
 * `readonly_observer` resolved to a persona the seed never defined;
 * `tt_staff` resolved to no persona at all. Same sentence from a user's
 * seat: a role that reaches no persona sees nothing.
 *
 * The assertion that matters most here is the negative one. The whole
 * value of a Read-Only Observer seat is that it cannot be used to alter
 * anything, and a seed that grants a write verb by copy-paste would be
 * worse than the gap it replaces — so `change`, `create_delete` and
 * `approve` are asserted absent rather than left to review.
 */
final class ObserverAndStaffPersonaTest extends WP_UnitTestCase {

    /**
     * The seed as data, without booting the matrix.
     *
     * @return list<array<string,string>>
     */
    private function seed(): array {
        $rows = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';
        $this->assertIsArray( $rows );
        return $rows;
    }

    /**
     * @return list<array<string,string>>
     */
    private function rowsFor( string $persona ): array {
        return array_values( array_filter(
            $this->seed(),
            static fn ( array $r ): bool => ( $r['persona'] ?? '' ) === $persona
        ) );
    }

    // ── Read-Only Observer ─────────────────────────────────────────────

    /**
     * Exactly the eight entities `RolesService::VIEW_CAPS` maps to
     * through `LegacyCapMapper`, and nothing else.
     *
     * Derived rather than hard-listed: if a ninth view capability is
     * added to the role, this test says so instead of silently passing on
     * a stale list.
     */
    public function test_the_observer_is_seeded_exactly_what_its_view_caps_map_to(): void {
        $expected = [];
        foreach ( RolesService::VIEW_CAPS as $cap ) {
            $tuple = LegacyCapMapper::tupleFor( $cap );
            $this->assertNotNull( $tuple, "VIEW_CAPS carries {$cap}, which the mapper does not know." );
            $this->assertSame( 'read', $tuple[1], "VIEW_CAPS carries {$cap}, which is not a read." );
            $expected[] = $tuple[0];
        }
        sort( $expected );

        $actual = array_map(
            static fn ( array $r ): string => (string) $r['entity'],
            $this->rowsFor( 'readonly_observer' )
        );
        sort( $actual );

        $this->assertSame(
            array_values( array_unique( $expected ) ),
            array_values( array_unique( $actual ) ),
            'The observer seed and RolesService::VIEW_CAPS have drifted apart.'
        );
    }

    /**
     * The load-bearing negative. A stray `c` or `d` in the seed's compact
     * activity string is one keystroke, and it would turn the read-only
     * seat into a writer without anything else changing.
     */
    public function test_the_observer_holds_no_write_verb_anywhere(): void {
        foreach ( $this->rowsFor( 'readonly_observer' ) as $row ) {
            $this->assertSame(
                'read',
                $row['activity'],
                sprintf(
                    'readonly_observer has "%s" on "%s". The seat exists because it cannot alter anything.',
                    $row['activity'],
                    $row['entity']
                )
            );
        }
    }

    /** Global, or the role is narrowed to the teams it is never assigned. */
    public function test_the_observer_is_seeded_at_global_scope(): void {
        foreach ( $this->rowsFor( 'readonly_observer' ) as $row ) {
            $this->assertSame( 'global', $row['scope_kind'], (string) $row['entity'] );
        }
    }

    /**
     * The entities the first pass would have granted and this one does
     * not. Named individually, because "not all 138" is not a property a
     * future edit can trip over but "not safeguarding_notes" is.
     */
    public function test_the_observer_reaches_nothing_sensitive(): void {
        $entities = array_map(
            static fn ( array $r ): string => (string) $r['entity'],
            $this->rowsFor( 'readonly_observer' )
        );

        foreach ( [
            'safeguarding_notes',
            'player_injuries',
            'player_notes',
            'player_behaviour_ratings',
            'player_potential',
            'parent_accounts',
            'media',
            'audit_log',
            'impersonation_log',
            'permission_debug',
            'thread_messages',
            'backup',
        ] as $entity ) {
            $this->assertNotContains(
                $entity,
                $entities,
                "A board member or sponsor must not read {$entity}."
            );
        }
    }

    // ── Staff ──────────────────────────────────────────────────────────

    public function test_the_staff_role_now_resolves_to_a_persona(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        $this->assertContains(
            'staff',
            PersonaResolver::personasFor( $user ),
            'tt_staff resolving to no persona is what denied it every mapped capability.'
        );
    }

    public function test_the_observer_role_still_resolves_to_its_persona(): void {
        $user = self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );

        $this->assertContains( 'readonly_observer', PersonaResolver::personasFor( $user ) );
    }

    /**
     * Every persona a role can resolve to has rows. This is the
     * confirmation the issue asked for, and it is written as a sweep so a
     * future persona added to the resolver without a seed fails here.
     */
    public function test_no_role_resolves_to_a_persona_with_no_seed_rows(): void {
        $seeded = array_unique( array_map(
            static fn ( array $r ): string => (string) $r['persona'],
            $this->seed()
        ) );

        foreach ( array_keys( ( new RolesService() )->roleDefinitions() ) as $role ) {
            $user     = self::factory()->user->create( [ 'role' => $role ] );
            $personas = PersonaResolver::personasFor( $user );

            foreach ( $personas as $persona ) {
                $this->assertContains(
                    $persona,
                    $seeded,
                    sprintf( 'Role "%s" resolves to persona "%s", which has no seed rows.', $role, $persona )
                );
            }
        }
    }

    /**
     * `tt_manage_players` is not "manage the roster": it gates season
     * rollover, player-account provisioning, custom-field definitions and
     * deletion, and `BehaviourPendingSource` uses it as the "sees every
     * player in the academy" marker for HoDs and admins. A physio or kit
     * manager does not get that surface.
     */
    public function test_staff_is_not_granted_the_academy_admin_player_verb(): void {
        foreach ( $this->rowsFor( 'staff' ) as $row ) {
            if ( $row['entity'] !== 'players' ) continue;
            $this->assertNotSame(
                'create_delete',
                $row['activity'],
                'players:create_delete carries season rollover, account provisioning and deletion.'
            );
        }
    }

    /** A physio is attached to squads; one attached to none reads nothing. */
    public function test_staff_is_scoped_to_its_teams_not_the_academy(): void {
        foreach ( $this->rowsFor( 'staff' ) as $row ) {
            if ( $row['entity'] === 'my_person' ) {
                $this->assertSame( 'self', $row['scope_kind'] );
                continue;
            }
            $this->assertSame(
                'team',
                $row['scope_kind'],
                sprintf( 'staff.%s is %s-scoped; a physio should not read the academy.', $row['entity'], $row['scope_kind'] )
            );
        }
    }

    /** Nothing outside the four mapped entities plus the self-service row. */
    public function test_staff_reaches_only_what_its_capabilities_imply(): void {
        $entities = array_unique( array_map(
            static fn ( array $r ): string => (string) $r['entity'],
            $this->rowsFor( 'staff' )
        ) );
        sort( $entities );

        $this->assertSame(
            [ 'my_person', 'people', 'player_notes', 'players', 'team' ],
            array_values( $entities )
        );
    }
}
