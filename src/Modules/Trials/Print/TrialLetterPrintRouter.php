<?php
namespace TT\Modules\Trials\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Letters\LetterTemplateEngine;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

/**
 * TrialLetterPrintRouter (#3661) — the trial outcome letter on paper.
 *
 *   ?tt_trial_letter_print=1&case_id=N
 *
 * Same isolation pattern as `MatchAnalysisPrintRouter` and
 * `TrainingPlanPrintRouter`: intercept before the theme shell renders,
 * emit a standalone document, exit. The "Print view" link used to point
 * at `?tt_view=trial-case&tab=letter&print=1`, which nothing read — so
 * the page it opened was the dashboard with its header, the case tabs
 * and the letter-history table, and a letter for a family printed with
 * the whole application around it.
 *
 * Gate: the Letter tab's own — entry to the case, then manager. A letter
 * saying whether the academy wants a child is not something an assigned
 * coach prints.
 */
final class TrialLetterPrintRouter {

    public const QUERY_ARG = 'tt_trial_letter_print';

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    /** The print URL for a case, for both links that offer the letter. */
    public static function urlFor( int $case_id ): string {
        return add_query_arg(
            [ self::QUERY_ARG => 1, 'case_id' => $case_id ],
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );
    }

    public static function canPrint( int $user_id, int $case_id ): bool {
        if ( $user_id <= 0 || $case_id <= 0 ) return false;

        return TrialCaseAccessPolicy::canOpenCase( $user_id, $case_id )
            && TrialCaseAccessPolicy::isManager( $user_id );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET[ self::QUERY_ARG ] ) ) return;

        $case_id = isset( $_GET['case_id'] ) ? absint( wp_unslash( $_GET['case_id'] ) ) : 0;
        if ( $case_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die(
                esc_html__( 'Log in to print this letter.', 'talenttrack' ),
                '',
                [ 'response' => 401 ]
            );
        }

        if ( ! self::canPrint( get_current_user_id(), $case_id ) ) {
            wp_die(
                esc_html__( 'You do not have access to print this letter.', 'talenttrack' ),
                '',
                [ 'response' => 403 ]
            );
        }

        // After the capability check, so someone who could never print this
        // gets the permission answer rather than an upgrade pitch. 402, not
        // 403: the plan said no, not the capability model (#3104).
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' )
             && ! \TT\Modules\License\LicenseGate::allows( 'trial_module' )
        ) {
            wp_die(
                esc_html__( 'Trial cases are part of a plan this install is not on.', 'talenttrack' ),
                '',
                [ 'response' => 402 ]
            );
        }

        $html = self::renderHtml( $case_id );
        if ( $html === '' ) {
            wp_die(
                esc_html__( 'No letter has been generated for this case yet.', 'talenttrack' ),
                '',
                [ 'response' => 404 ]
            );
        }

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — the document is assembled below, with the letter body read through wp_kses_post().
        exit;
    }

    /**
     * The standalone document, or '' when the case has no live letter.
     *
     * Public so the test can read what the route emits without following
     * it through `exit`.
     */
    public static function renderHtml( int $case_id ): string {
        $letter = ( new TrialLetterService() )->findActiveForCase( $case_id );
        if ( ! $letter ) return '';

        // Read through (array) rather than `$letter->rendered_html`: the row
        // is a plain `stdClass` from `get_row()`, and a property read on it
        // is an error PHPStan's level-8 baseline counts one by one.
        $row    = (array) $letter;
        $stored = isset( $row['rendered_html'] ) ? (string) $row['rendered_html'] : '';
        $body   = LetterTemplateEngine::displayHtml( $stored );
        if ( $body === '' ) return '';

        $close_url = add_query_arg(
            [ 'tt_view' => 'trial-case', 'id' => $case_id, 'tab' => 'letter' ], /* tt-xview-ok — back to the Letter tab this was printed from */
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
        );

        // Browsers default the Save-as-PDF filename to document.title, so
        // the title is the player's name and the word letter, nothing else.
        $title = self::documentTitle( $case_id );

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?php echo esc_html( $title ); ?></title>
    <style><?php /* tt-inline-ok — a standalone print document has no wp_head to enqueue into; the CSS itself lives in assets/css/trial-letter.css */ ?>
        <?php echo self::styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS read from assets/css/. ?>
    </style>
</head>
<body class="tt-root tt-letter-print">
    <div class="tt-letter-print__toolbar">
        <button type="button" onclick="window.print();"><?php esc_html_e( 'Print', 'talenttrack' ); ?></button>
        <a href="<?php echo esc_url( $close_url ); ?>"
           onclick="if (window.opener) { window.close(); return false; }">
            <?php esc_html_e( 'Close', 'talenttrack' ); ?>
        </a>
    </div>
    <?php echo wp_kses_post( $body ); ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }

    private static function documentTitle( int $case_id ): string {
        $case = ( new TrialCasesRepository() )->find( $case_id );
        $name = '';
        if ( $case ) {
            $case_row  = (array) $case;
            $player_id = isset( $case_row['player_id'] ) ? (int) $case_row['player_id'] : 0;
            $player    = \TT\Infrastructure\Query\QueryHelpers::get_player( $player_id );
            if ( $player ) $name = \TT\Infrastructure\Query\QueryHelpers::player_display_name( $player );
        }

        if ( $name === '' ) return __( 'Trial letter', 'talenttrack' );

        /* translators: %s: player name */
        return sprintf( __( 'Letter — %s', 'talenttrack' ), $name );
    }

    /**
     * The document's CSS, read from `assets/css/` rather than written here.
     *
     * A standalone print document has no `wp_head`, so the sheet cannot be
     * enqueued — but it can still live where every other stylesheet lives.
     * `tokens.css` comes along because the letter reads its tokens.
     */
    private static function styles(): string {
        $css = '';
        foreach ( [ 'assets/css/tokens.css', 'assets/css/trial-letter.css' ] as $relative ) {
            $path = TT_PLUGIN_DIR . $relative;
            if ( is_readable( $path ) ) $css .= (string) file_get_contents( $path ) . "\n";
        }

        return $css;
    }
}
