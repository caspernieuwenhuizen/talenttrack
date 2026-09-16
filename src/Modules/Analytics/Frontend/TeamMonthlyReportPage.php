<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendAppChrome;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamMonthlyReportPage (#3459, epic #3457) — the team monthly report on
 * screen, and the composition panel that decides what is in it.
 *
 * Chrome lives in `FrontendStandardReportsView` (picker, scope guard, window,
 * period bar, breadcrumbs), the same split the learning reports use; the panel
 * and the body live here so that 1,900-line file does not grow another six
 * hundred. Nothing here computes a figure: every number comes from
 * `TeamMonthlyReport`, and the fit meter from `TeamMonthlyReportLayout`, the
 * same estimate the PDF exporter (#3460) reads.
 *
 * ## The panel
 *
 * A plain GET form, so a composed report is a shareable URL
 * (`&layout=B&blocks=kpi,status,…`), works without script, and survives a
 * reload. A small enqueued script submits it on change and writes `blocks` as
 * one comma-separated value; without script the "Update report" button does
 * the same through `blk[]`, which is read too.
 *
 * Wizard plan: exemption — live-preview surface, not a multi-step flow
 * (decided 2026-09-16, epic #3457).
 */
final class TeamMonthlyReportPage {

    public const SLUG = 'team-monthly';

    /**
     * @param object                                          $team   tt_teams row.
     * @param array{from:string,to:string,period:string}      $window the resolved window.
     */
    public static function render( object $team, array $window ): void {
        self::enqueue();

        $team_id = (int) ( $team->id ?? 0 );
        $layout  = self::requestedLayout();
        $blocks  = self::requestedBlocks();

        $report = ( new TeamMonthlyReport() )->forTeam( $team_id, $window['from'], $window['to'], $blocks, get_current_user_id() );
        $fit    = TeamMonthlyReportLayout::fit( $report, $layout );
        $data   = $report['data'];

        self::renderPanel( $team_id, $window, $layout, $report['blocks'], $fit );

        echo '<div class="tt-mr" data-tt-monthly-report>';
        $head = $data['letterhead'] ?? [];
        self::renderLetterhead( $team, $head, $window );

        if ( (int) ( $head['activity_count'] ?? 0 ) === 0 ) {
            echo '<div class="tt-rep-section"><p class="tt-mr-empty">'
                . esc_html__( 'This team has no completed trainings or matches in this window, so there is nothing to report yet. Pick another period above.', 'talenttrack' )
                . '</p></div>';
            self::renderConfidential();
            echo '</div>';
            return;
        }

        foreach ( $report['blocks'] as $block ) {
            if ( $block === TeamMonthlyReportBlock::LETTERHEAD ) continue;
            $block_data = $data[ $block ] ?? [];
            switch ( $block ) {
                case TeamMonthlyReportBlock::COVERAGE:   self::renderCoverage( $block_data ); break;
                case TeamMonthlyReportBlock::KPI:        self::renderKpis( $block_data ); break;
                case TeamMonthlyReportBlock::STATUS:     self::renderStatus( $block_data ); break;
                case TeamMonthlyReportBlock::ATTENDANCE: self::renderAttendance( $block_data, $team_id, $window ); break;
                case TeamMonthlyReportBlock::MINUTES:    self::renderMinutes( $block_data, $team_id, $window ); break;
                case TeamMonthlyReportBlock::ATTENTION:  self::renderAttention( $block_data ); break;
                case TeamMonthlyReportBlock::CHANGES:    self::renderChanges( $block_data ); break;
                case TeamMonthlyReportBlock::TESTS:      self::renderTests( $block_data ); break;
                case TeamMonthlyReportBlock::ROSTER:     self::renderRoster( $block_data ); break;
                case TeamMonthlyReportBlock::NOTES:      self::renderNotes(); break;
                case TeamMonthlyReportBlock::QUALITY:    self::renderQuality( $block_data ); break;
            }
        }

        self::renderConfidential();
        echo '</div>';
    }

    /* ---------------------------------------------------------------
     * Request
     * ------------------------------------------------------------- */

    private static function requestedLayout(): string {
        $raw = isset( $_GET['layout'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['layout'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        return TeamMonthlyReportLayout::isValid( $raw ) ? $raw : TeamMonthlyReportLayout::DEFAULT;
    }

    /**
     * `blocks=a,b,c` from the script or a shared link; `blk[]=a` from the
     * no-script submit. Unknown keys from a hand-edited URL are dropped rather
     * than erroring the page — the composer is strict, the screen forgiving.
     *
     * @return list<string>
     */
    private static function requestedBlocks(): array {
        $keys = [];
        if ( isset( $_GET['blocks'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $keys = explode( ',', sanitize_text_field( wp_unslash( (string) $_GET['blocks'] ) ) );
        } elseif ( isset( $_GET['blk'] ) && is_array( $_GET['blk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            foreach ( wp_unslash( $_GET['blk'] ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized per key below.
                $keys[] = (string) $k;
            }
        }
        $keys = array_map( static fn( string $k ): string => sanitize_key( trim( $k ) ), $keys );
        return array_values( array_filter( $keys, [ TeamMonthlyReportBlock::class, 'isValid' ] ) );
    }

    private static function enqueue(): void {
        wp_enqueue_style(
            'tt-frontend-team-monthly-report',
            TT_PLUGIN_URL . 'assets/css/frontend-team-monthly-report.css',
            [ 'tt-frontend-standard-reports' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-frontend-team-monthly-report',
            TT_PLUGIN_URL . 'assets/js/frontend-team-monthly-report.js',
            [],
            TT_VERSION,
            true
        );
    }

    /* ---------------------------------------------------------------
     * Composition panel
     * ------------------------------------------------------------- */

    /**
     * @param array{from:string,to:string,period:string}                                  $window
     * @param list<string>                                                                $selected
     * @param array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>} $fit
     */
    private static function renderPanel( int $team_id, array $window, string $layout, array $selected, array $fit ): void {
        $hidden = [
            'tt_view' => 'standard-report', /* tt-xview-ok */ // the form re-opens this same view
            'slug'    => self::SLUG,
            'team_id' => (string) $team_id,
        ];
        if ( $window['period'] !== '' ) {
            $hidden['period'] = $window['period'];
        } else {
            $hidden['from'] = $window['from'];
            $hidden['to']   = $window['to'];
        }
        if ( ! empty( $_GET['tt_back'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $hidden['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        $block_labels = self::blockLabels();

        // A GET form replaces the query string of its action, so a dashboard
        // reached as `?page_id=58` would lose that on a no-script submit.
        // Carry the action's own parameters as fields, and post to its path.
        $action = RecordLink::dashboardUrl();
        $query  = (string) wp_parse_url( $action, PHP_URL_QUERY );
        if ( $query !== '' ) {
            parse_str( $query, $action_args );
            foreach ( $action_args as $name => $value ) {
                if ( is_string( $name ) && is_string( $value ) && ! isset( $hidden[ $name ] ) ) {
                    $hidden = [ $name => $value ] + $hidden;
                }
            }
            $action = strtok( $action, '?' );
        }

        echo '<form class="tt-mr-panel" method="get" action="' . esc_url( (string) $action ) . '" data-tt-mr-panel>';
        foreach ( $hidden as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        }

        // Report type — radios styled as cards: native keyboard behaviour
        // (arrow keys move within the group), no ARIA re-implementation.
        echo '<fieldset class="tt-mr-panel__group">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Report type', 'team monthly report panel', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-mr-types">';
        foreach ( TeamMonthlyReportLayout::labels() as $key => $label ) {
            $id = 'tt-mr-layout-' . strtolower( $key );
            echo '<label class="tt-mr-type" for="' . esc_attr( $id ) . '">';
            echo '<input type="radio" id="' . esc_attr( $id ) . '" name="layout" value="' . esc_attr( $key ) . '"' . checked( $layout, $key, false ) . '>';
            echo '<span class="tt-mr-type__t">' . esc_html( $label['title'] ) . '</span>';
            echo '<span class="tt-mr-type__d">' . esc_html( $label['desc'] ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'The type shapes the printed copy. Online, every selected block is shown in full.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';

        // Blocks.
        echo '<fieldset class="tt-mr-panel__group">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Sections', 'team monthly report panel', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-mr-blocks">';
        foreach ( TeamMonthlyReportBlock::ALL as $key ) {
            $id     = 'tt-mr-blk-' . $key;
            $locked = $key === TeamMonthlyReportBlock::LETTERHEAD;
            $on     = in_array( $key, $selected, true );
            $note   = TeamMonthlyReportLayout::availability( $layout, $key ) === 'compressed'
                ? __( 'Narrower on the one-pager', 'talenttrack' )
                : $block_labels[ $key ]['note'];

            echo '<label class="tt-mr-block' . ( $locked ? ' is-locked' : '' ) . '" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="blk[]" value="' . esc_attr( $key ) . '"'
                . checked( $on || $locked, true, false )
                . disabled( $locked, true, false ) . ' data-tt-mr-block>';
            if ( $locked ) {
                // A disabled checkbox is not submitted; the letterhead is added
                // by the composer anyway, this only keeps the URL honest.
                echo '<input type="hidden" name="blk[]" value="' . esc_attr( $key ) . '">';
            }
            echo '<span class="tt-mr-block__t">' . esc_html( $block_labels[ $key ]['title'] ) . '</span>';
            echo '<span class="tt-mr-block__n">' . esc_html( $note ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '</fieldset>';

        self::renderFitMeter( $fit );

        echo '<div class="tt-mr-panel__actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-mr-submit>' . esc_html__( 'Update report', 'talenttrack' ) . '</button>';
        echo '</div>';
        echo '</form>';
    }

    /**
     * @param array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>} $fit
     */
    private static function renderFitMeter( array $fit ): void {
        echo '<div class="tt-mr-fit" role="status" aria-live="polite">';
        echo '<p class="tt-mr-panel__legend">' . esc_html_x( 'Printed size', 'team monthly report panel', 'talenttrack' ) . '</p>';
        echo '<p class="tt-mr-fit__pages">' . esc_html( sprintf(
            /* translators: %d: number of printed pages */
            _n( '%d page', '%d pages', $fit['pages'], 'talenttrack' ),
            $fit['pages']
        ) ) . '</p>';

        echo '<ul class="tt-mr-fit__bars">';
        foreach ( $fit['fill'] as $i => $fill ) {
            $width = max( 0, min( 100, $fill ) );
            $state = $fill > 100 ? 'over' : ( $fill > 90 ? 'tight' : 'ok' );
            echo '<li class="tt-mr-fit__bar is-' . esc_attr( $state ) . '">';
            echo '<span class="tt-mr-fit__label">' . esc_html( sprintf(
                /* translators: 1: page number, 2: how full the page is, as a percentage */
                __( 'Page %1$d · %2$d%%', 'talenttrack' ),
                $i + 1,
                $fill
            ) ) . '</span>';
            echo '<span class="tt-mr-fit__track"><i style="width:' . (int) $width . '%;"></i></span>'; /* tt-inline-ok */
            echo '</li>';
        }
        echo '</ul>';

        if ( ! $fit['fits'] ) {
            $msg = $fit['max_pages'] === 1
                ? __( 'Does not fit on one page. Drop a section, or switch to the three-page pack.', 'talenttrack' )
                : __( 'A page overflows. Drop a section to keep the pack to three pages.', 'talenttrack' );
            echo '<p class="tt-mr-fit__msg is-over">' . esc_html( $msg ) . '</p>';
        } elseif ( $fit['degraded'] !== [] ) {
            echo '<p class="tt-mr-fit__msg is-tight">' . esc_html__( 'Fits by shortening: long player lists keep their top and bottom, and the agenda keeps its two most urgent players.', 'talenttrack' ) . '</p>';
        } else {
            echo '<p class="tt-mr-fit__msg is-ok">' . esc_html__( 'Everything fits.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';
    }

    /**
     * @return array<string,array{title:string, note:string}>
     */
    private static function blockLabels(): array {
        return [
            'letterhead' => [ 'title' => _x( 'Letterhead', 'team monthly report section', 'talenttrack' ),           'note' => __( 'Team, period, head coach — always included', 'talenttrack' ) ],
            'coverage'   => [ 'title' => _x( 'Data coverage', 'team monthly report section', 'talenttrack' ),        'note' => __( 'What the report could not see', 'talenttrack' ) ],
            'kpi'        => [ 'title' => _x( 'Headline numbers', 'team monthly report section', 'talenttrack' ),     'note' => __( 'Six figures against last period', 'talenttrack' ) ],
            'status'     => [ 'title' => _x( 'Squad status', 'team monthly report section', 'talenttrack' ),         'note' => __( 'How many players are on track', 'talenttrack' ) ],
            'attendance' => [ 'title' => _x( 'Attendance', 'team monthly report section', 'talenttrack' ),           'note' => __( 'Per player, lowest first', 'talenttrack' ) ],
            'minutes'    => [ 'title' => _x( 'Minutes share', 'team monthly report section', 'talenttrack' ),        'note' => __( 'Per player, against the target', 'talenttrack' ) ],
            'attention'  => [ 'title' => _x( 'Needs a conversation', 'team monthly report section', 'talenttrack' ), 'note' => __( 'The agenda', 'talenttrack' ) ],
            'changes'    => [ 'title' => _x( 'What changed', 'team monthly report section', 'talenttrack' ),         'note' => __( 'Injuries, moves, signings', 'talenttrack' ) ],
            'tests'      => [ 'title' => _x( 'Tests', 'team monthly report section', 'talenttrack' ),                'note' => __( 'Test rounds held and who moved', 'talenttrack' ) ],
            'roster'     => [ 'title' => _x( 'Player by player', 'team monthly report section', 'talenttrack' ),     'note' => __( 'Every measure in one table', 'talenttrack' ) ],
            'notes'      => [ 'title' => _x( 'Decisions and actions', 'team monthly report section', 'talenttrack' ), 'note' => __( 'Space to write on', 'talenttrack' ) ],
            'quality'    => [ 'title' => _x( 'Data quality', 'team monthly report section', 'talenttrack' ),         'note' => __( 'What to fix before next month', 'talenttrack' ) ],
        ];
    }

    /* ---------------------------------------------------------------
     * Body
     * ------------------------------------------------------------- */

    /**
     * @param array<string,mixed>                     $head
     * @param array{from:string,to:string,period:string} $window
     */
    private static function renderLetterhead( object $team, array $head, array $window ): void {
        $bits = [
            sprintf(
                /* translators: 1: window start date, 2: window end date */
                __( '%1$s – %2$s', 'talenttrack' ),
                TTDate::date( $window['from'] ),
                TTDate::date( $window['to'] )
            ),
        ];
        $coach = (string) ( $head['head_coach'] ?? '' );
        if ( $coach !== '' ) {
            /* translators: %s: head coach's name */
            $bits[] = sprintf( __( 'Head coach %s', 'talenttrack' ), $coach );
        }
        $squad = (int) ( $head['squad_size'] ?? 0 );
        /* translators: %d: players in the squad */
        $bits[] = sprintf( _n( '%d player', '%d players', $squad, 'talenttrack' ), $squad );
        $acts = (int) ( $head['activity_count'] ?? 0 );
        /* translators: %d: completed trainings and matches */
        $bits[] = sprintf( _n( '%d completed activity', '%d completed activities', $acts, 'talenttrack' ), $acts );

        echo '<header class="tt-rep-page-head tt-mr-head">';
        echo '<h1>' . esc_html( sprintf(
            /* translators: %s: team name */
            __( 'Monthly report — %s', 'talenttrack' ),
            (string) ( $team->name ?? '' )
        ) ) . '</h1>';
        echo '<p class="tt-rep-page-head__sub">' . esc_html( implode( ' · ', $bits ) ) . '</p>';
        echo '</header>';
    }

    /** @param array<string,mixed> $c */
    private static function renderCoverage( array $c ): void {
        $state     = (string) ( $c['state'] ?? 'empty' );
        $completed = (int) ( $c['completed'] ?? 0 );
        $with      = (int) ( $c['with_register'] ?? 0 );
        $missing   = is_array( $c['missing'] ?? null ) ? $c['missing'] : [];

        // "Complete" is rendered, not left out: the absence of a warning has
        // to read as evidence, or a reader cannot tell "all recorded" from
        // "the check did not run".
        echo '<section class="tt-mr-coverage is-' . esc_attr( $state ) . '" aria-label="' . esc_attr_x( 'Data coverage', 'team monthly report section', 'talenttrack' ) . '">';
        if ( $state === 'complete' ) {
            echo '<p><strong>' . esc_html__( 'Complete.', 'talenttrack' ) . '</strong> ' . esc_html( sprintf(
                /* translators: %d: completed activities */
                _n( 'The one completed activity has an attendance register.', 'All %d completed activities have an attendance register.', $completed, 'talenttrack' ),
                $completed
            ) ) . '</p>';
        } elseif ( $state === 'partial' ) {
            echo '<p><strong>' . esc_html__( 'Read the numbers with this in mind:', 'talenttrack' ) . '</strong> ' . esc_html( sprintf(
                /* translators: 1: activities with a register, 2: completed activities */
                __( 'the figures below are based on %1$d of %2$d completed activities. These have no attendance register:', 'talenttrack' ),
                $with,
                $completed
            ) ) . '</p>';
            echo '<ul class="tt-mr-coverage__list">';
            foreach ( $missing as $m ) {
                if ( ! is_array( $m ) ) continue;
                $url   = RecordLink::detailUrlForWithBack( 'activities', (int) ( $m['activity_id'] ?? 0 ) );
                $label = sprintf(
                    /* translators: 1: activity title, 2: activity date */
                    __( '%1$s, %2$s', 'talenttrack' ),
                    (string) ( $m['title'] ?? '' ),
                    TTDate::date( (string) ( $m['date'] ?? '' ) )
                );
                echo '<li>' . self::link( 'activities', $url, $label ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
            }
            echo '</ul>';
        } else {
            echo '<p>' . esc_html__( 'No completed activities with a register in this window.', 'talenttrack' ) . '</p>';
        }
        echo '</section>';
    }

    /** @param array<string,mixed> $k */
    private static function renderKpis( array $k ): void {
        echo '<div class="tt-report-kpis tt-mr-kpis">';

        $activities = self::measureOf( $k, 'activities' );
        echo FrontendAppChrome::kpiTile( self::tile( __( 'Activities', 'talenttrack' ), self::num( $activities['value'] ), $activities['delta'], '', 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kpiTile escapes.

        $att = self::measureOf( $k, 'attendance_pct' );
        echo FrontendAppChrome::kpiTile( self::tile( __( 'Attendance', 'talenttrack' ), self::pct( $att['value'] ), $att['delta'], _x( 'pts', 'percentage points', 'talenttrack' ), 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $min = self::measureOf( $k, 'minutes_share_median_pct' );
        echo FrontendAppChrome::kpiTile( self::tile( __( 'Median minutes share', 'talenttrack' ), self::pct( $min['value'] ), $min['delta'], _x( 'pts', 'percentage points', 'talenttrack' ), 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $eval  = is_array( $k['evaluated'] ?? null ) ? $k['evaluated'] : [];
        $of    = (int) ( $eval['of'] ?? 0 );
        $value = $of > 0 ? sprintf( '%d/%d', (int) ( $eval['value'] ?? 0 ), $of ) : '—';
        echo FrontendAppChrome::kpiTile( self::tile( __( 'Evaluated', 'talenttrack' ), $value, $eval['delta'] ?? null, _x( 'pts', 'percentage points', 'talenttrack' ), 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $rating = self::measureOf( $k, 'squad_rating' );
        echo FrontendAppChrome::kpiTile( self::tile( __( 'Squad rating', 'talenttrack' ), $rating['value'] !== null ? number_format_i18n( (float) $rating['value'], 1 ) : '—', $rating['delta'], '', 1 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        $attn = self::measureOf( $k, 'needs_attention' );
        $tile = self::tile( __( 'Need attention', 'talenttrack' ), self::num( $attn['value'] ), $attn['delta'], '', -1 );
        if ( (int) ( $attn['value'] ?? 0 ) > 0 ) $tile['flag'] = 'red';
        echo FrontendAppChrome::kpiTile( $tile ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

        echo '</div>';
    }

    /** @param array<string,mixed> $s */
    private static function renderStatus( array $s ): void {
        $counts   = is_array( $s['counts'] ?? null ) ? $s['counts'] : [];
        $previous = is_array( $s['previous'] ?? null ) ? $s['previous'] : null;
        $total    = array_sum( array_map( 'intval', $counts ) );

        self::sectionOpen( _x( 'Squad status', 'team monthly report section', 'talenttrack' ) );
        if ( $total > 0 ) {
            echo '<div class="tt-mr-band" role="img" aria-label="' . esc_attr( self::statusSentence( $counts ) ) . '">';
            foreach ( [ 'green', 'amber', 'red', 'unknown' ] as $color ) {
                $n = (int) ( $counts[ $color ] ?? 0 );
                if ( $n === 0 ) continue;
                $w = round( $n / $total * 100, 1 );
                echo '<span class="tt-mr-band__seg is-' . esc_attr( $color ) . '" style="width:' . esc_attr( (string) $w ) . '%;">' . (int) $n . '</span>'; /* tt-inline-ok */
            }
            echo '</div>';
        }
        echo '<p class="tt-mr-muted">' . esc_html( self::statusSentence( $counts ) . '.' );
        if ( $previous !== null ) {
            echo ' ' . esc_html( sprintf(
                /* translators: %s: last period's status split, e.g. "10 on track, 3 to watch, 1 needing action" */
                __( 'Last period: %s.', 'talenttrack' ),
                self::statusSentence( $previous )
            ) );
        }
        echo '</p>';
        self::sectionClose();
    }

    /**
     * @param array<string,mixed>                     $a
     * @param array{from:string,to:string,period:string} $window
     */
    private static function renderAttendance( array $a, int $team_id, array $window ): void {
        $rows = is_array( $a['rows'] ?? null ) ? $a['rows'] : [];
        $avg  = $a['team_avg_pct'] ?? null;

        // Gated where it is rendered: link() only emits the anchor when
        // CrossViewLink::allows() says the reader can open the target.
        $source = BackLink::appendTo( add_query_arg( [
            'tt_view' => 'attendance-report-team', /* tt-xview-ok */
            'team_id' => $team_id,
            'from'    => $window['from'],
            'to'      => $window['to'],
        ], RecordLink::dashboardUrl() ) );

        self::sectionOpen(
            _x( 'Attendance', 'team monthly report section', 'talenttrack' ),
            $avg !== null
                /* translators: %s: team average attendance percentage */
                ? sprintf( __( 'Team average %s. Lowest first.', 'talenttrack' ), self::pct( $avg ) )
                : __( 'No attendance recorded.', 'talenttrack' ),
            self::link( 'attendance-report-team', $source, __( 'Open the attendance report', 'talenttrack' ) )
        );
        if ( $rows !== [] ) {
            // Worst first, the order the query already returns.
            self::renderBars( $rows, 'present_pct', null );
        }
        self::sectionClose();
    }

    /**
     * @param array<string,mixed>                     $m
     * @param array{from:string,to:string,period:string} $window
     */
    private static function renderMinutes( array $m, int $team_id, array $window ): void {
        $rows   = is_array( $m['rows'] ?? null ) ? $m['rows'] : [];
        $target = (int) ( $m['target_pct'] ?? 50 );

        // Gated where it is rendered, as above.
        $source = BackLink::appendTo( add_query_arg( [
            'tt_view' => 'standard-report', /* tt-xview-ok */
            'slug'    => 'minutes-share',
            'team_id' => $team_id,
            'from'    => $window['from'],
            'to'      => $window['to'],
        ], RecordLink::dashboardUrl() ) );

        self::sectionOpen(
            _x( 'Minutes share', 'team monthly report section', 'talenttrack' ),
            sprintf(
                /* translators: 1: matches with minutes recorded, 2: matches played, 3: target percentage */
                __( 'Minutes recorded for %1$d of %2$d matches played. Target %3$d%%.', 'talenttrack' ),
                (int) ( $m['matches_recorded'] ?? 0 ),
                (int) ( $m['matches_played'] ?? 0 ),
                $target
            ),
            self::link( 'standard-report', $source, __( 'Open the minutes share report', 'talenttrack' ) )
        );
        if ( $rows === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No minutes recorded in this window.', 'talenttrack' ) . '</p>';
        } else {
            self::renderBars( $rows, 'share_pct', $target );
        }
        self::sectionClose();
    }

    /**
     * Ranked bars. Colour never carries the meaning alone: every row prints
     * its value, and a row below the line says so in words.
     *
     * @param array<mixed> $rows
     */
    private static function renderBars( array $rows, string $value_key, ?int $target ): void {
        echo '<div class="tt-table-wrap"><table class="tt-mr-bars">';
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) continue;
            $v    = $r[ $value_key ] ?? null;
            $pct  = $v !== null ? (float) $v : null;
            $band = (string) ( $r['band'] ?? '' );
            if ( $band === '' && $pct !== null && $target !== null ) {
                $band = $pct < $target ? 'amber' : 'green';
            }
            $url = RecordLink::detailUrlForWithBack( 'players', (int) ( $r['player_id'] ?? 0 ) );
            echo '<tr class="is-' . esc_attr( $band !== '' ? $band : 'none' ) . '">';
            echo '<th scope="row">' . self::link( 'players', $url, (string) ( $r['name'] ?? '' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
            echo '<td class="tt-mr-bars__track"><span class="tt-mr-track">';
            if ( $pct !== null ) {
                echo '<i style="width:' . (int) max( 0, min( 100, round( $pct ) ) ) . '%;"></i>'; /* tt-inline-ok */
            }
            if ( $target !== null ) {
                echo '<b style="left:' . (int) $target . '%;" aria-hidden="true"></b>'; /* tt-inline-ok */
            }
            echo '</span></td>';
            echo '<td class="num">' . esc_html( self::pct( $pct ) );
            if ( in_array( $band, [ 'amber', 'red' ], true ) ) {
                echo ' <span class="tt-mr-flag">' . esc_html( $band === 'red'
                    ? _x( 'low', 'a player well below the attendance or minutes line', 'talenttrack' )
                    : _x( 'watch', 'a player just below the attendance or minutes line', 'talenttrack' )
                ) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    }

    /** @param array<string,mixed> $a */
    private static function renderAttention( array $a ): void {
        $items = is_array( $a['items'] ?? null ) ? $a['items'] : [];

        self::sectionOpen(
            _x( 'Needs a conversation', 'team monthly report section', 'talenttrack' ),
            __( 'Players the status model flags, most urgent first.', 'talenttrack' )
        );
        if ( $items === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'Nobody is flagged this period.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-mr-attention">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $color = (string) ( $item['color'] ?? '' );
            $url   = RecordLink::detailUrlForWithBack( 'players', (int) ( $item['player_id'] ?? 0 ) );
            echo '<li class="tt-mr-attention__item is-' . esc_attr( $color ) . '">';
            echo '<p class="tt-mr-attention__name">' . self::link( 'players', $url, (string) ( $item['name'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
                . ' <span class="tt-mr-pill is-' . esc_attr( $color ) . '">' . esc_html( self::statusLabel( $color ) ) . '</span></p>';

            $facts   = [];
            $att_pct = $item['attendance_pct'] ?? null;
            if ( $att_pct !== null ) {
                /* translators: %s: attendance percentage */
                $facts[] = sprintf( __( 'Attendance %s', 'talenttrack' ), self::pct( (float) $att_pct ) );
            }
            foreach ( is_array( $item['reasons'] ?? null ) ? $item['reasons'] : [] as $reason ) {
                $facts[] = (string) $reason;
            }
            if ( $facts !== [] ) {
                echo '<p class="tt-mr-attention__facts">' . esc_html( implode( ' · ', $facts ) ) . '</p>';
            }
            $missing = is_array( $item['missing_inputs'] ?? null ) ? $item['missing_inputs'] : [];
            if ( $missing !== [] ) {
                $labels = array_map( static fn( $k ): string => \TT\Infrastructure\PlayerStatus\StatusVerdict::inputLabel( (string) $k ), $missing );
                echo '<p class="tt-mr-muted">' . esc_html( sprintf(
                    /* translators: %s: comma-separated list of missing inputs */
                    __( 'Computed without %s.', 'talenttrack' ),
                    implode( ', ', $labels )
                ) ) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $c */
    private static function renderChanges( array $c ): void {
        $events = is_array( $c['events'] ?? null ) ? $c['events'] : [];
        $open   = (int) ( $c['open_injuries'] ?? 0 );

        self::sectionOpen(
            _x( 'What changed', 'team monthly report section', 'talenttrack' ),
            sprintf(
                /* translators: %d: players with an open injury */
                _n( '%d player currently injured.', '%d players currently injured.', $open, 'talenttrack' ),
                $open
            )
        );
        if ( $events === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No injuries, moves or other changes recorded this period.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-mr-changes">';
            foreach ( $events as $e ) {
                if ( ! is_array( $e ) ) continue;
                $url = RecordLink::detailUrlForWithBack( 'players', (int) ( $e['player_id'] ?? 0 ) );
                echo '<li><span class="tt-mr-changes__date">' . esc_html( TTDate::date( (string) ( $e['date'] ?? '' ) ) ) . '</span> '
                    . self::link( 'players', $url, (string) ( $e['name'] ?? '' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
                    . ' — ' . esc_html( (string) ( $e['summary'] ?? '' ) ) . '</li>';
            }
            echo '</ul>';
        }
        self::sectionClose();
    }

    /** @param array<string,mixed> $t */
    private static function renderTests( array $t ): void {
        $rounds = is_array( $t['rounds'] ?? null ) ? $t['rounds'] : [];

        self::sectionOpen( _x( 'Tests', 'team monthly report section', 'talenttrack' ) );
        if ( $rounds === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No tests taken this period.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        foreach ( $rounds as $s ) {
            if ( ! is_array( $s ) ) continue;
            echo '<div class="tt-mr-test">';
            echo '<p class="tt-mr-test__name"><strong>' . esc_html( (string) ( $s['name'] ?? '' ) ) . '</strong> · '
                . esc_html( TTDate::date( (string) ( $s['date'] ?? '' ) ) ) . ' · '
                . esc_html( sprintf(
                    /* translators: 1: players tested, 2: squad size */
                    __( '%1$d of %2$d tested', 'talenttrack' ),
                    (int) ( $s['tested'] ?? 0 ),
                    (int) ( $s['squad'] ?? 0 )
                ) ) . '</p>';
            foreach ( [
                'improved' => __( 'Improved: %s', 'talenttrack' ),
                'declined' => __( 'Declined: %s', 'talenttrack' ),
            ] as $key => $template ) {
                $names = [];
                foreach ( is_array( $s[ $key ] ?? null ) ? $s[ $key ] : [] as $p ) {
                    if ( is_array( $p ) ) $names[] = (string) ( $p['name'] ?? '' );
                }
                if ( $names === [] ) continue;
                /* translators: %s: comma-separated player names */
                echo '<p class="tt-mr-muted">' . esc_html( sprintf( $template, implode( ', ', $names ) ) ) . '</p>';
            }
            echo '</div>';
        }
        self::sectionClose();
    }

    /** @param array<string,mixed> $r */
    private static function renderRoster( array $r ): void {
        $rows = is_array( $r['rows'] ?? null ) ? $r['rows'] : [];

        self::sectionOpen( _x( 'Player by player', 'team monthly report section', 'talenttrack' ) );
        echo '<div class="tt-table-wrap"><table class="tt-table tt-mr-roster">';
        echo '<thead><tr>'
            . '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html_x( 'Status', 'team monthly report column', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html__( 'Attendance', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html__( 'Minutes', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html_x( 'Share', 'minutes share column', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html__( 'Open goals', 'talenttrack' ) . '</th>'
            . '<th scope="col">' . esc_html_x( 'Injured', 'team monthly report column', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $status = (string) ( $row['status'] ?? 'unknown' );
            $url    = RecordLink::detailUrlForWithBack( 'players', (int) ( $row['player_id'] ?? 0 ) );
            $jersey = $row['jersey_number'] ?? null;
            $name   = (string) ( $row['name'] ?? '' );
            if ( $jersey !== null ) {
                /* translators: 1: jersey number, 2: player name */
                $name = sprintf( __( '#%1$d %2$s', 'talenttrack' ), (int) $jersey, $name );
            }
            $minutes = $row['minutes'] ?? null;
            echo '<tr>';
            echo '<th scope="row">' . self::link( 'players', $url, $name ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
            echo '<td><span class="tt-mr-pill is-' . esc_attr( $status ) . '">' . esc_html( self::statusLabel( $status ) ) . '</span></td>';
            echo '<td class="num">' . esc_html( self::pct( $row['attendance_pct'] ?? null ) ) . '</td>';
            echo '<td class="num">' . esc_html( $minutes !== null ? number_format_i18n( (int) $minutes ) : '—' ) . '</td>';
            echo '<td class="num">' . esc_html( self::pct( $row['share_pct'] ?? null ) ) . '</td>';
            echo '<td class="num">' . esc_html( number_format_i18n( (int) ( $row['open_goals'] ?? 0 ) ) ) . '</td>';
            echo '<td>' . esc_html( ! empty( $row['injured'] ) ? __( 'Yes', 'talenttrack' ) : '' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        self::sectionClose();
    }

    private static function renderNotes(): void {
        self::sectionOpen(
            _x( 'Decisions and actions', 'team monthly report section', 'talenttrack' ),
            __( 'Ruled lines on the printed copy, for what the meeting agrees.', 'talenttrack' )
        );
        echo '<div class="tt-mr-lines" aria-hidden="true">';
        for ( $i = 0; $i < 4; $i++ ) echo '<span></span>';
        echo '</div>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $q */
    private static function renderQuality( array $q ): void {
        $no_register   = is_array( $q['activities_without_register'] ?? null ) ? $q['activities_without_register'] : [];
        $no_minutes    = (int) ( $q['matches_without_minutes'] ?? 0 );
        $not_evaluated = is_array( $q['players_not_evaluated'] ?? null ) ? $q['players_not_evaluated'] : [];
        $incomplete    = is_array( $q['players_with_incomplete_status'] ?? null ) ? $q['players_with_incomplete_status'] : [];

        self::sectionOpen(
            _x( 'Data quality', 'team monthly report section', 'talenttrack' ),
            __( 'Fix these before next month and the report gets sharper.', 'talenttrack' )
        );
        $lines = [];
        if ( $no_register !== [] ) {
            /* translators: %d: activities without an attendance register */
            $lines[] = sprintf( _n( '%d completed activity has no attendance register.', '%d completed activities have no attendance register.', count( $no_register ), 'talenttrack' ), count( $no_register ) );
        }
        if ( $no_minutes > 0 ) {
            /* translators: %d: matches played without minutes recorded */
            $lines[] = sprintf( _n( '%d match played has no minutes recorded.', '%d matches played have no minutes recorded.', $no_minutes, 'talenttrack' ), $no_minutes );
        }
        if ( $not_evaluated !== [] ) {
            $names = array_map( static fn( $p ): string => is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '', $not_evaluated );
            /* translators: 1: number of players, 2: their names */
            $lines[] = sprintf( _n( '%1$d player has no evaluation this period: %2$s.', '%1$d players have no evaluation this period: %2$s.', count( $names ), 'talenttrack' ), count( $names ), implode( ', ', $names ) );
        }
        if ( $incomplete !== [] ) {
            /* translators: %d: players whose status was computed on incomplete evidence */
            $lines[] = sprintf( _n( '%d player\'s status was computed on incomplete evidence.', '%d players\' statuses were computed on incomplete evidence.', count( $incomplete ), 'talenttrack' ), count( $incomplete ) );
        }

        if ( $lines === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'Nothing missing. Every register, minute and evaluation this report looks for is in.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-mr-quality">';
            foreach ( $lines as $line ) echo '<li>' . esc_html( $line ) . '</li>';
            echo '</ul>';
        }
        self::sectionClose();
    }

    private static function renderConfidential(): void {
        echo '<p class="tt-mr-confidential">' . esc_html__( 'Confidential — staff only. This report names minors and describes their development. Do not share it with players, parents or anyone outside the coaching staff.', 'talenttrack' ) . '</p>';
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function sectionOpen( string $title, string $hint = '', string $action_html = '' ): void {
        echo '<section class="tt-rep-section tt-mr-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html( $title ) . '</h2>';
        if ( $hint !== '' ) echo '<span class="tt-rep-section__hint">' . esc_html( $hint ) . '</span>';
        if ( $action_html !== '' ) echo '<span class="tt-mr-section__source">' . $action_html . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by link().
        echo '</div>';
    }

    private static function sectionClose(): void {
        echo '</section>';
    }

    /**
     * A link where the reader may follow it, the plain text where they may
     * not — never a link to a door that will not open.
     */
    private static function link( string $slug, string $url, string $label ): string {
        if ( $url === '' || ! CrossViewLink::allows( $slug ) ) {
            return esc_html( $label );
        }
        return '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * @param array<string,mixed> $k
     * @return array{value:int|float|null, delta:int|float|null}
     */
    private static function measureOf( array $k, string $key ): array {
        $m = is_array( $k[ $key ] ?? null ) ? $k[ $key ] : [];
        $v = $m['value'] ?? null;
        $d = $m['delta'] ?? null;
        return [
            'value' => is_int( $v ) || is_float( $v ) ? $v : null,
            'delta' => is_int( $d ) || is_float( $d ) ? $d : null,
        ];
    }

    /**
     * A KPI tile. `$better` is +1 when a rise is good news, -1 when a rise is
     * bad news (more players needing attention), so the arrow's colour follows
     * the meaning rather than the sign.
     *
     * @param mixed $delta
     * @return array{label:string, value:string, delta:string, trend:string}
     */
    private static function tile( string $label, string $value, $delta, string $unit, int $better ): array {
        if ( ! is_int( $delta ) && ! is_float( $delta ) ) {
            // No preceding period: say so with a dash, never a zero.
            return [ 'label' => $label, 'value' => $value, 'delta' => '—', 'trend' => 'flat' ];
        }
        $sign  = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
        $text  = $sign . number_format_i18n( abs( (float) $delta ), is_float( $delta ) ? 1 : 0 );
        if ( $unit !== '' ) $text .= ' ' . $unit;
        $good  = $delta * $better;
        return [
            'label' => $label,
            'value' => $value,
            /* translators: %s: signed change against the previous period, e.g. "+3 pts" */
            'delta' => sprintf( __( '%s vs last period', 'talenttrack' ), $text ),
            'trend' => $good > 0 ? 'up' : ( $good < 0 ? 'down' : 'flat' ),
        ];
    }

    /** @param mixed $v */
    private static function num( $v ): string {
        return is_int( $v ) || is_float( $v ) ? number_format_i18n( (float) $v ) : '—';
    }

    /** @param mixed $v */
    private static function pct( $v ): string {
        if ( ! is_int( $v ) && ! is_float( $v ) ) return '—';
        return number_format_i18n( (float) $v, is_float( $v ) && floor( $v ) != $v ? 1 : 0 ) . '%';
    }

    private static function statusLabel( string $color ): string {
        switch ( $color ) {
            case 'green': return _x( 'On track', 'player status, staff report', 'talenttrack' );
            case 'amber': return _x( 'Watch', 'player status, staff report', 'talenttrack' );
            case 'red':   return _x( 'Needs action', 'player status, staff report', 'talenttrack' );
            default:      return _x( 'No read yet', 'player status, staff report', 'talenttrack' );
        }
    }

    /** @param array<mixed> $counts */
    private static function statusSentence( array $counts ): string {
        return sprintf(
            /* translators: 1: on track, 2: to watch, 3: needing action, 4: without a read yet */
            __( '%1$d on track, %2$d to watch, %3$d needing action, %4$d without a read yet', 'talenttrack' ),
            (int) ( $counts['green'] ?? 0 ),
            (int) ( $counts['amber'] ?? 0 ),
            (int) ( $counts['red'] ?? 0 ),
            (int) ( $counts['unknown'] ?? 0 )
        );
    }
}
