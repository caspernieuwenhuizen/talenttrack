<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A generator that hangs off master data (teams, players, people) and can
 * therefore be driven generically: the orchestrator hands it the finished
 * context and asks for a row count.
 *
 * The master generators (users, people, teams, players) stay outside this
 * contract — each returns the entity set the next one needs, and the
 * "use the club's existing rows instead" opt-out only applies to them.
 */
interface DependentGeneratorInterface extends GeneratorInterface {

    public static function fromContext( GeneratorContext $ctx ): self;

    /** @return int rows written */
    public function generate(): int;
}
