<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ArchiveRowActions (#1470) — the Restore + permanent-delete row actions
 * shared by every list-table management view that supports the archive
 * lifecycle (players, teams, evaluations, goals).
 *
 * Both actions carry `show_if => 'archived_at'`, so FrontendListTable's
 * JS renders them only on rows that are actually archived — Active rows
 * keep showing whatever the view's own actions are. Restore is gated by
 * the entity's edit capability; the recycle-bin move is gated by
 * `tt_edit_settings` (the same destructive-op gate the wp-admin bulk
 * actions use) and additionally enforced server-side by the REST route.
 *
 * #2023 — the archived-tier destructive action no longer hard-deletes.
 * It is now "Move to recycle bin" (`POST {plural}/{id}/trash`), a
 * REVERSIBLE move into the recycle bin (#2018 epic). The irreversible
 * permanent purge lives only in the recycle-bin view (#2024). The action
 * carries `confirm_cascade` so the list-table JS shows the itemized
 * cascade preview before the move, and `undo_path` so the success banner
 * can offer one-click restore-from-bin.
 */
final class ArchiveRowActions {

    /**
     * @param string      $plural   REST resource segment, e.g. 'players', 'goals'.
     * @param string      $edit_cap Capability that gates Restore (the entity's edit cap).
     * @param string|null $entity   ArchiveRepository entity key (e.g. 'player').
     *                              Drives the cascade-preview endpoint. Falls back
     *                              to a naive depluralisation when omitted, but
     *                              callers SHOULD pass it explicitly.
     * @return array<string, array<string, mixed>>  Merge into a view's `row_actions`.
     */
    public static function build( string $plural, string $edit_cap, ?string $entity = null ): array {
        $entity = $entity ?? rtrim( $plural, 's' );
        return [
            'restore' => [
                'label'       => __( 'Restore', 'talenttrack' ),
                'rest_method' => 'POST',
                'rest_path'   => $plural . '/{id}/restore',
                'confirm'     => __( 'Restore this record?', 'talenttrack' ),
                'show_if'     => 'archived_at',
                'cap'         => $edit_cap,
            ],
            // #2023 — reversible "Move to recycle bin". Replaces the old
            // archived-tier permanent delete. The JS shows the itemized
            // cascade preview (confirm_cascade) before the move and offers
            // Undo (undo_path → restore-from-bin) in the success banner.
            'trash' => [
                'label'           => __( 'Move to recycle bin', 'talenttrack' ),
                'rest_method'     => 'POST',
                'rest_path'       => $plural . '/{id}/trash',
                'confirm_cascade' => $entity,
                'confirm'         => __( 'Move this record to the recycle bin? You can restore it from the bin until it is purged.', 'talenttrack' ),
                'success_message' => __( 'Moved to the recycle bin.', 'talenttrack' ),
                'undo_path'       => $plural . '/{id}/restore',
                'variant'         => 'danger',
                'show_if'         => 'archived_at',
                'cap'             => 'tt_edit_settings',
            ],
        ];
    }
}
