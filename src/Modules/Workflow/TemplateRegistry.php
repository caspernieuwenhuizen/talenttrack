<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\TaskTemplateInterface;

/**
 * TemplateRegistry — in-memory map of template_key → TaskTemplateInterface
 * instance. Templates register themselves here on module boot; the
 * engine looks them up by key when a trigger fires or a task is
 * completed.
 *
 * Singleton-ish (a process-wide instance lives in WorkflowModule). Tests
 * can construct a fresh registry directly.
 */
class TemplateRegistry {

    /** @var array<string, TaskTemplateInterface> */
    private array $templates = [];

    public function register( TaskTemplateInterface $template ): void {
        $this->templates[ $template->key() ] = $template;
    }

    public function has( string $key ): bool {
        return isset( $this->templates[ $key ] );
    }

    public function get( string $key ): ?TaskTemplateInterface {
        return $this->templates[ $key ] ?? null;
    }

    /** @return array<string, TaskTemplateInterface> */
    public function all(): array {
        return $this->templates;
    }
}
