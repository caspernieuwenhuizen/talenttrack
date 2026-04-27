<?php
namespace TT\Modules\Configuration\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;
use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\BrandFonts;

/**
 * ConfigurationPage — admin tabs for every config surface.
 *
 * v2.6.0: adds a "Player Custom Fields" tab. Retired in v2.11.0 —
 * custom fields now live under their own top-level admin page
 * (TalentTrack → Custom Fields, see CustomFieldsPage). The handlers
 * are still registered here because this page's init() is already
 * hooked at the right admin lifecycle point.
 */
class ConfigurationPage {

    public static function init(): void {
        add_action( 'admin_post_tt_save_config',   [ __CLASS__, 'handle_save_config' ] );
        add_action( 'admin_post_tt_save_lookup',   [ __CLASS__, 'handle_save_lookup' ] );
        add_action( 'admin_post_tt_delete_lookup', [ __CLASS__, 'handle_delete_lookup' ] );
        add_action( 'admin_post_tt_save_toggles',  [ __CLASS__, 'handle_save_toggles' ] );

        // Sprint 1H (v2.11.0): Custom Fields moved to its own top-level admin
        // page (TalentTrack → Custom Fields). Handlers register here because
        // ConfigurationModule::boot runs at the right admin lifecycle point.
        add_action( 'admin_post_tt_save_custom_field',   [ CustomFieldsPage::class, 'handleSave' ] );
        add_action( 'admin_post_tt_toggle_custom_field', [ CustomFieldsPage::class, 'handleToggle' ] );
    }

