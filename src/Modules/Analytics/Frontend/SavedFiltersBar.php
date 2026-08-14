<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\SavedFiltersRepository;

/**
 * SavedFiltersBar (#2385) — the personal "saved views" strip rendered above
 * a standard report's shared filter bar. Lists the current user's named
 * presets for the report (each a one-click apply link), a delete control
 * per preset, and a "Save current filters" action that captures the live
 * query and POSTs it.
 *
 * Personal only: it reads the caller's own presets via
 * SavedFiltersRepository (club + user scoped). Apply links are built
 * server-side from each preset's stored params merged onto the report's
 * base params (`tt_view`, …); the period is stored as a key so "This
 * season" stays relative, a manual range as frozen from/to dates.
 */
final class SavedFiltersBar {

    /**
     * @param string               $report_key  stable key, e.g. 'attendance_team'.
     * @param string               $report_url  the report's own dashboard URL (no filter params).
     * @param array<string,string> $base_params params every apply link keeps (e.g. tt_view).
     */
    public static function render( string $report_key, string $report_url, array $base_params ): void {
        if ( $report_key === '' || ! is_user_logged_in() ) return;

        self::enqueueAssets( $report_key );

        $presets = ( new SavedFiltersRepository() )->listForUser( get_current_user_id(), $report_key );
        ?>
        <div class="tt-saved-filters" data-tt-saved-filters
             data-report-key="<?php echo esc_attr( $report_key ); ?>">
            <span class="tt-saved-filters__label"><?php esc_html_e( 'Saved views', 'talenttrack' ); ?></span>

            <ul class="tt-saved-filters__list">
                <?php foreach ( $presets as $preset ) :
                    $filters = json_decode( (string) ( $preset->filters_json ?? '' ), true );
                    $filters = is_array( $filters ) ? $filters : [];
                    $apply   = add_query_arg( array_map( 'strval', $filters + $base_params ), $report_url );
                    ?>
                    <li class="tt-saved-filters__item">
                        <a class="tt-saved-filters__apply" href="<?php echo esc_url( $apply ); ?>">
                            <?php echo esc_html( (string) $preset->name ); ?>
                        </a>
                        <button type="button" class="tt-saved-filters__del"
                                data-tt-preset-delete="<?php echo (int) $preset->id; ?>"
                                aria-label="<?php echo esc_attr( sprintf(
                                    /* translators: %s: saved view name */
                                    __( 'Delete saved view %s', 'talenttrack' ),
                                    (string) $preset->name
                                ) ); ?>">&times;</button>
                    </li>
                <?php endforeach; ?>
                <?php if ( empty( $presets ) ) : ?>
                    <li class="tt-saved-filters__empty"><?php esc_html_e( 'No saved views yet.', 'talenttrack' ); ?></li>
                <?php endif; ?>
            </ul>

            <div class="tt-saved-filters__save">
                <button type="button" class="tt-btn tt-btn-secondary" data-tt-preset-save-toggle>
                    <?php esc_html_e( 'Save current filters…', 'talenttrack' ); ?>
                </button>
                <div class="tt-saved-filters__save-form" data-tt-preset-save-form hidden>
                    <label class="tt-screen-reader-text" for="tt-preset-name-<?php echo esc_attr( $report_key ); ?>">
                        <?php esc_html_e( 'Name for this saved view', 'talenttrack' ); ?>
                    </label>
                    <input type="text" id="tt-preset-name-<?php echo esc_attr( $report_key ); ?>"
                           class="tt-saved-filters__name" data-tt-preset-name
                           maxlength="120" autocomplete="off"
                           placeholder="<?php esc_attr_e( 'e.g. U17 league games', 'talenttrack' ); ?>" />
                    <button type="button" class="tt-btn tt-btn-primary" data-tt-preset-save-confirm>
                        <?php esc_html_e( 'Save', 'talenttrack' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function enqueueAssets( string $report_key ): void {
        wp_enqueue_style(
            'tt-saved-filters',
            TT_PLUGIN_URL . 'assets/css/frontend-saved-filters.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-saved-filters',
            TT_PLUGIN_URL . 'assets/js/saved-filters.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-saved-filters', 'TT_SavedFilters', [
            'i18n' => [
                'name_required' => __( 'Give this view a name first.', 'talenttrack' ),
                'saved'         => __( 'Saved.', 'talenttrack' ),
                'delete_confirm' => __( 'Delete this saved view?', 'talenttrack' ),
                'error'         => __( 'Something went wrong. Please try again.', 'talenttrack' ),
            ],
            // The shared filter-bar params this bar captures on save.
            'keys' => [ 'period', 'activity_type_key', 'from', 'to', 'team_id', 'n' ],
        ] );
    }
}
