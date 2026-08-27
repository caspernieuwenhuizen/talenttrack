<?php
/**
 * Migration 0240 — reconcile the two contact stores (#2962, epic #2960).
 *
 * Until #2961 nothing decided which of `tt_people.email` and
 * `wp_users.user_email` was authoritative, and until #2962 nothing kept
 * them aligned. Existing installs therefore carry three populations:
 * rows where the two agree, rows where one side is empty, and rows where
 * both are populated and differ.
 *
 * WHAT THIS DOES, AND WHAT IT REFUSES TO DO
 *
 * Where one side is empty and the other is not, the populated value is
 * copied across. Nothing is lost and nothing is chosen — there was only
 * ever one address.
 *
 * Where BOTH are populated and they differ, this migration deliberately
 * changes nothing. Picking a winner here would silently redirect
 * somebody's mail, and the whole point of the epic is that mail stops
 * going quietly to the wrong place. Those rows are logged instead, so a
 * human can look at them.
 *
 * Phone is left alone entirely. `tt_people.phone` and the `tt_phone` user
 * meta have no shared history to reconcile — the meta is populated only
 * where someone deliberately entered it on their profile — so copying
 * either direction would be inventing data rather than recovering it.
 *
 * Idempotent: re-running copies nothing new, because after the first run
 * the only remaining mismatches are the conflicts it refuses to touch.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0240_reconcile_contact_stores';
    }

    public function up(): void {
        global $wpdb;

        $people = $wpdb->prefix . 'tt_people';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people ) ) !== $people ) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT p.id, p.club_id, p.email AS person_email, u.user_email AS account_email
               FROM {$people} p
               INNER JOIN {$wpdb->users} u ON u.ID = p.wp_user_id
              WHERE p.wp_user_id IS NOT NULL AND p.status = 'active'"
        );

        if ( ! is_array( $rows ) || $rows === [] ) return;

        $filled    = 0;
        $conflicts = [];

        foreach ( $rows as $row ) {
            $person_email  = trim( (string) ( $row->person_email ?? '' ) );
            $account_email = trim( (string) ( $row->account_email ?? '' ) );

            if ( $account_email === '' ) continue;

            if ( $person_email === '' ) {
                // Only one address exists. Copy it; nothing is being chosen.
                $wpdb->update(
                    $people,
                    [ 'email' => $account_email ],
                    [ 'id' => (int) $row->id, 'club_id' => (int) $row->club_id ]
                );
                $filled++;
                continue;
            }

            if ( strcasecmp( $person_email, $account_email ) !== 0 ) {
                $conflicts[] = [
                    'person_id'     => (int) $row->id,
                    'person_email'  => $person_email,
                    'account_email' => $account_email,
                ];
            }
        }

        if ( $filled > 0 ) {
            Logger::info( 'identity.reconcile.filled', [ 'count' => $filled ] );
        }

        if ( $conflicts !== [] ) {
            // Reported, not resolved. Whoever reads this decides which
            // address is the one the person actually uses.
            Logger::warning( 'identity.reconcile.conflicts', [
                'count'     => count( $conflicts ),
                'conflicts' => array_slice( $conflicts, 0, 50 ),
            ] );
        }
    }
};
