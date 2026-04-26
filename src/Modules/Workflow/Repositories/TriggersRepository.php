<?php
namespace TT\Modules\Workflow\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TriggersRepository — data access for tt_workflow_triggers.
 *
 * Sprint 1 ships create + listEnabled. Sprint 2 dispatchers consume
 * listEnabledByType(). Sprint 5 admin UI uses listAll() + update.
 */
class TriggersRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_workflow_triggers';
    }

    /**
     * @param array{
     *   template_key:string,
     *   trigger_type:string,
     *   cron_expression?:?string,
     *   event_hook?:?string,
     *   enabled?:bool,
     *   config_json?:?string
     * } $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
            'template_key'    => (string) $data['template_key'],
            'trigger_type'    => (string) $data['trigger_type'],
            'cron_expression' => $data['cron_expression'] ?? null,
            'event_hook'      => $data['event_hook'] ?? null,
            'enabled'         => isset( $data['enabled'] ) ? ( $data['enabled'] ? 1 : 0 ) : 1,
            'config_json'     => $data['config_json'] ?? null,
        ];
        $ok = $wpdb->insert( $this->table(), $row );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Enabled triggers of a given type.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listEnabledByType( string $trigger_type ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE trigger_type = %s AND enabled = 1
             ORDER BY id ASC",
            $trigger_type
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
