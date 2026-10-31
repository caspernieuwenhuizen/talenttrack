<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Modules\Authorization\Admin\FunctionalRolesPage;
use TT\Modules\Authorization\Admin\RolesPage;
use TT\Modules\People\Admin\PeoplePage;

/**
 * #4523 — role names (authorization roles, functional roles and the
 * people role types) read in sentence case, and `LabelTranslator` is the
 * one map: the roles admin page, the functional roles page and the People
 * admin page call it instead of keeping their own copy.
 */
final class RoleLabelsTest extends WP_UnitTestCase {

    public function test_role_labels_are_in_sentence_case(): void {
        $this->assertSame( 'Club admin', LabelTranslator::authRoleLabel( 'club_admin' ) );
        $this->assertSame( 'Head coach', LabelTranslator::authRoleLabel( 'head_coach' ) );
        $this->assertSame( 'Assistant coach', LabelTranslator::authRoleLabel( 'assistant_coach' ) );
        $this->assertSame( 'Team member', LabelTranslator::authRoleLabel( 'team_member' ) );
        $this->assertSame( 'Head of development', LabelTranslator::authRoleLabel( 'head_of_development' ) );
        $this->assertSame( 'Head of development', LabelTranslator::functionalRoleLabel( 'head_of_development' ) );
        $this->assertSame( 'Head of development', LabelTranslator::roleType( 'head_of_development' ) );
    }

    public function test_the_admin_pages_read_label_translator(): void {
        foreach ( [ 'club_admin', 'head_coach', 'assistant_coach', 'team_member', 'head_of_development', 'player' ] as $key ) {
            $this->assertSame( LabelTranslator::authRoleLabel( $key ), RolesPage::roleLabel( $key ), 'roles page: ' . $key );
        }
        foreach ( [ 'head_coach', 'assistant_coach', 'kit_manager', 'head_of_development', 'mentor' ] as $key ) {
            $this->assertSame( LabelTranslator::functionalRoleLabel( $key ), FunctionalRolesPage::roleLabel( $key ), 'functional roles page: ' . $key );
        }
        foreach ( [ 'coach', 'head_coach', 'head_of_development', 'physio', 'other' ] as $key ) {
            $this->assertSame( LabelTranslator::roleType( $key ), PeoplePage::roleLabel( $key ), 'people page: ' . $key );
        }
    }

    public function test_a_custom_role_keeps_its_humanised_fallback(): void {
        $this->assertSame( 'Video Analyst', RolesPage::roleLabel( 'video_analyst' ) );
        $this->assertSame( 'Video Analyst', FunctionalRolesPage::roleLabel( 'video_analyst' ) );
    }

    public function test_no_title_case_role_label_is_left_in_src(): void {
        $rii = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src', \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $file ) {
            if ( $file->getExtension() !== 'php' ) continue;
            $source = (string) file_get_contents( $file->getPathname() );
            $this->assertDoesNotMatchRegularExpression(
                "/'(Club Admin|Head Coach|Assistant Coach|Team Member)'/",
                $source,
                $file->getFilename() . ' still carries a Title Case role label'
            );
        }
    }
}
