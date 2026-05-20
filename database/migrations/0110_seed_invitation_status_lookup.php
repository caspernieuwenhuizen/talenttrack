<?php
/**
 * Migration 0108 — seed `invitation_status` as a `tt_lookups` lookup_type
 * so the four invitation statuses (pending / accepted / expired /
 * revoked) become operator-editable + translatable through the frontend
 * Lookups admin (#803, follow-up to #798).
 *
 * Why not retire the `InvitationStatus` PHP constants? They're the
 * contract between code (`if ($status === InvitationStatus::PENDING)`)
 * and the stored value in `tt_invitations.status`. Removing them would
 * mean the database schema's enum loses its code-side definition. Keep
 * them as keys; add per-locale rendered labels through `tt_lookups` +
 * `tt_translations`.
 *
 * Lookup row `name` matches the lowercase stored value (`'pending'`)
 * so `LookupTranslator::byTypeAndName('invitation_status', 'pending')`
 * resolves directly. Each row gets `tt_translations` rows for en_US
 * (canonical capitalised label) + nl_NL / fr_FR / de_DE / es_ES, so
 * the rendered label honours the current site locale.
 *
 * Idempotent: `INSERT IGNORE` on the unique
 * `(club_id, lookup_type, name)` and the
 * `(club_id, entity_type, entity_id, field, locale)` indexes mean
 * re-runs are no-ops. Operator-edited rows / translations are
 * preserved.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0108_seed_invitation_status_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = $p . 'tt_lookups';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = 1; // Single-tenant default; multi-tenant installs run the migration per tenant via the Activator.
        $now     = current_time( 'mysql', true );

        $seeds = [
            [
                'name'       => 'pending',
                'sort_order' => 10,
                'labels'     => [
                    'en_US' => 'Pending',
                    'nl_NL' => 'In afwachting',
                    'fr_FR' => 'En attente',
                    'de_DE' => 'Ausstehend',
                    'es_ES' => 'Pendiente',
                ],
            ],
            [
                'name'       => 'accepted',
                'sort_order' => 20,
                'labels'     => [
                    'en_US' => 'Accepted',
                    'nl_NL' => 'Geaccepteerd',
                    'fr_FR' => 'Acceptée',
                    'de_DE' => 'Akzeptiert',
                    'es_ES' => 'Aceptada',
                ],
            ],
            [
                'name'       => 'expired',
                'sort_order' => 30,
                'labels'     => [
                    'en_US' => 'Expired',
                    'nl_NL' => 'Verlopen',
                    'fr_FR' => 'Expirée',
                    'de_DE' => 'Abgelaufen',
                    'es_ES' => 'Caducada',
                ],
            ],
            [
                'name'       => 'revoked',
                'sort_order' => 40,
                'labels'     => [
                    'en_US' => 'Revoked',
                    'nl_NL' => 'Ingetrokken',
                    'fr_FR' => 'Révoquée',
                    'de_DE' => 'Widerrufen',
                    'es_ES' => 'Revocada',
                ],
            ],
        ];

        foreach ( $seeds as $seed ) {
            // Find or create the lookup row.
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'invitation_status' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'invitation_status',
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
