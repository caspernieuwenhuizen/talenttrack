<?php
namespace TT\Modules\Planning\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TeamPlannerWeeklyPrintRouter (#1631) — isolated print route for the
 * branded weekly team-planner sheet.
 *
 * URL:
 *   ?tt_planner_weekly_print=1&team_id=N&date_from=Y-m-d&date_to=Y-m-d
 *       [&fields[]=time&fields[]=…] [&header[]=academy_name&…]
 *
 * Same isolation pattern as MatchPrepPrintRouter / PdpPrintRouter: hook
 * before the admin / theme shell renders, emit a standalone document,
 * exit. No theme chrome leaks onto paper. The page is the live sheet
 * the coach sees; Save-as-PDF (or the Print button) produces the
 * pixel-perfect A4 portrait PDF that matches the approved design.
 *
 * Body + styles are composed by TeamPlannerWeeklyPrintable (the domain
 * layer) — this router only wraps them with the toolbar + document
 * scaffolding. Cap: tt_view_activities (mirrors the planner view and
 * the team_planning DomPDF exporter).
 */
class TeamPlannerWeeklyPrintRouter {

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'maybeRender' ], 1 );
        add_action( 'template_redirect', [ __CLASS__, 'maybeRender' ], 1 );
    }

    public static function maybeRender(): void {
        if ( empty( $_GET['tt_planner_weekly_print'] ) ) return;

        $team_id = isset( $_GET['team_id'] ) ? absint( $_GET['team_id'] ) : 0;
        if ( $team_id <= 0 ) return;

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Log in to print this planner.', 'talenttrack' ) );
        }
        if ( ! current_user_can( 'tt_view_activities' ) ) {
            wp_die( esc_html__( 'You do not have access to print this planner.', 'talenttrack' ) );
        }

        $tz   = wp_timezone();
        $from = self::dateParam( 'date_from', ( new \DateTime( 'today', $tz ) )->format( 'Y-m-d' ) );
        $to   = self::dateParam( 'date_to',   ( new \DateTime( '+6 days', $tz ) )->format( 'Y-m-d' ) );
        if ( $from > $to ) [ $from, $to ] = [ $to, $from ];

        $fields = self::toggleParam( 'fields', TeamPlannerWeeklyPrintable::DEFAULT_FIELDS );
        $header = self::toggleParam( 'header', TeamPlannerWeeklyPrintable::DEFAULT_HEADER );

        $parts = TeamPlannerWeeklyPrintable::render(
            $team_id, $from, $to, $fields, $header, (int) CurrentClub::id()
        );

        add_filter( 'show_admin_bar', '__return_false' );
        status_header( 200 );
        nocache_headers();
        header( 'Content-Type: text/html; charset=UTF-8' );

        echo self::document( $parts, $team_id, $from, $to ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — composed by TeamPlannerWeeklyPrintable with esc_html() on every dynamic field.
        exit;
    }

    /**
     * @param array{title:string,filename:string,style:string,body:string,empty:bool} $parts
     */
    private static function document( array $parts, int $team_id, string $from, string $to ): string {
        $close_url = add_query_arg(
            [ 'tt_view' => 'team-planner', 'week_start' => $from, 'teams' => $team_id ],
            home_url( '/' )
        );
        // Browsers default the Save-as-PDF filename to document.title, so the
        // page <title> carries the proposed name (no app-name suffix, which
        // would otherwise leak into the saved file's name).
        $filename = (string) ( $parts['filename'] ?? $parts['title'] );

        ob_start();
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo esc_html( $filename ); ?></title>
    <style>
        <?php echo $parts['style']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string from TeamPlannerWeeklyPrintable ?>
        <?php echo self::toolbarStyles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled CSS string ?>
    </style>
</head>
<body>
    <?php echo self::toolbar( $filename, $close_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — controlled markup ?>
    <?php echo $parts['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — body HTML produced by TeamPlannerWeeklyPrintable with esc_html() on every dynamic field. ?>
</body>
</html><?php
        return (string) ob_get_clean();
    }

    private static function toolbar( string $filename, string $close_url ): string {
        ob_start();
        ?>
        <div class="tt-wp-toolbar">
            <button type="button" class="primary" onclick="window.print();" data-tt-filename="<?php echo esc_attr( $filename ); ?>">
                <?php esc_html_e( 'Save as PDF / Print', 'talenttrack' ); ?>
            </button>
            <a href="<?php echo esc_url( $close_url ); ?>"
               onclick="if (window.opener) { window.close(); return false; }">
                <?php esc_html_e( 'Close', 'talenttrack' ); ?>
            </a>
            <p class="tt-wp-hint"><?php esc_html_e( 'Tip: in the print dialog, enable "Background graphics" and set margins to Default for an exact match.', 'talenttrack' ); ?></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function toolbarStyles(): string {
        return '.tt-wp-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap;'
            . ' max-width: 210mm; margin: 12px auto; padding: 0 4px; }'
            . '.tt-wp-toolbar button, .tt-wp-toolbar a {'
            . ' min-height: 48px; padding: 8px 18px; border: 1px solid #c5c8cc; background: #fff; cursor: pointer;'
            . ' border-radius: 6px; font-size: 14px; color: #1a1d21; text-decoration: none; font: inherit;'
            . ' display: inline-flex; align-items: center; touch-action: manipulation; }'
            . '.tt-wp-toolbar button.primary { background: #0b3d2e; border-color: #0b3d2e; color: #fff; font-weight: 700; }'
            . '.tt-wp-toolbar button:focus-visible, .tt-wp-toolbar a:focus-visible { outline: 3px solid #0b3d2e; outline-offset: 2px; }'
            . '.tt-wp-toolbar .tt-wp-hint { flex-basis: 100%; margin: 0; color: #5b6e75; font-size: 12px; }'
            . '@media print { .tt-wp-toolbar { display: none; } }';
    }

    private static function dateParam( string $key, string $fallback ): string {
        $v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $fallback;
    }

    /**
     * Parse a `key[]=…` toggle group into a full bool map: a key present
     * in the query is on, absent is off. When the group is entirely
     * absent from the URL, fall back to defaults (first-load / bookmarks).
     *
     * @param array<string,bool> $defaults
     * @return array<string,bool>
     */
    private static function toggleParam( string $key, array $defaults ): array {
        if ( ! isset( $_GET[ $key ] ) ) return $defaults;
        $on  = array_map( 'sanitize_key', array_map( 'strval', (array) wp_unslash( $_GET[ $key ] ) ) );
        $out = [];
        foreach ( $defaults as $k => $_ ) {
            $out[ $k ] = in_array( $k, $on, true );
        }
        return $out;
    }
}
