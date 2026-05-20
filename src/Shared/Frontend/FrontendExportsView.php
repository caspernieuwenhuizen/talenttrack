<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;

/**
 * FrontendExportsView — central exports surface (#797).
 *
 * Backed by the `/wp-json/talenttrack/v1/exports/<key>` REST endpoint
 * that's been live since the Export module shipped. Every bulk exporter
 * gets a card with its filter form here; per-record exports (PDFs,
 * one-pagers, scout reports, etc.) stay on their respective detail
 * pages where the relevant `id` is naturally in context.
 *
 * The page entry gates on "any bulk-export cap" — anyone with at least
 * one exporter's cap can reach the page. Individual cards then re-check
 * their own cap so a coach who can list players but not goals still
 * sees the players-list card and nothing else (rather than a permission
 * wall on the whole page).
 *
 * JS submits each card's form via `fetch()` to the REST endpoint with
 * the right `X-WP-Nonce`, reads the response as a `Blob`, and triggers
 * a browser download via an object URL + temporary `<a download>`. The
 * filename comes from the response's `Content-Disposition` header; the
 * existing exporters already set that.
 */
class FrontendExportsView extends FrontendViewBase {

    /**
     * Bulk-export cards rendered on the page.
     *
     * @return list<array{key:string,label:string,desc:string,format:string,cap:string,fields:list<array<string,mixed>>}>
     */
    private static function cards(): array {
        return [
            [
                'key'    => 'players_list',
                'label'  => __( 'Players list', 'talenttrack' ),
                'desc'   => __( 'Squad list with birthdate, parent email, jersey, positions.', 'talenttrack' ),
                'format' => 'CSV',
                'cap'    => 'tt_view_players',
                'fields' => [
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
                'key'    => 'attendance_register',
                'label'  => __( 'Attendance register', 'talenttrack' ),
                'desc'   => __( 'One row per (player, activity) with attendance status. Defaults to the last 90 days.', 'talenttrack' ),
                'format' => 'CSV',
                'cap'    => 'tt_view_activities',
                'fields' => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'default' => gmdate( 'Y-m-d', strtotime( '-90 days' ) ) ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'default' => gmdate( 'Y-m-d' ) ],
                ],
            ],
            [
                'key'    => 'goals_list',
                'label'  => __( 'Goals list', 'talenttrack' ),
                'desc'   => __( 'Team goals with priority, due date, owner.', 'talenttrack' ),
                'format' => 'CSV',
                'cap'    => 'tt_view_goals',
                'fields' => [
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
                'key'    => 'evaluations_xlsx',
                'label'  => __( 'Evaluations export', 'talenttrack' ),
                'desc'   => __( 'Multi-sheet workbook partitioned by season × evaluation type with main-category averages.', 'talenttrack' ),
                'format' => 'XLSX',
                'cap'    => 'tt_view_evaluations',
                'fields' => [
                    [ 'type' => 'team', 'name' => 'team_id', 'label' => __( 'Team', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_from', 'label' => __( 'From date', 'talenttrack' ), 'optional' => true ],
                    [ 'type' => 'date', 'name' => 'date_to',   'label' => __( 'To date',   'talenttrack' ), 'optional' => true ],
                ],
            ],
            [
                'key'    => 'team_ical',
                'label'  => __( 'Team activity calendar (iCal)', 'talenttrack' ),
                'desc'   => __( 'All-day calendar events for team activities. Importable into Google Calendar, Outlook, Apple Calendar.', 'talenttrack' ),
                'format' => 'iCal',
                'cap'    => 'tt_view_activities',
                'fields' => [
                    [ 'type' => 'number', 'name' => 'months_back',  'label' => __( 'Months back',  'talenttrack' ), 'default' => '1',  'min' => '0', 'max' => '24' ],
                    [ 'type' => 'number', 'name' => 'months_ahead', 'label' => __( 'Months ahead', 'talenttrack' ), 'default' => '12', 'min' => '0', 'max' => '36' ],
                ],
            ],
            [
                'key'    => 'federation_json',
                'label'  => __( 'Federation registration (JSON)', 'talenttrack' ),
                'desc'   => __( 'Neutral JSON envelope (club + teams + players) for federation imports. No federation-specific format.', 'talenttrack' ),
                'format' => 'JSON',
                'cap'    => 'tt_view_players',
                'fields' => [
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
                'key'    => 'backup_zip',
                'label'  => __( 'Full club-data backup', 'talenttrack' ),
                'desc'   => __( 'Gzipped JSON snapshot in a ZIP per selected table preset. Admin-only.', 'talenttrack' ),
                'format' => 'ZIP',
                'cap'    => 'tt_manage_backups',
                'fields' => [
                    [ 'type' => 'select', 'name' => 'preset', 'label' => __( 'Preset', 'talenttrack' ), 'options' => [
                        'minimal'  => __( 'Minimal — core tables only',           'talenttrack' ),
                        'standard' => __( 'Standard — core + activity history', 'talenttrack' ),
                        'thorough' => __( 'Thorough — every TalentTrack table', 'talenttrack' ),
                    ], 'default' => 'standard' ],
                ],
            ],
            [
                'key'    => 'demo_data_xlsx',
                'label'  => __( 'Demo-data round-trip', 'talenttrack' ),
                'desc'   => __( 'All club tables per SheetSchemas layout — for import round-trips. Admin-only.', 'talenttrack' ),
                'format' => 'XLSX',
                'cap'    => 'tt_edit_settings',
                'fields' => [],
            ],
        ];
    }

    public static function render( int $user_id, bool $is_admin ): void {
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

        echo '<p style="color:#5b6e75; max-width:760px; margin:0 0 20px;">';
        esc_html_e( 'Bulk exporters in one place. Per-record exports (player one-pager, scouting report PDF, PDP, etc.) stay on each record\'s detail page where the relevant id is in context.', 'talenttrack' );
        echo '</p>';

        // Pre-fetch teams the user can pick from (used by every card that
        // has a `team` field). Coaches see only their teams; admins +
        // HoDs see all. Skipped when the cards visible to the user don't
        // include a team picker.
        $teams = self::teamsForUser( $user_id, $is_admin );

        echo '<div class="tt-export-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:16px;">';
        foreach ( $visible_cards as $card ) {
            self::renderCard( $card, $teams );
        }
        echo '</div>';

        self::renderJs();
    }

    /**
     * @param array{key:string,label:string,desc:string,format:string,cap:string,fields:list<array<string,mixed>>} $card
     * @param array<int, object> $teams
     */
    private static function renderCard( array $card, array $teams ): void {
        $key      = $card['key'];
        $label    = $card['label'];
        $desc     = $card['desc'];
        $format   = $card['format'];
        $fields   = $card['fields'];

        echo '<div class="tt-card" style="background:#fff; border:1px solid #e5e7ea; border-radius:8px; padding:16px;">';
        echo '<div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:6px;">';
        echo '<strong style="font-size:14px;">' . esc_html( $label ) . '</strong>';
        echo '<span class="tt-pill" style="display:inline-block; padding:2px 8px; border-radius:999px; background:#0b3d2e; color:#fff; font-size:10px; font-weight:600; letter-spacing:0.04em;">' . esc_html( $format ) . '</span>';
        echo '</div>';
        echo '<p style="color:#5b6e75; font-size:12px; line-height:1.4; margin:0 0 12px;">' . esc_html( $desc ) . '</p>';

        echo '<form class="tt-export-form" data-export-key="' . esc_attr( $key ) . '" data-export-format="' . esc_attr( strtolower( $format ) ) . '" data-export-label="' . esc_attr( $label ) . '">';

        foreach ( $fields as $f ) {
            self::renderField( $f, $teams );
        }

        echo '<div style="margin-top:12px; display:flex; align-items:center; gap:10px;">';
        echo '<button type="submit" class="tt-btn tt-btn-primary" style="min-height:44px;">' . esc_html__( 'Export', 'talenttrack' ) . '</button>';
        echo '<span class="tt-export-msg" style="font-size:12px; color:#5b6e75;"></span>';
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

        echo '<label style="display:block; margin-bottom:8px; font-size:12px; color:#5b6e75;">';
        echo '<span style="display:block; margin-bottom:4px;">' . esc_html( $label );
        if ( $optional ) echo ' <span style="color:#7c7c7c;">(' . esc_html__( 'optional', 'talenttrack' ) . ')</span>';
        echo '</span>';

        switch ( $type ) {
            case 'team':
                echo '<select name="' . esc_attr( $name ) . '" class="tt-input" style="width:100%; min-height:44px;">';
                echo '<option value="">' . esc_html__( '— all teams —', 'talenttrack' ) . '</option>';
                foreach ( $teams as $t ) {
                    echo '<option value="' . (int) $t->id . '">' . esc_html( (string) $t->name ) . '</option>';
                }
                echo '</select>';
                break;
            case 'select':
                echo '<select name="' . esc_attr( $name ) . '" class="tt-input" style="width:100%; min-height:44px;">';
                foreach ( (array) ( $f['options'] ?? [] ) as $val => $lbl ) {
                    $sel = ( $default === (string) $val ) ? ' selected' : '';
                    echo '<option value="' . esc_attr( (string) $val ) . '"' . $sel . '>' . esc_html( (string) $lbl ) . '</option>';
                }
                echo '</select>';
                break;
            case 'date':
                echo '<input type="date" name="' . esc_attr( $name ) . '" class="tt-input" style="width:100%; min-height:44px;" value="' . esc_attr( $default ) . '">';
                break;
            case 'number':
                $min = isset( $f['min'] ) ? ' min="' . esc_attr( (string) $f['min'] ) . '"' : '';
                $max = isset( $f['max'] ) ? ' max="' . esc_attr( (string) $f['max'] ) . '"' : '';
                echo '<input type="number" inputmode="numeric" name="' . esc_attr( $name ) . '" class="tt-input" style="width:100%; min-height:44px;"' . $min . $max . ' value="' . esc_attr( $default ) . '">';
                break;
            default:
                echo '<input type="text" name="' . esc_attr( $name ) . '" class="tt-input" style="width:100%; min-height:44px;" value="' . esc_attr( $default ) . '">';
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

    private static function renderJs(): void {
        ?>
        <script>
        (function () {
            'use strict';
            var nonce = (window.TT && window.TT.rest_nonce) || (window.wpApiSettings && window.wpApiSettings.nonce) || '';
            var rest  = ((window.TT && window.TT.rest_url) || '/wp-json/talenttrack/v1/').replace(/\/+$/, '/');

            function filenameFromHeaders(headers, fallback) {
                var cd = headers.get('Content-Disposition') || '';
                var m = cd.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)/i);
                return m ? decodeURIComponent(m[1]) : fallback;
            }

            function triggerDownload(blob, filename) {
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
            }

            document.addEventListener('submit', function (e) {
                var form = e.target.closest && e.target.closest('.tt-export-form');
                if (!form) return;
                e.preventDefault();

                var key   = form.getAttribute('data-export-key') || '';
                var fmt   = form.getAttribute('data-export-format') || 'bin';
                var label = form.getAttribute('data-export-label') || key;
                var msg   = form.querySelector('.tt-export-msg');
                var btn   = form.querySelector('button[type="submit"]');
                if (!key) return;

                var body = {};
                var fd = new FormData(form);
                fd.forEach(function (v, k) {
                    if (v === '' || v === null) return; // skip empties so server defaults apply
                    body[k] = v;
                });

                if (msg) msg.textContent = '<?php echo esc_js( __( 'Generating…', 'talenttrack' ) ); ?>';
                if (btn) btn.disabled = true;

                fetch(rest + 'exports/' + encodeURIComponent(key), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce, 'Accept': '*/*' },
                    body: JSON.stringify(body)
                }).then(function (r) {
                    if (!r.ok) {
                        return r.json().then(function (j) {
                            var first = j && j.errors && j.errors[0] && j.errors[0].message;
                            throw new Error(first || '<?php echo esc_js( __( 'Export failed', 'talenttrack' ) ); ?> (' + r.status + ')');
                        }).catch(function (e) {
                            if (e instanceof Error) throw e;
                            throw new Error('<?php echo esc_js( __( 'Export failed', 'talenttrack' ) ); ?> (' + r.status + ')');
                        });
                    }
                    var fname = filenameFromHeaders(r.headers, label.replace(/\s+/g, '_').toLowerCase() + '.' + fmt);
                    return r.blob().then(function (blob) {
                        triggerDownload(blob, fname);
                        if (msg) msg.textContent = '<?php echo esc_js( __( 'Downloaded.', 'talenttrack' ) ); ?>';
                    });
                }).catch(function (err) {
                    if (msg) {
                        msg.style.color = '#b32d2e';
                        msg.textContent = err.message || '<?php echo esc_js( __( 'Network error.', 'talenttrack' ) ); ?>';
                    }
                }).finally(function () {
                    if (btn) btn.disabled = false;
                    setTimeout(function () {
                        if (msg) { msg.textContent = ''; msg.style.color = ''; }
                    }, 4000);
                });
            });
        }());
        </script>
        <?php
    }
}
