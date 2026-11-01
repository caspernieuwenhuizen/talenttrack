<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A form's completion step could not write what the answer needs: an id it
 * expected came back 0. `TaskEngine::complete()` rolls the completion back,
 * so the task stays open and nothing half-written is committed (#4550).
 */
final class CompletionStepFailed extends \RuntimeException {}
