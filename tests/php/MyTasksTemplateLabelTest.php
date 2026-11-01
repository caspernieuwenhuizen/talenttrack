<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #4555 — My tasks showed "Template:" in English on a Dutch install. The
 * bare "Template" msgid ships with an English msgstr, so the filter label
 * carries its own context and its Dutch.
 */
final class MyTasksTemplateLabelTest extends WP_UnitTestCase {

    private const CONTEXT = 'my tasks filter: the kind of task';

    private function root(): string {
        return dirname( __DIR__, 2 );
    }

    public function set_up(): void {
        parent::set_up();
        require_once $this->root() . '/tools/lib/gettext-calls.php';
        require_once $this->root() . '/tools/lib/po.php';
    }

    public function test_the_filter_label_is_the_context_keyed_msgid(): void {
        $calls = tt_gettext_calls( (string) file_get_contents( $this->root() . '/src/Modules/Workflow/Frontend/FrontendMyTasksView.php' ) );

        $keyed = array_filter( $calls, static fn ( array $c ): bool => $c['msgid'] === 'Template' && $c['msgctxt'] === self::CONTEXT );
        $bare  = array_filter( $calls, static fn ( array $c ): bool => $c['msgid'] === 'Template' && $c['msgctxt'] === '' );

        $this->assertCount( 1, $keyed );
        $this->assertSame( [], array_values( $bare ), 'the bare msgid ships untranslated' );
    }

    public function test_the_dutch_is_sjabloon(): void {
        $path = $this->root() . '/languages/pending/4555-template-label.po';
        if ( ! is_readable( $path ) ) {
            $this->markTestSkipped( 'The 4555 fragment has been consolidated by a release.' );
        }
        $entries = tt_po_parse( (string) file_get_contents( $path ) )['entries'];
        $match   = array_values( array_filter( $entries, static fn ( array $e ): bool => $e['msgid'] === 'Template' && (string) $e['msgctxt'] === self::CONTEXT ) );

        $this->assertSame( 'Sjabloon', (string) ( $match[0]['msgstr'][0] ?? '' ) );
    }
}
