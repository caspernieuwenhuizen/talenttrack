<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
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
        $options = self::requestedOptions( $blocks );

        $report = ( new TeamMonthlyReport() )->forTeam( $team_id, $window['from'], $window['to'], $blocks, get_current_user_id(), $options );
        $fit    = TeamMonthlyReportLayout::fit( $report, $layout );
        $data   = $report['data'];

        self::renderPanel( $team_id, $window, $layout, $report['blocks'], $fit, $options );

        echo '<div class="tt-mr" data-tt-monthly-report>';
        self::renderBlocks( $report, $team, $window );
        echo '</div>';

        TeamMonthlyReportSnapshotPage::renderTakeAndList( $team_id, $window, $layout, $report['blocks'], $options );
    }

    /**
     * The report body: the letterhead, then every selected section in print
     * order, then the confidentiality line.
     *
     * Shared with the snapshot view (#3517), which renders the same blocks from
     * stored data rather than a live query — that is the whole point of a
     * snapshot, and two copies of this loop would be two places for the frozen
     * document to drift from the live one.
     *
     * @param array{blocks:list<string>, data:array<string,array<string,mixed>>, from:string, to:string} $report
     * @param array{from:string,to:string,period:string}                                                $window
     * @param array<string,array{body:string, author:int, updated_at:string}>                           $notes
     * @param string                                                                                    $snapshot uuid, '' on the live report
     */
    public static function renderBlocks( array $report, ?object $team, array $window, array $notes = [], string $snapshot = '' ): void {
        $data    = $report['data'];
        $team_id = (int) ( $team->id ?? 0 );
        $head    = $data['letterhead'] ?? [];

        self::renderLetterhead( $team, $head, $window );

        if ( (int) ( $head['activity_count'] ?? 0 ) === 0 ) {
            echo '<div class="tt-rep-section"><p class="tt-mr-empty">'
                . esc_html__( 'This team has no trainings or matches in this window, so there is nothing to report yet. Pick another period above.', 'talenttrack' )
                . '</p></div>';
            self::renderConfidential();
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
                case TeamMonthlyReportBlock::MATCHES:    self::renderMatches( $block_data ); break;
                case TeamMonthlyReportBlock::ATTENTION:  self::renderAttention( $block_data ); break;
                case TeamMonthlyReportBlock::CHANGES:    self::renderChanges( $block_data ); break;
                case TeamMonthlyReportBlock::TESTS:      self::renderTests( $block_data ); break;
                case TeamMonthlyReportBlock::ROSTER:     self::renderRoster( $block_data ); break;
                case TeamMonthlyReportBlock::NOTES:      self::renderNotes(); break;
                case TeamMonthlyReportBlock::QUALITY:    self::renderQuality( $block_data ); break;
            }

            // Notes exist only on a snapshot. The live report is a view of
            // current data, and commentary on a moving number has nothing to
            // attach to (#3517).
            if ( $snapshot !== '' ) {
                TeamMonthlyReportSnapshotPage::renderNote( $block, $notes, $snapshot );
            }
        }

        self::renderConfidential();
    }

    /** The report's own styles, for a surface that is not the live report. */
    public static function enqueuePublic(): void {
        self::enqueue();
    }

    /* ---------------------------------------------------------------
     * Request
     * ------------------------------------------------------------- */

    private static function requestedLayout(): string {
        $raw = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( (string) $_GET['layout'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        return TeamMonthlyReportComposition::normalise( [ 'layout' => $raw ] )['layout'];
    }

    /**
     * `blocks=a,b,c` from the script, a shared link or a saved view; `blk[]=a`
     * from the no-script submit. Unknown keys — a hand-edited URL, a view saved
     * before a section was renamed — are dropped rather than erroring the page:
     * the composer is strict, the screen forgiving.
     *
     * @return list<string>
     */
    private static function requestedBlocks(): array {
        $keys = [];
        if ( isset( $_GET['blocks'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $keys = sanitize_text_field( wp_unslash( (string) $_GET['blocks'] ) );
        } elseif ( isset( $_GET['blk'] ) && is_array( $_GET['blk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $keys = [];
            foreach ( wp_unslash( $_GET['blk'] ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in normalise().
                $keys[] = is_scalar( $k ) ? (string) $k : '';
            }
        }
        return TeamMonthlyReportComposition::normalise( [ 'blocks' => $keys ] )['blocks'];
    }

    /**
     * #3514 — `options={"tests":{...}}`, the per-block option bags.
     *
     * JSON because the bags nest and a query string does not. Forgiving like
     * the rest of the screen: malformed JSON, an unknown block or an option no
     * block recognises is dropped, and the report renders without it. The PDF
     * exporter refuses the same input instead — a filter that silently does
     * nothing produces a document nobody asked for, and that matters more on
     * something that gets printed and handed round a table.
     *
     * @param list<string> $blocks
     * @return array<string,array<string,mixed>>
     */
    private static function requestedOptions( array $blocks ): array {
        $raw = isset( $_GET['options'] ) ? wp_unslash( $_GET['options'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in normalise().
        $bags = is_string( $raw ) && $raw !== ''
            ? TeamMonthlyReportComposition::normalise( [ 'blocks' => $blocks, 'options' => $raw ] )['options']
            : [];

        // #3515 — the panel's own controls are ordinary form fields, because a
        // no-script submit has to work and a GET form cannot post JSON. They
        // win over the JSON bag: the JSON is what the page arrived with, the
        // fields are what the reader just asked for.
        $tests = self::requestedTestsOptions();
        if ( $tests !== null ) $bags[ TeamMonthlyReportBlock::TESTS ] = $tests;

        $matches = self::requestedMatchesOptions();
        if ( $matches !== null ) $bags[ TeamMonthlyReportBlock::MATCHES ] = $matches;

        if ( $tests !== null || $matches !== null ) {
            $bags = TeamMonthlyReportComposition::normalise( [
                'blocks'  => $blocks,
                'options' => $bags,
            ] )['options'];
        }

        return $bags;
    }

    /**
     * The match section's switches as the panel submitted them, or null when
     * the panel was not the source (#3516).
     *
     * A checkbox that is off is simply absent from the submit, so the marker
     * field is what tells "all three unticked" from "no form was submitted".
     *
     * @return array<string,mixed>|null
     */
    private static function requestedMatchesOptions(): ?array {
        if ( ! isset( $_GET['opt_matches'] ) ) return null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.

        $on = [];
        if ( isset( $_GET['opt_matches_show'] ) && is_array( $_GET['opt_matches_show'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            foreach ( wp_unslash( $_GET['opt_matches_show'] ) as $part ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized below.
                if ( is_scalar( $part ) ) $on[] = sanitize_key( (string) $part );
            }
        }

        $out = [];
        foreach ( array_keys( MatchesBlockOptions::DEFAULTS ) as $part ) {
            $out[ $part ] = in_array( $part, $on, true );
        }

        return $out;
    }

    /**
     * The tests section's options as the panel submitted them, or null when
     * the panel was not the source — a shared link, a saved view, a first
     * visit.
     *
     * The marker field is what tells those apart. Without it, an unticked
     * "every test" would be indistinguishable from "no form was submitted",
     * and clearing the selection would silently restore whatever the URL said.
     *
     * @return array<string,mixed>|null
     */
    private static function requestedTestsOptions(): ?array {
        if ( ! isset( $_GET['opt_tests'] ) ) return null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.

        $definitions = [];
        if ( isset( $_GET['opt_tests_def'] ) && is_array( $_GET['opt_tests_def'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            foreach ( wp_unslash( $_GET['opt_tests_def'] ) as $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- cast below.
                if ( is_scalar( $id ) ) $definitions[] = (int) $id;
            }
        }

        $show = isset( $_GET['opt_tests_show'] ) ? sanitize_key( wp_unslash( (string) $_GET['opt_tests_show'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.

        return [ 'definitions' => $definitions, 'show' => $show ];
    }

    /**
     * The composition parameters the period bar carries, so changing the
     * window keeps the layout and sections, and so a saved view captures them.
     * Only what is on the URL: an absent layout or section list is the default,
     * and saving it as absent keeps it following the default.
     *
     * @return array<string,string>
     */
    public static function barParams( int $team_id ): array {
        $params = [ 'team_id' => (string) $team_id ];
        if ( isset( $_GET['layout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $params['layout'] = self::requestedLayout();
        }
        if ( isset( $_GET['blocks'] ) || isset( $_GET['blk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $params['blocks'] = implode( ',', self::requestedBlocks() );
        }
        // #3514 — re-encoded from the normalised bags rather than passed
        // through, so a hand-edited URL cannot be carried into a saved view.
        if ( isset( $_GET['options'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $options = self::requestedOptions( self::requestedBlocks() );
            if ( $options !== [] ) $params['options'] = (string) wp_json_encode( $options );
        }
        return $params;
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
     * @param array<string,array<string,mixed>>                                               $options #3514 per-block options
     */
    private static function renderPanel( int $team_id, array $window, string $layout, array $selected, array $fit, array $options = [] ): void {
        $hidden = [
            'tt_view' => 'standard-report', /* tt-xview-ok */ // the form re-opens this same view
            'slug'    => self::SLUG,
            'team_id' => (string) $team_id,
        ];
        // #3514 — the panel's own submit must not drop options a URL carried.
        // Blocks whose options the panel offers controls for are left out:
        // their controls are the source, and carrying the JSON as well would
        // submit the old value alongside the new one (#3515).
        $carried = $options;
        unset( $carried[ TeamMonthlyReportBlock::TESTS ], $carried[ TeamMonthlyReportBlock::MATCHES ] );
        if ( $carried !== [] ) {
            $hidden['options'] = (string) wp_json_encode( $carried );
        }
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

        self::renderMatchesOptions( $selected, $options );
        self::renderTestsOptions( $team_id, $window, $selected, $options );

        self::renderFitMeter( $fit );

        echo '<div class="tt-mr-panel__actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-mr-submit>' . esc_html__( 'Update report', 'talenttrack' ) . '</button>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( self::pdfUrl( $team_id, $window, $layout, $selected, $options ) ) . '" data-tt-mr-pdf>' . esc_html__( 'Download PDF', 'talenttrack' ) . '</a>';
        $schedule_url = self::scheduleUrl( $team_id, $layout, $selected, $options );
        if ( $schedule_url !== '' ) {
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $schedule_url ) . '" data-tt-mr-schedule>' . esc_html__( 'Schedule monthly', 'talenttrack' ) . '</a>';
        }
        echo '</div>';

        self::renderPresetStatus( TeamMonthlyReportComposition::normalise( [
            'team_id' => $team_id,
            'period'  => $window['period'],
            'from'    => $window['period'] === '' ? $window['from'] : '',
            'to'      => $window['period'] === '' ? $window['to'] : '',
            'layout'  => $layout,
            'blocks'  => $selected,
            'options' => $options,
        ] ) );
        echo '</form>';
    }

    /**
     * Says which saved view the reader is looking at, or that they changed
     * their default — so saving a new view is an informed choice.
     *
     * @param array{team_id:int, period:string, from:string, to:string, layout:string, blocks:list<string>, options:array<string,array<string,mixed>>} $current
     */
    private static function renderPresetStatus( array $current ): void {
        if ( ! SavedViewsRegistry::currentUserCan( TeamMonthlyReportComposition::VIEW_KEY ) ) return;

        $status = TeamMonthlyReportComposition::savedViewStatus( get_current_user_id(), $current );
        if ( $status['state'] === 'active' ) {
            echo '<p class="tt-mr-preset is-active">' . esc_html( sprintf(
                /* translators: %s: name of the reader's saved view */
                __( 'Your saved view “%s”.', 'talenttrack' ),
                $status['name']
            ) ) . '</p>';
        } elseif ( $status['state'] === 'drifted' ) {
            echo '<p class="tt-mr-preset is-drifted">' . esc_html( sprintf(
                /* translators: %s: name of the reader's default saved view */
                __( 'Changed from your default view “%s”. To keep this version, save it as a new view from the bookmark above and make that your default.', 'talenttrack' ),
                $status['name']
            ) ) . '</p>';
        }
    }

    /**
     * #3462 — "Schedule monthly": the schedules screen, carrying a copy of
     * this composition. Empty when the reader cannot schedule reports (no
     * analytics authoring, the tier lacks scheduled reports, or the screen is
     * switched off), so the button is not offered to someone it would refuse.
     *
     * @param list<string>                      $selected
     * @param array<string,array<string,mixed>> $options #3514 — the schedule keeps its own copy, options included
     */
    public static function scheduleUrl( int $team_id, string $layout, array $selected, array $options = [] ): string {
        if ( ! current_user_can( 'tt_view_analytics' ) ) return '';
        // #3832 — the schedules screen is academy-wide and now refuses a
        // team-scoped analytics grant. Offering the button anyway would be
        // a link to a door that will not open, which this file's own
        // `link()` helper exists to avoid.
        if ( ! current_user_can( 'tt_edit_settings' )
            && ! \TT\Modules\Authorization\AllTeamsScope::canSeeClubWideAnalytics( get_current_user_id() )
        ) {
            return '';
        }
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' ) && ! \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' ) ) return '';
        if ( ! CrossViewLink::allows( 'scheduled-reports' ) ) return '';

        $args = [
            'tt_view' => 'scheduled-reports', /* tt-xview-ok */ // gated by CrossViewLink::allows() above
            'report'  => 'team_monthly',
            'team_id' => $team_id,
            'layout'  => $layout,
            'blocks'  => implode( ',', $selected ),
        ];
        if ( $options !== [] ) $args['options'] = (string) wp_json_encode( $options );

        return BackLink::appendTo( add_query_arg( $args, RecordLink::dashboardUrl() ) );
    }

    /**
     * The composition as it stands, printed: the same team, window, type and
     * sections, handed to the `team_monthly_report_pdf` exporter. The page
     * reloads on every panel change, so the link is never stale.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $selected
     * @param array<string,array<string,mixed>>          $options #3514 per-block options
     */
    public static function pdfUrl( int $team_id, array $window, string $layout, array $selected, array $options = [] ): string {
        $args = [
            'format'  => 'pdf',
            'team_id' => $team_id,
            'layout'  => $layout,
            'blocks'  => implode( ',', $selected ),
        ];
        if ( $options !== [] ) $args['options'] = (string) wp_json_encode( $options );
        if ( $window['period'] !== '' ) {
            $args['period'] = $window['period'];
        } else {
            $args['from'] = $window['from'];
            $args['to']   = $window['to'];
        }
        $args['_wpnonce'] = wp_create_nonce( 'wp_rest' );
        return add_query_arg( $args, rest_url( 'talenttrack/v1/exports/team_monthly_report_pdf' ) );
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
            'matches'    => [ 'title' => _x( 'Matches', 'team monthly report section', 'talenttrack' ),               'note' => __( 'Results, scorers and squads', 'talenttrack' ) ],
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
     * Nullable team: a snapshot outlives the team it was taken for, and the
     * meeting's record must still open after that team is archived away.
     *
     * @param array<string,mixed>                     $head
     * @param array{from:string,to:string,period:string} $window
     */
    private static function renderLetterhead( ?object $team, array $head, array $window ): void {
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
        /* translators: %d: trainings and matches on the team's calendar for the window */
        $bits[] = sprintf( _n( '%d activity', '%d activities', $acts, 'talenttrack' ), $acts );

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
        $state        = (string) ( $c['state'] ?? 'empty' );
        $completed    = (int) ( $c['completed'] ?? 0 );
        $with         = (int) ( $c['with_register'] ?? 0 );
        $missing      = is_array( $c['missing'] ?? null ) ? $c['missing'] : [];
        $never_closed = is_array( $c['never_closed'] ?? null ) ? $c['never_closed'] : [];

        // "Complete" is rendered, not left out: the absence of a warning has
        // to read as evidence, or a reader cannot tell "all recorded" from
        // "the check did not run".
        echo '<section class="tt-mr-coverage is-' . esc_attr( $state ) . '" aria-label="' . esc_attr_x( 'Data coverage', 'team monthly report section', 'talenttrack' ) . '">';
        if ( $state === 'empty' ) {
            echo '<p>' . esc_html__( 'No trainings or matches in this window.', 'talenttrack' ) . '</p>';
            echo '</section>';
            return;
        }

        if ( $missing !== [] ) {
            echo '<p><strong>' . esc_html__( 'Read the numbers with this in mind:', 'talenttrack' ) . '</strong> ' . esc_html( sprintf(
                /* translators: 1: activities with a register, 2: completed activities */
                __( 'the figures below are based on %1$d of %2$d completed activities. These have no attendance register:', 'talenttrack' ),
                $with,
                $completed
            ) ) . '</p>';
            self::renderCoverageList( $missing );
        } elseif ( $completed > 0 ) {
            echo '<p><strong>' . esc_html__( 'Complete.', 'talenttrack' ) . '</strong> ' . esc_html( sprintf(
                /* translators: %d: completed activities */
                _n( 'The one completed activity has an attendance register.', 'All %d completed activities have an attendance register.', $completed, 'talenttrack' ),
                $completed
            ) ) . '</p>';
        }

        // A session nobody closed is a different failure from one closed
        // without a register, and it sends the coach to a different screen.
        if ( $never_closed !== [] ) {
            echo '<p><strong>' . esc_html__( 'Never closed.', 'talenttrack' ) . '</strong> ' . esc_html( sprintf(
                /* translators: %d: activities whose date has passed and that were never marked completed */
                _n(
                    '%d activity in this window has passed and was never marked completed, so nothing it produced counts towards the figures below:',
                    '%d activities in this window have passed and were never marked completed, so nothing they produced counts towards the figures below:',
                    count( $never_closed ),
                    'talenttrack'
                ),
                count( $never_closed )
            ) ) . '</p>';
            self::renderCoverageList( $never_closed );
        }

        if ( $completed === 0 && $never_closed === [] ) {
            echo '<p>' . esc_html__( 'Nothing in this window has taken place yet.', 'talenttrack' ) . '</p>';
        }
        echo '</section>';
    }

    /**
     * The named activities under a coverage sentence, each a link where the
     * reader may open it.
     *
     * @param array<array-key,mixed> $rows
     */
    private static function renderCoverageList( array $rows ): void {
        echo '<ul class="tt-mr-coverage__list">';
        foreach ( $rows as $m ) {
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
        $show   = TestsBlockOptions::show( [ 'show' => $t['show'] ?? null ] );

        self::sectionOpen( _x( 'Tests', 'team monthly report section', 'talenttrack' ) );
        if ( $rounds === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No tests taken this period.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        foreach ( $rounds as $s ) {
            if ( ! is_array( $s ) ) continue;
            echo '<div class="tt-mr-test">';

            // A test that was asked for but not taken this window says so,
            // rather than quietly not appearing (#3515).
            if ( ! empty( $s['empty'] ) ) {
                echo '<p class="tt-mr-test__name"><strong>' . esc_html( (string) ( $s['name'] ?? '' ) ) . '</strong></p>';
                echo '<p class="tt-mr-muted">' . esc_html__( 'No readings this period.', 'talenttrack' ) . '</p>';
                echo '</div>';
                continue;
            }

            echo '<p class="tt-mr-test__name"><strong>' . esc_html( (string) ( $s['name'] ?? '' ) ) . '</strong> · '
                . esc_html( TTDate::date( (string) ( $s['date'] ?? '' ) ) ) . ' · '
                . esc_html( sprintf(
                    /* translators: 1: players tested, 2: squad size */
                    __( '%1$d of %2$d tested', 'talenttrack' ),
                    (int) ( $s['tested'] ?? 0 ),
                    (int) ( $s['squad'] ?? 0 )
                ) ) . '</p>';

            if ( TestsBlockOptions::showsPlayers( $show ) ) {
                self::renderTestReadings( $s, $show );
                echo '</div>';
                continue;
            }

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

    /**
     * The match section's own switches (#3516).
     *
     * Squads default off: it is the longest part and it overlaps the minutes
     * section, so a full report would otherwise print the same numbers twice.
     *
     * @param list<string>                     $selected
     * @param array<string,array<string,mixed>> $options
     */
    private static function renderMatchesOptions( array $selected, array $options ): void {
        if ( ! in_array( TeamMonthlyReportBlock::MATCHES, $selected, true ) ) return;

        $bag = $options[ TeamMonthlyReportBlock::MATCHES ] ?? [];

        echo '<fieldset class="tt-mr-panel__group tt-mr-opts">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Matches', 'team monthly report panel', 'talenttrack' ) . '</legend>';

        // Marker: tells "submitted with nothing ticked" from "not submitted".
        echo '<input type="hidden" name="opt_matches" value="1">';

        echo '<div class="tt-mr-blocks">';
        foreach ( MatchesBlockOptions::labels() as $part => $label ) {
            $id = 'tt-mr-matches-' . $part;
            echo '<label class="tt-mr-block" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="opt_matches_show[]" value="' . esc_attr( $part ) . '"'
                . checked( MatchesBlockOptions::shows( $bag, $part ), true, false ) . ' data-tt-mr-block>';
            echo '<span class="tt-mr-block__t">' . esc_html( $label ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Squads and minutes per match are the longest part, and repeat the minutes section.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';
    }

    /**
     * Results and match statistics (#3516).
     *
     * @param array<string,mixed> $m
     */
    private static function renderMatches( array $m ): void {
        $shows    = is_array( $m['shows'] ?? null ) ? $m['shows'] : [];
        $fixtures = is_array( $m['fixtures'] ?? null ) ? $m['fixtures'] : [];
        $record   = is_array( $m['record'] ?? null ) ? $m['record'] : [];

        self::sectionOpen( _x( 'Matches', 'team monthly report section', 'talenttrack' ) );

        if ( $fixtures === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No matches played this period.', 'talenttrack' ) . '</p>';
            self::renderTournamentNote( $m );
            self::sectionClose();
            return;
        }

        if ( ! empty( $shows['record'] ) ) {
            echo '<dl class="tt-mr-record">';
            foreach ( [
                'played'          => _x( 'Played', 'monthly report match record', 'talenttrack' ),
                'won'             => _x( 'Won', 'monthly report match record', 'talenttrack' ),
                'drawn'           => _x( 'Drawn', 'monthly report match record', 'talenttrack' ),
                'lost'            => _x( 'Lost', 'monthly report match record', 'talenttrack' ),
                'goals_for'       => _x( 'Goals for', 'monthly report match record', 'talenttrack' ),
                'goals_against'   => _x( 'Goals against', 'monthly report match record', 'talenttrack' ),
                'goal_difference' => _x( 'Difference', 'monthly report match record', 'talenttrack' ),
            ] as $key => $label ) {
                $value = (int) ( $record[ $key ] ?? 0 );
                echo '<div class="tt-mr-record__cell">';
                echo '<dt>' . esc_html( $label ) . '</dt>';
                echo '<dd>' . esc_html( $key === 'goal_difference' && $value > 0 ? '+' . $value : (string) $value ) . '</dd>';
                echo '</div>';
            }
            echo '</dl>';

            $gaps = (int) ( $record['without_score'] ?? 0 );
            if ( $gaps > 0 ) {
                echo '<p class="tt-mr-muted">' . esc_html( sprintf(
                    /* translators: %d: number of matches with no score recorded */
                    _n(
                        '%d match has no score recorded and is not counted in the record.',
                        '%d matches have no score recorded and are not counted in the record.',
                        $gaps,
                        'talenttrack'
                    ),
                    $gaps
                ) ) . '</p>';
            }
        }

        if ( ! empty( $shows['scorers'] ) ) {
            self::renderScorers( is_array( $m['scorers'] ?? null ) ? $m['scorers'] : [] );
        }

        foreach ( $fixtures as $fixture ) {
            if ( is_array( $fixture ) ) self::renderFixture( $fixture, ! empty( $shows['squads'] ) );
        }

        self::renderTournamentNote( $m );
        self::sectionClose();
    }

    /**
     * Tournaments are left out of the record on purpose — a tournament is a
     * multi-game day (#2686) and one score line cannot describe one. Saying so
     * beats a record that quietly disagrees with what the coach remembers.
     *
     * @param array<string,mixed> $m
     */
    private static function renderTournamentNote( array $m ): void {
        $count = (int) ( $m['tournaments_excluded'] ?? 0 );
        if ( $count <= 0 ) return;

        echo '<p class="tt-mr-muted">' . esc_html( sprintf(
            /* translators: %d: number of tournaments in the period */
            _n(
                '%d tournament this period is not included — a tournament is a multi-game day.',
                '%d tournaments this period are not included — a tournament is a multi-game day.',
                $count,
                'talenttrack'
            ),
            $count
        ) ) . '</p>';
    }

    /** @param list<array<string,mixed>>|array<int,mixed> $scorers */
    private static function renderScorers( array $scorers ): void {
        if ( $scorers === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No goals or assists recorded this period.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<div class="tt-table-wrap"><table class="tt-table tt-mr-scorers">';
        echo '<thead><tr>'
            . '<th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html_x( 'Goals', 'monthly report matches column', 'talenttrack' ) . '</th>'
            . '<th scope="col" class="num">' . esc_html_x( 'Assists', 'monthly report matches column', 'talenttrack' ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $scorers as $row ) {
            if ( ! is_array( $row ) ) continue;
            $url = RecordLink::detailUrlForWithBack( 'players', (int) ( $row['player_id'] ?? 0 ) );
            echo '<tr>';
            echo '<th scope="row">' . self::link( 'players', $url, (string) ( $row['name'] ?? '' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
            echo '<td class="num">' . esc_html( number_format_i18n( (int) ( $row['goals'] ?? 0 ) ) ) . '</td>';
            echo '<td class="num">' . esc_html( number_format_i18n( (int) ( $row['assists'] ?? 0 ) ) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * One match: date, opponent, home or away and score, with the squad under
     * it when asked for. The heading carries the result, which is why the
     * section has no separate results list to repeat it.
     *
     * @param array<string,mixed> $fixture
     */
    private static function renderFixture( array $fixture, bool $with_squad ): void {
        $outcome = (string) ( $fixture['outcome'] ?? '' );

        echo '<div class="tt-mr-fixture">';
        echo '<p class="tt-mr-fixture__head">';
        echo '<span class="tt-mr-fixture__date">' . esc_html( TTDate::date( (string) ( $fixture['date'] ?? '' ) ) ) . '</span> ';
        echo '<strong>' . esc_html( self::fixtureOpponent( $fixture ) ) . '</strong> ';
        if ( $fixture['team_score'] === null || $fixture['opp_score'] === null ) {
            echo '<span class="tt-mr-muted">' . esc_html__( 'no score recorded', 'talenttrack' ) . '</span>';
        } else {
            echo '<span class="tt-mr-score is-' . esc_attr( $outcome !== '' ? strtolower( $outcome ) : 'none' ) . '">'
                . esc_html( (int) $fixture['team_score'] . '–' . (int) $fixture['opp_score'] ) . '</span>';
        }
        echo '</p>';

        $squad = is_array( $fixture['squad'] ?? null ) ? $fixture['squad'] : [];
        if ( $with_squad && $squad !== [] ) {
            $parts = [];
            foreach ( $squad as $player ) {
                if ( ! is_array( $player ) ) continue;
                $parts[] = sprintf(
                    /* translators: 1: player name, 2: minutes played */
                    _x( '%1$s %2$d′', 'player and minutes in a match squad', 'talenttrack' ),
                    (string) ( $player['name'] ?? '' ),
                    (int) ( $player['minutes'] ?? 0 )
                );
            }
            echo '<p class="tt-mr-muted">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
        } elseif ( $with_squad ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No minutes recorded for this match.', 'talenttrack' ) . '</p>';
        }
        echo '</div>';
    }

    /** @param array<string,mixed> $fixture */
    private static function fixtureOpponent( array $fixture ): string {
        $who = (string) ( $fixture['opponent'] ?? '' );
        if ( $who === '' ) $who = __( 'Unknown opponent', 'talenttrack' );

        $where = (string) ( $fixture['home_away'] ?? '' );
        if ( $where === 'away' ) {
            /* translators: %s: opponent name */
            return sprintf( __( 'away to %s', 'talenttrack' ), $who );
        }
        if ( $where !== '' ) {
            /* translators: %s: opponent name */
            return sprintf( __( 'home to %s', 'talenttrack' ), $who );
        }

        // No home/away recorded: show the opponent without guessing which way
        // round the score goes.
        return $who;
    }

    /**
     * The tests section's own controls: which tests, and how much of each
     * (#3515).
     *
     * Only rendered when the section is selected — options for a section that
     * is switched off are controls for something the reader cannot see. Only
     * tests with a session in the window are offered, because offering one
     * without readings is offering an empty section.
     *
     * @param array{from:string, to:string, period:string} $window
     * @param list<string>                                 $selected
     * @param array<string,array<string,mixed>>            $options
     */
    private static function renderTestsOptions( int $team_id, array $window, array $selected, array $options ): void {
        if ( ! in_array( TeamMonthlyReportBlock::TESTS, $selected, true ) ) return;

        $available = TeamMonthlyReport::testableDefinitions( $team_id, $window['from'], $window['to'] );
        if ( $available === [] ) return;

        $bag    = $options[ TeamMonthlyReportBlock::TESTS ] ?? [];
        $chosen = TestsBlockOptions::definitionIds( $bag );
        $show   = TestsBlockOptions::show( $bag );

        echo '<fieldset class="tt-mr-panel__group tt-mr-opts">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Tests', 'team monthly report panel', 'talenttrack' ) . '</legend>';

        // Marker: tells "submitted with nothing ticked" from "not submitted".
        echo '<input type="hidden" name="opt_tests" value="1">';

        echo '<div class="tt-mr-opts__row">';
        echo '<span class="tt-mr-opts__label" id="tt-mr-tests-which">' . esc_html__( 'Which tests', 'talenttrack' ) . '</span>';
        echo '<div class="tt-mr-blocks" role="group" aria-labelledby="tt-mr-tests-which">';
        foreach ( $available as $definition ) {
            $def_id = (int) $definition['definition_id'];
            $id     = 'tt-mr-test-' . $def_id;
            echo '<label class="tt-mr-block" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="opt_tests_def[]" value="' . esc_attr( (string) $def_id ) . '"'
                . checked( in_array( $def_id, $chosen, true ), true, false ) . ' data-tt-mr-block>';
            echo '<span class="tt-mr-block__t">' . esc_html( (string) $definition['name'] ) . '</span>';
            echo '<span class="tt-mr-block__n">' . esc_html( TTDate::date( (string) $definition['date'] ) ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Tick none to show every test taken this period.', 'talenttrack' ) . '</p>';
        echo '</div>';

        $select_id = 'tt-mr-tests-show';
        echo '<div class="tt-mr-opts__row">';
        echo '<label class="tt-mr-opts__label" for="' . esc_attr( $select_id ) . '">' . esc_html__( 'How much to show', 'talenttrack' ) . '</label>';
        echo '<select class="tt-input" id="' . esc_attr( $select_id ) . '" name="opt_tests_show" data-tt-mr-block>';
        foreach ( TestsBlockOptions::showLabels() as $value => $label ) {
            echo '<option value="' . esc_attr( $value ) . '"' . selected( $show, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Readings and change print as a table, which the one-pager shortens to the summary.', 'talenttrack' ) . '</p>';
        echo '</div>';

        echo '</fieldset>';
    }

    /**
     * One test's readings per player, in shirt order (#3515).
     *
     * @param array<string,mixed> $round
     */
    private static function renderTestReadings( array $round, string $show ): void {
        $rows = is_array( $round['readings'] ?? null ) ? $round['readings'] : [];
        if ( $rows === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'No readings this period.', 'talenttrack' ) . '</p>';
            return;
        }

        $unit   = (string) ( $round['unit'] ?? '' );
        $values = TestsBlockOptions::showsValues( $show );
        $trend  = TestsBlockOptions::showsTrend( $show );

        echo '<div class="tt-table-wrap"><table class="tt-table tt-mr-test-readings">';
        echo '<thead><tr><th scope="col">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        if ( $values ) {
            echo '<th scope="col" class="num">' . esc_html(
                $unit !== ''
                    /* translators: %s: unit of measurement, e.g. "s" or "cm" */
                    ? sprintf( _x( 'Result (%s)', 'monthly report tests column', 'talenttrack' ), $unit )
                    : _x( 'Result', 'monthly report tests column', 'talenttrack' )
            ) . '</th>';
        }
        if ( $trend ) {
            echo '<th scope="col" class="num">' . esc_html_x( 'Change', 'monthly report tests column', 'talenttrack' ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $url = RecordLink::detailUrlForWithBack( 'players', (int) ( $row['player_id'] ?? 0 ) );
            echo '<tr>';
            echo '<th scope="row">' . self::link( 'players', $url, (string) ( $row['name'] ?? '' ) ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
            if ( $values ) {
                $value = $row['value'] ?? null;
                echo '<td class="num">' . esc_html( is_scalar( $value ) ? (string) $value : '—' ) . '</td>';
            }
            if ( $trend ) {
                echo '<td class="num ' . esc_attr( 'is-' . ( (string) ( $row['trend'] ?? '' ) !== '' ? (string) $row['trend'] : 'flat' ) ) . '">'
                    . esc_html( self::testDelta( $row ) ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * A reading's change since the player's previous one.
     *
     * A first reading has nothing to compare with, which is not the same as no
     * change — it gets a dash, like every other "no comparison" in this report.
     *
     * @param array<string,mixed> $row
     */
    private static function testDelta( array $row ): string {
        if ( ! empty( $row['first'] ) ) return '—';

        $delta = (float) ( $row['delta'] ?? 0 );
        if ( abs( $delta ) < 0.0001 ) return '0';

        $formatted = number_format_i18n( abs( $delta ), abs( $delta ) < 10 ? 2 : 1 );

        return ( $delta > 0 ? '+' : '−' ) . $formatted;
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
        $never_closed  = is_array( $q['activities_never_closed'] ?? null ) ? $q['activities_never_closed'] : [];
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
        if ( $never_closed !== [] ) {
            /* translators: %d: activities whose date has passed and that were never marked completed */
            $lines[] = sprintf( _n( '%d activity has passed without being marked completed.', '%d activities have passed without being marked completed.', count( $never_closed ), 'talenttrack' ), count( $never_closed ) );
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
