<?php
namespace TT\Modules\Export;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ScopeGatedExporter (#3155) — for exporters whose gate is not a WordPress
 * capability.
 *
 * `ExporterInterface::requiredCap()` returns a capability string, which
 * `ExportService::run()` and `ExportRestController::list_exporters()` both
 * check with `user_can()`. That works for the fifty-three exporters whose
 * entity has a legacy capability mapped to it. It cannot express a gate on
 * a **matrix-only** entity — `measurements` has no `tt_*` capability, by
 * design — so the one exporter over that entity returned `''`, which turns
 * the coarse gate into a no-op. The route in front of it is bare
 * `is_user_logged_in()`, so a no-op there is the whole gate gone.
 *
 * Rather than invent a phantom capability for a matrix-only entity, an
 * exporter that answers to the matrix implements this instead. The pipeline
 * prefers it over `requiredCap()` when present, so the coarse gate becomes
 * real without changing the contract the other fifty-three implement.
 *
 * This is still the *coarse* gate: `collect()` remains the authoritative
 * one, and is also where row-level scope is applied.
 */
interface ScopeGatedExporter {

    /**
     * May this user run the exporter at all? Answered in whatever model the
     * exporter's entity actually uses.
     */
    public function isAvailableFor( int $user_id ): bool;
}
