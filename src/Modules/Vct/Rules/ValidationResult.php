<?php
namespace TT\Modules\Vct\Rules;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ValidationResult — return shape of `RulesEngine::validate()`.
 *
 * `passes` = no `block`-severity warnings. The engine returns this
 * envelope unchanged from `validate()`; REST callers translate it
 * into the `{error: {code, reasons[]}}` shape on failure or pass it
 * through on success (per spec § REST API → response envelopes).
 */
class ValidationResult {

    public bool $passes;

    /** @var list<array{code:string, severity:string, details:array<string,mixed>}> */
    public array $warnings;

    public int $total_load;

    /**
     * @param list<array{code:string, severity:string, details:array<string,mixed>}> $warnings
     */
    public function __construct( bool $passes, array $warnings, int $total_load ) {
        $this->passes     = $passes;
        $this->warnings   = $warnings;
        $this->total_load = $total_load;
    }

    /**
     * Filter to just the blocking reasons — what the REST 400 envelope
     * surfaces as `error.reasons[]`.
     *
     * @return list<array{code:string, details:array<string,mixed>}>
     */
    public function blockingReasons(): array {
        $out = [];
        foreach ( $this->warnings as $w ) {
            if ( ( $w['severity'] ?? '' ) === 'block' ) {
                $out[] = [ 'code' => $w['code'], 'details' => $w['details'] ];
            }
        }
        return $out;
    }
}
