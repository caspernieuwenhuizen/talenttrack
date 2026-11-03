<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * UnknownFactKeyException (#4860) — a fact query named a dimension or a
 * filter the fact does not declare, and no alias maps it.
 *
 * `FactQuery` used to skip such a key, so the query ran wider than asked:
 * `decided_by_eq=5` on a fact without `decided_by` counted every decider.
 * Never a plausible number for the wrong rows: the key is refused, and each
 * caller decides how to say so. A live call (REST, the explorer, CSV export)
 * answers `400 unknown_dimension`; a stored report renders that one block as
 * "this block refers to a field that no longer exists" and the rest as usual.
 */
final class UnknownFactKeyException extends \RuntimeException {

    public const KIND_DIMENSION = 'dimension';
    public const KIND_FILTER    = 'filter';
    /** A stored reference to a fact or a measure that is no longer registered. */
    public const KIND_FACT      = 'fact';
    public const KIND_MEASURE   = 'measure';

    public function __construct(
        public readonly string $factKey,
        public readonly string $key,
        public readonly string $kind
    ) {
        // Developer-facing; readers get `readerMessage()`.
        parent::__construct( $kind . ' "' . $key . '" is not known to the fact "' . $factKey . '".' );
    }

    /** The message a reader sees, naming the key. */
    public function readerMessage(): string {
        return sprintf(
            /* translators: %s: the name of the field that no longer exists */
            __( 'This refers to a field that no longer exists: %s.', 'talenttrack' ),
            $this->key
        );
    }

    /**
     * The details a REST error carries.
     *
     * @return array{fact:string, key:string, kind:string}
     */
    public function details(): array {
        return [ 'fact' => $this->factKey, 'key' => $this->key, 'kind' => $this->kind ];
    }
}
