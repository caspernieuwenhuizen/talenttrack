<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoCoverage;

/**
 * #3565 — every dependent category has a writer of its own.
 *
 * The run calls `generate()` once per dependent category. A class that is
 * the writer for two categories therefore runs twice and writes everything
 * twice: `PipelineGenerator` wrote both `trials` and `pipeline`, and every
 * demo player with a trial had two overlapping cases, two of them open.
 */
final class DemoDependentWritersTest extends WP_UnitTestCase {

    public function test_no_class_writes_more_than_one_dependent_category(): void {
        $writers = DemoCoverage::dependentGenerators();

        $this->assertNotEmpty( $writers );
        $this->assertSame(
            array_values( $writers ),
            array_values( array_unique( $writers ) ),
            'a writer mapped to two categories runs twice per demo run'
        );
    }

    public function test_each_writer_declares_the_category_it_runs_for(): void {
        foreach ( DemoCoverage::dependentGenerators() as $category => $class ) {
            $this->assertSame( $category, $class::category(), $class . ' runs for a category it does not declare' );
        }
    }
}