    public static function render_page(): void {
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : 'eval_types';
        $tabs = [
            'eval_types'      => __( 'Evaluation Types', 'talenttrack' ),
            'positions'       => __( 'Positions', 'talenttrack' ),
            'foot_options'    => __( 'Preferred Foot', 'talenttrack' ),
            'age_groups'      => __( 'Age Groups', 'talenttrack' ),
            'goal_statuses'   => __( 'Goal Statuses', 'talenttrack' ),
            'goal_priorities' => __( 'Goal Priorities', 'talenttrack' ),
            'att_statuses'    => __( 'Attendance Statuses', 'talenttrack' ),
            'rating'          => __( 'Rating Scale', 'talenttrack' ),
            'branding'        => __( 'Branding', 'talenttrack' ),
            'toggles'         => __( 'Feature Toggles', 'talenttrack' ),
            'backups'         => __( 'Backups', 'talenttrack' ),
            'wizard'          => __( 'Setup wizard', 'talenttrack' ),
            'translations'    => __( 'Translations', 'talenttrack' ),
            'audit'           => __( 'Audit Log', 'talenttrack' ),
        ];
        // #0025 — let other modules append config tabs without
        // editing this file. Existing keys win on collision.
        $tabs = apply_filters( 'tt_config_tabs', $tabs );

        // v2.12.0: eval_categories retired as a Configuration tab — it now
        // lives at TalentTrack → Evaluation Categories (top-level, supports
        // hierarchy). Redirect anyone bookmarked on the old URL.
        if ( $tab === 'eval_categories' ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tt-eval-categories' ) );
            exit;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'TalentTrack Configuration', 'talenttrack' ); ?> <?php \TT\Shared\Admin\HelpLink::render( 'configuration-branding' ); ?></h1>
            <?php if ( isset( $_GET['tt_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo $_GET['tt_msg'] === 'deleted' ? esc_html__( 'Deleted.', 'talenttrack' ) : esc_html__( 'Saved.', 'talenttrack' ); ?></p></div>
            <?php endif; ?>
            <?php if ( isset( $_GET['tt_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html( self::errorMessage( (string) $_GET['tt_error'] ) ); ?></p></div>
            <?php endif; ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $k => $l ) : ?>
                    <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$k" ) ); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $l ); ?></a>
                <?php endforeach; ?>
            </nav>
            <div style="margin-top:20px;">
            <?php
            switch ( $tab ) {
                case 'eval_types':      self::tab_eval_types(); break;
                case 'positions':       self::tab_lookup( 'position', __( 'Position', 'talenttrack' ), false, true ); break;
                case 'foot_options':    self::tab_lookup( 'foot_option', __( 'Foot Option', 'talenttrack' ), false, true ); break;
                case 'age_groups':      self::tab_lookup( 'age_group', __( 'Age Group', 'talenttrack' ), false, true ); break;
                case 'goal_statuses':   self::tab_lookup( 'goal_status', __( 'Goal Status', 'talenttrack' ), false, true ); break;
                case 'goal_priorities': self::tab_lookup( 'goal_priority', __( 'Goal Priority', 'talenttrack' ), false, true ); break;
                case 'att_statuses':    self::tab_lookup( 'attendance_status', __( 'Attendance Status', 'talenttrack' ), false, true ); break;
                case 'rating':          self::tab_rating(); break;
                case 'branding':        self::tab_branding(); break;
                case 'toggles':         self::tab_toggles(); break;
                case 'backups':         \TT\Modules\Backup\Admin\BackupSettingsPage::render(); break;
                case 'wizard':          self::tab_wizard(); break;
                case 'translations':    \TT\Modules\Translations\Admin\TranslationsConfigTab::render(); break;
                case 'audit':           self::tab_audit(); break;
                default:
                    // #0025 — tab key registered by another module.
                    // Modules listen on `tt_config_tab_<key>` to render.
                    if ( isset( $tabs[ $tab ] ) ) do_action( 'tt_config_tab_' . $tab );
            }
            ?>
            </div>
        </div>
        <?php
    }

    private static function errorMessage( string $code ): string {
        switch ( $code ) {
            case 'missing_label':   return __( 'Label is required.', 'talenttrack' );
            case 'invalid_type':    return __( 'Field type is invalid.', 'talenttrack' );
            case 'missing_options': return __( 'Select-type fields require at least one option.', 'talenttrack' );
            default:                return __( 'An error occurred.', 'talenttrack' );
        }
    }

    // Feature Toggles tab

    private static function tab_toggles(): void {
        /** @var FeatureToggleService $toggles */
        $toggles = Kernel::instance()->container()->get( 'toggles' );
        $definitions = FeatureToggleService::definitions();
        ?>
        <h2><?php esc_html_e( 'Feature Toggles', 'talenttrack' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Enable or disable specific TalentTrack features without code changes.', 'talenttrack' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_toggles', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_toggles" />
            <table class="widefat striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th style="width:120px;"><?php esc_html_e( 'State', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Feature', 'talenttrack' ); ?></th>
                        <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $definitions as $key => $def ) :
                        $enabled = $toggles->isEnabled( $key );
                    ?>
                        <tr>
                            <td>
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input type="checkbox" name="toggles[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $enabled ); ?> />
                                    <span><?php echo $enabled ? esc_html__( 'Enabled', 'talenttrack' ) : esc_html__( 'Disabled', 'talenttrack' ); ?></span>
                                </label>
                            </td>
                            <td><strong><?php echo esc_html( (string) $def['label'] ); ?></strong><br/>
                                <code style="font-size:11px;color:#888;"><?php echo esc_html( FeatureToggleService::PREFIX . $key ); ?></code></td>
                            <td><?php echo esc_html( (string) $def['description'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Save Toggles', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    // Setup wizard tab

    private static function tab_wizard(): void {
        $state         = \TT\Modules\Onboarding\OnboardingState::get();
        $is_completed  = \TT\Modules\Onboarding\OnboardingState::isCompleted();
        $current_step  = (string) ( $state['step'] ?? 'welcome' );
        $wizard_url    = admin_url( 'admin.php?page=tt-welcome' );
        $resume_url    = $wizard_url;
        $restart_url   = admin_url( 'admin.php?page=tt-welcome&force_welcome=1' );
        $step_labels   = [
            'welcome'     => __( 'Welcome', 'talenttrack' ),
            'academy'     => __( 'Academy basics', 'talenttrack' ),
            'first_team'  => __( 'First team', 'talenttrack' ),
            'first_admin' => __( 'First admin', 'talenttrack' ),
            'done'        => __( 'Done', 'talenttrack' ),
        ];
        $current_label = $step_labels[ $current_step ] ?? $current_step;
        ?>
        <h2><?php esc_html_e( 'Setup wizard', 'talenttrack' ); ?></h2>
        <p style="max-width:680px;">
            <?php esc_html_e( 'The setup wizard walks you through naming your academy, picking a primary colour, creating your first team, and registering your first administrator. You can stop and resume at any point — your progress is saved automatically.', 'talenttrack' ); ?>
        </p>

        <?php if ( $is_completed ) : ?>
            <div class="notice notice-success inline" style="margin:16px 0;">
                <p>
                    <strong><?php esc_html_e( 'Wizard completed.', 'talenttrack' ); ?></strong>
                    <?php esc_html_e( 'You can run it again any time — restarting won\'t delete the data you already entered.', 'talenttrack' ); ?>
                </p>
            </div>
            <p>
                <a href="<?php echo esc_url( $restart_url ); ?>" class="button"><?php esc_html_e( 'Run wizard again', 'talenttrack' ); ?></a>
            </p>
        <?php else : ?>
            <div class="notice notice-info inline" style="margin:16px 0;">
                <p>
                    <strong><?php esc_html_e( 'In progress.', 'talenttrack' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %s is the current wizard step label. */
                        esc_html__( 'Last step you were on: %s.', 'talenttrack' ),
                        '<em>' . esc_html( $current_label ) . '</em>'
                    );
                    ?>
                </p>
            </div>
            <p>
                <a href="<?php echo esc_url( $resume_url ); ?>" class="button button-primary"><?php esc_html_e( 'Resume setup wizard', 'talenttrack' ); ?></a>
                <a href="<?php echo esc_url( $restart_url ); ?>" class="button" style="margin-left:8px;"><?php esc_html_e( 'Start over', 'talenttrack' ); ?></a>
            </p>
        <?php endif; ?>
        <?php
    }

    // Audit Log tab

    private static function tab_audit(): void {
        /** @var AuditService $audit */
        $audit = Kernel::instance()->container()->get( 'audit' );
        /** @var FeatureToggleService $toggles */
        $toggles = Kernel::instance()->container()->get( 'toggles' );

        $is_on = $toggles->isEnabled( 'audit_log' );
        $filters = [];
        if ( ! empty( $_GET['f_action'] ) )      $filters['action']      = sanitize_text_field( wp_unslash( (string) $_GET['f_action'] ) );
        if ( ! empty( $_GET['f_entity_type'] ) ) $filters['entity_type'] = sanitize_text_field( wp_unslash( (string) $_GET['f_entity_type'] ) );
        if ( ! empty( $_GET['f_user_id'] ) )     $filters['user_id']     = absint( $_GET['f_user_id'] );

        $entries = $audit->recent( 100, $filters );
        ?>
        <h2><?php esc_html_e( 'Audit Log', 'talenttrack' ); ?></h2>
        <?php if ( ! $is_on ) : ?>
            <div class="notice notice-warning inline"><p>
                <?php esc_html_e( 'Audit logging is currently disabled. Enable it under Feature Toggles to start recording entries.', 'talenttrack' ); ?>
            </p></div>
        <?php endif; ?>

        <form method="get" style="margin:10px 0;">
            <input type="hidden" name="page" value="tt-config" />
            <input type="hidden" name="tab"  value="audit" />
            <input type="text" name="f_action"      value="<?php echo esc_attr( $filters['action'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Action (e.g. player.saved)', 'talenttrack' ); ?>" style="width:220px" />
            <input type="text" name="f_entity_type" value="<?php echo esc_attr( $filters['entity_type'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Entity type', 'talenttrack' ); ?>" style="width:140px" />
            <input type="number" name="f_user_id"   value="<?php echo esc_attr( (string) ( $filters['user_id'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'User ID', 'talenttrack' ); ?>" style="width:100px" />
            <?php submit_button( __( 'Filter', 'talenttrack' ), 'secondary', 'submit', false ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=tt-config&tab=audit' ) ); ?>" class="button"><?php esc_html_e( 'Clear', 'talenttrack' ); ?></a>
        </form>

        <table class="widefat striped">
            <thead><tr>
                <th style="width:150px;"><?php esc_html_e( 'When', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'User', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Action', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Entity', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'IP', 'talenttrack' ); ?></th>
                <th><?php esc_html_e( 'Payload', 'talenttrack' ); ?></th>
            </tr></thead>
            <tbody>
                <?php if ( empty( $entries ) ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No audit entries match your filters.', 'talenttrack' ); ?></td></tr>
                <?php else : foreach ( $entries as $e ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( (string) $e->created_at ); ?></code></td>
                        <td><?php echo esc_html( $e->user_name ?: '(system)' ); ?></td>
                        <td><code><?php echo esc_html( (string) $e->action ); ?></code></td>
                        <td><?php echo esc_html( $e->entity_type ? "{$e->entity_type}#{$e->entity_id}" : '—' ); ?></td>
                        <td><?php echo esc_html( (string) $e->ip_address ); ?></td>
                        <td style="font-size:11px;font-family:monospace;max-width:400px;word-break:break-all;"><?php echo esc_html( (string) $e->payload ); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
    }

    // Lookup-table tabs (unchanged)

    private static function tab_lookup( string $type, string $label, bool $show_desc, bool $show_sort ): void {
        $action = isset( $_GET['crud'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['crud'] ) ) : 'list';
        $id     = isset( $_GET['lookup_id'] ) ? absint( $_GET['lookup_id'] ) : 0;
        $tab    = self::tab_key_for_type( $type );

        if ( $action === 'edit' || $action === 'new' ) {
            $item = ( $action === 'edit' && $id ) ? QueryHelpers::get_lookup( $id ) : null;
            self::render_lookup_form( $type, $label, $item, $show_desc, $show_sort, $tab );
            return;
        }

        $items = QueryHelpers::get_lookups( $type );
        ?>
        <h2><?php echo esc_html( $label ); ?>s <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=new" ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h2>
        <?php if ( $show_sort && ! empty( $items ) ) : ?>
            <p style="color:#666; font-size:13px; max-width:700px;">
                <?php esc_html_e( 'Drag rows by the handle on the left to reorder. Order is saved automatically.', 'talenttrack' ); ?>
            </p>
        <?php endif; ?>
        <table class="widefat striped tt-sortable-table" style="max-width:760px;"><thead><tr>
            <?php if ( $show_sort ) : ?><th style="width:30px;"></th><?php endif; ?>
            <th style="width:50px"><?php esc_html_e( 'Order', 'talenttrack' ); ?></th>
            <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
            <?php if ( $show_desc ) : ?><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><?php endif; ?>
            <th style="width:120px"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
        </tr></thead><tbody data-tt-sortable="<?php echo $show_sort ? '1' : '0'; ?>">
        <?php if ( empty( $items ) ) : ?>
            <tr><td colspan="<?php echo ( $show_desc ? 4 : 3 ) + ( $show_sort ? 1 : 0 ); ?>"><?php esc_html_e( 'No items.', 'talenttrack' ); ?></td></tr>
        <?php else : foreach ( $items as $item ) : ?>
            <tr data-id="<?php echo (int) $item->id; ?>">
                <?php if ( $show_sort ) : ?><td class="tt-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'talenttrack' ); ?>">⋮⋮</td><?php endif; ?>
                <td class="tt-sort-order-cell"><?php echo (int) $item->sort_order; ?></td>
                <td><strong><?php echo esc_html( (string) $item->name ); ?></strong></td>
                <?php if ( $show_desc ) : ?><td><?php echo esc_html( (string) $item->description ); ?></td><?php endif; ?>
                <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=edit&lookup_id={$item->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_lookup&id={$item->id}&tab=$tab" ), 'tt_del_lookup_' . $item->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td>
            </tr>
        <?php endforeach; endif; ?></tbody></table>
        <?php if ( $show_sort && ! empty( $items ) ) : ?>
            <?php \TT\Shared\Admin\DragReorder::renderScript( 'lookup', $type ); ?>
        <?php endif; ?>
        <?php
    }

    private static function render_lookup_form( string $type, string $label, ?object $item, bool $show_desc, bool $show_sort, string $tab ): void {
        $is_edit = $item !== null;
        ?>
        <h2><?php echo $is_edit ? esc_html__( 'Edit', 'talenttrack' ) : esc_html__( 'Add', 'talenttrack' ); ?> <?php echo esc_html( $label ); ?>
            <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab" ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;">
            <?php wp_nonce_field( 'tt_save_lookup', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_lookup" />
            <input type="hidden" name="lookup_type" value="<?php echo esc_attr( $type ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
            <?php if ( $is_edit ) : ?><input type="hidden" name="id" value="<?php echo (int) $item->id; ?>" /><?php endif; ?>
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Name', 'talenttrack' ); ?> *</th><td><input type="text" name="name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="regular-text" required /></td></tr>
                <?php if ( $show_desc ) : ?><tr><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><td><input type="text" name="description" value="<?php echo esc_attr( $item->description ?? '' ); ?>" class="large-text" /></td></tr><?php endif; ?>
                <?php if ( $show_sort ) : ?><tr><th><?php esc_html_e( 'Sort Order', 'talenttrack' ); ?></th><td><input type="number" name="sort_order" value="<?php echo (int) ( $item->sort_order ?? 0 ); ?>" min="0" /></td></tr><?php endif; ?>
            </table>

            <?php self::renderTranslationsSection( $item, $show_desc ); ?>

            <?php submit_button( $is_edit ? __( 'Update', 'talenttrack' ) : __( 'Add', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    /**
     * v3.6.0 — per-locale translations block shown on every lookup edit
     * form. One row per installed site locale, with Name and (when
     * applicable) Description inputs. Leave a field empty to fall back
     * to the canonical name + the `.po` translation. Everything lives
     * under the `tt_i18n[<locale>][name|description]` input namespace
     * so handle_save_lookup() can reconstruct the JSON cleanly.
     */
    private static function renderTranslationsSection( ?object $item, bool $show_desc ): void {
        $translations = \TT\Infrastructure\Query\LookupTranslator::decode( $item );
        $locales      = \TT\Infrastructure\Query\LookupTranslator::installedLocales();
        if ( ! $locales ) return;
        ?>
        <h3 style="margin-top:18px;"><?php esc_html_e( 'Translations', 'talenttrack' ); ?></h3>
        <p class="description" style="max-width:560px;">
            <?php esc_html_e( 'Override the display name (and optionally description) per installed site locale. Leave a field empty to fall back to the canonical Name above and any matching translation shipped with the plugin.', 'talenttrack' ); ?>
        </p>
        <table class="form-table">
            <?php foreach ( $locales as $locale ) :
                $existing = $translations[ $locale ] ?? [ 'name' => '', 'description' => '' ];
            ?>
                <tr>
                    <th style="vertical-align:top;">
                        <label><code><?php echo esc_html( $locale ); ?></code></label>
                    </th>
                    <td>
                        <input type="text"
                               name="tt_i18n[<?php echo esc_attr( $locale ); ?>][name]"
                               value="<?php echo esc_attr( (string) ( $existing['name'] ?? '' ) ); ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Translated name', 'talenttrack' ); ?>" />
                        <?php if ( $show_desc ) : ?>
                            <br />
                            <input type="text"
                                   name="tt_i18n[<?php echo esc_attr( $locale ); ?>][description]"
                                   value="<?php echo esc_attr( (string) ( $existing['description'] ?? '' ) ); ?>"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e( 'Translated description', 'talenttrack' ); ?>"
                                   style="margin-top:4px;" />
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private static function tab_eval_types(): void {
        $action = isset( $_GET['crud'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['crud'] ) ) : 'list';
        $id     = isset( $_GET['lookup_id'] ) ? absint( $_GET['lookup_id'] ) : 0;
        $tab    = 'eval_types';
        if ( $action === 'edit' || $action === 'new' ) {
            $item = ( $action === 'edit' && $id ) ? QueryHelpers::get_lookup( $id ) : null;
            $meta = $item ? QueryHelpers::lookup_meta( $item ) : [];
            ?>
            <h2><?php echo $item ? esc_html__( 'Edit', 'talenttrack' ) : esc_html__( 'Add', 'talenttrack' ); ?> <?php esc_html_e( 'Evaluation Type', 'talenttrack' ); ?>
                <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab" ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back', 'talenttrack' ); ?></a></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:500px;">
                <?php wp_nonce_field( 'tt_save_lookup', 'tt_nonce' ); ?>
                <input type="hidden" name="action" value="tt_save_lookup" />
                <input type="hidden" name="lookup_type" value="eval_type" />
                <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
                <?php if ( $item ) : ?><input type="hidden" name="id" value="<?php echo (int) $item->id; ?>" /><?php endif; ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Name', 'talenttrack' ); ?> *</th><td><input type="text" name="name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" class="regular-text" required /></td></tr>
                    <tr><th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th><td><input type="text" name="description" value="<?php echo esc_attr( $item->description ?? '' ); ?>" class="large-text" /></td></tr>
                    <tr><th><?php esc_html_e( 'Requires Match Details', 'talenttrack' ); ?></th><td><label><input type="checkbox" name="requires_match_details" value="1" <?php checked( ! empty( $meta['requires_match_details'] ) ); ?> /> <?php esc_html_e( 'Prompts for opponent, competition, result, home/away, minutes played', 'talenttrack' ); ?></label></td></tr>
                    <tr><th><?php esc_html_e( 'Sort Order', 'talenttrack' ); ?></th><td><input type="number" name="sort_order" value="<?php echo (int) ( $item->sort_order ?? 0 ); ?>" min="0" /></td></tr>
                </table>
                <?php submit_button( $item ? __( 'Update', 'talenttrack' ) : __( 'Add', 'talenttrack' ) ); ?>
            </form>
            <?php
            return;
        }
        $items = QueryHelpers::get_eval_types();
        ?>
        <h2><?php esc_html_e( 'Evaluation Types', 'talenttrack' ); ?> <a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=new" ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'talenttrack' ); ?></a></h2>
        <?php if ( ! empty( $items ) ) : ?>
            <p style="color:#666; font-size:13px; max-width:700px;">
                <?php esc_html_e( 'Drag rows by the handle on the left to reorder. Order is saved automatically.', 'talenttrack' ); ?>
            </p>
        <?php endif; ?>
        <table class="widefat striped tt-sortable-table" style="max-width:760px;"><thead><tr>
            <th style="width:30px;"></th>
            <th style="width:50px"><?php esc_html_e( 'Order', 'talenttrack' ); ?></th>
            <th><?php esc_html_e( 'Name', 'talenttrack' ); ?></th>
            <th><?php esc_html_e( 'Description', 'talenttrack' ); ?></th>
            <th><?php esc_html_e( 'Match Details?', 'talenttrack' ); ?></th>
            <th style="width:120px"><?php esc_html_e( 'Actions', 'talenttrack' ); ?></th>
        </tr></thead><tbody data-tt-sortable="1">
        <?php if ( empty( $items ) ) : ?><tr><td colspan="6"><?php esc_html_e( 'No evaluation types.', 'talenttrack' ); ?></td></tr>
        <?php else : foreach ( $items as $item ) : $meta = QueryHelpers::lookup_meta( $item ); ?>
            <tr data-id="<?php echo (int) $item->id; ?>">
                <td class="tt-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'talenttrack' ); ?>">⋮⋮</td>
                <td class="tt-sort-order-cell"><?php echo (int) $item->sort_order; ?></td>
                <td><strong><?php echo esc_html( (string) $item->name ); ?></strong></td>
                <td><?php echo esc_html( (string) $item->description ); ?></td>
                <td><?php echo ! empty( $meta['requires_match_details'] ) ? '✓' : '—'; ?></td>
                <td><a href="<?php echo esc_url( admin_url( "admin.php?page=tt-config&tab=$tab&crud=edit&lookup_id={$item->id}" ) ); ?>"><?php esc_html_e( 'Edit', 'talenttrack' ); ?></a> | <a href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=tt_delete_lookup&id={$item->id}&tab=$tab" ), 'tt_del_lookup_' . $item->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete?', 'talenttrack' ) ); ?>')" style="color:#b32d2e;"><?php esc_html_e( 'Delete', 'talenttrack' ); ?></a></td>
            </tr>
        <?php endforeach; endif; ?></tbody></table>
        <?php if ( ! empty( $items ) ) : ?>
            <?php \TT\Shared\Admin\DragReorder::renderScript( 'lookup', 'eval_type' ); ?>
        <?php endif; ?>
        <?php
    }

    private static function tab_rating(): void {
        ?>
        <h2><?php esc_html_e( 'Rating Scale', 'talenttrack' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" /><input type="hidden" name="tab" value="rating" />
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Minimum', 'talenttrack' ); ?></th><td><input type="number" name="cfg[rating_min]" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_min', '1' ) ); ?>" min="0" max="10" step="0.5" /></td></tr>
                <tr><th><?php esc_html_e( 'Maximum', 'talenttrack' ); ?></th><td><input type="number" name="cfg[rating_max]" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_max', '5' ) ); ?>" min="1" max="100" step="0.5" /></td></tr>
                <tr><th><?php esc_html_e( 'Step', 'talenttrack' ); ?></th><td><input type="number" name="cfg[rating_step]" value="<?php echo esc_attr( QueryHelpers::get_config( 'rating_step', '0.5' ) ); ?>" min="0.1" max="1" step="0.1" /></td></tr>
                <tr>
                    <th><?php esc_html_e( 'Evaluation display', 'talenttrack' ); ?></th>
                    <td>
                        <?php $eval_display = QueryHelpers::get_config( 'eval_display_mode', 'detailed' ); ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="radio" name="cfg[eval_display_mode]" value="detailed" <?php checked( $eval_display, 'detailed' ); ?> />
                            <?php esc_html_e( 'Detailed — show every subcategory rating.', 'talenttrack' ); ?>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="cfg[eval_display_mode]" value="summary" <?php checked( $eval_display, 'summary' ); ?> />
                            <?php esc_html_e( 'Summary — show only the four main categories (Technical, Tactical, Physical, Mental).', 'talenttrack' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Club-wide default. Each coach can override their own preference under Profile.', 'talenttrack' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Low-rating comment', 'talenttrack' ); ?></th>
                    <td>
                        <?php $low_threshold = (float) QueryHelpers::get_config( 'eval_low_rating_threshold', '3' ); ?>
                        <?php $low_required  = QueryHelpers::get_config( 'eval_low_rating_require_comment', 'soft' ); ?>
                        <label>
                            <?php esc_html_e( 'Threshold:', 'talenttrack' ); ?>
                            <input type="number" name="cfg[eval_low_rating_threshold]" value="<?php echo esc_attr( (string) $low_threshold ); ?>" min="0" max="10" step="0.5" style="width:80px;" />
                        </label>
                        <p class="description" style="margin:6px 0;">
                            <?php esc_html_e( 'Ratings at or below this value trigger a "consider adding a comment" prompt.', 'talenttrack' ); ?>
                        </p>
                        <label style="display:block;margin-top:6px;">
                            <input type="radio" name="cfg[eval_low_rating_require_comment]" value="soft" <?php checked( $low_required, 'soft' ); ?> />
                            <?php esc_html_e( 'Soft — show a warning but allow saving without a comment.', 'talenttrack' ); ?>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="cfg[eval_low_rating_require_comment]" value="hard" <?php checked( $low_required, 'hard' ); ?> />
                            <?php esc_html_e( 'Hard — block save until a comment is provided.', 'talenttrack' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Save', 'talenttrack' ) ); ?>
        </form>
        <?php
    }

    private static function tab_branding(): void {
        wp_enqueue_media();
        $logo = QueryHelpers::get_config( 'logo_url', '' );
        ?>
        <h2><?php esc_html_e( 'Branding', 'talenttrack' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'tt_save_config', 'tt_nonce' ); ?>
            <input type="hidden" name="action" value="tt_save_config" /><input type="hidden" name="tab" value="branding" />
            <table class="form-table">
                <tr><th><?php esc_html_e( 'Academy Name', 'talenttrack' ); ?></th><td><input type="text" name="cfg[academy_name]" value="<?php echo esc_attr( QueryHelpers::get_config( 'academy_name', '' ) ); ?>" class="regular-text" /></td></tr>
                <tr><th><?php esc_html_e( 'Logo', 'talenttrack' ); ?></th><td>
                    <div id="tt-logo-preview"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" style="max-height:70px" /><?php endif; ?></div>
                    <input type="hidden" name="cfg[logo_url]" id="tt_logo_url" value="<?php echo esc_url( $logo ); ?>" />
                    <button type="button" class="button" id="tt-upload-logo"><?php esc_html_e( 'Upload', 'talenttrack' ); ?></button>
                </td></tr>
                <?php $show_logo = (string) QueryHelpers::get_config( 'show_logo', '0' ); ?>
                <tr><th><?php esc_html_e( 'Show logo on dashboard', 'talenttrack' ); ?></th><td>
                    <input type="hidden" name="cfg[show_logo]" value="0" />
                    <label>
                        <input type="checkbox" name="cfg[show_logo]" value="1" <?php checked( $show_logo, '1' ); ?> />
                        <?php esc_html_e( 'Render the logo image in the dashboard header. Off by default — the academy name is the primary brand mark.', 'talenttrack' ); ?>
                    </label>
                </td></tr>
                <tr><th><?php esc_html_e( 'Primary Color', 'talenttrack' ); ?></th><td><input type="color" name="cfg[primary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'primary_color', '#0b3d2e' ) ); ?>" /></td></tr>
                <tr><th><?php esc_html_e( 'Secondary Color', 'talenttrack' ); ?></th><td><input type="color" name="cfg[secondary_color]" value="<?php echo esc_attr( QueryHelpers::get_config( 'secondary_color', '#e8b624' ) ); ?>" /></td></tr>
                <?php $tile_scale = (int) QueryHelpers::get_config( 'tile_scale', '100' ); ?>
                <tr><th><?php esc_html_e( 'Tile size', 'talenttrack' ); ?></th><td>
                    <input type="number" name="cfg[tile_scale]" value="<?php echo esc_attr( (string) $tile_scale ); ?>" min="50" max="150" step="5" style="width:80px;" /> %
                    <p class="description"><?php esc_html_e( 'Scales padding, icons, and typography of the dashboard tiles. 50–150%, default 100%.', 'talenttrack' ); ?></p>
                </td></tr>
            </table>

            <?php
            // #0019 Sprint 6 — Legacy wp-admin menu toggle
            $show_legacy = (string) QueryHelpers::get_config( 'show_legacy_menus', '0' );
            ?>
            <h3 style="margin-top:2.5rem;"><?php esc_html_e( 'Legacy wp-admin menus', 'talenttrack' ); ?></h3>
            <p class="description" style="max-width:680px;">
                <?php esc_html_e( 'TalentTrack admin tools moved to the frontend in v3.12.0. The legacy wp-admin menu entries (Players, Teams, Configuration, etc.) are hidden by default. Direct URLs continue to work — this toggle only controls menu visibility.', 'talenttrack' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Show legacy wp-admin menus', 'talenttrack' ); ?></th>
                    <td>
                        <input type="hidden" name="cfg[show_legacy_menus]" value="0" />
                        <label>
                            <input type="checkbox" name="cfg[show_legacy_menus]" value="1" <?php checked( $show_legacy, '1' ); ?> />
                            <?php esc_html_e( 'Re-expose the legacy menu entries.', 'talenttrack' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Per-site setting. The frontend admin tier remains primary; this is for users who prefer the wp-admin path while the frontend continues to mature.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
            </table>

            <?php
            // #0023 Sprint 1 — Theme inheritance + curated styling
            $theme_inherit = (string) QueryHelpers::get_config( 'theme_inherit', '0' );
            $font_display  = (string) QueryHelpers::get_config( 'font_display',  BrandFonts::SYSTEM_DEFAULT );
            $font_body     = (string) QueryHelpers::get_config( 'font_body',     BrandFonts::SYSTEM_DEFAULT );
            $colors = [
                'color_accent'  => [ __( 'Accent color',     'talenttrack' ), '#1e88e5' ],
                'color_danger'  => [ __( 'Danger color',     'talenttrack' ), '#b32d2e' ],
                'color_warning' => [ __( 'Warning color',    'talenttrack' ), '#dba617' ],
                'color_success' => [ __( 'Success color',    'talenttrack' ), '#00a32a' ],
                'color_info'    => [ __( 'Info color',       'talenttrack' ), '#2271b1' ],
                'color_focus'   => [ __( 'Focus ring color', 'talenttrack' ), '#1e88e5' ],
            ];
            ?>
            <h3 style="margin-top:2.5rem;"><?php esc_html_e( 'Theme inheritance & curated styling', 'talenttrack' ); ?></h3>
            <p class="description" style="max-width:680px;">
                <?php esc_html_e( 'Inheritance applies to fonts, colors, and basic links/buttons. TalentTrack’s structural design (spacing, layout, player cards) is unchanged. Pick fonts and accent colors below — fields left as “(System default)” or empty fall back to TalentTrack’s defaults.', 'talenttrack' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Inherit WordPress theme styles', 'talenttrack' ); ?></th>
                    <td>
                        <?php // Hidden 0 first so unchecking persists. ?>
                        <input type="hidden" name="cfg[theme_inherit]" value="0" />
                        <label>
                            <input type="checkbox" name="cfg[theme_inherit]" value="1" <?php checked( $theme_inherit, '1' ); ?> />
                            <?php esc_html_e( 'Defer typography, link color, headings and plain buttons to the active WP theme.', 'talenttrack' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Display font', 'talenttrack' ); ?></th>
                    <td>
                        <select name="cfg[font_display]">
                            <?php foreach ( BrandFonts::displayOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_display, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Used for headings, tile titles, and player card numbers.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Body font', 'talenttrack' ); ?></th>
                    <td>
                        <select name="cfg[font_body]">
                            <?php foreach ( BrandFonts::bodyOptions() as $value => $label ) : ?>
                                <option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $font_body, (string) $value ); ?>><?php echo esc_html( (string) $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Used for paragraphs, tables, and form fields.', 'talenttrack' ); ?></p>
                    </td>
                </tr>
                <?php foreach ( $colors as $key => $meta ) :
                    [ $label, $default ] = $meta; ?>
                    <tr>
                        <th><?php echo esc_html( $label ); ?></th>
                        <td><input type="color" name="cfg[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( QueryHelpers::get_config( $key, $default ) ); ?>" /></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php submit_button( __( 'Save', 'talenttrack' ) ); ?>
        </form>
        <script>
        jQuery(function($){ var f; $('#tt-upload-logo').on('click',function(e){ e.preventDefault(); if(!f)f=wp.media({title:'<?php echo esc_js( __( 'Select Logo', 'talenttrack' ) ); ?>',button:{text:'<?php echo esc_js( __( 'Use', 'talenttrack' ) ); ?>'},multiple:false}); f.on('select',function(){ var u=f.state().get('selection').first().toJSON().url; $('#tt_logo_url').val(u); $('#tt-logo-preview').html('<img src="'+u+'" style="max-height:70px"/>'); }); f.open(); }); });
        </script>
        <?php
    }

    // Handlers (unchanged from v2.3.0)

    public static function handle_save_config(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_config', 'tt_nonce' );
        $tab = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tab'] ) ) : '';
        $cfg = isset( $_POST['cfg'] ) && is_array( $_POST['cfg'] ) ? $_POST['cfg'] : [];
        foreach ( $cfg as $k => $v ) {
            QueryHelpers::set_config( sanitize_key( (string) $k ), sanitize_text_field( wp_unslash( (string) $v ) ) );
        }
        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=saved" ) );
        exit;
    }

    public static function handle_save_toggles(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_toggles', 'tt_nonce' );
        /** @var FeatureToggleService $toggles */
        $toggles = Kernel::instance()->container()->get( 'toggles' );
        $submitted = isset( $_POST['toggles'] ) && is_array( $_POST['toggles'] ) ? $_POST['toggles'] : [];
        foreach ( array_keys( FeatureToggleService::definitions() ) as $key ) {
            $enabled = ! empty( $submitted[ $key ] );
            $toggles->setEnabled( $key, $enabled );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=tt-config&tab=toggles&tt_msg=saved' ) );
        exit;
    }

    public static function handle_save_lookup(): void {
        if ( ! current_user_can( 'tt_edit_settings' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        check_admin_referer( 'tt_save_lookup', 'tt_nonce' );
        global $wpdb;
        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $type = isset( $_POST['lookup_type'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lookup_type'] ) ) : '';
        $tab  = isset( $_POST['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['tab'] ) ) : '';
        $data = [
            'lookup_type' => $type,
            'name'        => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '',
            'description' => isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['description'] ) ) : '',
            'sort_order'  => isset( $_POST['sort_order'] ) ? absint( $_POST['sort_order'] ) : 0,
        ];
        if ( $type === 'eval_type' ) {
            $data['meta'] = wp_json_encode( [ 'requires_match_details' => isset( $_POST['requires_match_details'] ) ] );
        }

        // v3.6.0: per-locale translations posted as tt_i18n[<locale>][name|description].
        // Stored as JSON in the new `translations` column so seeded .po translations
        // keep working while admin-added values gain inline translation support.
        if ( isset( $_POST['tt_i18n'] ) && is_array( $_POST['tt_i18n'] ) ) {
            $raw_i18n = wp_unslash( (array) $_POST['tt_i18n'] );
            $clean = [];
            foreach ( $raw_i18n as $locale => $fields ) {
                if ( ! is_string( $locale ) || ! is_array( $fields ) ) continue;
                $locale_key = sanitize_text_field( (string) $locale );
                if ( $locale_key === '' ) continue;
                $clean[ $locale_key ] = [
                    'name'        => isset( $fields['name'] ) ? sanitize_text_field( (string) $fields['name'] ) : '',
                    'description' => isset( $fields['description'] ) ? sanitize_text_field( (string) $fields['description'] ) : '',
                ];
            }
            $data['translations'] = \TT\Infrastructure\Query\LookupTranslator::encode( $clean );
        }

        if ( $id ) $wpdb->update( $wpdb->prefix . 'tt_lookups', $data, [ 'id' => $id ] );
        else $wpdb->insert( $wpdb->prefix . 'tt_lookups', $data );
        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=saved" ) );
        exit;
    }

    public static function handle_delete_lookup(): void {
        $id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : '';
        check_admin_referer( 'tt_del_lookup_' . $id );
        if ( ! current_user_can( 'tt_edit_settings' ) ) wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'tt_lookups', [ 'id' => $id ] );
        wp_safe_redirect( admin_url( "admin.php?page=tt-config&tab=$tab&tt_msg=deleted" ) );
        exit;
    }

    private static function tab_key_for_type( string $type ): string {
        // v2.12.0: eval_category removed — main categories live in
        // tt_eval_categories now, managed on a dedicated admin page.
        $map = [
            'eval_type' => 'eval_types',
            'position' => 'positions', 'foot_option' => 'foot_options',
            'age_group' => 'age_groups', 'goal_status' => 'goal_statuses',
            'goal_priority' => 'goal_priorities', 'attendance_status' => 'att_statuses',
        ];
        return $map[ $type ] ?? $type;
    }
}
