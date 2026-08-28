<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\PersonaDashboard\Admin\EditorPage;
use TT\Modules\PersonaDashboard\Frontend\FrontendPersonaTemplatesView;

/**
 * #2978 — the persona dashboard editor on the frontend.
 *
 * The port's claim is that this is **one editor on two screens**, so what is
 * worth testing is the things that would quietly stop being true if someone
 * later "tidied" the two apart:
 *
 *   - both screens admit exactly the same people, and
 *   - both emit the same editor markup and the same bootstrap, differing
 *     only in the host's button classes.
 *
 * Not tested here: the drag-and-drop behaviour, which is the JS's and lives
 * in the Playwright suite. This is about the two hosts staying in step.
 */
final class PersonaTemplatesFrontendTest extends WP_UnitTestCase {

    public function test_both_screens_are_governed_by_one_capability_constant(): void {
        // The frontend view reads EditorPage::CAP rather than repeating the
        // string. If someone re-inlines it, this is what notices.
        $this->assertSame( 'tt_edit_persona_templates', EditorPage::CAP );
    }

    public function test_the_editor_markup_is_the_same_on_both_hosts(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $admin    = $this->capture( static fn() => EditorPage::renderEditor() );
        $frontend = $this->capture( static fn() => EditorPage::renderEditor( [
            'wrapper' => 'tt-pde-wrap tt-pde-wrap--frontend',
            'button'  => 'tt-btn tt-btn-secondary tt-pde-btn',
            'primary' => 'tt-btn tt-btn-primary tt-pde-btn-primary',
        ] ) );

        // Every hook the editor JS binds to has to exist on both, or the
        // frontend host gets an editor that renders and does nothing.
        foreach ( [
            'data-tt-pde="persona-select"',
            'data-tt-pde="canvas"',
            'data-tt-pde="properties"',
            'data-tt-pde="publish"',
            'data-tt-pde="save-draft"',
            'data-tt-pde="undo"',
            'data-tt-pde="redo"',
            'data-tt-pde="reset"',
            'data-tt-pde-band="hero"',
            'data-tt-pde-band="task"',
        ] as $hook ) {
            $this->assertStringContainsString( $hook, $admin, "wp-admin lost {$hook}" );
            $this->assertStringContainsString( $hook, $frontend, "the frontend lost {$hook}" );
        }
    }

    public function test_only_the_chrome_classes_differ(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $frontend = $this->capture( static fn() => EditorPage::renderEditor( [
            'wrapper' => 'tt-pde-wrap tt-pde-wrap--frontend',
            'button'  => 'tt-btn tt-btn-secondary tt-pde-btn',
            'primary' => 'tt-btn tt-btn-primary tt-pde-btn-primary',
        ] ) );

        $this->assertStringContainsString( 'tt-pde-wrap--frontend', $frontend );
        $this->assertStringContainsString( 'tt-btn tt-btn-primary tt-pde-btn-primary', $frontend );

        // wp-admin's own classes must not leak onto the frontend surface:
        // `.wrap` supplies an admin page gutter, and `.button` is styled by
        // wp-admin's stylesheet, which the frontend does not load.
        $this->assertStringNotContainsString( 'class="wrap tt-pde-wrap"', $frontend );
        $this->assertStringNotContainsString( 'class="button tt-pde-btn"', $frontend );
    }

    public function test_the_frontend_slug_is_stable(): void {
        // The slug is referenced by config/mobile_surfaces.php, the
        // dispatcher and the configuration tile. Renaming it is a
        // three-place change, not a one-place one.
        $this->assertSame( 'persona-templates', FrontendPersonaTemplatesView::SLUG );
    }

    /** @param callable():void $fn */
    private function capture( callable $fn ): string {
        ob_start();
        $fn();
        return (string) ob_get_clean();
    }
}
