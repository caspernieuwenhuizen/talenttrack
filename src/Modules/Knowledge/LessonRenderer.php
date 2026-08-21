<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LessonRenderer — a lesson's markdown to the HTML a reader sees.
 *
 * Thin on purpose. It renders the body, notes whether anything in it needs
 * the interactive script, and registers the assets. The view that puts it
 * on a page arrives in #2646; keeping the render callable without a view
 * means the REST layer can return the same HTML the page shows, which is
 * the §4 test — delete every view file and the API still answers.
 *
 * A lesson only enqueues the block script when a block on it needs one, so
 * a reading-only lesson costs zero JavaScript.
 */
final class LessonRenderer {

    public const STYLE_HANDLE  = 'tt-knowledge-lesson';
    public const SCRIPT_HANDLE = 'tt-knowledge-blocks';

    /**
     * Render a lesson body.
     *
     * @return array{html: string, interactive: bool}
     */
    public static function render( string $markdown ): array {
        return LessonMarkdown::render( $markdown );
    }

    /**
     * Render and enqueue in one step, for a view that is about to echo it.
     *
     * @return string HTML
     */
    public static function renderAndEnqueue( string $markdown ): string {
        $rendered = self::render( $markdown );

        self::enqueueStyle();
        if ( $rendered['interactive'] ) {
            self::enqueueScript();
        }

        return $rendered['html'];
    }

    /**
     * Register the handles. Called on `wp_enqueue_scripts` so a view can
     * enqueue by handle without knowing the paths.
     */
    public static function registerAssets(): void {
        $version = defined( 'TT_VERSION' ) ? TT_VERSION : false;

        wp_register_style(
            self::STYLE_HANDLE,
            TT_PLUGIN_URL . 'assets/css/knowledge-lesson.css',
            [ 'tt-tokens' ],
            $version
        );

        wp_register_script(
            self::SCRIPT_HANDLE,
            TT_PLUGIN_URL . 'assets/js/knowledge-blocks.js',
            [],
            $version,
            true
        );

        // The periodisation numbers the tools compute with. Localised once
        // per page rather than embedded per block: three blocks on one
        // lesson would otherwise ship the same table three times, and the
        // copies could disagree after a partial edit.
        wp_localize_script(
            self::SCRIPT_HANDLE,
            'TTKnowledge',
            [
                'periodisation' => Periodisation::forScript(),
                'i18n'          => self::scriptStrings(),
            ]
        );
    }

    public static function enqueueStyle(): void {
        wp_enqueue_style( self::STYLE_HANDLE );
    }

    public static function enqueueScript(): void {
        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    /**
     * Strings the block scripts render.
     *
     * Server-side through `__()` and handed over as data, never hardcoded
     * English in the JavaScript — the reader is a Dutch coach and the
     * course is in Dutch.
     *
     * @return array<string, string>
     */
    private static function scriptStrings(): array {
        return [
            /* translators: 1: step number, 2: number of games, 3: minutes per game */
            'zeroPointResult'  => __( 'Start at step %1$d: %2$d games of %3$s minutes.', 'talenttrack' ),
            'zeroPointPrompt'  => __( 'Enter the minutes to see your starting step.', 'talenttrack' ),
            'zeroPointTooLow'  => __( 'That is below the lightest step. Start at step 1 and build from there.', 'talenttrack' ),
            'weekOk'           => __( 'This week respects every recovery time.', 'talenttrack' ),
            'weekPrompt'       => __( 'Add a match and at least one session to check the week.', 'talenttrack' ),
            /* translators: %d: number of problems found */
            'weekProblems'     => __( '%d problem(s) with this week:', 'talenttrack' ),
            'pitchRuleWorks'   => __( 'The rule of thumb works at this format.', 'talenttrack' ),
            /* translators: 1: computed width in metres, 2: minimum usable width in metres */
            'pitchTooNarrow'   => __( 'The computed width of %1$d m is narrower than a penalty area. Widen to %2$d m, or the game turns into a more intensive method than you planned.', 'talenttrack' ),
        ];
    }
}
