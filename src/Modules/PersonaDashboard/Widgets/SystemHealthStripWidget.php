<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Invitations\InvitationStatus;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * SystemHealthStripWidget — Admin hero.
 *
 * Four panels: backup status, pending invitations, license tier,
 * modules summary. Each panel queries its own source — no central
 * "system_health" service exists yet, and a thin assembly here is
 * cheaper than a new infrastructure layer for one widget.
 */
class SystemHealthStripWidget extends AbstractWidget {

    public function id(): string { return 'system_health_strip'; }

    public function label(): string { return __( 'System health strip', 'talenttrack' ); }

    public function description(): string {
        return __( 'Admin landing strip: active-user count, last migration timestamp, license tier, Spond sync status, mail-queue depth. Useful for the academy admin\'s "is anything red" glance before they dig in. Sourced from `wp_users`, `tt_migrations`, license config, and the Spond per-team sync status.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'academy_admin' ];
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function capRequired(): string { return 'tt_edit_settings'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $panels = [
            self::backupPanel(),
            self::invitationsPanel(),
            self::licensePanel(),
            self::modulesPanel(),
        ];

        $cards = '';
        foreach ( $panels as $p ) {
            $cards .= '<div class="tt-pd-health-panel tt-pd-health-' . sanitize_html_class( $p['state'] ) . '">'
                . '<div class="tt-pd-health-label">' . esc_html( (string) $p['label'] ) . '</div>'
                . '<div class="tt-pd-health-value">' . esc_html( (string) $p['value'] ) . '</div>'
                . '</div>';
        }

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html__( 'System health', 'talenttrack' ) . '</div>'
            . '<div class="tt-pd-health-row">' . $cards . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-system-health' );
    }

    /** @return array{label:string,value:string,state:string} */
    private static function backupPanel(): array {
        $last = (string) get_option( 'tt_backup_last_run', '' );
        if ( $last === '' ) {
            return [
                'label' => __( 'Last backup', 'talenttrack' ),
                'value' => __( 'Never run', 'talenttrack' ),
                'state' => 'warn',
            ];
        }
        $ts = strtotime( $last );
        if ( $ts === false ) {
            return [ 'label' => __( 'Last backup', 'talenttrack' ), 'value' => '—', 'state' => 'warn' ];
        }
        $age_hours = (int) round( ( time() - $ts ) / 3600 );
        $state = $age_hours <= 36 ? 'ok' : ( $age_hours <= 168 ? 'warn' : 'warn' );
        $value = (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
        return [
            'label' => __( 'Last backup', 'talenttrack' ),
            'value' => $value,
            'state' => $state,
        ];
    }

    /** @return array{label:string,value:string,state:string} */
    private static function invitationsPanel(): array {
        $count = self::countPendingInvitations();
        return [
            'label' => __( 'Pending invitations', 'talenttrack' ),
            'value' => (string) $count,
            'state' => $count > 0 ? 'warn' : 'ok',
        ];
    }

    private static function countPendingInvitations(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_invitations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return 0;
        }
        $status = class_exists( InvitationStatus::class ) ? InvitationStatus::PENDING : 'pending';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND club_id = %d",
            $status, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
    }

    /** @return array{label:string,value:string,state:string} */
    private static function licensePanel(): array {
        $tier = (string) get_option( 'tt_license_tier', '' );
        if ( $tier === '' ) $tier = (string) get_option( 'tt_license_plan', '' );
        if ( $tier === '' ) $tier = __( 'Free', 'talenttrack' );
        return [
            'label' => __( 'License', 'talenttrack' ),
            'value' => ucfirst( $tier ),
            'state' => 'info',
        ];
    }

    /** @return array{label:string,value:string,state:string} */
    private static function modulesPanel(): array {
        if ( ! class_exists( ModuleRegistry::class ) ) {
            return [ 'label' => __( 'Modules active', 'talenttrack' ), 'value' => '—', 'state' => 'info' ];
        }
        $config_file = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/modules.php' : '';
        $total = 0;
        $enabled = 0;
        if ( $config_file !== '' && file_exists( $config_file ) ) {
            $declared = include $config_file;
            if ( is_array( $declared ) ) {
                foreach ( $declared as $cls => $on ) {
                    $total += 1;
                    if ( $on && ModuleRegistry::isEnabled( (string) $cls ) ) $enabled += 1;
                }
            }
        }
        return [
            'label' => __( 'Modules active', 'talenttrack' ),
            'value' => sprintf( '%d / %d', $enabled, $total ),
            'state' => 'info',
        ];
    }
}
