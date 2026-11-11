<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReportTemplate (#5022, epic #4564) — a starting point for a Studio report:
 * the starting choices it fixes and the definition it starts with.
 *
 * Code, not a row. A report started from it is an ordinary report owned by
 * its builder; changing that report never changes the template.
 */
final class ReportTemplate {

    /**
     * @param array<string,string> $choices the starting choices the template fixes,
     *        keyed as `ReportStartRules::FIELDS`
     * @param array{blocks:list<array{id:string, key:string, options:array<string,mixed>, width:string}>} $definition
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly array $choices,
        public readonly array $definition
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'description' => $this->description,
            'choices'     => $this->choices,
            'definition'  => $this->definition,
        ];
    }
}
