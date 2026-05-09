<?php
namespace TT\Modules\StaffDevelopment\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\StaffDevelopment\Repositories\StaffCertificationsRepository;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyStaffCertificationsView — list + add form for the staff
 * member's certifications. Each row shows a colour pill driven by
 * `expires_on`: green if more than 90 days out, amber within 90, red
 * within 30, grey if expired or no expiry. The same colour ladder
 * drives the workflow template's threshold logic.
 */
class FrontendMyStaffCertificationsView extends FrontendViewBase {

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

        if ( ! $rows ) {
            echo '<p>' . esc_html__( 'No certifications on file yet.', 'talenttrack' ) . '</p>';
        } else {
            echo '<table class="tt-table" style="width:100%;"><thead><tr>';
            echo '<th>' . esc_html__( 'Certification', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Issuer', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Issued', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Expires', 'talenttrack' ) . '</th>';
            echo '<th>' . esc_html__( 'Document', 'talenttrack' ) . '</th>';
            echo '</tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $name   = (string) ( $type_by_id[ (int) $r->cert_type_lookup_id ] ?? '#' . (int) $r->cert_type_lookup_id );
                $expires_label = $r->expires_on ? esc_html( (string) $r->expires_on ) : esc_html__( 'no expiry', 'talenttrack' );
                $pill = self::expiryPill( $r->expires_on );
                echo '<tr>';
                echo '<td>' . esc_html( $name ) . '</td>';
                echo '<td>' . esc_html( (string) ( $r->issuer ?? '—' ) ) . '</td>';
                echo '<td>' . esc_html( (string) $r->issued_on ) . '</td>';
                echo '<td>' . $pill . ' ' . $expires_label . '</td>';
                echo '<td>' . ( $r->document_url ? '<a href="' . esc_url( (string) $r->document_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'open', 'talenttrack' ) . '</a>' : '—' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h3 style="margin-top:24px;">' . esc_html__( 'Add a certification', 'talenttrack' ) . '</h3>';
        echo '<form method="post" class="tt-form" style="max-width:720px;">';
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

        echo '<div class="tt-form-actions"><button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Add certification', 'talenttrack' ) . '</button></div>';
        echo '</form>';
    }

    private static function expiryPill( ?string $expires_on ): string {
        if ( $expires_on === null || $expires_on === '' ) {
            $bg = '#e5e7ea'; $fg = '#5b6470'; $label = __( 'no expiry', 'talenttrack' );
        } else {
            $today = strtotime( gmdate( 'Y-m-d' ) );
            $exp   = strtotime( $expires_on );
            if ( $exp === false ) $exp = $today;
            $days = (int) round( ( $exp - $today ) / 86400 );
            if ( $days < 0 )       { $bg = '#e5e7ea'; $fg = '#5b6470'; $label = __( 'expired', 'talenttrack' ); }
            elseif ( $days <= 30 ) { $bg = '#fde2e2'; $fg = '#a02828'; $label = __( '< 30 days', 'talenttrack' ); }
            elseif ( $days <= 90 ) { $bg = '#fdf3d8'; $fg = '#7a5a05'; $label = __( '< 90 days', 'talenttrack' ); }
            else                   { $bg = '#dff5e1'; $fg = '#1a6b2c'; $label = __( 'OK', 'talenttrack' ); }
        }
        return '<span style="display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; background:' . esc_attr( $bg ) . '; color:' . esc_attr( $fg ) . ';">' . esc_html( $label ) . '</span>';
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
