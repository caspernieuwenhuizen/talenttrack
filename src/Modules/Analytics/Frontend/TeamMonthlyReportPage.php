<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * TeamMonthlyReportPage (#3459, epic #3457) — the team monthly report on
 * screen, and the composition panel that decides what is in it.
 *
 * Chrome lives in `FrontendStandardReportsView` (picker, scope guard, window,
 * period bar, breadcrumbs), the same split the learning reports use; the panel
 * lives here. Nothing here computes a figure: every number comes from
 * `TeamMonthlyReport`, and the fit meter from `TeamMonthlyReportLayout`.
 *
 * ## What you see is what prints (#4097)
 *
 * The report body is not rendered here. `TeamMonthlyReportDocument` builds
 * the document the PDF prints, and this page shows that same markup: A4
 * sheets on a desktop, stacked cards with a "page N in the PDF" tag on a
 * phone. The fit meter reads the estimate the sheets were laid out from, so
 * the panel, the screen and the paper cannot disagree.
 *
 * ## The panel
 *
 * A plain GET form, so a composed report is a shareable URL
 * (`&layout=B&blocks=kpi,status,…`), works without script, and survives a
 * reload. Changes apply on the "Update report" button, never on a tick
 * (#3988). A small enqueued script writes `blocks` as one comma-separated
 * value and says when the panel has changes not applied yet; without script
 * the button submits `blk[]`, which is read too.
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

        $report   = ( new TeamMonthlyReport() )->forTeam( $team_id, $window['from'], $window['to'], $blocks, get_current_user_id(), $options );
        $prepared = TeamMonthlyReportDocument::prepare( $report, $layout );

        self::renderPanel( $team_id, $window, $layout, $report['blocks'], $prepared['fit'], $options );

        self::renderDocument(
            $prepared,
            $layout,
            (string) ( $team->name ?? '' ),
            [],
            self::sourceLinks( $team_id, $window ),
            self::pdfUrl( $team_id, $window, $layout, $report['blocks'], $options )
        );

        TeamMonthlyReportSnapshotPage::renderTakeAndList( $team_id, $window, $layout, $report['blocks'], $options );
    }

    /**
     * #4097 — the document the PDF prints, shown on screen. Shared with the
     * snapshot view (#3517), which renders its stored payload through the
     * same renderer, notes included.
     *
     * @param array{report:array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}, fit:array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>, groups:list<list<string>>, group_pages:list<int>}} $prepared
     *        from `TeamMonthlyReportDocument::prepare()`.
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     * @param (callable(string):string)|null $after_section screen-only markup under a section.
     */
    public static function renderDocument( array $prepared, string $layout, string $team_name, array $notes = [], ?callable $after_section = null, string $pdf_url = '' ): void {
        // The sheets wear the PDF's own stylesheet, scoped to the report and
        // applied from the desktop breakpoint: one source of the paper's
        // colours and sizes, not a copy of it in a static file.
        wp_add_inline_style( 'tt-frontend-team-monthly-report', TeamMonthlyReportDocument::screenCss( $layout ) );

        echo '<div class="' . esc_attr( self::wrapperClass() ) . '" data-tt-monthly-report>';
        echo TeamMonthlyReportDocument::screen( $prepared['report'], $layout, $team_name, $prepared['fit'], $notes, $after_section, $pdf_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the document escapes its own output.
        echo '</div>';
    }

    /**
     * The link from a section to the full report it summarises, under the
     * section on screen only. Gated: a reader who cannot open the report
     * gets no link to it.
     *
     * @param array{from:string,to:string,period:string} $window
     * @return callable(string):string
     */
    private static function sourceLinks( int $team_id, array $window ): callable {
        return static function ( string $block ) use ( $team_id, $window ): string {
            if ( $block === TeamMonthlyReportBlock::ATTENDANCE ) {
                $slug  = 'attendance-report-team';
                $label = __( 'Open the attendance report', 'talenttrack' );
                $args  = [
                    'tt_view' => 'attendance-report-team', /* tt-xview-ok */ // gated by CrossViewLink::allows() below
                    'team_id' => $team_id,
                    'from'    => $window['from'],
                    'to'      => $window['to'],
                ];
            } elseif ( $block === TeamMonthlyReportBlock::MINUTES ) {
                $slug  = 'standard-report';
                $label = __( 'Open the minutes share report', 'talenttrack' );
                $args  = [
                    'tt_view' => 'standard-report', /* tt-xview-ok */ // gated by CrossViewLink::allows() below
                    'slug'    => 'minutes-share',
                    'team_id' => $team_id,
                    'from'    => $window['from'],
                    'to'      => $window['to'],
                ];
            } else {
                return '';
            }
            if ( ! CrossViewLink::allows( $slug ) ) return '';

            $url = BackLink::appendTo( add_query_arg( $args, RecordLink::dashboardUrl() ) );
            return '<p class="tt-mr-doc__source"><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
        };
    }

    /** The report's own styles, for a surface that is not the live report. */
    public static function enqueuePublic(): void {
        self::enqueue();
    }

    /**
     * The report wrapper's classes. #4096 — a club colour too pale to read as
     * the section number on its own tint gets ink numbers instead, decided by
     * the same check the PDF makes.
     */
    public static function wrapperClass(): string {
        return 'tt-mr' . ( ReportBrandColour::readableOnTint( ReportBrandColour::primary() ) ? '' : ' tt-mr--ink-numbers' );
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
        // #4093 — the test standing chip is the player profile's, from its
        // own stylesheet, so the two cannot drift apart in colour.
        wp_enqueue_style(
            'tt-frontend-measurements',
            TT_PLUGIN_URL . 'assets/css/frontend-measurements.css',
            [ 'tt-frontend-mobile' ],
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
        // #3990 — the saved view the coach opened, so changing a section keeps
        // it on offer as "Update ‹name›".
        $hidden += \TT\Shared\Frontend\Components\SavedViews::openedField();

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

        echo '<form class="tt-mr-panel tt-mr-panel--split" method="get" action="' . esc_url( (string) $action ) . '" autocomplete="off" data-tt-mr-panel>';
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
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'The report below is the printed copy: the same sections, pages and detail as the PDF.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';

        // Blocks.
        echo '<fieldset class="tt-mr-panel__group tt-mr-panel__group--sections">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Sections', 'team monthly report panel', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-mr-blocks tt-mr-blocks--sections">';
        foreach ( TeamMonthlyReportBlock::ALL as $key ) {
            $id     = 'tt-mr-blk-' . $key;
            $locked = $key === TeamMonthlyReportBlock::LETTERHEAD;
            $on     = in_array( $key, $selected, true );
            $availability = TeamMonthlyReportLayout::availability( $layout, $key );
            if ( $availability === 'compressed' ) {
                $note = __( 'Narrower on the one-pager', 'talenttrack' );
            } elseif ( $availability === 'in_roster' ) {
                $note = __( 'Landscape prints it as columns of the player-by-player table', 'talenttrack' );
            } else {
                $note = $block_labels[ $key ]['note'];
            }

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

        // #4062 — the section options stack in a sidebar on desktop. The
        // wrapper keeps that stack explicit instead of leaving it to grid
        // auto-placement; below 1024px it simply follows Sections.
        echo '<div class="tt-mr-panel__side">';
        self::renderMatchesOptions( $selected, $options );
        self::renderTestsOptions( $team_id, $window, $layout, $selected, $options );
        echo '</div>';

        self::renderFitMeter( $fit );

        echo '<div class="tt-mr-panel__actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-mr-submit>' . esc_html__( 'Update report', 'talenttrack' ) . '</button>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( self::pdfUrl( $team_id, $window, $layout, $selected, $options ) ) . '" data-tt-mr-pdf>' . esc_html__( 'Download PDF', 'talenttrack' ) . '</a>';
        $schedule_url = self::scheduleUrl( $team_id, $layout, $selected, $options );
        if ( $schedule_url !== '' ) {
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $schedule_url ) . '" data-tt-mr-schedule>' . esc_html__( 'Schedule monthly', 'talenttrack' ) . '</a>';
        }
        self::renderPendingHint();
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
     * #3988 — beside "Update report": the panel has changes that are not
     * applied yet. Hidden until the panel script finds the form differs from
     * what was rendered, and hidden again once it matches. Shared with the
     * player report, whose panel runs the same script.
     */
    public static function renderPendingHint(): void {
        echo '<p class="tt-mr-pending" role="status" aria-live="polite" data-tt-mr-pending hidden>'
            . esc_html__( 'Not applied yet. The report below, the PDF and the snapshot still show the last applied selection.', 'talenttrack' )
            . '</p>';
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
        if ( $options !== [] ) $args['options'] = rawurlencode( (string) wp_json_encode( $options ) );

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
        if ( $options !== [] ) $args['options'] = rawurlencode( (string) wp_json_encode( $options ) );
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
            // #4092 — the pack shortens nothing: a page that runs over flows
            // onto another sheet, and this says so.
            $msg = $fit['max_pages'] === 1
                ? __( 'Does not fit on one page. Drop a section, or switch to the pack.', 'talenttrack' )
                : __( 'A page runs over onto another sheet. Nothing is left out; drop a section to keep the pack to four pages.', 'talenttrack' );
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
            'attendance' => [ 'title' => _x( 'Attendance', 'team monthly report section', 'talenttrack' ),           'note' => __( 'Per player, in shirt-number order', 'talenttrack' ) ],
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
    private static function renderTestsOptions( int $team_id, array $window, string $layout, array $selected, array $options ): void {
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
            echo '<label class="tt-mr-block tt-mr-block--test" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="opt_tests_def[]" value="' . esc_attr( (string) $def_id ) . '"'
                . checked( in_array( $def_id, $chosen, true ), true, false ) . ' data-tt-mr-block>';
            echo '<span class="tt-mr-block__t">' . esc_html( (string) $definition['name'] ) . '</span>';
            echo '<span class="tt-mr-block__n">' . esc_html( TTDate::date( (string) $definition['date'] ) ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Tick none to show every test taken this period.', 'talenttrack' ) . '</p>';
        echo '</div>';

        // #4095 — radios rather than a select, so an option the chosen
        // layout cannot print can stand disabled with its reason under it.
        // The panel script re-evaluates them when the layout changes; without
        // script the page does it on "Update report".
        $printed = TeamMonthlyReportLayout::testsShowFor( $layout, $show );
        echo '<div class="tt-mr-opts__row">';
        echo '<span class="tt-mr-opts__label" id="tt-mr-tests-show">' . esc_html__( 'How much to show', 'talenttrack' ) . '</span>';
        echo '<div class="tt-mr-blocks" role="radiogroup" aria-labelledby="tt-mr-tests-show">';
        foreach ( TestsBlockOptions::showLabels() as $value => $label ) {
            $id     = 'tt-mr-tests-show-' . $value;
            $reason = TeamMonthlyReportLayout::testsShowReason( $layout, $value );
            $whys   = '';
            foreach ( TeamMonthlyReportLayout::ALL as $key ) {
                $whys .= ' data-tt-mr-why-' . strtolower( $key ) . '="' . esc_attr( TeamMonthlyReportLayout::testsShowReason( $key, $value ) ) . '"';
            }
            echo '<label class="tt-mr-block tt-mr-block--opt' . ( $reason !== '' ? ' is-unavailable' : '' ) . '" for="' . esc_attr( $id ) . '" data-tt-mr-show-option' . $whys . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $whys is escaped above.
            echo '<input type="radio" id="' . esc_attr( $id ) . '" name="opt_tests_show" value="' . esc_attr( $value ) . '"'
                . checked( $printed, $value, false ) . disabled( $reason !== '', true, false ) . ' data-tt-mr-block>';
            echo '<span class="tt-mr-block__t">' . esc_html( $label ) . '</span>';
            echo '<span class="tt-mr-block__why" data-tt-mr-why>' . esc_html( $reason ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        if ( $printed !== $show ) {
            // A saved view, a shared link or a schedule asked for more than
            // this layout prints. It renders the summary, and says so here
            // rather than on the coach's printer.
            echo '<p class="tt-mr-panel__hint tt-mr-panel__hint--warn">' . esc_html( sprintf(
                /* translators: %s: the tests option that was asked for, e.g. "Readings" */
                __( 'This report asked for “%s”, which this layout cannot print. It prints the summary; choose the pack to print the tables.', 'talenttrack' ),
                TestsBlockOptions::showLabels()[ $show ] ?? $show
            ) ) . '</p>';
        }
        echo '</div>';

        // #4095 — the landscape strip holds three tests; say so before the
        // PDF does, and which ones fall off.
        $max   = TeamMonthlyReportLayout::MATRIX_MAX_TESTS;
        $count = $chosen !== [] ? count( $chosen ) : count( $available );
        if ( $count > $max ) {
            echo '<p class="tt-mr-panel__hint tt-mr-panel__hint--warn" data-tt-mr-when-layout="' . esc_attr( TeamMonthlyReportLayout::MATRIX ) . '"'
                . ( TeamMonthlyReportLayout::maxTests( $layout ) === null ? ' hidden' : '' ) . '>' . esc_html( sprintf(
                /* translators: 1: tests the landscape layout prints, 2: tests selected */
                __( 'Landscape prints up to %1$d tests; %2$d are selected. The PDF names the ones it leaves out.', 'talenttrack' ),
                $max,
                $count
            ) ) . '</p>';
        }

        echo '</fieldset>';
    }
}
