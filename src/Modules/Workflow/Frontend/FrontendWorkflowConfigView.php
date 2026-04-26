<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Repositories\TemplateConfigRepository;
use TT\Modules\Workflow\Repositories\TriggersRepository;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendWorkflowConfigView — academy-admin surface to enable/disable
 * shipped templates and override their cadence + deadline. Reachable
 * at `?tt_view=workflow-config`, gated by `tt_configure_workflow_templates`.
 *
 * One row per registered template. The form posts back to itself; on
 * save, the per-install row in tt_workflow_template_config is upserted
 * and (for cron-typed templates) the cron_expression on the matching
 * tt_workflow_triggers row is updated in place.
 *
 * Minors-assignment policy lives at the bottom — single dropdown that
 * sets the four supported values on tt_config.tt_workflow_minors_assignment_policy.
 */
class FrontendWorkflowConfigView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_workflow_config_save';
    public const NONCE_FIELD  = '_tt_workflow_config_nonce';

    public static function render( int $user_id ): void {
        if ( ! current_user_can( 'tt_configure_workflow_templates' ) ) {
            self::renderHeader( __( 'Workflow templates', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to workflow configuration.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();

        $flash = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['tt_workflow_config_submit'] ) ) {
            if ( isset( $_POST[ self::NONCE_FIELD ] )
                && wp_verify_nonce( sanitize_text_field( (string) $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
                self::saveSubmission( $_POST );
                $flash = __( 'Configuration saved.', 'talenttrack' );
            } else {
                $flash = __( 'Security check failed. Please refresh and try again.', 'talenttrack' );
            }
        }

        self::renderHeader( __( 'Workflow templates', 'talenttrack' ) );

        $templates = WorkflowModule::registry()->all();
        $config_repo = new TemplateConfigRepository();
        $triggers_repo = new TriggersRepository();
        $cron_triggers = self::indexCronTriggersByKey();
        $current_policy = self::loadMinorsPolicy();

        ?>
        <style>
            .tt-wcfg-table { width: 100%; border-collapse: collapse; background:#fff; border:1px solid #e5e7ea; border-radius: 8px; overflow: hidden; margin-bottom: 24px; }
            .tt-wcfg-table th, .tt-wcfg-table td { padding: 10px 12px; text-align: left; font-size: 13px; vertical-align: top; }
            .tt-wcfg-table thead th { background: #f6f7f8; color: #5b6e75; font-weight: 600; border-bottom: 1px solid #e5e7ea; }
            .tt-wcfg-table tbody tr + tr td { border-top: 1px solid #f1f3f4; }
            .tt-wcfg-template-name { font-weight: 600; color: #1a1d21; }
            .tt-wcfg-template-desc { font-size: 12px; color: #5b6e75; margin-top: 4px; }
            .tt-wcfg-input { font-family: monospace; font-size: 12px; padding: 4px 6px; }
            .tt-wcfg-policy { background:#fff; border:1px solid #e5e7ea; border-radius: 8px; padding: 16px; }
        </style>

        <?php if ( $flash !== '' ) : ?>
            <div class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">
                <?php echo esc_html( $flash ); ?>
            </div>
        <?php endif; ?>

        <p style="color:#5b6e75; margin: 0 0 16px;">
            <?php esc_html_e( 'Enable or disable shipped templates and override their cadence and deadline. Changes take effect on the next cron tick or trigger.', 'talenttrack' ); ?>
        </p>

        <form method="post" class="tt-workflow-config-form">
            <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

            <table class="tt-wcfg-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Template', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Enabled', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Cadence', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Deadline offset', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $templates as $template ) :
                    $key = $template->key();
                    $config = $config_repo->findByKey( $key );
                    $enabled = $config !== null ? (bool) $config['enabled'] : true;
                    $cadence_override = (string) ( $config['cadence_override'] ?? '' );
                    $deadline_override = (string) ( $config['deadline_offset_override'] ?? '' );
                    $cron_row = $cron_triggers[ $key ] ?? null;
                    $current_cadence = $cadence_override !== ''
                        ? $cadence_override
                        : ( $cron_row ? (string) $cron_row['cron_expression'] : self::scheduleSummary( $template->defaultSchedule() ) );
                    $current_deadline = $deadline_override !== ''
                        ? $deadline_override
                        : $template->defaultDeadlineOffset();
                    ?>
                    <tr>
                        <td>
                            <div class="tt-wcfg-template-name"><?php echo esc_html( $template->name() ); ?></div>
                            <div class="tt-wcfg-template-desc"><?php echo esc_html( $template->description() ); ?></div>
                        </td>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="templates[<?php echo esc_attr( $key ); ?>][enabled]"
                                       value="1"
                                       <?php checked( $enabled ); ?> />
                                <?php esc_html_e( 'On', 'talenttrack' ); ?>
                            </label>
                        </td>
                        <td>
                            <?php if ( $cron_row !== null ) : ?>
                                <input type="text" class="tt-wcfg-input"
                                       name="templates[<?php echo esc_attr( $key ); ?>][cadence]"
                                       value="<?php echo esc_attr( $current_cadence ); ?>"
                                       placeholder="<?php echo esc_attr( (string) $cron_row['cron_expression'] ); ?>"
                                       style="width: 140px;" />
                            <?php else : ?>
                                <span style="color:#5b6e75; font-size: 12px;"><?php echo esc_html( $current_cadence ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" class="tt-wcfg-input"
                                   name="templates[<?php echo esc_attr( $key ); ?>][deadline]"
                                   value="<?php echo esc_attr( $current_deadline ); ?>"
                                   placeholder="<?php echo esc_attr( $template->defaultDeadlineOffset() ); ?>"
                                   style="width: 120px;" />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="tt-wcfg-policy">
                <h3 style="margin: 0 0 8px;"><?php esc_html_e( 'Minors assignment policy', 'talenttrack' ); ?></h3>
                <p style="color:#5b6e75; margin: 0 0 12px; font-size: 13px;">
                    <?php esc_html_e( 'How tasks for under-18 players are routed. Affects new tasks only — existing open tasks keep their original assignee.', 'talenttrack' ); ?>
                </p>
                <select name="minors_policy" class="tt-wcfg-input" style="width: 320px;">
                    <?php foreach ( self::policyOptions() as $value => $label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_policy, $value ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <p style="margin-top: 18px;">
                <button type="submit" name="tt_workflow_config_submit" value="1"
                        class="button button-primary" style="padding:8px 18px;">
                    <?php esc_html_e( 'Save configuration', 'talenttrack' ); ?>
                </button>
            </p>
        </form>
        <?php
    }

    /** @param array<string,mixed> $post */
    private static function saveSubmission( array $post ): void {
        $config_repo = new TemplateConfigRepository();
        $registry = WorkflowModule::registry();

        $submitted = is_array( $post['templates'] ?? null ) ? $post['templates'] : [];
        foreach ( $submitted as $key => $row ) {
            $template = $registry->get( (string) $key );
            if ( $template === null ) continue;
            if ( ! is_array( $row ) ) continue;

            $enabled = ! empty( $row['enabled'] );
            $cadence = isset( $row['cadence'] ) ? trim( sanitize_text_field( (string) $row['cadence'] ) ) : '';
            $deadline = isset( $row['deadline'] ) ? trim( sanitize_text_field( (string) $row['deadline'] ) ) : '';

            $cadence_override = $cadence !== '' ? $cadence : null;
            $deadline_override = $deadline !== '' ? $deadline : null;

            $config_repo->upsert( (string) $key, [
                'enabled'                  => $enabled,
                'cadence_override'         => $cadence_override,
                'deadline_offset_override' => $deadline_override,
            ] );

            // Mirror the cadence to the active trigger row so cron picks
            // it up. Only for cron-typed templates with an existing trigger.
            if ( $cadence !== '' ) {
                self::updateCronExpression( (string) $key, $cadence );
            }
            self::toggleTriggerEnabled( (string) $key, $enabled );
        }

        $policy = isset( $post['minors_policy'] ) ? sanitize_key( (string) $post['minors_policy'] ) : '';
        $valid = [ 'direct_only', 'parent_proxy', 'direct_with_parent_visibility', 'age_based' ];
        if ( in_array( $policy, $valid, true ) ) {
            self::saveMinorsPolicy( $policy );
        }
    }

    private static function updateCronExpression( string $template_key, string $expression ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_workflow_triggers',
            [ 'cron_expression' => $expression ],
            [ 'template_key' => $template_key, 'trigger_type' => 'cron' ]
        );
    }

    private static function toggleTriggerEnabled( string $template_key, bool $enabled ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_workflow_triggers',
            [ 'enabled' => $enabled ? 1 : 0 ],
            [ 'template_key' => $template_key ]
        );
    }

    /** @return array<string, array<string,mixed>> */
    private static function indexCronTriggersByKey(): array {
        $rows = ( new TriggersRepository() )->listEnabledByType( 'cron' );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (string) $row['template_key'] ] = $row;
        }
        // Also include rows that may currently be disabled — admin needs to see them to re-enable.
        global $wpdb;
        $disabled = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_triggers WHERE trigger_type = 'cron' AND enabled = 0",
            ARRAY_A
        );
        if ( is_array( $disabled ) ) {
            foreach ( $disabled as $row ) {
                $key = (string) $row['template_key'];
                if ( ! isset( $out[ $key ] ) ) $out[ $key ] = $row;
            }
        }
        return $out;
    }

    private static function loadMinorsPolicy(): string {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE config_key = %s LIMIT 1",
            'tt_workflow_minors_assignment_policy'
        ) );
        $valid = [ 'direct_only', 'parent_proxy', 'direct_with_parent_visibility', 'age_based' ];
        return in_array( (string) $value, $valid, true ) ? (string) $value : 'age_based';
    }

    private static function saveMinorsPolicy( string $policy ): void {
        global $wpdb;
        $wpdb->replace( $wpdb->prefix . 'tt_config', [
            'config_key'   => 'tt_workflow_minors_assignment_policy',
            'config_value' => $policy,
        ] );
    }

    /** @return array<string,string> */
    private static function policyOptions(): array {
        return [
            'age_based'                       => __( 'Age-based (default) — under 13: parent. 13-15: player + parent visibility. 16+: player only.', 'talenttrack' ),
            'direct_only'                     => __( 'Direct only — task always goes to the player.', 'talenttrack' ),
            'parent_proxy'                    => __( 'Parent proxy — task always goes to the parent.', 'talenttrack' ),
            'direct_with_parent_visibility'   => __( 'Player with parent visibility — task to the player; parent has read-only view.', 'talenttrack' ),
        ];
    }

    /** @param array{type:string, expression?:string, hook?:string} $schedule */
    private static function scheduleSummary( array $schedule ): string {
        $type = $schedule['type'] ?? 'manual';
        if ( $type === 'cron' && ! empty( $schedule['expression'] ) ) return (string) $schedule['expression'];
        if ( $type === 'event' && ! empty( $schedule['hook'] ) ) return 'event: ' . (string) $schedule['hook'];
        return $type;
    }
}
