<?php
namespace TT\Modules\Exercises\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExercisesRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendExerciseCsvImportView — bulk-fill the exercise library (#2613).
 *
 * Routed at `?tt_view=exercises-import`. Same three-step shape as the
 * players importer, driven by the same `csv-import.js`, pointed at
 * `POST /exercises/import` through the root element's data attributes.
 *
 * Why bulk import matters more here than it looks: since #2497 the
 * generator ranks drills by how many of a team's open player goals each
 * would serve, so the library's usefulness scales with its size *and*
 * with how completely each row is tagged. An academy entering 150 drills
 * by hand stops at 20, and the generator keeps proposing the same
 * handful.
 */
class FrontendExerciseCsvImportView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_manage_exercises' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to import exercises.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Import exercises from CSV', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'exercises', __( 'Exercises', 'talenttrack' ) ) ]
        );

        self::enqueueAssets();

        wp_enqueue_style(
            'tt-frontend-csv-import',
            TT_PLUGIN_URL . 'assets/css/frontend-csv-import.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-csv-import',
            TT_PLUGIN_URL . 'assets/js/components/csv-import.js',
            [],
            TT_VERSION,
            true
        );

        self::renderHeader( __( 'Import exercises from CSV', 'talenttrack' ) );

        $fields = wp_json_encode( [
            [ 'key' => 'name',            'label' => __( 'Exercise', 'talenttrack' ) ],
            [ 'key' => 'category',        'label' => __( 'Category', 'talenttrack' ) ],
            [ 'key' => 'intensity_band',  'label' => __( 'Intensity', 'talenttrack' ) ],
            [ 'key' => 'principle_codes', 'label' => __( 'Principles', 'talenttrack' ) ],
        ] );

        $band_min = ExercisesRepository::INTENSITY_BAND_MIN;
        $band_max = ExercisesRepository::INTENSITY_BAND_MAX;
        ?>
        <div class="tt-csv-import"
             data-tt-csv-import="1"
             data-tt-csv-endpoint="exercises/import"
             data-tt-csv-fields="<?php echo esc_attr( (string) $fields ); ?>">

            <p class="tt-csv-import-help">
                <?php esc_html_e( 'Upload a CSV with one exercise per row. The first row must be a header naming the columns. A row that fails does not abort the import: every good row is saved, and the rows that failed come back as a file you can correct and upload again.', 'talenttrack' ); ?>
            </p>

            <p class="tt-csv-import-help">
                <?php esc_html_e( 'Fill in principle_codes wherever you can. A drill with no principles can still be chosen for a session, but the planner can never prefer it — so a large library tagged with nothing behaves like an empty one.', 'talenttrack' ); ?>
            </p>

            <details class="tt-csv-import-fields">
                <summary><?php esc_html_e( 'Accepted columns', 'talenttrack' ); ?></summary>
                <p>
                    <code>name</code> (<?php esc_html_e( 'required', 'talenttrack' ); ?>),
                    <code>description</code>, <code>organisation</code>,
                    <code>duration_minutes</code>,
                    <code>duration_minutes_min</code>, <code>duration_minutes_max</code>,
                    <code>intensity_band</code>
                    (<?php
                        printf(
                            /* translators: 1: lowest intensity band, 2: highest intensity band. */
                            esc_html__( '%1$d to %2$d', 'talenttrack' ),
                            (int) $band_min,
                            (int) $band_max
                        );
                    ?>),
                    <code>players_min</code>, <code>players_max</code>,
                    <code>age_min</code>, <code>age_max</code>,
                    <code>tactical_theme</code>,
                    <code>principle_codes</code> (<?php esc_html_e( 'semicolon-separated', 'talenttrack' ); ?>),
                    <code>category</code>, <code>visibility</code>,
                    <code>code</code>, <code>pitch_preset</code>, <code>diagram_url</code>.
                </p>
                <p>
                    <?php esc_html_e( 'A number outside its allowed range fails its row rather than being rounded into range, so a column filled in on the wrong scale is reported instead of quietly rewritten across every row.', 'talenttrack' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'organisation is added to the end of the description, matching the single field the exercise form offers. Exercises have no coaching_points column: coaching points belong to a training plan, not to the drill.', 'talenttrack' ); ?>
                </p>
                <p>
                    <?php esc_html_e( 'visibility is team unless you say otherwise. Publishing to the whole club needs the methodology-editing permission, the same as in the exercise form.', 'talenttrack' ); ?>
                </p>
            </details>

            <?php // Step 1 — upload. ?>
            <div class="tt-panel" data-step="upload">
                <h3 class="tt-panel-title"><?php esc_html_e( '1. Upload your CSV', 'talenttrack' ); ?></h3>
                <form data-tt-csv-form="1" enctype="multipart/form-data">
                    <div class="tt-field">
                        <label class="tt-field-label" for="tt-ex-csv-file"><?php esc_html_e( 'CSV file (max 5 MB)', 'talenttrack' ); ?></label>
                        <input type="file" id="tt-ex-csv-file" name="file" accept=".csv,text/csv" class="tt-input" required />
                    </div>
                    <div class="tt-form-actions tt-csv-import-actions">
                        <button type="submit" class="tt-btn tt-btn-primary" data-tt-csv-preview="1"><?php esc_html_e( 'Check the file', 'talenttrack' ); ?></button>
                    </div>
                    <div class="tt-form-msg" data-tt-csv-msg="1"></div>
                </form>
            </div>

            <?php // Step 2 — preview, rendered by csv-import.js. ?>
            <div class="tt-panel" data-step="preview" hidden>
                <h3 class="tt-panel-title"><?php esc_html_e( '2. Check', 'talenttrack' ); ?></h3>
                <div data-tt-csv-header-warnings="1"></div>
                <p data-tt-csv-preview-summary="1" class="tt-field-hint"></p>
                <div class="tt-list-table-wrap">
                    <table class="tt-list-table-table">
                        <thead><tr>
                            <th><?php esc_html_e( 'Row', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Exercise', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Category', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Intensity', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Principles', 'talenttrack' ); ?></th>
                            <th><?php esc_html_e( 'Notes', 'talenttrack' ); ?></th>
                        </tr></thead>
                        <tbody data-tt-csv-preview-body="1"></tbody>
                    </table>
                </div>
                <div class="tt-form-actions tt-csv-import-actions">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-csv-restart="1"><?php esc_html_e( 'Pick a different file', 'talenttrack' ); ?></button>
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-csv-commit="1"><?php esc_html_e( 'Import exercises', 'talenttrack' ); ?></button>
                </div>
            </div>

            <?php // Step 3 — result, rendered by csv-import.js. ?>
            <div class="tt-panel" data-step="result" hidden>
                <h3 class="tt-panel-title"><?php esc_html_e( '3. Result', 'talenttrack' ); ?></h3>
                <ul data-tt-csv-result-summary="1" class="tt-csv-result-summary"></ul>
                <p data-tt-csv-result-error-cta="1" hidden>
                    <a href="#" data-tt-csv-error-download="1" class="tt-btn tt-btn-secondary">
                        <?php esc_html_e( 'Download the rows that failed', 'talenttrack' ); ?>
                    </a>
                </p>
                <div class="tt-form-actions tt-csv-import-actions">
                    <button type="button" class="tt-btn tt-btn-secondary" data-tt-csv-restart="1"><?php esc_html_e( 'Import another file', 'talenttrack' ); ?></button>
                </div>
            </div>
        </div>
        <?php
    }
}
