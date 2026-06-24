<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\StaffDevelopment\Repositories\StaffCertificationsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffCertificationsView — list + add form for the staff
 * member's certifications. Each row shows a colour pill driven by
 * `expires_on`: green if more than 90 days out, amber within 90, red
 * within 30, grey if expired or no expiry. The same colour ladder
 * drives the workflow template's threshold logic.
 */
class FrontendMyStaffCertificationsView extends FrontendViewBase {

    /**
     * B4 2026 restyle — enqueue the shared staff-development card
     * stylesheet on top of the shared frontend assets. Loaded here (not in
     * FrontendViewBase) because only the staff-development surfaces use it;
     * depends on the global app-chrome sheet for the shared brand tokens.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-staff-development',
            TT_PLUGIN_URL . 'assets/css/frontend-staff-development.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        $title = __( 'My certifications', 'talenttrack' );

        if ( ! current_user_can( 'tt_view_staff_development' ) ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to this section.', 'talenttrack' ) . '</p>';
            return;
        }
        $person = StaffPersonHelper::personForUser( $user_id );
        if ( ! $person ) {
            \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
            self::renderHeader( $title );
            echo '<p class="tt-notice">' . esc_html__( 'This section is only available for staff members linked to a People record.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::handlePost( (int) $person->id );
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( $title );
        self::renderHeader( $title );

        $repo  = new StaffCertificationsRepository();
        $rows  = $repo->listForPerson( (int) $person->id );
        $types = QueryHelpers::get_lookups( 'cert_type' );
        $type_by_id = [];
        foreach ( $types as $t ) { $type_by_id[ (int) $t->id ] = (string) $t->name; }

        // KPI strip — counts derived from the already-fetched list (no new
        // query). "Expiring soon" = bucket within 90 days (gold or red).
        $total    = count( $rows );
        $expiring = 0;
        foreach ( $rows as $r ) {
            $bucket = self::expiryBucket( $r->expires_on )['key'];
            if ( $bucket === 'red' || $bucket === 'gold' ) $expiring++;
        }
        echo '<div class="tt-sdev-kpis">';
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Certifications', 'talenttrack' ), 'value' => (string) $total ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo FrontendAppChrome::kpiTile( [ 'label' => __( 'Expiring soon', 'talenttrack' ), 'value' => (string) $expiring, 'flag' => $expiring > 0 ? 'red' : '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
        echo '</div>';

        if ( ! $rows ) {
            echo '<p class="tt-sdev-empty">' . esc_html__( 'No certifications on file yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-sdev-list">';
            foreach ( $rows as $r ) {
                $name   = (string) ( $type_by_id[ (int) $r->cert_type_lookup_id ] ?? '#' . (int) $r->cert_type_lookup_id );
                $expires_label = $r->expires_on ? (string) $r->expires_on : __( 'no expiry', 'talenttrack' );
                $bucket = self::expiryBucket( $r->expires_on );
                echo '<li class="tt-sdev-card">';
                echo '<div class="tt-sdev-card__head">';
                echo '<h4 class="tt-sdev-card__title">' . esc_html( $name ) . '</h4>';
                echo '<span class="tt-sdev-chip tt-sdev-chip--' . esc_attr( $bucket['key'] ) . '">' . esc_html( $bucket['label'] ) . '</span>';
                echo '</div>';
                echo '<div class="tt-sdev-card__meta">';
                echo '<span>' . esc_html__( 'Issuer', 'talenttrack' ) . ': <b>' . esc_html( (string) ( $r->issuer ?? '—' ) ) . '</b></span>';
                echo '<span>' . esc_html__( 'Issued', 'talenttrack' ) . ': <b>' . esc_html( (string) $r->issued_on ) . '</b></span>';
                echo '<span>' . esc_html__( 'Expires', 'talenttrack' ) . ': <b>' . esc_html( $expires_label ) . '</b></span>';
                echo '</div>';
                if ( $r->document_url ) {
                    echo '<a class="tt-sdev-card__doc" href="' . esc_url( (string) $r->document_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open document', 'talenttrack' ) . '</a>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '<h3 class="tt-sdev-section-title">' . esc_html__( 'Add a certification', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form tt-sdev-form">';
        wp_nonce_field( 'tt_staff_cert_save', 'tt_staff_cert_nonce' );
        echo '<div class="tt-field"><label class="tt-field-label tt-field-required" for="tt-staff-cert-type">' . esc_html__( 'Certification', 'talenttrack' ) . '</label>';
        echo '<select id="tt-staff-cert-type" name="cert_type_lookup_id" class="tt-input" required>';
        foreach ( $types as $t ) {
            echo '<option value="' . esc_attr( (string) $t->id ) . '">' . esc_html( (string) $t->name ) . '</option>';
        }
        echo '</select></div>';

        echo '<div class="tt-grid tt-grid-2">';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-cert-issuer">' . esc_html__( 'Issuer', 'talenttrack' ) . '</label>';
        echo '<input type="text" id="tt-staff-cert-issuer" name="issuer" class="tt-input" maxlength="120"></div>';
        echo '<div class="tt-field"><label class="tt-field-label tt-field-required" for="tt-staff-cert-issued">' . esc_html__( 'Issued on', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-staff-cert-issued" name="issued_on" class="tt-input" required value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-cert-expires">' . esc_html__( 'Expires on', 'talenttrack' ) . '</label>';
        echo '<input type="date" id="tt-staff-cert-expires" name="expires_on" class="tt-input"></div>';
        echo '<div class="tt-field"><label class="tt-field-label" for="tt-staff-cert-doc">' . esc_html__( 'Document URL (optional)', 'talenttrack' ) . '</label>';
        echo '<input type="url" inputmode="url" id="tt-staff-cert-doc" name="document_url" class="tt-input" placeholder="https://…"></div>';
        echo '</div>';

        // CLAUDE.md §6 — Save + Cancel on a record-creating form. Cancel
        // returns to this same list view; a tt_back hint on the entry URL
        // overrides that destination.
        $back       = BackLink::resolve();
        $cancel_url = $back !== null
            ? $back['url']
            : add_query_arg( 'tt_view', 'my-staff-certifications', RecordLink::dashboardUrl() );
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper escapes its own output.
            'label'      => __( 'Add certification', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );
        echo '</form>';
    }

    /**
     * Resolve an expiry date into a chip bucket: a CSS-class key
     * (green/gold/red/ghost) + a translated label. The colour ladder
     * matches the workflow template's threshold logic: OK > 90 days,
     * gold within 90, red within 30, ghost for expired / no expiry.
     *
     * @return array{key:string,label:string}
     */
    private static function expiryBucket( ?string $expires_on ): array {
        if ( $expires_on === null || $expires_on === '' ) {
            return [ 'key' => 'ghost', 'label' => __( 'no expiry', 'talenttrack' ) ];
        }
        $today = strtotime( gmdate( 'Y-m-d' ) );
        $exp   = strtotime( $expires_on );
        if ( $exp === false ) $exp = $today;
        $days = (int) round( ( $exp - $today ) / 86400 );
        if ( $days < 0 )       return [ 'key' => 'ghost', 'label' => __( 'expired', 'talenttrack' ) ];
        if ( $days <= 30 )     return [ 'key' => 'red',   'label' => __( '< 30 days', 'talenttrack' ) ];
        if ( $days <= 90 )     return [ 'key' => 'gold',  'label' => __( '< 90 days', 'talenttrack' ) ];
        return [ 'key' => 'green', 'label' => __( 'OK', 'talenttrack' ) ];
    }

    private static function handlePost( int $person_id ): void {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;
        if ( ! isset( $_POST['tt_staff_cert_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['tt_staff_cert_nonce'] ) ), 'tt_staff_cert_save' ) ) return;

        $type_id = (int) ( $_POST['cert_type_lookup_id'] ?? 0 );
        if ( $type_id <= 0 ) return;

        $repo = new StaffCertificationsRepository();
        $repo->create( [
            'person_id'           => $person_id,
            'cert_type_lookup_id' => $type_id,
            'issuer'              => sanitize_text_field( wp_unslash( (string) ( $_POST['issuer'] ?? '' ) ) ),
            'issued_on'           => sanitize_text_field( wp_unslash( (string) ( $_POST['issued_on'] ?? gmdate( 'Y-m-d' ) ) ) ),
            'expires_on'          => isset( $_POST['expires_on'] ) && (string) $_POST['expires_on'] !== '' ? sanitize_text_field( wp_unslash( (string) $_POST['expires_on'] ) ) : null,
            'document_url'        => esc_url_raw( wp_unslash( (string) ( $_POST['document_url'] ?? '' ) ) ),
        ] );
    }
}
