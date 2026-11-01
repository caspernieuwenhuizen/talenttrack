<?php
namespace TT\Modules\Workflow\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A task form whose answer creates or changes records elsewhere: a consent
 * request, a prospect, a test training, a trial case.
 *
 * `FormInterface::serializeResponse()` only turns the posted fields into the
 * stored answer and writes nothing. The writes belong here, and
 * `TaskEngine::complete()` calls this only once the task row is marked
 * completed. A completion that fails therefore leaves nothing behind, and
 * the next attempt cannot create a second record for the same task.
 *
 * The step runs before the template's `onComplete()` and chain steps, and the
 * response it returns is the one they see and the one stored on the task, so
 * the ids of what it wrote reach both.
 */
interface CompletionStepInterface {

    /**
     * @param array<string,mixed> $task     The task row, already completed.
     * @param array<string,mixed> $raw      The validated POST input.
     * @param array<string,mixed> $response What `serializeResponse()` returned.
     * @return array<string,mixed>          The response, with the ids of what was written.
     */
    public function onCompleted( array $task, array $raw, array $response ): array;
}
