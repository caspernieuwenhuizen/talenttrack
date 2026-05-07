<?php
namespace TT\Modules\SeedReview\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\SeedReview\SeedExporter;
use TT\Modules\SeedReview\SeedImporter;

/**
 * SeedReviewPage — Configuration → Seed review admin surface.
 *
 * Two actions, both cap-gated on `tt_edit_settings`:
 *   - Download review template      → `tt_seed_review_export`
 *   - Apply edits from upload       → `tt_seed_review_import`
 *
 * The page itself is read-only; renders the two forms, the
 * Most-recent-import summary (counts + any error lines), and a short
 * help blurb.
 */
final class SeedReviewPage {

    private const CAP = 'tt_edit_settings';

    public static function render(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'talenttrack' ) );
        }
        $export_url = admin_url( 'admin-post.php' );
        $import_url = admin_url( 'admin-post.php' );

        $last = get_transient( 'tt_seed_review_last_import' );
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Seed review', 'talenttrack' ) . '</h1>';

        echo '<p>' . esc_html__( 'Download a review template containing every seeded lookup, evaluation category, and role label. Edit offline (translations, sort order, descriptions, colors), then upload the edited file here to apply the changes back to this install.', 'talenttrack' ) . '</p>';
        echo '<p><em>' . esc_html__( 'Live-DB updates only — the shipped seed PHP files are not rewritten. Operators who want a label change to ship to other installs as code should work the change back into config/authorization_seed.php or the relevant migration manually.', 'talenttrack' ) . '</em></p>';

        if ( $last && is_array( $last ) ) {
            $kind = ! empty( $last['errors'] ) ? 'notice-warning' : 'notice-success';
            echo '<div class="notice ' . esc_attr( $kind ) . '" style="margin-top:18px;">';
            echo '<p><strong>' . esc_html__( 'Last import:', 'talenttrack' ) . '</strong> ';
            echo esc_html( sprintf(
                /* translators: 1: number of rows updated, 2: number skipped */
                __( '%1$d updated, %2$d unchanged.', 'talenttrack' ),
                (int) ( $last['updated'] ?? 0 ),
                (int) ( $last['skipped'] ?? 0 )
            ) );
            echo '</p>';
            if ( ! empty( $last['errors'] ) && is_array( $last['errors'] ) ) {
                echo '<ul>';
                foreach ( $last['errors'] as $err ) {
                    echo '<li>' . esc_html( (string) $err ) . '</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
        }

        echo '<h2 style="margin-top:32px;">' . esc_html__( '1. Download review template', 'talenttrack' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( $export_url ) . '">';
        wp_nonce_field( 'tt_seed_review_export', 'tt_seed_review_nonce' );
        echo '<input type="hidden" name="action" value="tt_seed_review_export" />';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Download seed review .xlsx', 'talenttrack' ) . '</button></p>';
        echo '</form>';

        echo '<h2 style="margin-top:32px;">' . esc_html__( '2. Apply edits from upload', 'talenttrack' ) . '</h2>';
        echo '<form method="post" action="' . esc_url( $import_url ) . '" enctype="multipart/form-data">';
        wp_nonce_field( 'tt_seed_review_import', 'tt_seed_review_nonce' );
        echo '<input type="hidden" name="action" value="tt_seed_review_import" />';
        echo '<p><input type="file" name="seed_review_xlsx" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required /></p>';
        echo '<p><button type="submit" class="button">' . esc_html__( 'Apply edits', 'talenttrack' ) . '</button></p>';
        echo '<p class="description">' . esc_html__( 'The importer matches rows by their `id` column and applies in-place updates. Rows missing from the upload are left unchanged. Edits are audit-logged as `seed_review.row_updated`.', 'talenttrack' ) . '</p>';
        echo '</form>';

        echo '</div>';
    }

    public static function handleExport(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_seed_review_export', 'tt_seed_review_nonce' );

        if ( ! SeedExporter::streamDownload() ) {
            wp_die( esc_html__( 'PhpSpreadsheet is not installed; seed review export is unavailable.', 'talenttrack' ) );
        }
        exit;
    }

    public static function handleImport(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You are not allowed to access this page.', 'talenttrack' ) );
        }
        check_admin_referer( 'tt_seed_review_import', 'tt_seed_review_nonce' );

        if ( empty( $_FILES['seed_review_xlsx']['tmp_name'] ) || ! is_uploaded_file( (string) $_FILES['seed_review_xlsx']['tmp_name'] ) ) {
            self::redirectBack( [ 'tt_msg' => 'no_file' ] );
            return;
        }
        $tmp = (string) $_FILES['seed_review_xlsx']['tmp_name'];
        $result = SeedImporter::importFromFile( $tmp );
        set_transient( 'tt_seed_review_last_import', $result, 5 * MINUTE_IN_SECONDS );
        self::redirectBack( [ 'tt_msg' => 'imported' ] );
    }

    private static function redirectBack( array $extra ): void {
        $url = add_query_arg( array_merge( [ 'page' => 'tt-seed-review' ], $extra ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }
}
