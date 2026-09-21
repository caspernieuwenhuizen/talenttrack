<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\StatusVerdict;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Shared\Dates\TTDate;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\EvidencePanel;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PlayerReportPage (#3873, epic #3871) — the player report on screen, and the
 * panel that decides what is in it.
 *
 * Chrome lives in `FrontendStandardReportsView` (picker, access guard, window,
 * period bar, breadcrumbs), as it does for the team monthly report. Nothing
 * here computes a figure: every number is `PlayerReport`'s, which reads the
 * evidence packet the PDP screens read.
 *
 * It borrows two shipped pieces rather than growing its own: the team monthly
 * report's document shell and panel script (the same `tt-mr-*` contract, so a
 * tick re-renders and the URL stays the report), and the evidence panel's
 * tables, stats and cards, so a player's evidence looks the same here as on
 * the PDP Evidence tab.
 *
 * Wizard plan: exemption — live-preview surface, not a multi-step flow.
 */
final class PlayerReportPage {

    public const SLUG = 'player-report';

    /**
     * @param object                                     $player tt_players row.
     * @param array{from:string,to:string,period:string} $window the resolved window.
     */
    public static function render( object $player, array $window ): void {
        self::enqueue();

        $player_id = (int) ( $player->id ?? 0 );
        $blocks    = self::requestedBlocks();
        $layout    = self::requestedLayout();

        $report = ( new PlayerReport() )->forPlayer( $player_id, $window['from'], $window['to'], $blocks, get_current_user_id() );
        if ( $report === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderPanel( $player_id, $window, $layout, $report['blocks'], PlayerReportLayout::fit( $report, $layout ), $report['audience'] );

        echo '<div class="tt-mr tt-pr" data-tt-player-report>';
        self::renderBlocks( $report, $window );
        echo '</div>';

        PlayerReportSnapshotPage::renderTakeAndList( $player_id, $window, $layout, $report['blocks'] );
    }

    /** The report's styles, for a surface that is not the live report (a snapshot). */
    public static function enqueuePublic(): void {
        self::enqueue();
    }

    /**
     * The report body: the letterhead, then every selected section in print
     * order, then the confidentiality line.
     *
     * Shared with the snapshot view (#3890), which renders the same blocks from
     * stored data and puts each section's note under it — one loop, so the
     * frozen document cannot drift from the live one.
     *
     * @param array{player_id:int, blocks:list<string>, data:array<string,array<string,mixed>>} $report
     * @param array{from:string,to:string,period:string}                                       $window
     * @param array<string,array{body:string, author:int, updated_at:string}>                  $notes
     * @param string                                                                           $snapshot uuid, '' on the live report
     */
    public static function renderBlocks( array $report, array $window, array $notes = [], string $snapshot = '' ): void {
        $data = $report['data'];

        self::renderLetterhead( $data['letterhead'] ?? [], $window );

        foreach ( $report['blocks'] as $block ) {
            if ( $block === PlayerReportBlock::LETTERHEAD ) continue;
            $d = $data[ $block ] ?? [];
            switch ( $block ) {
                case PlayerReportBlock::STATUS:         self::renderStatus( $d ); break;
                case PlayerReportBlock::TALKING_POINTS: self::renderTalkingPoints( $d ); break;
                case PlayerReportBlock::RATINGS:        self::renderRatings( $d ); break;
                case PlayerReportBlock::ATTENDANCE:     self::renderAttendance( $d ); break;
                case PlayerReportBlock::MINUTES:        self::renderMinutes( $d ); break;
                case PlayerReportBlock::GOALS:          self::renderGoals( $d ); break;
                case PlayerReportBlock::PDP:            self::renderPdp( $d ); break;
                case PlayerReportBlock::NOTES:          self::renderNotes(); break;
                case PlayerReportBlock::MATCHES:        self::renderMatches( $d ); break;
                case PlayerReportBlock::TESTS:          self::renderTests( $d ); break;
                case PlayerReportBlock::JOURNEY:        self::renderJourney( $d ); break;
                case PlayerReportBlock::INJURIES:       self::renderInjuries( $d ); break;
                case PlayerReportBlock::BEHAVIOUR:      self::renderBehaviour( $d ); break;
                case PlayerReportBlock::POTENTIAL:      self::renderPotential( $d ); break;
                case PlayerReportBlock::THREAD_NOTES:   self::renderThreadNotes( $d ); break;
            }

            // Notes exist only on a snapshot: commentary on a moving number
            // has nothing to attach to.
            if ( $snapshot !== '' ) {
                PlayerReportSnapshotPage::renderNote( $block, $notes, $snapshot );
            }
        }

        echo '<p class="tt-mr-confidential">' . esc_html__( 'Confidential — staff only. This report describes a minor\'s development. Do not share it with the player, their parents or anyone outside the coaching staff.', 'talenttrack' ) . '</p>';
    }

    /* ---------------------------------------------------------------
     * Request
     * ------------------------------------------------------------- */

    /**
     * `blocks=a,b,c` from the script, a shared link or a saved view; `blk[]=a`
     * from the no-script submit. Unknown keys are dropped rather than erroring
     * the page — the composer is strict, the screen forgiving.
     *
     * @return list<string>
     */
    private static function requestedBlocks(): array {
        $keys = [];
        if ( isset( $_GET['blocks'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $keys = sanitize_text_field( wp_unslash( (string) $_GET['blocks'] ) );
        } elseif ( isset( $_GET['blk'] ) && is_array( $_GET['blk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            foreach ( wp_unslash( $_GET['blk'] ) as $k ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized in normalise().
                $keys[] = is_scalar( $k ) ? (string) $k : '';
            }
        }
        return PlayerReportComposition::normalise( [ 'blocks' => $keys ] )['blocks'];
    }

    private static function requestedLayout(): string {
        $raw = isset( $_GET['layout'] ) ? sanitize_key( wp_unslash( (string) $_GET['layout'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
        return PlayerReportComposition::normalise( [ 'layout' => $raw ] )['layout'];
    }

    /**
     * The composition parameters the period bar carries, so changing the
     * window keeps the layout and sections, and a saved view captures them.
     * Only what is on the URL: an absent value is the default, and saving it
     * as absent keeps it following the default.
     *
     * @return array<string,string>
     */
    public static function barParams(): array {
        $params = [];
        if ( isset( $_GET['layout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $params['layout'] = self::requestedLayout();
        }
        if ( isset( $_GET['blocks'] ) || isset( $_GET['blk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $params['blocks'] = implode( ',', self::requestedBlocks() );
        }
        return $params;
    }

    private static function enqueue(): void {
        TeamMonthlyReportPage::enqueuePublic();
        EvidencePanel::enqueue();
        // The level swatches a status test's score is painted with.
        wp_enqueue_style(
            'tt-frontend-measurement-levels',
            TT_PLUGIN_URL . 'assets/css/frontend-measurement-levels.css',
            [],
            TT_VERSION
        );
        wp_enqueue_style(
            'tt-frontend-player-report',
            TT_PLUGIN_URL . 'assets/css/frontend-player-report.css',
            [ 'tt-frontend-team-monthly-report', 'tt-frontend-pdp-evidence' ],
            TT_VERSION
        );
    }

    /* ---------------------------------------------------------------
     * Composition panel
     * ------------------------------------------------------------- */

    /**
     * @param array{from:string,to:string,period:string}                                          $window
     * @param list<string>                                                                        $selected
     * @param array{pages:int, max_pages:int, fits:bool, fill:list<int>, degraded:list<string>} $fit
     * @param string                                                                              $audience the reader's; a scout is offered only what a scout may receive
     */
    private static function renderPanel( int $player_id, array $window, string $layout, array $selected, array $fit, string $audience ): void {
        $hidden = [
            'tt_view'   => 'standard-report', /* tt-xview-ok */ // the form re-opens this same view
            'slug'      => self::SLUG,
            'player_id' => (string) $player_id,
        ];
        if ( $window['period'] !== '' ) {
            $hidden['period'] = $window['period'];
        } elseif ( isset( $_GET['from'] ) || isset( $_GET['to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            // A manual window is carried; the default is not, so the report
            // keeps meaning "this season so far" rather than today's dates.
            $hidden['from'] = $window['from'];
            $hidden['to']   = $window['to'];
        }
        if ( ! empty( $_GET['tt_back'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view state.
            $hidden['tt_back'] = sanitize_text_field( wp_unslash( (string) $_GET['tt_back'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        // A GET form replaces the query string of its action, so a dashboard
        // reached as `?page_id=58` would lose that on a no-script submit.
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

        $labels = self::blockLabels();

        echo '<form class="tt-mr-panel" method="get" action="' . esc_url( (string) $action ) . '" data-tt-mr-panel>';
        foreach ( $hidden as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
        }

        // Report type — radios styled as cards, as on the team report: native
        // keyboard behaviour, no ARIA re-implementation.
        echo '<fieldset class="tt-mr-panel__group">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Printed copy', 'player report panel', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-mr-types">';
        foreach ( PlayerReportLayout::labels() as $key => $label ) {
            $id = 'tt-pr-layout-' . strtolower( $key );
            echo '<label class="tt-mr-type" for="' . esc_attr( $id ) . '">';
            echo '<input type="radio" id="' . esc_attr( $id ) . '" name="layout" value="' . esc_attr( $key ) . '"' . checked( $layout, $key, false ) . '>';
            echo '<span class="tt-mr-type__t">' . esc_html( $label['title'] ) . '</span>';
            echo '<span class="tt-mr-type__d">' . esc_html( $label['desc'] ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'The layout shapes the printed copy. On screen, every selected section is shown in full.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';

        echo '<fieldset class="tt-mr-panel__group">';
        echo '<legend class="tt-mr-panel__legend">' . esc_html_x( 'Sections', 'player report panel', 'talenttrack' ) . '</legend>';
        echo '<div class="tt-mr-blocks">';
        $offered = $audience === \TT\Modules\Analytics\Reports\PlayerReportAudience::SCOUT
            ? \TT\Modules\Analytics\Reports\PlayerReportAudience::SCOUT_BLOCKS
            : PlayerReportBlock::ALL;
        foreach ( $offered as $key ) {
            $id     = 'tt-pr-blk-' . $key;
            $locked = $key === PlayerReportBlock::LETTERHEAD;
            $on     = in_array( $key, $selected, true );

            echo '<label class="tt-mr-block' . ( $locked ? ' is-locked' : '' ) . '" for="' . esc_attr( $id ) . '">';
            echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="blk[]" value="' . esc_attr( $key ) . '"'
                . checked( $on || $locked, true, false )
                . disabled( $locked, true, false ) . ' data-tt-mr-block>';
            if ( $locked ) {
                // A disabled checkbox is not submitted; the letterhead is added
                // by the composer anyway, this only keeps the URL honest.
                echo '<input type="hidden" name="blk[]" value="' . esc_attr( $key ) . '">';
            }
            echo '<span class="tt-mr-block__t">' . esc_html( $labels[ $key ]['title'] ) . '</span>';
            echo '<span class="tt-mr-block__n">' . esc_html( $labels[ $key ]['note'] ) . '</span>';
            echo '</label>';
        }
        echo '</div>';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'The report opens on the sections a one-to-one conversation needs. Tick more when the conversation needs them.', 'talenttrack' ) . '</p>';
        echo '</fieldset>';

        self::renderFitMeter( $fit );

        echo '<div class="tt-mr-panel__actions">';
        echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-mr-submit>' . esc_html__( 'Update report', 'talenttrack' ) . '</button>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( self::pdfUrl( $player_id, $window, $layout, $selected ) ) . '" data-tt-mr-pdf>' . esc_html__( 'Download PDF', 'talenttrack' ) . '</a>';
        $schedule_url = self::scheduleUrl( $player_id, $window, $layout, $selected );
        if ( $schedule_url !== '' ) {
            echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $schedule_url ) . '" data-tt-mr-schedule>' . esc_html__( 'Schedule monthly', 'talenttrack' ) . '</a>';
        }
        echo '</div>';
        echo '</form>';
    }

    /**
     * #3891 — "Schedule monthly": the schedules screen, carrying a copy of this
     * composition. Empty when the reader cannot schedule reports — the
     * schedules screen is academy-wide, needs the plan's scheduled reports and
     * may be switched off — so the button is never a door that will not open.
     * The team report's rule, `TeamMonthlyReportPage::scheduleUrl()`.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $selected
     */
    public static function scheduleUrl( int $player_id, array $window, string $layout, array $selected ): string {
        if ( ! current_user_can( 'tt_view_analytics' ) ) return '';
        if ( ! current_user_can( 'tt_edit_settings' )
            && ! \TT\Modules\Authorization\AllTeamsScope::canSeeClubWideAnalytics( get_current_user_id() )
        ) {
            return '';
        }
        if ( class_exists( '\\TT\\Modules\\License\\LicenseGate' ) && ! \TT\Modules\License\LicenseGate::allows( 'scheduled_reports' ) ) return '';
        if ( ! CrossViewLink::allows( 'scheduled-reports' ) ) return '';

        $args = [
            'tt_view'   => 'scheduled-reports', /* tt-xview-ok */ // gated by CrossViewLink::allows() above
            'report'    => 'player_report',
            'player_id' => $player_id,
            'layout'    => $layout,
            'blocks'    => implode( ',', $selected ),
            'period'    => $window['period'],
        ];

        return \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg( $args, RecordLink::dashboardUrl() ) );
    }

    /**
     * The composition as it stands, printed: the same player, window, layout
     * and sections, handed to the `player_report_pdf` exporter. The page
     * reloads on every panel change, so the link is never stale.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $selected
     */
    public static function pdfUrl( int $player_id, array $window, string $layout, array $selected ): string {
        $args = [
            'format'    => 'pdf',
            'player_id' => $player_id,
            'layout'    => $layout,
            'blocks'    => implode( ',', $selected ),
            'from'      => $window['from'],
            'to'        => $window['to'],
            '_wpnonce'  => wp_create_nonce( 'wp_rest' ),
        ];
        return add_query_arg( $args, rest_url( 'talenttrack/v1/exports/player_report_pdf' ) );
    }

    /**
     * How the printed copy will come out: pages, how full each is, and what
     * the one-pager shortened to fit — the same estimate the PDF applies.
     *
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
                ? __( 'Does not fit on one page, even with long lists shortened. Drop a section, or switch to the two-page pack.', 'talenttrack' )
                : __( 'Runs past two pages. Drop a section or pick a shorter period.', 'talenttrack' );
            echo '<p class="tt-mr-fit__msg is-over">' . esc_html( $msg ) . '</p>';
        } elseif ( $fit['degraded'] !== [] ) {
            echo '<p class="tt-mr-fit__msg is-tight">' . esc_html__( 'Fits by shortening: long lists keep their most recent entries and say how many more there are, the development plan becomes one line, and the notes area keeps three lines.', 'talenttrack' ) . '</p>';
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
            'letterhead'     => [ 'title' => _x( 'Letterhead', 'player report section', 'talenttrack' ),        'note' => __( 'Player, team, period — always included', 'talenttrack' ) ],
            'status'         => [ 'title' => _x( 'Status', 'player report section', 'talenttrack' ),            'note' => __( 'Where the player stands now', 'talenttrack' ) ],
            'talking_points' => [ 'title' => _x( 'Talking points', 'player report section', 'talenttrack' ),    'note' => __( 'What to raise in the conversation', 'talenttrack' ) ],
            'ratings'        => [ 'title' => _x( 'Evaluations', 'player report section', 'talenttrack' ),       'note' => __( 'Ratings per category and what was written', 'talenttrack' ) ],
            'attendance'     => [ 'title' => _x( 'Attendance', 'player report section', 'talenttrack' ),        'note' => __( 'Present, absent and excused', 'talenttrack' ) ],
            'minutes'        => [ 'title' => _x( 'Playing time', 'player report section', 'talenttrack' ),      'note' => __( 'Matches and minutes played', 'talenttrack' ) ],
            'goals'          => [ 'title' => _x( 'Goals', 'player report section', 'talenttrack' ),             'note' => __( 'Open goals and what moved', 'talenttrack' ) ],
            'pdp'            => [ 'title' => _x( 'Development plan', 'player report section', 'talenttrack' ),  'note' => __( 'Conversations and what was agreed', 'talenttrack' ) ],
            'notes'          => [ 'title' => _x( 'Notes', 'player report section', 'talenttrack' ),             'note' => __( 'Space to write on during the talk', 'talenttrack' ) ],
            'matches'        => [ 'title' => _x( 'Match by match', 'player report section', 'talenttrack' ),    'note' => __( 'Minutes in every match', 'talenttrack' ) ],
            'tests'          => [ 'title' => _x( 'Tests', 'player report section', 'talenttrack' ),             'note' => __( 'Latest readings and the change', 'talenttrack' ) ],
            'journey'        => [ 'title' => _x( 'Journey', 'player report section', 'talenttrack' ),           'note' => __( 'Moves, transitions and milestones', 'talenttrack' ) ],
            'injuries'       => [ 'title' => _x( 'Injuries', 'player report section', 'talenttrack' ),          'note' => __( 'Only for readers with medical access', 'talenttrack' ) ],
            'behaviour'      => [ 'title' => _x( 'Behaviour', 'player report section', 'talenttrack' ),         'note' => __( 'Behaviour ratings in the period', 'talenttrack' ) ],
            'potential'      => [ 'title' => _x( 'Potential', 'player report section', 'talenttrack' ),         'note' => __( 'Potential set in the period', 'talenttrack' ) ],
            'thread_notes'   => [ 'title' => _x( 'Staff notes', 'player report section', 'talenttrack' ),       'note' => __( 'Notes from the player\'s file', 'talenttrack' ) ],
        ];
    }

    /* ---------------------------------------------------------------
     * Body
     * ------------------------------------------------------------- */

    /**
     * @param array<string,mixed>                        $head
     * @param array{from:string,to:string,period:string} $window
     */
    private static function renderLetterhead( array $head, array $window ): void {
        $bits = [
            sprintf(
                /* translators: 1: window start date, 2: window end date */
                __( '%1$s – %2$s', 'talenttrack' ),
                TTDate::date( $window['from'] ),
                TTDate::date( $window['to'] )
            ),
        ];
        $team = (string) ( $head['team_name'] ?? '' );
        if ( $team !== '' ) {
            $age = (string) ( $head['age_group'] ?? '' );
            $bits[] = $age !== '' ? $team . ' (' . LookupTranslator::byTypeAndName( 'age_group', $age ) . ')' : $team;
        }
        $coach = (string) ( $head['head_coach'] ?? '' );
        if ( $coach !== '' ) {
            /* translators: %s: head coach's name */
            $bits[] = sprintf( __( 'Head coach %s', 'talenttrack' ), $coach );
        }
        $jersey = $head['jersey_number'] ?? null;
        if ( is_int( $jersey ) ) {
            /* translators: %d: shirt number */
            $bits[] = sprintf( __( 'No. %d', 'talenttrack' ), $jersey );
        }
        $born = $head['birth_year'] ?? null;
        if ( is_int( $born ) ) {
            /* translators: %d: year of birth */
            $bits[] = sprintf( __( 'Born %d', 'talenttrack' ), $born );
        }

        $name  = (string) ( $head['name'] ?? '' );
        $photo = (string) ( $head['photo_url'] ?? '' );

        echo '<header class="tt-rep-page-head tt-mr-head tt-pr-head">';
        if ( $photo !== '' ) {
            echo '<img class="tt-pr-head__photo" src="' . esc_url( $photo ) . '" alt="" width="72" height="72">';
        }
        echo '<div class="tt-pr-head__text">';
        echo '<h1>' . esc_html( sprintf(
            /* translators: %s: player name */
            __( 'Player report — %s', 'talenttrack' ),
            $name
        ) ) . '</h1>';
        echo '<p class="tt-rep-page-head__sub">' . esc_html( implode( ' · ', $bits ) ) . '</p>';
        echo '</div>';
        echo '</header>';
    }

    /** @param array<string,mixed> $s */
    private static function renderStatus( array $s ): void {
        $color = (string) ( $s['color'] ?? StatusVerdict::COLOR_UNKNOWN );

        self::sectionOpen( _x( 'Status', 'player report section', 'talenttrack' ) );
        echo '<p><span class="tt-mr-pill is-' . esc_attr( $color ) . '">' . esc_html( self::statusLabel( $color ) ) . '</span>';
        $score = $s['score'] ?? null;
        if ( is_int( $score ) || is_float( $score ) ) {
            echo ' <span class="tt-mr-muted">' . esc_html( sprintf(
                /* translators: %s: status score */
                __( 'Score %s', 'talenttrack' ),
                number_format_i18n( (float) $score, 0 )
            ) ) . '</span>';
        }
        echo '</p>';

        $missing = is_array( $s['missing_inputs'] ?? null ) ? $s['missing_inputs'] : [];
        if ( $missing !== [] ) {
            $labels = array_map( static fn( $k ): string => StatusVerdict::inputLabel( (string) $k ), $missing );
            echo '<p class="tt-mr-muted">' . esc_html( sprintf(
                /* translators: %s: comma-separated list of missing inputs */
                __( 'Computed without %s.', 'talenttrack' ),
                implode( ', ', $labels )
            ) ) . '</p>';
        }
        self::sectionClose();
    }

    /** @param array<string,mixed> $t */
    private static function renderTalkingPoints( array $t ): void {
        $items = is_array( $t['items'] ?? null ) ? $t['items'] : [];

        self::sectionOpen(
            _x( 'Talking points', 'player report section', 'talenttrack' ),
            __( 'What the data suggests raising, most urgent first.', 'talenttrack' )
        );
        if ( $items === [] ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'Nothing in the data asks to be raised this period.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-mr-attention">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $level = (string) ( $item['level'] ?? '' );
            echo '<li class="tt-mr-attention__item is-' . esc_attr( $level !== '' ? $level : 'amber' ) . '">';
            echo '<p class="tt-mr-attention__name">' . esc_html( (string) ( $item['text'] ?? '' ) ) . '</p>';
            $evidence = (string) ( $item['evidence'] ?? '' );
            if ( $evidence !== '' ) {
                echo '<p class="tt-mr-attention__facts">' . esc_html( $evidence ) . '</p>';
            }
            echo '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $r */
    private static function renderRatings( array $r ): void {
        $count      = (int) ( $r['evaluation_count'] ?? 0 );
        $categories = is_array( $r['categories'] ?? null ) ? $r['categories'] : [];
        $evals      = is_array( $r['evaluations'] ?? null ) ? $r['evaluations'] : [];

        self::sectionOpen( _x( 'Evaluations', 'player report section', 'talenttrack' ) );
        if ( $count === 0 ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No evaluations in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }

        echo '<dl class="tt-evidence__stats">';
        self::stat( __( 'Evaluations', 'talenttrack' ), number_format_i18n( $count ) );
        self::stat( _x( 'Latest', 'player report rating', 'talenttrack' ), self::rating( $r['latest'] ?? null ) );
        self::stat( _x( 'Average', 'player report rating', 'talenttrack' ), self::rating( $r['average'] ?? null ) );
        echo '</dl>';

        if ( $categories !== [] ) {
            $c_cat    = __( 'Category', 'talenttrack' );
            $c_latest = _x( 'Latest', 'player report rating', 'talenttrack' );
            $c_avg    = _x( 'Average', 'player report rating', 'talenttrack' );
            echo '<div class="tt-evidence__scroll"><table class="tt-list-table-table tt-evidence__table"><thead><tr>'
                . '<th>' . esc_html( $c_cat ) . '</th><th>' . esc_html( $c_latest ) . '</th><th>' . esc_html( $c_avg ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $categories as $cat ) {
                if ( ! is_array( $cat ) ) continue;
                echo '<tr>'
                    . '<td data-label="' . esc_attr( $c_cat ) . '">' . esc_html( (string) ( $cat['label'] ?? '' ) ) . '</td>'
                    . '<td data-label="' . esc_attr( $c_latest ) . '">' . esc_html( self::rating( $cat['latest'] ?? null ) ) . '</td>'
                    . '<td data-label="' . esc_attr( $c_avg ) . '">' . esc_html( self::rating( $cat['average'] ?? null ) ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        $c_date     = __( 'Date', 'talenttrack' );
        $c_rating   = __( 'Rating', 'talenttrack' );
        $c_assessor = __( 'Assessor', 'talenttrack' );
        $c_notes    = __( 'Notes', 'talenttrack' );
        echo '<div class="tt-evidence__scroll"><table class="tt-list-table-table tt-evidence__table"><thead><tr>'
            . '<th>' . esc_html( $c_date ) . '</th><th>' . esc_html( $c_rating ) . '</th>'
            . '<th>' . esc_html( $c_assessor ) . '</th><th>' . esc_html( $c_notes ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $evals as $e ) {
            if ( ! is_array( $e ) ) continue;
            $date  = TTDate::date( (string) ( $e['eval_date'] ?? '' ) );
            $url   = RecordLink::detailUrlForWithBack( 'evaluations', (int) ( $e['id'] ?? 0 ) );
            $notes = trim( wp_strip_all_tags( (string) ( $e['notes'] ?? '' ) ) );
            $who   = (string) ( $e['assessor_name'] ?? '' );
            echo '<tr>'
                . '<td data-label="' . esc_attr( $c_date ) . '">' . self::link( 'evaluations', $url, $date ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
                . '<td data-label="' . esc_attr( $c_rating ) . '">' . esc_html( self::rating( $e['rating'] ?? null ) ) . '</td>'
                . '<td data-label="' . esc_attr( $c_assessor ) . '">' . esc_html( $who !== '' ? $who : '—' ) . '</td>'
                . '<td data-label="' . esc_attr( $c_notes ) . '">' . esc_html( $notes !== '' ? $notes : '—' ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $a */
    private static function renderAttendance( array $a ): void {
        $activities = (int) ( $a['activities'] ?? 0 );

        self::sectionOpen( _x( 'Attendance', 'player report section', 'talenttrack' ) );
        if ( $activities === 0 ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No training or matches recorded in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        $rate    = $a['rate'] ?? null;
        $present = (int) ( $a['present'] ?? 0 );
        echo '<dl class="tt-evidence__stats">';
        self::stat( __( 'Activities', 'talenttrack' ), (string) $activities );
        self::stat(
            __( 'Present', 'talenttrack' ),
            $rate === null
                ? (string) $present
                /* translators: 1: present count, 2: attendance percentage */
                : sprintf( __( '%1$d (%2$d%%)', 'talenttrack' ), $present, (int) $rate )
        );
        self::stat( __( 'Absent', 'talenttrack' ), (string) (int) ( $a['absent'] ?? 0 ) );
        self::stat( __( 'Excused', 'talenttrack' ), (string) (int) ( $a['excused'] ?? 0 ) );
        echo '</dl>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $m */
    private static function renderMinutes( array $m ): void {
        self::sectionOpen( _x( 'Playing time', 'player report section', 'talenttrack' ) );
        if ( (int) ( $m['apps'] ?? 0 ) === 0 && (int) ( $m['minutes'] ?? 0 ) === 0 ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No per-match minutes recorded in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<dl class="tt-evidence__stats">';
        self::stat( __( 'Matches played', 'talenttrack' ), (string) (int) ( $m['apps'] ?? 0 ) );
        self::stat( __( 'Minutes played', 'talenttrack' ), number_format_i18n( (int) ( $m['minutes'] ?? 0 ) ) );
        echo '</dl>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $g */
    private static function renderGoals( array $g ): void {
        $items = is_array( $g['items'] ?? null ) ? $g['items'] : [];

        self::sectionOpen( _x( 'Goals', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No goals open or closed in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-evidence__cards">';
        foreach ( $items as $goal ) {
            if ( ! is_array( $goal ) ) continue;
            $title = (string) ( $goal['title'] ?? '' );
            if ( $title === '' ) $title = __( 'Untitled goal', 'talenttrack' );
            $url      = RecordLink::detailUrlForWithBack( 'goals', (int) ( $goal['id'] ?? 0 ) );
            $status   = LookupTranslator::byTypeAndName( 'goal_status', (string) ( $goal['status'] ?? '' ) );
            $movement = ! empty( $goal['changed_in_window'] )
                ? ( ! empty( $goal['created_in_window'] )
                    ? __( 'Set in this window', 'talenttrack' )
                    : __( 'Moved in this window', 'talenttrack' ) )
                : __( 'No movement in this window', 'talenttrack' );
            $due = (string) ( $goal['due_date'] ?? '' );

            echo '<li class="tt-evidence__card">'
                . '<span class="tt-evidence__card-title">' . self::link( 'goals', $url, $title ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
                . '<span class="tt-evidence__card-meta">' . esc_html( $status ) . '</span>'
                . '<span class="tt-evidence__card-meta">' . esc_html( $movement ) . '</span>';
            if ( $due !== '' && empty( $goal['is_closed'] ) ) {
                echo '<span class="tt-evidence__card-meta">' . esc_html( sprintf(
                    /* translators: %s: goal due date */
                    __( 'Due %s', 'talenttrack' ),
                    TTDate::date( $due )
                ) ) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $p */
    private static function renderPdp( array $p ): void {
        self::sectionOpen( _x( 'Development plan', 'player report section', 'talenttrack' ) );

        if ( empty( $p['available'] ) ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'The development plan is not available to you, or is switched off for your academy.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }

        $file = is_array( $p['file'] ?? null ) ? $p['file'] : null;
        if ( $file === null ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'This player has no development plan file yet.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }

        $file_url = RecordLink::detailUrlForWithBack( 'pdp', (int) ( $file['id'] ?? 0 ) );
        echo '<p>' . self::link( 'pdp', $file_url, __( 'Open the development plan', 'talenttrack' ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.

        $convs = is_array( $p['conversations'] ?? null ) ? $p['conversations'] : [];
        if ( $convs !== [] ) {
            $c_seq  = _x( 'Conversation', 'player report pdp column', 'talenttrack' );
            $c_plan = _x( 'Planned', 'player report pdp column', 'talenttrack' );
            $c_held = _x( 'Held', 'player report pdp column', 'talenttrack' );
            $c_sign = _x( 'Signed off', 'player report pdp column', 'talenttrack' );
            echo '<div class="tt-evidence__scroll"><table class="tt-list-table-table tt-evidence__table"><thead><tr>'
                . '<th>' . esc_html( $c_seq ) . '</th><th>' . esc_html( $c_plan ) . '</th>'
                . '<th>' . esc_html( $c_held ) . '</th><th>' . esc_html( $c_sign ) . '</th>'
                . '</tr></thead><tbody>';
            foreach ( $convs as $c ) {
                if ( ! is_array( $c ) ) continue;
                $held = (string) ( $c['conducted_at'] ?? '' );
                echo '<tr>'
                    . '<td data-label="' . esc_attr( $c_seq ) . '">' . (int) ( $c['sequence'] ?? 0 ) . '</td>'
                    . '<td data-label="' . esc_attr( $c_plan ) . '">' . esc_html( TTDate::date( (string) ( $c['scheduled_at'] ?? '' ) ) ) . '</td>'
                    . '<td data-label="' . esc_attr( $c_held ) . '">' . esc_html( $held !== '' ? TTDate::date( $held ) : '—' ) . '</td>'
                    . '<td data-label="' . esc_attr( $c_sign ) . '">' . esc_html( ! empty( $c['signed_off'] ) ? __( 'Yes', 'talenttrack' ) : '—' ) . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table></div>';
        }

        $actions = trim( wp_strip_all_tags( (string) ( $p['last_agreed_actions'] ?? '' ) ) );
        if ( $actions !== '' ) {
            echo '<p class="tt-mr-muted">' . esc_html__( 'Agreed at the last conversation:', 'talenttrack' ) . '</p>';
            echo '<blockquote class="tt-evidence__quote">' . esc_html( $actions ) . '</blockquote>';
        }

        $verdict = is_array( $p['verdict'] ?? null ) ? $p['verdict'] : null;
        if ( $verdict !== null ) {
            echo '<p>' . esc_html( sprintf(
                /* translators: %s: end-of-season verdict, e.g. "Renew" */
                __( 'End-of-season verdict: %s', 'talenttrack' ),
                (string) ( $verdict['label'] ?? '' )
            ) ) . '</p>';
        }
        self::sectionClose();
    }

    private static function renderNotes(): void {
        self::sectionOpen(
            _x( 'Notes', 'player report section', 'talenttrack' ),
            __( 'Ruled lines on the printed copy, for what the conversation agrees.', 'talenttrack' )
        );
        echo '<div class="tt-mr-lines" aria-hidden="true">';
        for ( $i = 0; $i < 6; $i++ ) echo '<span></span>';
        echo '</div>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $m */
    private static function renderMatches( array $m ): void {
        $items = is_array( $m['items'] ?? null ) ? $m['items'] : [];

        self::sectionOpen( _x( 'Match by match', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No per-match minutes recorded in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        $c_date  = __( 'Date', 'talenttrack' );
        $c_match = __( 'Match', 'talenttrack' );
        $c_min   = __( 'Minutes', 'talenttrack' );
        echo '<div class="tt-evidence__scroll"><table class="tt-list-table-table tt-evidence__table"><thead><tr>'
            . '<th>' . esc_html( $c_date ) . '</th><th>' . esc_html( $c_match ) . '</th><th>' . esc_html( $c_min ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $items as $match ) {
            if ( ! is_array( $match ) ) continue;
            $title = (string) ( $match['title'] ?? '' );
            if ( $title === '' ) $title = __( 'Match', 'talenttrack' );
            $url = RecordLink::detailUrlForWithBack( 'activities', (int) ( $match['activity_id'] ?? 0 ) );
            echo '<tr>'
                . '<td data-label="' . esc_attr( $c_date ) . '">' . esc_html( TTDate::date( (string) ( $match['session_date'] ?? '' ) ) ) . '</td>'
                . '<td data-label="' . esc_attr( $c_match ) . '">' . self::link( 'activities', $url, $title ) . '</td>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link() escapes.
                . '<td data-label="' . esc_attr( $c_min ) . '">' . (int) ( $match['minutes'] ?? 0 ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $t */
    private static function renderTests( array $t ): void {
        $items = is_array( $t['items'] ?? null ) ? $t['items'] : [];

        self::sectionOpen( _x( 'Tests', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No tests taken this period.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        $c_test   = _x( 'Test', 'player report tests column', 'talenttrack' );
        $c_result = _x( 'Result', 'monthly report tests column', 'talenttrack' );
        $c_date   = __( 'Date', 'talenttrack' );
        $c_change = _x( 'Change', 'monthly report tests column', 'talenttrack' );
        $c_score  = _x( 'Score', 'player report tests column', 'talenttrack' );
        echo '<div class="tt-evidence__scroll"><table class="tt-list-table-table tt-evidence__table"><thead><tr>'
            . '<th>' . esc_html( $c_test ) . '</th><th>' . esc_html( $c_result ) . '</th>'
            . '<th>' . esc_html( $c_score ) . '</th>'
            . '<th>' . esc_html( $c_date ) . '</th><th>' . esc_html( $c_change ) . '</th>'
            . '</tr></thead><tbody>';
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $unit  = (string) ( $row['unit'] ?? '' );
            $value = $row['value'] ?? null;
            $text  = $row['text'] ?? null;
            $shown = is_int( $value ) || is_float( $value )
                ? trim( number_format_i18n( (float) $value, floor( (float) $value ) == $value ? 0 : 2 ) . ' ' . $unit )
                : ( is_string( $text ) && $text !== '' ? $text : '—' );
            echo '<tr>'
                . '<td data-label="' . esc_attr( $c_test ) . '">' . esc_html( (string) ( $row['name'] ?? '' ) ) . '</td>'
                . '<td data-label="' . esc_attr( $c_result ) . '">' . esc_html( $shown ) . '</td>'
                . '<td data-label="' . esc_attr( $c_score ) . '">' . self::testScore( $row ) . '</td>'
                . '<td data-label="' . esc_attr( $c_date ) . '">' . esc_html( TTDate::date( (string) ( $row['date'] ?? '' ) ) ) . '</td>'
                . '<td data-label="' . esc_attr( $c_change ) . '">' . esc_html( self::testChange( $row ) ) . '</td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        self::sectionClose();
    }

    /**
     * The reading's score: against the age-group target in words, with the
     * Test results report's dot; on a status test, the level's own colour next
     * to the level. A test with neither has no score, and says so with a dash.
     *
     * @param array<string,mixed> $row
     */
    private static function testScore( array $row ): string {
        $flag  = (string) ( $row['score'] ?? '' );
        $label = \TT\Modules\Measurements\Repositories\MeasurementTargetsRepository::flagLabel( $flag );
        if ( $label !== '' ) {
            return '<span class="tt-pr-score"><span class="tt-pr-score__dot is-' . esc_attr( sanitize_html_class( $flag ) ) . '" aria-hidden="true"></span>'
                . esc_html( $label ) . '</span>';
        }
        $token = (string) ( $row['level_token'] ?? '' );
        $text  = $row['text'] ?? null;
        if ( $token !== '' && is_string( $text ) && $text !== '' ) {
            return '<span class="tt-pr-score"><span class="tt-mlvl-swatch ' . esc_attr( \TT\Modules\Measurements\Levels\MeasurementLevelPalette::cssClass( $token ) ) . '" aria-hidden="true"></span>'
                . esc_html( $text ) . '</span>';
        }
        return '—';
    }

    /**
     * The change in words as well as numbers — a colour or an arrow alone is
     * not an answer on a printed page, and a faster sprint is a smaller number.
     *
     * @param array<string,mixed> $row
     */
    private static function testChange( array $row ): string {
        $delta = $row['delta'] ?? null;
        if ( ! is_int( $delta ) && ! is_float( $delta ) ) {
            return ( $row['previous_date'] ?? '' ) === '' ? __( 'First reading', 'talenttrack' ) : '—';
        }
        $sign = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
        $text = $sign . number_format_i18n( abs( (float) $delta ), floor( abs( (float) $delta ) ) == abs( (float) $delta ) ? 0 : 2 );
        $unit = (string) ( $row['unit'] ?? '' );
        if ( $unit !== '' ) $text .= ' ' . $unit;

        switch ( (string) ( $row['trend'] ?? '' ) ) {
            case 'up':   return $text . ' · ' . _x( 'better', 'player report test change', 'talenttrack' );
            case 'down': return $text . ' · ' . _x( 'worse', 'player report test change', 'talenttrack' );
            case 'flat': return $text . ' · ' . _x( 'unchanged', 'player report test change', 'talenttrack' );
        }
        return $text;
    }

    /** @param array<string,mixed> $j */
    private static function renderJourney( array $j ): void {
        $items = is_array( $j['items'] ?? null ) ? $j['items'] : [];

        self::sectionOpen( _x( 'Journey', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'Nothing recorded on the journey in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        $can_open = CrossViewLink::allows( 'activities' );
        echo '<ul class="tt-mr-changes">';
        foreach ( $items as $e ) {
            if ( ! is_array( $e ) ) continue;
            echo '<li><span class="tt-mr-changes__date">' . esc_html( TTDate::date( (string) ( $e['date'] ?? '' ) ) ) . '</span> '
                . esc_html( (string) ( $e['summary'] ?? '' ) );
            // What the comment or evaluation was about.
            $activity = is_array( $e['activity'] ?? null ) ? $e['activity'] : null;
            if ( $activity !== null ) {
                $label = PlayerReport::activityLabel( $activity );
                $id    = (int) ( $activity['id'] ?? 0 );
                echo '<span class="tt-pr-journey__activity">';
                if ( $can_open && $id > 0 ) {
                    echo '<a href="' . esc_url( RecordLink::detailUrlForWithBack( 'activities', $id ) ) . '">' . esc_html( $label ) . '</a>';
                } else {
                    echo esc_html( $label );
                }
                echo '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $i */
    private static function renderInjuries( array $i ): void {
        $items = is_array( $i['items'] ?? null ) ? $i['items'] : [];

        self::sectionOpen( _x( 'Injuries', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No injuries in this window, or none you have access to.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-evidence__cards">';
        foreach ( $items as $injury ) {
            if ( ! is_array( $injury ) ) continue;
            $meta = ! empty( $injury['is_open'] )
                ? __( 'Still out', 'talenttrack' )
                /* translators: %s = return-to-play date */
                : sprintf( __( 'Back on %s', 'talenttrack' ), TTDate::date( (string) ( $injury['actual_return'] ?? '' ) ) );
            $body = trim( wp_strip_all_tags( (string) ( $injury['notes'] ?? '' ) ) );
            echo '<li class="tt-evidence__card tt-evidence__card--injury">'
                . '<span class="tt-evidence__card-title">' . esc_html( sprintf(
                    /* translators: %s = injury start date */
                    __( 'Injury from %s', 'talenttrack' ),
                    TTDate::date( (string) ( $injury['started_on'] ?? '' ) )
                ) ) . '</span>'
                . '<span class="tt-evidence__card-meta">' . esc_html( $meta ) . '</span>'
                . ( $body !== '' ? '<span class="tt-evidence__card-body">' . esc_html( $body ) . '</span>' : '' )
                . '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $b */
    private static function renderBehaviour( array $b ): void {
        $items = is_array( $b['items'] ?? null ) ? $b['items'] : [];

        self::sectionOpen( _x( 'Behaviour', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No behaviour ratings in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-evidence__cards">';
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $notes = trim( wp_strip_all_tags( (string) ( $row['notes'] ?? '' ) ) );
            echo '<li class="tt-evidence__card">'
                . '<span class="tt-evidence__card-title">' . esc_html( number_format_i18n( (float) ( $row['rating'] ?? 0 ), 1 ) ) . '</span>'
                . '<span class="tt-evidence__card-meta">' . esc_html( TTDate::date( (string) ( $row['rated_at'] ?? '' ) ) ) . '</span>'
                . ( $notes !== '' ? '<span class="tt-evidence__card-body">' . esc_html( $notes ) . '</span>' : '' )
                . '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $p */
    private static function renderPotential( array $p ): void {
        $items = is_array( $p['items'] ?? null ) ? $p['items'] : [];

        self::sectionOpen( _x( 'Potential', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No potential set in this window.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-evidence__cards">';
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            echo '<li class="tt-evidence__card">'
                . '<span class="tt-evidence__card-title">' . esc_html( LookupTranslator::byTypeAndName( 'potential_band', (string) ( $row['potential_band'] ?? '' ) ) ) . '</span>'
                . '<span class="tt-evidence__card-meta">' . esc_html( TTDate::date( (string) ( $row['set_at'] ?? '' ) ) ) . '</span>'
                . '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /** @param array<string,mixed> $n */
    private static function renderThreadNotes( array $n ): void {
        $items = is_array( $n['items'] ?? null ) ? $n['items'] : [];

        self::sectionOpen( _x( 'Staff notes', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            echo '<p class="tt-evidence__empty">' . esc_html__( 'No staff notes in this window, or none you have access to.', 'talenttrack' ) . '</p>';
            self::sectionClose();
            return;
        }
        echo '<ul class="tt-evidence__cards">';
        foreach ( $items as $note ) {
            if ( ! is_array( $note ) ) continue;
            $body = trim( wp_strip_all_tags( (string) ( $note['body'] ?? '' ) ) );
            if ( $body === '' ) continue;
            $who  = (string) ( $note['author_name'] ?? '' );
            $when = TTDate::date( (string) ( $note['created_at'] ?? '' ) );
            $meta = $who !== ''
                /* translators: 1: staff member name, 2: date the note was written */
                ? sprintf( __( '%1$s · %2$s', 'talenttrack' ), $who, $when )
                : $when;
            echo '<li class="tt-evidence__card tt-evidence__card--note">'
                . '<span class="tt-evidence__card-title">' . esc_html__( 'Staff note', 'talenttrack' ) . '</span>'
                . '<span class="tt-evidence__card-meta">' . esc_html( $meta ) . '</span>'
                . '<span class="tt-evidence__card-body">' . esc_html( $body ) . '</span>'
                . '</li>';
        }
        echo '</ul>';
        self::sectionClose();
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function sectionOpen( string $title, string $hint = '' ): void {
        echo '<section class="tt-rep-section tt-mr-section">';
        echo '<div class="tt-rep-section__head"><h2 class="tt-rep-section__title">' . esc_html( $title ) . '</h2>';
        if ( $hint !== '' ) echo '<span class="tt-rep-section__hint">' . esc_html( $hint ) . '</span>';
        echo '</div>';
    }

    private static function sectionClose(): void {
        echo '</section>';
    }

    private static function stat( string $label, string $value ): void {
        echo '<div class="tt-evidence__stat"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
    }

    /** @param mixed $v */
    private static function rating( $v ): string {
        return is_int( $v ) || is_float( $v ) ? number_format_i18n( (float) $v, 1 ) : '—';
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

    private static function statusLabel( string $color ): string {
        switch ( $color ) {
            case 'green': return _x( 'On track', 'player status, staff report', 'talenttrack' );
            case 'amber': return _x( 'Watch', 'player status, staff report', 'talenttrack' );
            case 'red':   return _x( 'Needs action', 'player status, staff report', 'talenttrack' );
            default:      return _x( 'No read yet', 'player status, staff report', 'talenttrack' );
        }
    }
}
