<?php
/**
 * Migration 0013 — Competition type lookup (v3.6.0).
 *
 * Introduces the `competition_type` lookup so the evaluation form's
 * Competition field can be a translatable dropdown instead of free
 * text. Seeds the two canonical values on first run — `League` and
 * `Cup` — only if no competition_type rows exist yet, so existing
 * installs that have already customized the lookup aren't overwritten.
 *
 * Labels are translated at display time via `__()` / LabelTranslator,
 * so the stored value stays in English but renders in the user's
 * locale via the `.po` file.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0013_competition_type_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_lookups WHERE lookup_type = %s",
            'competition_type'
        ) );
        if ( $existing > 0 ) {
            return;
        }

        foreach ( [
            [ 'League', 'Regular league match.', 1 ],
            [ 'Cup',    'Knock-out cup competition.', 2 ],
        ] as $row ) {
            $wpdb->insert( "{$p}tt_lookups", [
                'lookup_type' => 'competition_type',
                'name'        => $row[0],
                'description' => $row[1],
                'sort_order'  => $row[2],
            ] );
        }
    }
};
