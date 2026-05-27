<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Export\ExportException;
use TT\Modules\Export\ExportService;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendExportsView — central exports surface (#797).
 *
 * Every bulk exporter gets a card with its filter form here; per-record
 * exports (PDFs, one-pagers, scout reports, etc.) stay on their
 * respective detail pages where the relevant `id` is naturally in
 * context.
 *
 * The page entry gates on "any bulk-export cap" — anyone with at least
 * one exporter's cap can reach the page. Individual cards then re-check
 * their own cap so a coach who can list players but not goals still
 * sees the players-list card and nothing else (rather than a permission
 * wall on the whole page).
 *
 * v4.2.2 (#903) — submission flipped from REST `fetch()` to standard
 * form POST against `?tt_view=exports`. The server-side handler verifies
 * the `tt_export` nonce, runs the exporter via `ExportService`, and
 * streams the file. The REST route at
 * `/wp-json/talenttrack/v1/exports/<key>` stays registered for
 * direct-link / API integrations, but the in-page Export click no
 * longer depends on the REST cookie-auth pipeline. Restores exports on
 * installs where REST cookie auth is broken (host REST hardening,
 * certain WAFs, etc.) and brings the surface in line with every other
 * TalentTrack form which also uses form POST + nonce.
 */
class FrontendExportsView extends FrontendViewBase {

    private static ?string $post_error = null;

    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-exports',
            TT_PLUGIN_URL . 'assets/css/frontend-exports.css',
            [ 'tt-frontend-mobile' ],
            TT_VERSION
        );
    }

    /**
     * Bulk-export cards rendered on the page.
     *
     * Each card declares `formats` as a list of format slugs. When the
     * list has more than one entry the card renders a chip-style format
     * toggle and the picked value is submitted as `format` in the POST
     * body. When the list has exactly one entry the card hides the
     * toggle and submits the single format via a hidden input. (#864)
     *
     * @return list<array{key:string,label:string,desc:string,formats:list<string>,cap:string,fields:list<array<string,mixed>>}>
     */
    private static function cards(): array {
        return [
            [
                'key'     => 'players_list',
                'label'   => __( 'Players list', 'talenttrack' ),
                'desc'    => __( 'Squad list with birthdate, parent email, jersey, positions.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_players',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'select', 'name' => 'status', 'label' => __( 'Status', 'talenttrack' ), 'options' => [
                        'active'   => __( 'Active only',   'talenttrack' ),
                        'trial'    => __( 'Trial only',    'talenttrack' ),
                        'archived' => __( 'Archived only', 'talenttrack' ),
                        'all'      => __( 'All',           'talenttrack' ),
                    ], 'default' => 'active' ],
                ],
            ],
            [
                'key'     => 'attendance_register',
                'label'   => __( 'Attendance register', 'talenttrack' ),
                'desc'    => __( 'One row per (player, activity) with attendance status. Defaults to the last 90 days.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_activities',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                ],
            ],
            [
                'key'     => 'goals_list',
                'label'   => __( 'Goals list', 'talenttrack' ),
                'desc'    => __( 'Team goals with priority, due date, owner.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_goals',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'select', 'name' => 'status', 'label' => __( 'Status', 'talenttrack' ), 'options' => [
                        'pending'     => __( 'Pending',     'talenttrack' ),
                        'in_progress' => __( 'In progress', 'talenttrack' ),
                        'completed'   => __( 'Completed',   'talenttrack' ),
                        'archived'    => __( 'Archived',    'talenttrack' ),
                        'all'         => __( 'All',         'talenttrack' ),
                    ], 'default' => 'all' ],
                ],
            ],
            [
                'key'     => 'evaluations_xlsx',
                'label'   => __( 'Evaluations export', 'talenttrack' ),
                'desc'    => __( 'Multi-sheet workbook partitioned by season × evaluation type with main-category averages.', 'talenttrack' ),
                'formats' => [ 'xlsx' ],
                'cap'     => 'tt_view_evaluations',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'optional' => true ],
                ],
            ],
            [
                'key'     => 'player_evaluations',
                'label'   => __( 'Player evaluations (flat)', 'talenttrack' ),
                'desc'    => __( 'One row per evaluation with main-category averages. Flat-CSV companion to the multi-sheet evaluations workbook.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_evaluations',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'optional' => true ],
                ],
            ],
            [
                'key'     => 'team_roster_stats',
                'label'   => __( 'Team roster + season stats', 'talenttrack' ),
                'desc'    => __( 'One row per player on the chosen team — roster fields plus attendance count, minutes played, and average rating across the date range.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_players',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ) ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-d', strtotime( '-1 year' ) ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                ],
            ],
            [
                'key'     => 'team_activities',
                'label'   => __( 'Team activity history', 'talenttrack' ),
                'desc'    => __( 'One row per activity — date, title, attendance count, average rating. Date-bounded.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_activities',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-d', strtotime( '-1 year' ) ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                ],
            ],
            [
                'key'     => 'kpi_snapshot',
                'label'   => __( 'KPI snapshot', 'talenttrack' ),
                'desc'    => __( 'Point-in-time KPIs — active players, teams, activities, attendance %, goal counts. For board reports.', 'talenttrack' ),
                'formats' => [ 'xlsx' ],
                'cap'     => 'tt_view_reports',
                'fields'  => [
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-01' ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                ],
            ],
            [
                'key'     => 'staff_directory',
                'label'   => __( 'Coach / staff directory', 'talenttrack' ),
                'desc'    => __( 'Contact list of coaches, scouts and staff with role, team assignments, email and phone.', 'talenttrack' ),
                'formats' => [ 'csv', 'xlsx' ],
                'cap'     => 'tt_view_people',
                'fields'  => [
                    [ 'type' => 'select', 'name' => 'role_type', 'label' => __( 'Role type', 'talenttrack' ), 'options' => [
                        'all'   => __( 'All',         'talenttrack' ),
                        'coach' => __( 'Coach only',  'talenttrack' ),
                        'scout' => __( 'Scout only',  'talenttrack' ),
                        'staff' => __( 'Staff only',  'talenttrack' ),
                        'other' => __( 'Other',       'talenttrack' ),
                    ], 'default' => 'all' ],
                ],
            ],
            [
                'key'     => 'audit_log',
                'label'   => __( 'Audit log', 'talenttrack' ),
                'desc'    => __( 'Compliance / GDPR — who changed what, when. Admin-only. Defaults to the last 30 days.', 'talenttrack' ),
                'formats' => [ 'csv' ],
                'cap'     => 'tt_manage_settings',
                'fields'  => [
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                    [ 'type' => 'text', 'name' => 'action', 'label' => __( 'Action (contains)', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'text', 'name' => 'entity_type', 'label' => __( 'Entity type', 'talenttrack' ), 'optional' => true ],
                ],
            ],
            [
                'key'     => 'team_ical',
                'label'   => __( 'Team activity calendar (iCal)', 'talenttrack' ),
                'desc'    => __( 'All-day calendar events for team activities. Importable into Google Calendar, Outlook, Apple Calendar.', 'talenttrack' ),
                'formats' => [ 'ics' ],
                'cap'     => 'tt_view_activities',
                'fields'  => [
                    [ 'type' => 'number', 'name' => 'months_back',  'label' => __( 'Months back',  'talenttrack' ), 'default' => '1',  'min' => '0', 'max' => '24' ],
                    [ 'type' => 'number', 'name' => 'months_ahead', 'label' => __( 'Months ahead', 'talenttrack' ), 'default' => '12', 'min' => '0', 'max' => '36' ],
                ],
            ],
            [
                'key'     => 'federation_json',
                'label'   => __( 'Federation registration (JSON)', 'talenttrack' ),
                'desc'    => __( 'Neutral JSON envelope (club + teams + players) for federation imports. No federation-specific format.', 'talenttrack' ),
                'formats' => [ 'json' ],
                'cap'     => 'tt_view_players',
                'fields'  => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'select', 'name' => 'status', 'label' => __( 'Status', 'talenttrack' ), 'options' => [
                        'active'   => __( 'Active only',   'talenttrack' ),
                        'trial'    => __( 'Trial only',    'talenttrack' ),
                        'archived' => __( 'Archived only', 'talenttrack' ),
                        'all'      => __( 'All',           'talenttrack' ),
                    ], 'default' => 'active' ],
                ],
            ],
            [
                'key'     => 'backup_zip',
                'label'   => __( 'Full club-data backup', 'talenttrack' ),
                'desc'    => __( 'Gzipped JSON snapshot in a ZIP per selected table preset. Admin-only.', 'talenttrack' ),
                'formats' => [ 'zip' ],
                'cap'     => 'tt_manage_backups',
                'fields'  => [
                    [ 'type' => 'select', 'name' => 'preset', 'label' => __( 'Preset', 'talenttrack' ), 'options' => [
                        'minimal'  => __( 'Minimal — core tables only',           'talenttrack' ),
                        'standard' => __( 'Standard — core + activity history', 'talenttrack' ),
                        'thorough' => __( 'Thorough — every TalentTrack table', 'talenttrack' ),
                    ], 'default' => 'standard' ],
                ],
            ],
            [
                'key'     => 'demo_data_xlsx',
                'label'   => __( 'Demo-data round-trip', 'talenttrack' ),
                'desc'    => __( 'All club tables per SheetSchemas layout — for import round-trips. Admin-only.', 'talenttrack' ),
                'formats' => [ 'xlsx' ],
                'cap'     => 'tt_edit_settings',
                'fields'  => [],
            ],
        ];
    }

    /**
     * Display label for a format slug (badge + chip text).
     */
    private static function formatLabel( string $slug ): string {
        $map = [
            'csv'  => 'CSV',
            'xlsx' => 'XLSX',
            'ics'  => 'iCal',
            'json' => 'JSON',
            'zip'  => 'ZIP',
            'pdf'  => 'PDF',
        ];
        return $map[ strtolower( $slug ) ] ?? strtoupper( $slug );
    }

    public static function render( int $user_id, bool $is_admin ): void {
        // #939 — Exports surface is GET-only. The download path used to
        // live in this method via `handleExportPost()`, but it ran
        // INSIDE the `[talenttrack_dashboard]` shortcode — i.e. after
        // `wp_head()`, after the theme had streamed `<!DOCTYPE html>`
        // + header + nav, and inside the shortcode's own `ob_start()`.
        // Result: `header('Content-Type: ...')` silently failed
        // (headers already sent), and the binary/CSV bytes echoed into
        // the OB buffer appended AFTER the page HTML. Browser saved
        // the whole HTML-plus-binary blob with the requested filename
        // and Content-Type text/html → corrupt XLSX, HTML-prefixed CSV.
        //
        // Form POSTs now target admin-post.php (`action=tt_export`)
        // handled by `handleAdminPostExport()`, registered via
        // `ExportModule::boot()`. admin-post.php doesn't bootstrap the
        // theme, so headers are clean and binary content uncorrupted.
        // Mirrors the wizard-form switch in #940.
        if ( isset( $_GET['tt_export_error'] ) ) {
            self::$post_error = self::messageForExportError( sanitize_key( (string) wp_unslash( (string) $_GET['tt_export_error'] ) ) );
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Exports', 'talenttrack' ) );

        $all_cards = self::cards();
        $visible_cards = array_values( array_filter( $all_cards, static function ( $c ) use ( $is_admin ) {
            return $is_admin || current_user_can( $c['cap'] );
        } ) );

        if ( empty( $visible_cards ) ) {
            self::renderHeader( __( 'Exports', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have access to any bulk exporters. Per-record exports (player one-pager, evaluation PDF, etc.) are still available from each record\'s detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        self::enqueueAssets();
        self::renderHeader( __( 'Exports', 'talenttrack' ) );

        echo '<p class="tt-export-intro">';
        esc_html_e( 'Bulk exporters in one place. Per-record exports (player one-pager, scouting report PDF, PDP, etc.) stay on each record\'s detail page where the relevant id is in context.', 'talenttrack' );
        echo '</p>';

        if ( self::$post_error !== null ) {
            echo '<div class="tt-notice tt-notice--error" style="background:#fdecea; border-left:4px solid #d63638; padding:12px 16px; margin:8px 0 16px;">';
            echo esc_html( self::$post_error );
            echo '</div>';
        }

        // Pre-fetch teams the user can pick from (used by every card that
        // has a `team` field). Coaches see only their teams; admins +
        // HoDs see all. Skipped when the cards visible to the user don't
        // include a team picker.
        $teams = self::teamsForUser( $user_id, $is_admin );

        echo '<div class="tt-export-grid">';
        foreach ( $visible_cards as $card ) {
            self::renderCard( $card, $teams );
        }
        echo '</div>';
    }

    /**
     * #939 — admin-post.php handler for an export form POST.
     *
     * Why: the prior in-shortcode handler ran AFTER `wp_head()` had
     * already flushed the theme chrome; `header('Content-Type: …')`
     * silently failed and binary bytes appended after `</html>`. CSV
     * downloads contained the dashboard HTML; XLSX downloads were
     * corrupt (ZIP signature not at byte 0). admin-post.php loads
     * `wp-load.php` but doesn't bootstrap the theme, so download
     * headers fire cleanly and bytes go straight onto the wire.
     *
     * On success: emits headers, echoes bytes, `exit`s. On failure:
     * redirects back to `?tt_view=exports&tt_export_error=<key>` and
     * carries the error message via the same transient mechanism as
     * the wizard handler (#940), so the next GET surfaces a visible
     * notice above the export grid.
     */
    public static function handleAdminPostExport(): void {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $return_url = isset( $_POST['tt_export_return_url'] )
            ? esc_url_raw( wp_unslash( (string) $_POST['tt_export_return_url'] ) )
            : '';
        if ( $return_url === '' ) {
            $return_url = add_query_arg( 'tt_view', 'exports', \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() );
        }

        $nonce = isset( $_POST['_tt_export_nonce'] )
            ? sanitize_text_field( wp_unslash( (string) $_POST['_tt_export_nonce'] ) )
            : '';
        if ( ! wp_verify_nonce( $nonce, 'tt_export' ) ) {
            self::redirectWithExportError( $return_url, 'nonce' );
        }

        $key = sanitize_key( (string) ( $_POST['tt_export_key'] ?? '' ) );
        if ( $key === '' ) {
            self::redirectWithExportError( $return_url, 'missing_key' );
        }

        $exporter = ExporterRegistry::get( $key );
        if ( $exporter === null ) {
            self::redirectWithExportError( $return_url, 'unknown_key' );
        }

        $format = (string) ( $_POST['format'] ?? '' );
        if ( $format === '' ) {
            $supported = $exporter->supportedFormats();
            $format    = $supported[0] ?? 'csv';
        }
        $format = sanitize_key( $format );

        $entity_id_raw = $_POST['entity_id'] ?? null;
        $entity_id     = $entity_id_raw !== null ? absint( $entity_id_raw ) : null;

        $brand_raw = $_POST['brand'] ?? null;
        $brand     = is_string( $brand_raw ) && in_array( $brand_raw, [ 'auto', 'blank', 'letterhead' ], true ) ? $brand_raw : null;

        $reserved = [ 'action', '_tt_export_nonce', 'tt_export_key', 'tt_export_return_url', 'format', 'entity_id', 'brand', '_wpnonce', '_wp_http_referer' ];
        $filters  = [];
        foreach ( $_POST as $k => $v ) {
            $k = (string) $k;
            if ( in_array( $k, $reserved, true ) ) continue;
            if ( is_array( $v ) ) {
                $filters[ $k ] = array_map( static function ( $vv ) {
                    return is_scalar( $vv ) ? sanitize_text_field( wp_unslash( (string) $vv ) ) : '';
                }, $v );
            } elseif ( is_scalar( $v ) ) {
                $val = sanitize_text_field( wp_unslash( (string) $v ) );
                if ( $val !== '' ) $filters[ $k ] = $val;
            }
        }

        $request = new ExportRequest(
            $key,
            $format,
            (int) CurrentClub::id(),
            $user_id,
            $entity_id !== null && $entity_id > 0 ? $entity_id : null,
            $filters,
            $brand,
            null
        );

        try {
            $result = ( new ExportService() )->run( $request );
        } catch ( ExportException $e ) {
            set_transient( 'tt_export_err_' . $user_id, $e->getMessage(), 60 );
            self::redirectWithExportError( $return_url, 'service' );
        }

        nocache_headers();
        header( 'Content-Type: ' . $result->mime );
        header( 'Content-Length: ' . $result->size );
        header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $result->filename ) . '"' );
        echo $result->bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary download.
        exit;
    }

    /**
     * Redirect back to the exports page with a coded error key. The
     * `service` error code additionally consumes a transient set by
     * the caller carrying the human-readable message.
     */
    private static function redirectWithExportError( string $return_url, string $code ): void {
        wp_safe_redirect( add_query_arg( 'tt_export_error', $code, $return_url ) );
        exit;
    }

    /**
     * Resolve a coded error key from the query string into a
     * human-readable notice shown above the export grid. The `service`
     * code consumes the per-user transient set by the handler.
     */
    private static function messageForExportError( string $code ): ?string {
        switch ( $code ) {
            case 'nonce':
                return __( 'Export request failed: session expired. Please reload the page and try again.', 'talenttrack' );
            case 'missing_key':
                return __( 'Export request failed: missing exporter key.', 'talenttrack' );
            case 'unknown_key':
                return __( 'Export request failed: no exporter registered for that key.', 'talenttrack' );
            case 'service':
                $user_id = get_current_user_id();
                $key     = 'tt_export_err_' . $user_id;
                $msg     = get_transient( $key );
                if ( $msg !== false ) {
                    delete_transient( $key );
                    return (string) $msg;
                }
                return __( 'Export request failed.', 'talenttrack' );
        }
        return null;
    }

    /**
     * @param array{key:string,label:string,desc:string,formats:list<string>,cap:string,fields:list<array<string,mixed>>} $card
     * @param array<int, object> $teams
     */
    private static function renderCard( array $card, array $teams ): void {
        $key      = $card['key'];
        $label    = $card['label'];
        $desc     = $card['desc'];
        $formats  = $card['formats'];
        $fields   = $card['fields'];

        $primary  = $formats[0];
        $multi    = count( $formats ) > 1;

        echo '<div class="tt-export-card">';

        // Single-format cards keep the static badge top-right; multi-format
        // cards render the chip-group inline below the description.
        if ( ! $multi ) {
            echo '<span class="tt-export-card__format">' . esc_html( self::formatLabel( $primary ) ) . '</span>';
        }

        echo '<div class="tt-export-card__header"><strong class="tt-export-card__title">' . esc_html( $label ) . '</strong></div>';
        echo '<p class="tt-export-card__desc">' . esc_html( $desc ) . '</p>';

        // #939 — POST target is admin-post.php (`action=tt_export`),
        // not the current page URL. Reason: the in-shortcode handler
        // ran AFTER wp_head() flushed the theme chrome, corrupting
        // every binary download and prefixing CSVs with the page HTML.
        // admin-post.php bypasses the theme entirely.
        echo '<form class="tt-export-form tt-export-card__form" method="POST" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-export-key="' . esc_attr( $key ) . '" data-export-label="' . esc_attr( $label ) . '">';
        wp_nonce_field( 'tt_export', '_tt_export_nonce' );
        echo '<input type="hidden" name="action" value="tt_export">';
        echo '<input type="hidden" name="tt_export_key" value="' . esc_attr( $key ) . '">';
        echo '<input type="hidden" name="tt_export_return_url" value="' . esc_attr( add_query_arg( 'tt_view', 'exports', \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl() ) ) . '">';

        foreach ( $fields as $f ) {
            self::renderField( $f, $teams );
        }

        if ( $multi ) {
            echo '<div class="tt-export-card__field">';
            echo '<span class="tt-export-card__field-label">' . esc_html__( 'Format', 'talenttrack' ) . '</span>';
            echo '<div class="tt-export-card__formats" role="radiogroup" aria-label="' . esc_attr__( 'Export format', 'talenttrack' ) . '">';
            foreach ( $formats as $i => $fmt ) {
                $checked = $i === 0 ? ' checked' : '';
                echo '<label class="tt-export-card__format-chip">';
                echo '<input type="radio" name="format" value="' . esc_attr( $fmt ) . '"' . $checked . '>';
                echo '<span>' . esc_html( self::formatLabel( $fmt ) ) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo '</div>';
        } else {
            echo '<input type="hidden" name="format" value="' . esc_attr( $primary ) . '">';
        }

        echo '<div class="tt-export-card__footer">';
        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Export', 'talenttrack' ) . '</button>';
        echo '<span class="tt-export-msg tt-export-card__msg"></span>';
        echo '</div>';

        echo '</form>';
        echo '</div>';
    }

    /**
     * @param array<string,mixed> $f
     * @param array<int, object> $teams
     */
    private static function renderField( array $f, array $teams ): void {
        $type     = (string) ( $f['type'] ?? 'text' );
        $name     = (string) ( $f['name'] ?? '' );
        $label    = (string) ( $f['label'] ?? '' );
        $optional = ! empty( $f['optional'] );
        $default  = (string) ( $f['default'] ?? '' );

        echo '<label class="tt-export-card__field">';
        echo '<span class="tt-export-card__field-label">' . esc_html( $label );
        if ( $optional ) echo ' <span class="tt-export-card__field-optional">(' . esc_html__( 'optional', 'talenttrack' ) . ')</span>';
        echo '</span>';

        switch ( $type ) {
            case 'team':
                echo '<select name="' . esc_attr( $name ) . '" class="tt-input">';
                echo '<option value="">' . esc_html__( '— all teams —', 'talenttrack' ) . '</option>';
                foreach ( $teams as $t ) {
                    echo '<option value="' . (int) $t->id . '">' . esc_html( (string) $t->name ) . '</option>';
                }
                echo '</select>';
                break;
            case 'select':
                echo '<select name="' . esc_attr( $name ) . '" class="tt-input">';
                foreach ( (array) ( $f['options'] ?? [] ) as $val => $lbl ) {
                    $sel = ( $default === (string) $val ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( (string) $val ) . '"' . $sel . '>' . esc_html( (string) $lbl ) . '</option>';
                }
                echo '</select>';
                break;
            case 'date':
                echo '<input type="date" name="' . esc_attr( $name ) . '" class="tt-input" value="' . esc_attr( $default ) . '">';
                break;
            case 'number':
                $min = isset( $f['min'] ) ? ' min="' . esc_attr( (string) $f['min'] ) . '"' : '';
                $max = isset( $f['max'] ) ? ' max="' . esc_attr( (string) $f['max'] ) . '"' : '';
                echo '<input type="number" inputmode="numeric" name="' . esc_attr( $name ) . '" class="tt-input"' . $min . $max . ' value="' . esc_attr( $default ) . '">';
                break;
            default:
                echo '<input type="text" name="' . esc_attr( $name ) . '" class="tt-input" value="' . esc_attr( $default ) . '">';
        }

        echo '</label>';
    }

    /**
     * @return array<int, object>
     */
    private static function teamsForUser( int $user_id, bool $is_admin ): array {
        if ( $is_admin || current_user_can( 'tt_manage_teams' ) ) {
            $teams = QueryHelpers::get_teams();
        } else {
            $teams = method_exists( QueryHelpers::class, 'get_teams_for_coach' )
                ? QueryHelpers::get_teams_for_coach( $user_id )
                : QueryHelpers::get_teams();
        }
        return is_array( $teams ) ? $teams : [];
    }

}
