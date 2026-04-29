<?php
namespace TT\Modules\Workflow\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TemplateConfigRepository — data access for tt_workflow_template_config.
 *
 * Per-install overrides for shipped templates: enable flag, cadence
 * override, deadline-offset override, assignee override.
 *
 * Sprint 1 ships findByKey() + upsert(). Sprint 5 admin UI consumes
 * both. The engine asks findByKey() at task-creation time and
 * applies overrides on top of the template's defaults.
 */
class TemplateConfigRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_workflow_template_config';
    }

    /** @return array<string,mixed>|null */
    public function findByKey( string $template_key ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE template_key = %s AND club_id = %d LIMIT 1",
            $template_key, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Upsert config for a template. Returns true on success.
     *
     * @param array{
     *   enabled?:bool,
     *   cadence_override?:?string,
     *   deadline_offset_override?:?string,
     *   assignee_override_json?:?string,
     *   dispatcher_chain?:?string,
     *   updated_by?:int
     * } $changes
     */
    public function upsert( string $template_key, array $changes ): bool {
        global $wpdb;
        $existing = $this->findByKey( $template_key );
        $row = [
            'club_id'                  => CurrentClub::id(),
            'template_key'             => $template_key,
            'enabled'                  => isset( $changes['enabled'] ) ? ( $changes['enabled'] ? 1 : 0 ) : ( $existing['enabled'] ?? 1 ),
            'cadence_override'         => $changes['cadence_override'] ?? ( $existing['cadence_override'] ?? null ),
            'deadline_offset_override' => $changes['deadline_offset_override'] ?? ( $existing['deadline_offset_override'] ?? null ),
            'assignee_override_json'   => $changes['assignee_override_json'] ?? ( $existing['assignee_override_json'] ?? null ),
            'dispatcher_chain'         => array_key_exists( 'dispatcher_chain', $changes )
                                            ? $changes['dispatcher_chain']
                                            : ( $existing['dispatcher_chain'] ?? null ),
            'updated_by'               => (int) ( $changes['updated_by'] ?? get_current_user_id() ),
        ];
        if ( $existing === null ) {
            $ok = $wpdb->insert( $this->table(), $row );
            return $ok !== false;
        }
        $ok = $wpdb->update( $this->table(), $row, [ 'template_key' => $template_key, 'club_id' => CurrentClub::id() ] );
        return $ok !== false;
    }
}
