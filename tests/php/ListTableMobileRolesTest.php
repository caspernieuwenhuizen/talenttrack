<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Shared\Frontend\Components\FrontendListTable;

/**
 * #4192 — each list-table column carries a phone role. A view that
 * declares none still gets a title and a subtitle line on a phone.
 */
final class ListTableMobileRolesTest extends WP_UnitTestCase {

    public function test_undeclared_columns_get_first_primary_second_secondary(): void {
        $roles = FrontendListTable::mobileRoles( [
            'name'  => [ 'label' => 'Name' ],
            'team'  => [ 'label' => 'Team' ],
            'foot'  => [ 'label' => 'Foot' ],
            'notes' => [ 'label' => 'Notes' ],
        ] );

        $this->assertSame(
            [ 'name' => 'primary', 'team' => 'secondary', 'foot' => 'detail', 'notes' => 'detail' ],
            $roles
        );
    }

    public function test_declared_roles_win_and_undeclared_columns_are_detail(): void {
        $roles = FrontendListTable::mobileRoles( [
            'name'  => [ 'label' => 'Name', 'mobile' => 'primary' ],
            'team'  => [ 'label' => 'Team' ],
            'foot'  => [ 'label' => 'Foot', 'mobile' => 'badge' ],
            'id'    => [ 'label' => 'ID', 'mobile' => 'hide' ],
        ] );

        $this->assertSame(
            [ 'name' => 'primary', 'team' => 'detail', 'foot' => 'badge', 'id' => 'hide' ],
            $roles
        );
    }

    public function test_an_unknown_role_falls_back_to_detail(): void {
        $roles = FrontendListTable::mobileRoles( [
            'name' => [ 'label' => 'Name', 'mobile' => 'headline' ],
        ] );

        $this->assertSame( [ 'name' => 'detail' ], $roles );
    }
}
