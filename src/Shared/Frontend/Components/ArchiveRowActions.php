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
 * the entity's edit capability; permanent delete is gated by
 * `tt_edit_settings` (the same destructive-op gate the wp-admin bulk
 * actions use) and additionally enforced server-side by the REST route.
 */
final class ArchiveRowActions {

    /**
     * @param string $plural   REST resource segment, e.g. 'players', 'goals'.
     * @param string $edit_cap Capability that gates Restore (the entity's edit cap).
     * @return array<string, array<string, mixed>>  Merge into a view's `row_actions`.
     */
    public static function build( string $plural, string $edit_cap ): array {
        return [
            'restore' => [
                'label'       => __( 'Restore', 'talenttrack' ),
                'rest_method' => 'POST',
                'rest_path'   => $plural . '/{id}/restore',
                'confirm'     => __( 'Restore this record?', 'talenttrack' ),
                'show_if'     => 'archived_at',
                'cap'         => $edit_cap,
            ],
            'delete_permanent' => [
                'label'       => __( 'Delete permanently', 'talenttrack' ),
                'rest_method' => 'DELETE',
                'rest_path'   => $plural . '/{id}/permanent',
                'confirm'     => __( 'Permanently delete this record? This cannot be undone and removes its linked data.', 'talenttrack' ),
                'variant'     => 'danger',
                'show_if'     => 'archived_at',
                'cap'         => 'tt_edit_settings',
            ],
        ];
    }
}
