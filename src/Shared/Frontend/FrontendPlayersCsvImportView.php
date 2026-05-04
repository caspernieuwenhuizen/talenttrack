<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FrontendPlayersCsvImportView — frontend players CSV bulk import.
 *
 * #0019 Sprint 3 session 3.2. Routed via the new
 * `?tt_view=players-import` tile slug. Sync version per Q1 in
 * shaping — no async job, no per-row dupe UI. Three steps in the
 * UI, one endpoint backing them:
 *
 *   1. Upload  — file input + dupe-strategy radio + "Preview"
 *      button. Hits POST /players/import?dry_run=1.
 *   2. Preview — first 20 rows with valid/warning/error status.
 *      Header warnings shown above the table. "Import" button
 *      commits via dry_run=0 (re-uploads the file).
 *   3. Result  — created/updated/skipped/errored counts. If errors,
 *      a "Download error rows" link gives back a corrected-input
 *      CSV the user can fix and re-upload.
 *
 * No per-row interaction. The dupe strategy is one radio set
 * applied to the whole batch. Per Sprint 3 plan: the simpler-
 * but-complete flow.
 *
 * Transactional behavior: accept-what-worked. The view documents
 * this in the upload-step help text so users aren't surprised.
 */
class FrontendPlayersCsvImportView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_players' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to import players.', 'talenttrack' ) . '</p>';
            return;
        }

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Import players from CSV', 'talenttrack' ),
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'players', __( 'Players', 'talenttrack' ) ) ]
        );

        // #0011 — feature gate. CSV import is Standard-tier.
        if ( \TT\Core\ModuleRegistry::isEnabled( 'TT\\Modules\\License\\LicenseModule' )
             && class_exists( '\TT\Modules\License\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::can( 'csv_import' )
        ) {
            FrontendBackButton::render();
            echo \TT\Modules\License\Admin\UpgradeNudge::inline(
                __( 'CSV bulk import', 'talenttrack' ),
                'standard'
            );
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Import players from CSV', 'talenttrack' ) );

        ?>
        <div class="tt-csv-import" data-tt-csv-import="1">

            <p class="tt-csv-import-help">
                <?php esc_html_e( 'Upload a CSV with one player per row. The first row must be a header naming the columns. Errors in individual rows do not abort the import — every row that succeeds is committed; rows that fail are reported and downloadable as a corrected-input CSV for retry.', 'talenttrack' ); ?>
            </p>

            <details class="tt-csv-import-fields" style="margin-bottom:16px;">
                <summary><?php esc_html_e( 'Accepted columns', 'talenttrack' ); ?></summary>
                <p style="margin-top:8px;">
                    <code>first_name</code> (required), <code>last_name</code> (required),
                    <code>date_of_birth</code> (YYYY-MM-DD), <code>nationality</code>,
                    <code>height_cm</code>, <code>weight_kg</code>,
                    <code>preferred_foot</code>,
                    <code>preferred_positions</code> (<?php esc_html_e( 'comma-separated', 'talenttrack' ); ?>),
                    <code>jersey_number</code>,
                    <code>team_id</code> <?php esc_html_e( 'or', 'talenttrack' ); ?> <code>team_name</code>,
                    <code>date_joined</code>, <code>photo_url</code>,
                    <code>guardian_name</code>, <code>guardian_email</code>, <code>guardian_phone</code>,
                    <code>status</code>.
                </p>
            </details>

            <?php // Step 1: upload ?>
            <div class="tt-panel" data-step="upload">
                <h3 class="tt-panel-title"><?php esc_html_e( '1. Upload your CSV', 'talenttrack' ); ?></h3>
                <form data-tt-csv-form="1" enctype="multipart/form-data">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-csv-file"><?php esc_html_e( 'CSV file (max 5 MB)', 'talenttrack' ); ?></label>
                        <input type="file" id="tt-csv-file" name="file" accept=".csv,text/csv" class="tt-input" required />
                    </div>

                    <fieldset class="tt-field">
                        <legend class="tt-field-label"><?php esc_html_e( 'When a row matches an existing player', 'talenttrack' ); ?></legend>
                        <label style="display:block;"><input type="radio" name="dupe_strategy" value="skip" checked /> <?php esc_html_e( 'Skip the row (default)', 'talenttrack' ); ?></label>
                        <label style="display:block;"><input type="radio" name="dupe_strategy" value="update" /> <?php esc_html_e( 'Update the existing player', 'talenttrack' ); ?></label>
                        <label style="display:block;"><input type="radio" name="dupe_strategy" value="create" /> <?php esc_html_e( 'Create a new player anyway', 'talenttrack' ); ?></label>
                        <p class="tt-field-hint"><?php esc_html_e( 'A row matches an existing player when first_name + last_name + date_of_birth all match.', 'talenttrack' ); ?></p>
                    </fieldset>

                    <div class="tt-form-actions" style="margin-top:12px;">
                        <button type="submit" class="tt-btn tt-btn-primary" data-tt-csv-preview="1"><?php esc_html_e( 'Preview', 'talenttrack' ); ?></button>
                    </div>
                    <div class="tt-form-msg" data-tt-csv-msg="1"></div>
                </form>
            </div>

            <?php // Step 2: preview (rendered by JS) ?>
            <div class="tt-panel" data-step="preview" hidden>
                <h3 class="tt-panel-title"><?php esc_html_e( '2. Preview', 'talenttrack' ); ?></h3>
                <div data-tt-csv-header-warnings="1"></div>
                <p data-tt-csv-preview-summary="1" class="tt-field-hint"></p>
                <div class="tt-list-table-wrap">
                    <table class="tt-list-table-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Row', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Player', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Date of birth', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Team', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                        </tr></thead>
                        <tbody data-tt-csv-preview-body="1"></tbody>
                    </table>
                </div>
                <div class="tt-form-actions" style="margin-top:12px;">
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-csv-commit="1"><?php esc_html_e( 'Import', 'talenttrack' ); ?></button>
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-csv-restart="1"><?php esc_html_e( 'Pick a different file', 'talenttrack' ); ?></button>
                </div>
            </div>

            <?php // Step 3: result (rendered by JS) ?>
            <div class="tt-panel" data-step="result" hidden>
                <h3 class="tt-panel-title"><?php esc_html_e( '3. Result', 'talenttrack' ); ?></h3>
                <ul data-tt-csv-result-summary="1" style="font-size:var(--tt-fs-md); line-height:1.7;"></ul>
                <p data-tt-csv-result-error-cta="1" hidden>
                    <a href="#" data-tt-csv-error-download="1" class="tt-btn tt-btn-secondary">
                        <?php esc_html_e( 'Download error rows', 'talenttrack' ); ?>
                    </a>
                </p>
                <div class="tt-form-actions" style="margin-top:12px;">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-csv-restart="1"><?php esc_html_e( 'Import another file', 'talenttrack' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
