<?php
/**
 * Migration 0115 — seed `idea_status` as a `tt_lookups` lookup_type
 * so the nine internal idea statuses (#0009 ideas board) become
 * operator-editable + translatable through the frontend Lookups admin
 * (#803 audit; #840).
 *
 * Stored values stay sacred (the `IdeaStatus::*` constants are the
 * contract with `tt_dev_ideas.status`). The lookup row `name` matches
 * the stored value so `LookupTranslator::byTypeAndName('idea_status',
 * $value)` resolves directly.
 *
 * Note on author-facing labels: `IdeaStatus::authorFacingLabel()`
 * collapses 9 internal statuses to 4 buckets (In review / Not accepted /
 * Accepted) and stays as static text — it's a curated rollup of the
 * underlying statuses, not a per-status label, so the lookup admin
 * doesn't expose it.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0115_seed_idea_status_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = $p . 'tt_lookups';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );

        $seeds = [
            [ 'name' => 'submitted',          'sort_order' => 10, 'labels' => [ 'en_US' => 'Submitted',          'nl_NL' => 'Ingediend',           'fr_FR' => 'Soumis',           'de_DE' => 'Eingereicht',         'es_ES' => 'Enviado'          ] ],
            [ 'name' => 'refining',           'sort_order' => 20, 'labels' => [ 'en_US' => 'Refining',           'nl_NL' => 'Aan het verfijnen',   'fr_FR' => 'En affinage',      'de_DE' => 'In Verfeinerung',     'es_ES' => 'En refinamiento'  ] ],
            [ 'name' => 'ready-for-approval', 'sort_order' => 30, 'labels' => [ 'en_US' => 'Ready for approval', 'nl_NL' => 'Klaar voor goedkeuring','fr_FR' => 'Prêt pour approbation','de_DE' => 'Bereit zur Freigabe','es_ES' => 'Listo para aprobación' ] ],
            [ 'name' => 'rejected',           'sort_order' => 40, 'labels' => [ 'en_US' => 'Rejected',           'nl_NL' => 'Afgewezen',           'fr_FR' => 'Rejeté',           'de_DE' => 'Abgelehnt',           'es_ES' => 'Rechazado'        ] ],
            [ 'name' => 'promoting',          'sort_order' => 50, 'labels' => [ 'en_US' => 'Promoting…',         'nl_NL' => 'Bezig met bevorderen…','fr_FR' => 'Promotion en cours…','de_DE' => 'Wird befördert…',    'es_ES' => 'Promocionando…'   ] ],
            [ 'name' => 'promoted',           'sort_order' => 60, 'labels' => [ 'en_US' => 'Accepted',           'nl_NL' => 'Geaccepteerd',        'fr_FR' => 'Acceptée',         'de_DE' => 'Angenommen',          'es_ES' => 'Aceptada'         ] ],
            [ 'name' => 'promotion-failed',   'sort_order' => 70, 'labels' => [ 'en_US' => 'Promotion failed',   'nl_NL' => 'Bevorderen mislukt',  'fr_FR' => 'Échec de la promotion','de_DE' => 'Beförderung fehlgeschlagen','es_ES' => 'Promoción fallida' ] ],
            [ 'name' => 'in-progress',        'sort_order' => 80, 'labels' => [ 'en_US' => 'In progress',        'nl_NL' => 'In behandeling',      'fr_FR' => 'En cours',         'de_DE' => 'In Bearbeitung',      'es_ES' => 'En curso'         ] ],
            [ 'name' => 'done',               'sort_order' => 90, 'labels' => [ 'en_US' => 'Done',               'nl_NL' => 'Klaar',               'fr_FR' => 'Terminé',          'de_DE' => 'Erledigt',            'es_ES' => 'Hecho'            ] ],
        ];

        foreach ( $seeds as $seed ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'idea_status' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'idea_status',
                    'name'        => (string) $seed['name'],
                    'sort_order'  => (int) $seed['sort_order'],
                ] );
                $lookup_id = (int) $wpdb->insert_id;
            } else {
                $lookup_id = $existing_id;
            }
            if ( $lookup_id <= 0 ) continue;

            foreach ( $seed['labels'] as $locale => $value ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, 'lookup', %d, 'name', %s, %s, %s)",
                    $club_id, $lookup_id, $locale, $value, $now
                ) );
            }
        }
    }
};
