<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Shared\Dates\TTDate;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Modules\Measurements\Services\TestVerdict;

/**
 * TeamMonthlyReportPdfDocument (#3460, epic #3457) — the team monthly report as
 * printable HTML for DomPDF.
 *
 * ## Built for DomPDF, not for a browser
 *
 * DomPDF implements CSS 2.1: tables, floats, inline-block. **Flexbox and grid
 * render as plain blocks**, so a layout written with them looks right in a
 * browser and prints as one collapsed column. Everything here is a table — the
 * KPI strip, the side-by-side pairs, the bars (a percentage-width `<div>` in a
 * cell). A test greps the output for `display:flex` and `display:grid`.
 *
 * ## Built to match the estimate
 *
 * `TeamMonthlyReportLayout::fit()` predicts page counts, and the composition
 * panel shows that prediction. So rows here have fixed heights in millimetres,
 * and names, labels and generated lines are cut to one line. Written text —
 * what changed, why a player needs a conversation, who has no evaluation —
 * prints whole and wraps (#3970), because a sentence cut off on paper cannot
 * be finished; the estimate counts the lines it wraps to.
 *
 * Pure rendering: every figure arrives in the composer's payload.
 */
final class TeamMonthlyReportPdfDocument {

    /**
     * The snapshot notes being printed, if any (#3517).
     *
     * @var array<string,array{body:string, author:int, updated_at:string}>
     */
    private static array $notes = [];

    /**
     * The layout being printed (#4069). The tests section prints its stat
     * strip only on the one-pager, which renders its sections with the same
     * full-width helpers as the pack.
     */
    private static string $layout = TeamMonthlyReportLayout::DEFAULT;

    /**
     * #4096 — each printed section's number, following the ticked sections
     * in print order, and the data the header's meta line is read from.
     *
     * @var array<string,int>
     */
    private static array $numbers = [];

    /** @var array<string,array<string,mixed>> */
    private static array $data = [];

    /** The club colour the headers carry (#4096). */
    private static string $primary = ReportBrandColour::FALLBACK;

    /**
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     *        already degraded by `TeamMonthlyReportLayout::degrade()`.
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     *        a snapshot's section notes; empty for a live report (#3517).
     * @param list<list<string>> $groups the page groups `TeamMonthlyReportLayout::fit()`
     *        split the report into (#4092); measured here when not given.
     */
    public static function html( array $report, string $layout, string $team_name, array $notes = [], array $groups = [] ): string {
        $data   = $report['data'];
        $blocks = $report['blocks'];
        $wide   = $layout === TeamMonthlyReportLayout::MATRIX;

        // #3517 — a snapshot's section notes. Static rather than threaded
        // through six render methods: they are a property of the document
        // being printed, and every one of those methods would otherwise grow
        // a parameter it does not use. Reset on every call so one export
        // cannot leak a note into the next.
        self::$notes   = $notes;
        self::$layout  = $layout;
        self::$numbers = TeamMonthlyReportLayout::sectionNumbers( $blocks, $layout );
        self::$data    = $data;
        self::$primary = ReportBrandColour::primary();

        $head  = self::letterhead( $data['letterhead'] ?? [], $team_name, $report['from'], $report['to'] );
        $empty = (int) ( ( $data['letterhead'] ?? [] )['activity_count'] ?? 0 ) === 0;

        $body = '';
        if ( $empty ) {
            $body = $head . '<p class="empty">' . esc_html__( 'This team has no trainings or matches in this window, so there is nothing to report yet.', 'talenttrack' ) . '</p>';
        } elseif ( $layout === TeamMonthlyReportLayout::PACK ) {
            $body = self::pack( $data, $blocks, $head, $groups !== [] ? $groups : TeamMonthlyReportLayout::fit( $report, $layout )['groups'] );
        } elseif ( $wide ) {
            $body = self::matrix( $data, $blocks, $head );
        } else {
            $body = $head . self::sections( $data, $blocks, array_values( array_diff( TeamMonthlyReportBlock::ALL, [ TeamMonthlyReportBlock::LETTERHEAD ] ) ), false );
        }

        // DomPDF reads no enqueued stylesheet; the document carries its own.
        return '<!doctype html><html><head><meta charset="UTF-8"><style>' . self::css( $layout ) . '</style></head><body>' /* tt-inline-ok */
            . '<div class="footer">' . esc_html__( 'Confidential — staff only. This report names minors and describes their development. Do not share it with players, parents or anyone outside the coaching staff.', 'talenttrack' ) . '</div>'
            . $body
            . '</body></html>';
    }

    /* ---------------------------------------------------------------
     * Layouts
     * ------------------------------------------------------------- */

    /**
     * The pack: dashboard, roster, then the meeting pages, split where
     * `TeamMonthlyReportLayout::fit()` split them (#4092) — the tests, the
     * ruled lines and the data-quality list share page 3 when they fit
     * there and start a page 4 when they do not. A page with nothing
     * selected on it is not printed, so deselecting the roster gives a
     * shorter pack rather than a blank page.
     *
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $blocks
     * @param list<list<string>>                $groups
     */
    private static function pack( array $data, array $blocks, string $head, array $groups ): string {
        $out   = '';
        $first = true;
        foreach ( $groups as $group ) {
            $page = ( in_array( TeamMonthlyReportBlock::LETTERHEAD, $group, true ) ? $head : '' )
                . self::sections( $data, $blocks, $group, false );
            if ( $page === '' ) continue;
            $out  .= '<div class="page' . ( $first ? '' : ' break' ) . '">' . $page . '</div>';
            $first = false;
        }
        return $out;
    }

    /**
     * Landscape matrix: the roster is the page; matches, changes, tests and
     * notes share a footer strip.
     *
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $blocks
     */
    private static function matrix( array $data, array $blocks, string $head ): string {
        $out = $head . self::sections( $data, $blocks, [ 'coverage', 'kpi', 'status', 'roster', 'attention', 'quality' ], true );

        $cells = [];
        foreach ( [ 'matches', 'changes', 'tests', 'notes' ] as $block ) {
            if ( ! in_array( $block, $blocks, true ) ) continue;
            $cells[] = '<td class="strip">' . self::section( $block, $data[ $block ] ?? [], true ) . '</td>';
        }
        if ( $cells !== [] ) {
            $out .= '<table class="cols"><tr>' . implode( '', $cells ) . '</tr></table>';
        }
        return $out;
    }

    /**
     * @param array<string,array<string,mixed>> $data
     * @param list<string>                      $selected
     * @param list<string>                      $order
     */
    private static function sections( array $data, array $selected, array $order, bool $wide ): string {
        $out = '';
        foreach ( $order as $block ) {
            if ( ! in_array( $block, $selected, true ) ) continue;
            $out .= self::section( $block, $data[ $block ] ?? [], $wide ) . self::note( $block );
        }
        return $out;
    }

    /**
     * The meeting's note on one section (#3517), printed under it so paper and
     * screen say the same thing. Empty on a live report, which has no notes.
     */
    private static function note( string $block ): string {
        $note = self::$notes[ $block ] ?? null;
        if ( $note === null || trim( $note['body'] ) === '' ) return '';

        return '<div class="note">' . nl2br( esc_html( $note['body'] ) ) . '</div>';
    }

    /** @param array<string,mixed> $d */
    private static function section( string $block, array $d, bool $wide ): string {
        switch ( $block ) {
            case 'coverage':   return self::coverage( $d );
            case 'kpi':        return self::kpi( $d );
            case 'status':     return self::status( $d );
            case 'attendance': return self::bars( 'attendance', $d, 'present_pct', null );
            case 'minutes':    return self::bars( 'minutes', $d, 'share_pct', (int) ( $d['target_pct'] ?? 50 ) );
            case 'attention':  return self::attention( $d, $wide );
            case 'changes':    return self::changes( $d, $wide );
            case 'tests':      return self::tests( $d, $wide );
            case 'matches':    return self::matches( $d, $wide );
            case 'roster':     return self::roster( $d, $wide );
            case 'notes':      return $wide ? self::notesCompact() : self::notes();
            case 'quality':    return self::quality( $d );
        }
        return '';
    }

    /**
     * #4096 — the section header, one component for screen and paper: the
     * section's number, its title and a line saying what it holds, on a band
     * tinted with the club colour and a bar in it. A one-row table, because
     * DomPDF prints nothing else side by side; the title is an `h2` so the
     * screen keeps its heading outline.
     *
     * `$compact` is the landscape footer strip, a third of the page wide,
     * where the meta line would not fit beside the title.
     */
    public static function sectionHeader( int $number, string $title, string $meta, bool $compact = false ): string {
        return '<table class="tt-mr-sh' . ( $compact ? ' tt-mr-sh--compact' : '' ) . '"><tr>'
            . '<td class="tt-mr-sh__no">' . ( $number > 0 ? esc_html( sprintf( '%02d', $number ) ) : '' ) . '</td>'
            . '<td class="tt-mr-sh__t"><h2>' . esc_html( $title ) . '</h2></td>'
            . ( ! $compact && $meta !== '' ? '<td class="tt-mr-sh__meta">' . esc_html( $meta ) . '</td>' : '' )
            . '</tr></table>';
    }

    /** The header for one of this document's sections (#4096). */
    private static function head( string $block, bool $compact = false ): string {
        return self::sectionHeader(
            self::$numbers[ $block ] ?? 0,
            TeamMonthlyReportBlock::title( $block ),
            self::cut( TeamMonthlyReport::sectionMeta( $block, self::$data[ $block ] ?? [] ), 64 ),
            $compact
        );
    }

    /* ---------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------- */

    /** @param array<string,mixed> $h */
    private static function letterhead( array $h, string $team_name, string $from, string $to ): string {
        $from_ts = strtotime( $from . ' 12:00:00' );
        $to_ts   = strtotime( $to . ' 12:00:00' );
        // Month names through wp_date(), so they print in the site's language.
        $period = ( $from_ts !== false && $to_ts !== false )
            ? ( gmdate( 'Y-m', $from_ts ) === gmdate( 'Y-m', $to_ts ) && gmdate( 'd', $from_ts ) === '01' && gmdate( 't', $to_ts ) === gmdate( 'd', $to_ts )
                ? (string) wp_date( 'F Y', $from_ts )
                : (string) wp_date( 'j F Y', $from_ts ) . ' – ' . (string) wp_date( 'j F Y', $to_ts ) )
            : $from . ' – ' . $to;

        $meta = [];
        $coach = (string) ( $h['head_coach'] ?? '' );
        /* translators: %s: head coach's name */
        if ( $coach !== '' ) $meta[] = sprintf( __( 'Head coach %s', 'talenttrack' ), $coach );
        $squad = (int) ( $h['squad_size'] ?? 0 );
        /* translators: %d: players in the squad */
        $meta[] = sprintf( _n( '%d player', '%d players', $squad, 'talenttrack' ), $squad );
        $acts = (int) ( $h['activity_count'] ?? 0 );
        /* translators: %d: trainings and matches on the team's calendar for the window */
        $meta[] = sprintf( _n( '%d activity', '%d activities', $acts, 'talenttrack' ), $acts );

        return '<table class="lh"><tr>'
            . '<td class="lh-title"><div class="lh-kicker">' . esc_html__( 'Monthly report', 'talenttrack' ) . '</div>'
            . '<div class="lh-team">' . esc_html( self::cut( $team_name, 48 ) ) . '</div></td>'
            . '<td class="lh-meta"><div class="lh-period">' . esc_html( $period ) . '</div>'
            . '<div>' . esc_html( self::cut( implode( ' · ', $meta ), 70 ) ) . '</div>'
            /* translators: %s: date and time the report was generated */
            . '<div class="muted">' . esc_html( sprintf( __( 'Generated %s', 'talenttrack' ), (string) wp_date( 'j F Y H:i' ) ) ) . '</div></td>'
            . '</tr></table>';
    }

    /** @param array<string,mixed> $c */
    private static function coverage( array $c ): string {
        $state        = (string) ( $c['state'] ?? 'empty' );
        $completed    = (int) ( $c['completed'] ?? 0 );
        $with         = (int) ( $c['with_register'] ?? 0 );
        $missing      = is_array( $c['missing'] ?? null ) ? $c['missing'] : [];
        $never_closed = is_array( $c['never_closed'] ?? null ) ? $c['never_closed'] : [];

        $parts = [];
        if ( $missing !== [] ) {
            $parts[] = sprintf(
                /* translators: 1: activities with a register, 2: completed activities, 3: the activities without one */
                __( 'Based on %1$d of %2$d completed activities. No register: %3$s', 'talenttrack' ),
                $with,
                $completed,
                self::coverageNames( $missing )
            );
        } elseif ( $completed > 0 ) {
            $parts[] = sprintf(
                /* translators: %d: completed activities */
                _n( 'Complete: the one completed activity has an attendance register.', 'Complete: all %d completed activities have an attendance register.', $completed, 'talenttrack' ),
                $completed
            );
        }
        if ( $never_closed !== [] ) {
            $parts[] = sprintf(
                /* translators: 1: number of activities, 2: their titles and dates */
                _n(
                    'Never closed: %1$d activity has passed and was never marked completed — %2$s',
                    'Never closed: %1$d activities have passed and were never marked completed — %2$s',
                    count( $never_closed ),
                    'talenttrack'
                ),
                count( $never_closed ),
                self::coverageNames( $never_closed )
            );
        }
        if ( $parts === [] ) {
            $parts[] = $state === 'empty'
                ? __( 'No trainings or matches in this window.', 'talenttrack' )
                : __( 'Nothing in this window has taken place yet.', 'talenttrack' );
        }

        return '<table class="cov"><tr><td class="cov-' . esc_attr( $state ) . '">' . esc_html( self::cut( implode( ' ', $parts ), 150 ) ) . '</td></tr></table>';
    }

    /**
     * "Tuesday (3 Mar), Thursday (5 Mar)" — the activities a coverage sentence
     * names, short enough to survive the line's character budget.
     *
     * @param array<array-key,mixed> $rows
     */
    private static function coverageNames( array $rows ): string {
        $names = [];
        foreach ( $rows as $m ) {
            if ( is_array( $m ) ) $names[] = (string) ( $m['title'] ?? '' ) . ' (' . self::shortDate( (string) ( $m['date'] ?? '' ) ) . ')';
        }
        return implode( ', ', $names );
    }

    /** @param array<string,mixed> $k */
    private static function kpi( array $k ): string {
        $pts   = _x( 'pts', 'percentage points', 'talenttrack' );
        $cells = [
            self::kpiCell( __( 'Activities', 'talenttrack' ), self::measureValue( $k, 'activities', '' ), self::measureDelta( $k, 'activities', '' ) ),
            self::kpiCell( __( 'Attendance', 'talenttrack' ), self::measureValue( $k, 'attendance_pct', '%' ), self::measureDelta( $k, 'attendance_pct', $pts ) ),
            self::kpiCell( __( 'Median minutes share', 'talenttrack' ), self::measureValue( $k, 'minutes_share_median_pct', '%' ), self::measureDelta( $k, 'minutes_share_median_pct', $pts ) ),
        ];
        $eval  = is_array( $k['evaluated'] ?? null ) ? $k['evaluated'] : [];
        $of    = (int) ( $eval['of'] ?? 0 );
        $cells[] = self::kpiCell(
            __( 'Evaluated', 'talenttrack' ),
            $of > 0 ? (int) ( $eval['value'] ?? 0 ) . '/' . $of : '—',
            self::deltaText( $eval['delta'] ?? null, $pts )
        );
        $cells[] = self::kpiCell( __( 'Squad rating', 'talenttrack' ), self::measureValue( $k, 'squad_rating', '' ), self::measureDelta( $k, 'squad_rating', '' ) );
        $cells[] = self::kpiCell( __( 'Need attention', 'talenttrack' ), self::measureValue( $k, 'needs_attention', '' ), self::measureDelta( $k, 'needs_attention', '' ) );

        return '<table class="kpi"><tr>' . implode( '', $cells ) . '</tr></table>';
    }

    /** @param array<string,mixed> $s */
    private static function status( array $s ): string {
        $counts = is_array( $s['counts'] ?? null ) ? $s['counts'] : [];
        $total  = 0;
        foreach ( [ 'green', 'amber', 'red', 'unknown' ] as $c ) $total += (int) ( $counts[ $c ] ?? 0 );

        $out = '<div class="sec">' . self::head( 'status' );
        if ( $total > 0 ) {
            $out .= '<table class="band"><tr>';
            foreach ( [ 'green', 'amber', 'red', 'unknown' ] as $c ) {
                $n = (int) ( $counts[ $c ] ?? 0 );
                if ( $n === 0 ) continue;
                $out .= '<td class="b-' . $c . '" style="width:' . round( $n / $total * 100, 1 ) . '%">' . $n . '</td>'; /* tt-inline-ok */
            }
            $out .= '</tr></table>';
        }
        $out .= '<div class="muted">' . esc_html( sprintf(
            /* translators: 1: on track, 2: to watch, 3: needing action, 4: without a read yet */
            __( '%1$d on track, %2$d to watch, %3$d needing action, %4$d without a read yet', 'talenttrack' ),
            (int) ( $counts['green'] ?? 0 ), (int) ( $counts['amber'] ?? 0 ), (int) ( $counts['red'] ?? 0 ), (int) ( $counts['unknown'] ?? 0 )
        ) ) . '</div></div>';
        return $out;
    }

    /** @param array<string,mixed> $d */
    private static function bars( string $block, array $d, string $value_key, ?int $target ): string {
        $rows = is_array( $d['rows'] ?? null ) ? $d['rows'] : [];
        $out  = '<div class="sec">' . self::head( $block ) . '<table class="bars">';
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) ) continue;
            if ( isset( $r['elided'] ) ) {
                $min = $r['min'] ?? null;
                $max = $r['max'] ?? null;
                $text = ( $min !== null && $max !== null )
                    /* translators: 1: number of players left out, 2: lowest value, 3: highest value */
                    ? sprintf( _n( '… %1$d player between %2$s and %3$s …', '… %1$d players between %2$s and %3$s …', (int) $r['elided'], 'talenttrack' ), (int) $r['elided'], self::pct( $min ), self::pct( $max ) )
                    /* translators: %d: number of players left out */
                    : sprintf( _n( '… %d more player …', '… %d more players …', (int) $r['elided'], 'talenttrack' ), (int) $r['elided'] );
                $out .= '<tr><td colspan="3" class="elided">' . esc_html( $text ) . '</td></tr>';
                continue;
            }
            $v    = $r[ $value_key ] ?? null;
            $pct  = ( is_int( $v ) || is_float( $v ) ) ? (float) $v : null;
            $band = (string) ( $r['band'] ?? '' );
            if ( $band === '' && $pct !== null && $target !== null ) $band = $pct < $target ? 'amber' : 'green';
            $w    = $pct !== null ? max( 0, min( 100, (int) round( $pct ) ) ) : 0;

            $out .= '<tr>'
                . '<td class="nm">' . esc_html( self::cut( (string) ( $r['name'] ?? '' ), 26 ) ) . '</td>'
                . '<td class="tr"><div class="track"><div class="fill f-' . esc_attr( $band !== '' ? $band : 'none' ) . '" style="width:' . $w . '%"></div></div></td>' /* tt-inline-ok */
                . '<td class="vl">' . esc_html( self::pct( $pct ) ) . '</td>'
                . '</tr>';
        }
        return $out . '</table></div>';
    }

    /** @param array<string,mixed> $a */
    private static function attention( array $a, bool $wide ): string {
        $items   = is_array( $a['items'] ?? null ) ? $a['items'] : [];
        $omitted = (int) ( $a['omitted'] ?? 0 );

        $out = '<div class="sec">' . self::head( 'attention' );
        if ( $items === [] ) {
            return $out . '<div class="muted">' . esc_html__( 'Nobody is flagged this period.', 'talenttrack' ) . '</div></div>';
        }
        $out .= '<table class="att">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $color = (string) ( $item['color'] ?? '' );
            $facts = [];
            $att   = $item['attendance_pct'] ?? null;
            /* translators: %s: attendance percentage */
            if ( is_int( $att ) || is_float( $att ) ) $facts[] = sprintf( __( 'Attendance %s', 'talenttrack' ), self::pct( $att ) );
            foreach ( is_array( $item['reasons'] ?? null ) ? $item['reasons'] : [] as $reason ) $facts[] = (string) $reason;

            $out .= '<tr><td class="a-' . esc_attr( $color ) . '">'
                . '<div class="att-n">' . esc_html( self::cut( (string) ( $item['name'] ?? '' ), 40 ) ) . ' · ' . esc_html( self::statusLabel( $color ) ) . '</div>'
                . '<div class="att-w">' . esc_html( implode( ' · ', $facts ) ) . '</div>'
                . '</td></tr>';
        }
        $out .= '</table>';
        if ( $omitted > 0 ) {
            /* translators: %d: players on the agenda not printed on this page */
            $out .= '<div class="muted">' . esc_html( sprintf( _n( '…and %d more on the online report.', '…and %d more on the online report.', $omitted, 'talenttrack' ), $omitted ) ) . '</div>';
        }
        return $out . '</div>';
    }

    /** @param array<string,mixed> $c */
    private static function changes( array $c, bool $compact ): string {
        $events = is_array( $c['events'] ?? null ) ? $c['events'] : [];
        $out    = '<div class="sec">' . self::head( 'changes', $compact );
        if ( $events === [] ) {
            return $out . '<div class="muted">' . esc_html__( 'No injuries, moves or other changes recorded this period.', 'talenttrack' ) . '</div></div>';
        }
        $limit = $compact ? TeamMonthlyReportLayout::STRIP_CHANGES : count( $events );
        $out  .= '<table class="list">';
        foreach ( array_slice( $events, 0, $limit ) as $e ) {
            if ( ! is_array( $e ) ) continue;
            $line = self::shortDate( (string) ( $e['date'] ?? '' ) ) . '  ' . (string) ( $e['name'] ?? '' ) . ' — ' . (string) ( $e['summary'] ?? '' );
            $out .= '<tr><td class="wrap">' . esc_html( $line ) . '</td></tr>';
        }
        return $out . '</table></div>';
    }

    /**
     * Results and match statistics (#3516).
     *
     * On the compact layouts the squad tables are dropped whatever was asked
     * for — they are the longest thing this section prints and would overflow.
     * Degrading to less detail is the ladder's job; overflowing is not.
     *
     * @param array<string,mixed> $m
     */
    private static function matches( array $m, bool $compact ): string {
        $shows    = is_array( $m['shows'] ?? null ) ? $m['shows'] : [];
        $fixtures = is_array( $m['fixtures'] ?? null ) ? $m['fixtures'] : [];
        $record   = is_array( $m['record'] ?? null ) ? $m['record'] : [];

        $out = '<div class="sec">' . self::head( 'matches', $compact );

        if ( $fixtures === [] ) {
            $out .= '<div class="muted">' . esc_html__( 'No matches played this period.', 'talenttrack' ) . '</div>';
            return $out . self::tournamentNote( $m ) . '</div>';
        }

        // #4069 — the portrait layouts get the record as tiles and results
        // beside the ranked scorers. The landscape strip is a third of the
        // page wide, so it keeps the lines.
        if ( ! $compact ) {
            if ( ! empty( $shows['record'] ) ) {
                $out .= self::recordTiles( $record ) . self::withoutScoreNote( $record );
            }
            $results = self::resultsTable( $fixtures, ! empty( $shows['squads'] ), ! empty( $shows['scorers'] ) );
            if ( ! empty( $shows['scorers'] ) ) {
                $out .= '<table class="cols2"><tr>'
                    . '<td class="left">' . $results . '</td>'
                    . '<td class="right">' . self::scorersTable( $m ) . '</td>'
                    . '</tr></table>';
            } else {
                $out .= $results;
            }
            return $out . self::tournamentNote( $m ) . '</div>';
        }

        if ( ! empty( $shows['record'] ) ) {
            $out .= '<div><b>' . esc_html( self::recordLine( $record ) ) . '</b></div>';
            $out .= self::withoutScoreNote( $record );
        }

        // The strip drops the squads whatever was asked for: they are the
        // longest thing this section prints and would overflow the footer.
        $out .= '<table class="list">';
        foreach ( $fixtures as $f ) {
            if ( ! is_array( $f ) ) continue;
            $out .= '<tr><td>' . esc_html( self::cut( self::fixtureLine( $f ), 60 ) ) . '</td></tr>';
        }
        $out .= '</table>';

        if ( ! empty( $shows['scorers'] ) ) {
            $scorers = is_array( $m['scorers'] ?? null ) ? $m['scorers'] : [];
            $out    .= self::scorerLines( $scorers );
        }

        return $out . self::tournamentNote( $m ) . '</div>';
    }

    /** @param array<string,mixed> $record */
    private static function withoutScoreNote( array $record ): string {
        $gaps = (int) ( $record['without_score'] ?? 0 );
        if ( $gaps <= 0 ) return '';

        return '<div class="muted">' . esc_html( sprintf(
            /* translators: %d: number of matches with no score recorded */
            _n(
                '%d match has no score recorded and is not counted in the record.',
                '%d matches have no score recorded and are not counted in the record.',
                $gaps,
                'talenttrack'
            ),
            $gaps
        ) ) . '</div>';
    }

    /**
     * The record as a row of tiles, like the KPI strip (#4069). Won, lost and
     * the goal difference carry the outcome colour; the number says it too.
     *
     * @param array<string,mixed> $record
     */
    private static function recordTiles( array $record ): string {
        $gd    = (int) ( $record['goal_difference'] ?? 0 );
        $tiles = [
            [ (int) ( $record['played'] ?? 0 ), _x( 'Played', 'monthly report match record', 'talenttrack' ), '', '' ],
            [ (int) ( $record['won'] ?? 0 ), _x( 'Won', 'monthly report match record', 'talenttrack' ), 't-w', '' ],
            [ (int) ( $record['drawn'] ?? 0 ), _x( 'Drawn', 'monthly report match record', 'talenttrack' ), 't-d', '' ],
            [ (int) ( $record['lost'] ?? 0 ), _x( 'Lost', 'monthly report match record', 'talenttrack' ), 't-l', '' ],
            [ (int) ( $record['goals_for'] ?? 0 ), _x( 'Goals for', 'monthly report match record', 'talenttrack' ), '', '' ],
            [ (int) ( $record['goals_against'] ?? 0 ), _x( 'Goals against', 'monthly report match record', 'talenttrack' ), '', '' ],
            [ $gd, _x( 'Difference', 'monthly report match record', 'talenttrack' ), 't-gd', $gd > 0 ? 'pos' : ( $gd < 0 ? 'neg' : '' ) ],
        ];

        $out = '<table class="mrec"><tr>';
        foreach ( $tiles as [ $n, $label, $class, $tone ] ) {
            $text = $class === 't-gd' ? self::signed( $n ) : (string) $n;
            $out .= '<td' . ( $class !== '' ? ' class="' . $class . '"' : '' ) . '>'
                . '<div class="n' . ( $tone !== '' ? ' ' . $tone : '' ) . '">' . esc_html( $text ) . '</div>'
                . '<div class="l">' . esc_html( self::cut( $label, 14 ) ) . '</div></td>';
        }
        return $out . '</tr></table>';
    }

    /**
     * Date, home or away, opponent and the score coloured by outcome (#4069).
     * With squads on, the squad wraps under its match.
     *
     * @param array<int|string,mixed> $fixtures
     */
    private static function resultsTable( array $fixtures, bool $with_squads, bool $beside ): string {
        $home = _x( 'H', 'home match, one letter', 'talenttrack' );
        $away = _x( 'A', 'away match, one letter', 'talenttrack' );

        $out = '<div class="sub">' . esc_html__( 'Results', 'talenttrack' ) . '</div><table class="res">';
        foreach ( $fixtures as $f ) {
            if ( ! is_array( $f ) ) continue;

            $squad = $with_squads && is_array( $f['squad'] ?? null ) ? $f['squad'] : [];
            $where = (string) ( $f['home_away'] ?? '' );
            $chip  = $where === 'away' ? $away : ( $where !== '' ? $home : '' );
            $who   = (string) ( $f['opponent'] ?? '' );
            if ( $who === '' ) $who = __( 'Unknown opponent', 'talenttrack' );

            if ( ( $f['team_score'] ?? null ) === null || ( $f['opp_score'] ?? null ) === null ) {
                $score = '<span class="score s-none">' . esc_html__( 'no score recorded', 'talenttrack' ) . '</span>';
            } else {
                $outcome = strtolower( (string) ( $f['outcome'] ?? '' ) );
                $score   = '<span class="score s-' . esc_attr( in_array( $outcome, [ 'w', 'd', 'l' ], true ) ? $outcome : 'd' ) . '">'
                    . esc_html( (int) $f['team_score'] . '–' . (int) $f['opp_score'] ) . '</span>';
            }

            $out .= '<tr' . ( $squad !== [] ? ' class="has-sq"' : '' ) . '>'
                . '<td class="dt">' . esc_html( self::shortDate( (string) ( $f['date'] ?? '' ) ) ) . '</td>'
                . '<td class="ha">' . ( $chip !== '' ? '<div class="ha-chip">' . esc_html( $chip ) . '</div>' : '' ) . '</td>'
                . '<td>' . esc_html( self::cut( $who, $beside ? 30 : 70 ) ) . '</td>'
                . '<td class="sc">' . $score . '</td>'
                . '</tr>';

            if ( $squad !== [] ) {
                $names = [];
                foreach ( $squad as $player ) {
                    if ( ! is_array( $player ) ) continue;
                    $names[] = sprintf(
                        /* translators: 1: player name, 2: minutes played */
                        _x( '%1$s %2$d′', 'player and minutes in a match squad', 'talenttrack' ),
                        (string) ( $player['name'] ?? '' ),
                        (int) ( $player['minutes'] ?? 0 )
                    );
                }
                $out .= '<tr class="sq"><td colspan="4">' . esc_html( implode( ' · ', $names ) ) . '</td></tr>';
            }
        }
        $out .= '</table>';

        /* translators: 1: the letter for a home match, 2: the letter for an away match */
        return $out . '<div class="muted recon">' . esc_html( sprintf( __( '%1$s = home · %2$s = away', 'talenttrack' ), $home, $away ) ) . '</div>';
    }

    /**
     * Goals and assists, ranked (#4069): rank, player, goals with a bar,
     * assists with a bar. The top scorer is highlighted, and the totals row is
     * reconciled against the goals in the record.
     *
     * @param array<string,mixed> $m
     */
    private static function scorersTable( array $m ): string {
        $scorers = is_array( $m['scorers'] ?? null ) ? $m['scorers'] : [];
        $title   = MatchesBlockOptions::labels()[ MatchesBlockOptions::SCORERS ];
        $out     = '<div class="sub">' . esc_html( $title ) . '</div>';
        if ( $scorers === [] ) {
            return $out . '<div class="muted">' . esc_html__( 'No goals or assists recorded this period.', 'talenttrack' ) . '</div>';
        }

        $max_g = 0;
        $max_a = 0;
        $sum_g = 0;
        $sum_a = 0;
        foreach ( $scorers as $row ) {
            if ( ! is_array( $row ) ) continue;
            $max_g  = max( $max_g, (int) ( $row['goals'] ?? 0 ) );
            $max_a  = max( $max_a, (int) ( $row['assists'] ?? 0 ) );
            $sum_g += (int) ( $row['goals'] ?? 0 );
            $sum_a += (int) ( $row['assists'] ?? 0 );
        }

        $out .= '<table class="scr"><tr><th class="rk"></th><th class="nm">' . esc_html__( 'Player', 'talenttrack' ) . '</th>'
            . '<th class="num">' . esc_html_x( 'G', 'goals, one-letter column heading', 'talenttrack' ) . '</th><th class="bar"></th>'
            . '<th class="num">' . esc_html_x( 'A', 'assists, one-letter column heading', 'talenttrack' ) . '</th><th class="bar"></th></tr>';
        foreach ( $scorers as $row ) {
            if ( ! is_array( $row ) ) continue;
            $g    = (int) ( $row['goals'] ?? 0 );
            $a    = (int) ( $row['assists'] ?? 0 );
            $rank = $row['rank'] ?? null;
            $out .= '<tr' . ( $rank === 1 ? ' class="top"' : '' ) . '>'
                . '<td class="rk">' . esc_html( is_int( $rank ) ? (string) $rank : '–' ) . '</td>'
                . '<td>' . esc_html( self::cut( (string) ( $row['name'] ?? '' ), 24 ) ) . '</td>'
                . '<td class="num g">' . $g . '</td>'
                . '<td class="bar">' . self::gauge( 'gbar', $max_g > 0 ? $g / $max_g * 100 : 0.0 ) . '</td>'
                . '<td class="num">' . $a . '</td>'
                . '<td class="bar">' . self::gauge( 'gbar abar', $max_a > 0 ? $a / $max_a * 100 : 0.0 ) . '</td>'
                . '</tr>';
        }
        $out .= '<tr class="tot"><td class="rk"></td><td>' . esc_html__( 'Total', 'talenttrack' ) . '</td>'
            . '<td class="num">' . $sum_g . '</td><td></td><td class="num">' . $sum_a . '</td><td></td></tr></table>';

        $totals = is_array( $m['scorer_totals'] ?? null ) ? $m['scorer_totals'] : null;
        if ( $totals !== null ) {
            $for  = (int) ( $totals['goals_for'] ?? 0 );
            $out .= '<div class="muted recon">' . esc_html( sprintf(
                /* translators: 1: goals with a scorer entered, 2: goals scored in the record */
                _n( '%1$d of %2$d goal attributed.', '%1$d of %2$d goals attributed.', $for, 'talenttrack' ),
                (int) ( $totals['attributed_goals'] ?? $totals['goals'] ?? $sum_g ),
                $for
            ) ) . '</div>';
        }
        return $out;
    }

    /** A bar in a cell: a fixed-height track with a percentage-width fill. */
    private static function gauge( string $class, float $pct ): string {
        $w = max( 0, min( 100, (int) round( $pct ) ) );
        return '<div class="' . esc_attr( $class ) . '"><div style="width:' . $w . '%"></div></div>'; /* tt-inline-ok */
    }

    /**
     * Tournaments fall outside the record on purpose (#2686). Saying so beats
     * a record that quietly disagrees with what the coach remembers.
     *
     * @param array<string,mixed> $m
     */
    private static function tournamentNote( array $m ): string {
        $count = (int) ( $m['tournaments_excluded'] ?? 0 );
        if ( $count <= 0 ) return '';

        return '<div class="muted">' . esc_html( sprintf(
            /* translators: %d: number of tournaments in the period */
            _n(
                '%d tournament this period is not included — a tournament is a multi-game day.',
                '%d tournaments this period are not included — a tournament is a multi-game day.',
                $count,
                'talenttrack'
            ),
            $count
        ) . self::tournamentScorersNote( $m ) ) . '</div>';
    }

    /**
     * #4079 — the scorers table is a leaderboard and keeps tournament goals,
     * while the record and the "N of M goals attributed" line do not. When
     * both are on the page, the tournament note says which is which.
     *
     * @param array<string,mixed> $m
     */
    private static function tournamentScorersNote( array $m ): string {
        $shows = is_array( $m['shows'] ?? null ) ? $m['shows'] : [];
        if ( empty( $shows['scorers'] ) ) return '';
        return ' ' . __( 'Goals scored at a tournament still count in the scorers table, but not against the record.', 'talenttrack' );
    }

    /** @param array<string,mixed> $record */
    private static function recordLine( array $record ): string {
        return sprintf(
            /* translators: 1: played, 2: won, 3: drawn, 4: lost, 5: goals for, 6: goals against, 7: signed goal difference */
            __( 'Played %1$d · W %2$d D %3$d L %4$d · %5$d–%6$d (%7$s)', 'talenttrack' ),
            (int) ( $record['played'] ?? 0 ),
            (int) ( $record['won'] ?? 0 ),
            (int) ( $record['drawn'] ?? 0 ),
            (int) ( $record['lost'] ?? 0 ),
            (int) ( $record['goals_for'] ?? 0 ),
            (int) ( $record['goals_against'] ?? 0 ),
            self::signed( (int) ( $record['goal_difference'] ?? 0 ) )
        );
    }

    /** @param array<string,mixed> $fixture */
    private static function fixtureLine( array $fixture ): string {
        $where = (string) ( $fixture['home_away'] ?? '' );
        $who   = (string) ( $fixture['opponent'] ?? '' );
        if ( $who === '' ) $who = __( 'Unknown opponent', 'talenttrack' );
        if ( $where === 'away' ) {
            /* translators: %s: opponent name */
            $who = sprintf( __( 'away to %s', 'talenttrack' ), $who );
        } elseif ( $where !== '' ) {
            /* translators: %s: opponent name */
            $who = sprintf( __( 'home to %s', 'talenttrack' ), $who );
        }

        $score = $fixture['team_score'] === null || $fixture['opp_score'] === null
            ? __( 'no score recorded', 'talenttrack' )
            : (int) $fixture['team_score'] . '–' . (int) $fixture['opp_score'];

        return sprintf(
            /* translators: 1: date, 2: opponent with home/away, 3: score */
            _x( '%1$s, %2$s — %3$s', 'one match line in the monthly report', 'talenttrack' ),
            self::shortDate( (string) ( $fixture['date'] ?? '' ) ),
            $who,
            $score
        );
    }

    /** @param list<array<string,mixed>>|array<int,mixed> $scorers */
    private static function scorerLines( array $scorers ): string {
        if ( $scorers === [] ) {
            return '<div class="muted">' . esc_html__( 'No goals or assists recorded this period.', 'talenttrack' ) . '</div>';
        }

        $parts = [];
        foreach ( $scorers as $row ) {
            if ( ! is_array( $row ) ) continue;
            $parts[] = sprintf(
                /* translators: 1: player name, 2: goals, 3: assists */
                __( '%1$s %2$dG %3$dA', 'talenttrack' ),
                (string) ( $row['name'] ?? '' ),
                (int) ( $row['goals'] ?? 0 ),
                (int) ( $row['assists'] ?? 0 )
            );
        }

        return '<div class="muted">' . esc_html( implode( ' · ', $parts ) ) . '</div>';
    }

    private static function signed( int $n ): string {
        return $n > 0 ? '+' . $n : (string) $n;
    }

    /** @param array<string,mixed> $t */
    private static function tests( array $t, bool $compact ): string {
        $rounds = is_array( $t['rounds'] ?? null ) ? $t['rounds'] : [];
        $out      = '<div class="sec">' . self::head( 'tests', $compact );
        if ( $rounds === [] ) {
            return $out . '<div class="muted">' . esc_html__( 'No tests taken this period.', 'talenttrack' ) . '</div></div>';
        }
        // #4095 — what the layout can print, by the same rule the panel
        // offers its options by (`TeamMonthlyReportLayout::testsShowFor()`):
        // a player table needs the pack, and the panel says so before the
        // coach downloads anything. The exporter has already applied it; it
        // is applied here too so no path prints a table a layout cannot hold.
        $show = TeamMonthlyReportLayout::testsShowFor( self::$layout, TestsBlockOptions::show( [ 'show' => $t['show'] ?? null ] ) );

        // #4069 — a test is a card: header, stat strip, and on the pack the
        // ranked readings. The landscape strip, a third of the page wide,
        // keeps its lines. A snapshot frozen before the strip existed has no
        // figures for it and prints the lines too.
        if ( ! $compact ) {
            foreach ( $rounds as $s ) {
                if ( ! is_array( $s ) ) continue;
                $out .= is_array( $s['average'] ?? null ) || ! empty( $s['empty'] )
                    ? self::testCard( $s, $show )
                    : self::testLines( [ $s ], $show, false );
            }
            // #4093 — once per section, as on the player profile.
            if ( TeamMonthlyReport::testsHaveTargetlessRound( $t ) ) {
                $out .= '<div class="muted">' . esc_html__( '"No target" means the test has no better or worse — the reading is recorded, not judged.', 'talenttrack' ) . '</div>';
            }
            return $out . '</div>';
        }

        return $out . self::testLines( $rounds, $show, true ) . self::omittedTests( $t ) . '</div>';
    }

    /**
     * #4095 — the tests the landscape strip had no room for, named, so the
     * page says what it left out rather than quietly printing three.
     *
     * @param array<string,mixed> $t
     */
    private static function omittedTests( array $t ): string {
        $names = is_array( $t['omitted'] ?? null ) ? array_map( 'strval', $t['omitted'] ) : [];
        if ( $names === [] ) return '';

        return '<div class="muted">' . esc_html( sprintf(
            /* translators: 1: number of tests not printed, 2: their names */
            _n( '+ %1$d more test, in the pack: %2$s', '+ %1$d more tests, in the pack: %2$s', count( $names ), 'talenttrack' ),
            count( $names ),
            self::cut( implode( ', ', $names ), 40 )
        ) ) . '</div>';
    }

    /**
     * Test rounds as lines: the landscape strip, and snapshots frozen before
     * the stat strip existed.
     *
     * @param array<int|string,mixed> $rounds
     */
    private static function testLines( array $rounds, string $show, bool $compact ): string {
        $out = '<table class="list">';
        foreach ( $rounds as $s ) {
            if ( ! is_array( $s ) ) continue;

            if ( ! empty( $s['empty'] ) ) {
                $out .= '<tr><td><b>' . esc_html( self::cut( (string) ( $s['name'] ?? '' ), $compact ? 60 : 110 ) ) . '</b></td></tr>'
                    . '<tr><td class="muted">' . esc_html__( 'No readings this period.', 'talenttrack' ) . '</td></tr>';
                continue;
            }

            $head = sprintf(
                /* translators: 1: test name, 2: date, 3: players tested, 4: squad size */
                __( '%1$s, %2$s — %3$d of %4$d tested', 'talenttrack' ),
                (string) ( $s['name'] ?? '' ),
                self::shortDate( (string) ( $s['date'] ?? '' ) ),
                (int) ( $s['tested'] ?? 0 ),
                (int) ( $s['squad'] ?? 0 )
            );
            $target = TeamMonthlyReport::testTargetLabel( $s );
            if ( $target !== '' ) $head .= ' · ' . $target;
            $out .= '<tr><td><b>' . esc_html( self::cut( $head, $compact ? 60 : 110 ) ) . '</b></td></tr>';

            if ( TestsBlockOptions::showsPlayers( $show ) ) {
                $out .= '<tr><td>' . self::testReadings( $s, $show ) . '</td></tr>';
                continue;
            }

            $moves = sprintf(
                /* translators: 1: players improved, 2: players declined */
                __( 'Improved %1$d · declined %2$d', 'talenttrack' ),
                is_array( $s['improved'] ?? null ) ? count( $s['improved'] ) : 0,
                is_array( $s['declined'] ?? null ) ? count( $s['declined'] ) : 0
            );
            $out .= '<tr><td class="muted">' . esc_html( $moves ) . '</td></tr>';
        }
        return $out . '</table>';
    }

    /**
     * One test as the approved card (#4069): name, which way is better, date
     * and how many were tested; the stat strip; and, when readings were asked
     * for on a full layout, the ranked table.
     *
     * @param array<string,mixed> $s
     */
    private static function testCard( array $s, string $show ): string {
        $name = (string) ( $s['name'] ?? '' );
        if ( ! empty( $s['empty'] ) ) {
            return '<div class="tcard"><table class="thead"><tr><td><span class="tname">' . esc_html( self::cut( $name, 70 ) ) . '</span></td></tr></table>'
                . '<div class="muted">' . esc_html__( 'No readings this period.', 'talenttrack' ) . '</div></div>';
        }

        $direction = (string) ( $s['direction'] ?? '' );
        $chip      = $direction === 'lower' ? __( 'Lower is better', 'talenttrack' ) : ( $direction === 'higher' ? __( 'Higher is better', 'talenttrack' ) : '' );
        $meta      = self::shortDate( (string) ( $s['date'] ?? '' ) ) . ' · ' . sprintf(
            /* translators: 1: players tested, 2: squad size */
            __( '%1$d of %2$d tested', 'talenttrack' ),
            (int) ( $s['tested'] ?? 0 ),
            (int) ( $s['squad'] ?? 0 )
        );

        $with_table = TestsBlockOptions::showsPlayers( $show ) && is_array( $s['readings'] ?? null ) && $s['readings'] !== [];
        // #4093 — the target the readings are judged against, on every
        // layout, the summary included.
        $target = TeamMonthlyReport::testTargetLabel( $s );

        return '<div class="tcard"><table class="thead"><tr>'
            . '<td><span class="tname">' . esc_html( self::cut( $name, $target !== '' ? 40 : 60 ) ) . '</span>'
            . ( $chip !== '' ? '<span class="dirchip">' . esc_html( $chip ) . '</span>' : '' )
            . ( $target !== '' ? '<span class="tgt' . ( ! empty( $s['target_absent'] ) ? ' tgt-none' : '' ) . '">' . esc_html( $target ) . '</span>' : '' ) . '</td>'
            . '<td class="tmeta">' . esc_html( $meta ) . '</td>'
            . '</tr></table>'
            . self::testStrip( $s, $with_table )
            . ( $with_table ? self::testRanked( $s, $show ) : '' )
            . '</div>';
    }

    /**
     * The stat strip (#4069): squad average and its change, the best
     * reading, who moved which way, how many are on target, and either the
     * average over the last rounds (next to a table) or the worst reading
     * (when the strip stands alone).
     *
     * @param array<string,mixed> $s
     */
    private static function testStrip( array $s, bool $with_history ): string {
        $direction = (string) ( $s['direction'] ?? '' );
        $avg       = is_array( $s['average'] ?? null ) ? $s['average'] : [];
        $cells     = [];

        $change = '';
        if ( (string) ( $avg['delta_display'] ?? '—' ) !== '—' ) {
            $change = sprintf(
                /* translators: 1: change of the squad average, e.g. "−4 s", 2: month of the previous test round */
                _x( '%1$s vs %2$s', 'squad average against the previous test round', 'talenttrack' ),
                (string) $avg['delta_display'],
                self::shortMonth( (string) ( $s['previous_date'] ?? '' ) )
            );
        }
        $cells[] = self::statCell(
            esc_html( (string) ( $avg['display'] ?? '—' ) ),
            __( 'Squad average', 'talenttrack' ),
            $change,
            self::moveClass( (string) ( $avg['trend'] ?? '' ) )
        );

        $best    = is_array( $s['best'] ?? null ) ? $s['best'] : [];
        $cells[] = self::statCell(
            esc_html( (string) ( $best['value_display'] ?? '—' ) ),
            $direction !== '' ? _x( 'Best', 'best test reading', 'talenttrack' ) : _x( 'Highest', 'highest test reading', 'talenttrack' ),
            self::cut( (string) ( $best['name'] ?? '' ), 22 ),
            ''
        );

        $moves  = is_array( $s['moves'] ?? null ) ? $s['moves'] : [];
        $flat   = (int) ( $moves['flat'] ?? 0 );
        $number = '<span class="up">' . (int) ( $moves['up'] ?? 0 ) . '</span> · <span class="dn">' . (int) ( $moves['down'] ?? 0 ) . '</span>'
            . ( $flat > 0 ? ' · <span class="eq">' . $flat . '</span>' : '' )
            . ' · <span class="eq">' . (int) ( $moves['first'] ?? 0 ) . '</span>';
        if ( $direction !== '' ) {
            $label = $flat > 0
                ? _x( 'Better · worse · same · first', 'test round moves, in that order', 'talenttrack' )
                : _x( 'Better · worse · first', 'test round moves, in that order', 'talenttrack' );
        } else {
            $label = $flat > 0
                ? _x( 'Rose · fell · same · first', 'test round moves, in that order', 'talenttrack' )
                : _x( 'Rose · fell · first', 'test round moves, in that order', 'talenttrack' );
        }
        $cells[] = self::statCell( $number, $label, __( 'against their own previous reading', 'talenttrack' ), 'eq' );

        $bands = is_array( $s['bands'] ?? null ) ? $s['bands'] : null;
        if ( $bands !== null ) {
            $of   = max( 1, (int) ( $bands['of'] ?? 0 ) );
            $band = '';
            if ( $with_history ) {
                $band = '<table class="bandrow"><tr>';
                foreach ( [ 'ok' => 'fg', 'warn' => 'fa', 'bad' => 'fr' ] as $flag => $class ) {
                    $n = (int) ( $bands[ $flag ] ?? 0 );
                    if ( $n > 0 ) $band .= '<td class="' . $class . '" style="width:' . round( $n / $of * 100, 1 ) . '%">' . $n . '</td>'; /* tt-inline-ok */
                }
                $band .= '</tr></table>';
            }
            $cells[] = '<td><div class="n">' . (int) ( $bands['ok'] ?? 0 ) . ' / ' . (int) ( $bands['of'] ?? 0 ) . '</div>'
                . '<div class="l">' . esc_html( self::cut( sprintf(
                    /* translators: %s: age group, e.g. "U14" */
                    __( 'On target (%s)', 'talenttrack' ),
                    (string) ( $bands['age_group'] ?? '' )
                ), 24 ) ) . '</div>' . $band . '</td>';
        }

        $history = is_array( $s['history'] ?? null ) ? $s['history'] : [];
        if ( $with_history && count( $history ) > 1 ) {
            $bars   = '';
            $labels = '';
            foreach ( $history as $i => $point ) {
                if ( ! is_array( $point ) ) continue;
                $h       = max( 0.4, round( (float) ( $point['pct'] ?? 0 ) / 100 * 9, 1 ) );
                $now     = $i === count( $history ) - 1;
                $bars   .= '<td><div class="col' . ( $now ? ' now' : '' ) . '" style="height:' . $h . 'mm"></div></td>'; /* tt-inline-ok */
                $labels .= '<td class="hl">' . esc_html( self::shortMonth( (string) ( $point['date'] ?? '' ) ) ) . '</td>';
            }
            $cells[] = '<td><div class="l">' . esc_html__( 'Squad average per round', 'talenttrack' ) . '</div>'
                . '<table class="hist"><tr class="hb">' . $bars . '</tr><tr>' . $labels . '</tr></table></td>';
        } else {
            $worst = is_array( $s['worst'] ?? null ) ? $s['worst'] : [];
            if ( $direction === 'lower' ) {
                $label = ! empty( $s['is_duration'] ) ? _x( 'Slowest', 'worst reading on a timed test', 'talenttrack' ) : _x( 'Highest', 'highest test reading', 'talenttrack' );
            } else {
                $label = _x( 'Lowest', 'lowest test reading', 'talenttrack' );
            }
            $cells[] = self::statCell( esc_html( (string) ( $worst['value_display'] ?? '—' ) ), $label, self::cut( (string) ( $worst['name'] ?? '' ), 22 ), '' );
        }

        return '<table class="tstat"><tr>' . implode( '', $cells ) . '</tr></table>';
    }

    /** @param string $number already escaped */
    private static function statCell( string $number, string $label, string $detail, string $class ): string {
        return '<td><div class="n">' . $number . '</div><div class="l">' . esc_html( $label ) . '</div>'
            . ( $detail !== '' ? '<div class="d' . ( $class !== '' ? ' ' . $class : '' ) . '">' . esc_html( $detail ) . '</div>' : '' )
            . '</td>';
    }

    /**
     * The ranked readings (#4069): rank, player with a PB tag, reading, a bar
     * in its target-band colour, previous reading, change coloured by whether
     * it is an improvement, and the distance to the squad average. A dashed
     * rule marks where the squad average falls.
     *
     * @param array<string,mixed> $s
     */
    private static function testRanked( array $s, string $show ): string {
        $rows   = is_array( $s['readings'] ?? null ) ? $s['readings'] : [];
        $values = TestsBlockOptions::showsValues( $show );
        $trend  = TestsBlockOptions::showsTrend( $show );
        $unit   = (string) ( $s['unit_label'] ?? '' );
        $ranked = (string) ( $s['direction'] ?? '' ) !== '';

        // #4093 — each reading's standing against the target, in words, as
        // on the player's profile. Only where a target exists.
        $standing = false;
        foreach ( $rows as $row ) {
            if ( is_array( $row ) && (string) ( $row['verdict_label'] ?? '' ) !== '' ) $standing = true;
        }

        $head = '<tr><th class="rk">#</th><th class="nm">' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        $cols = 2;
        if ( $values ) {
            $head .= '<th class="v">' . esc_html(
                $unit !== ''
                    /* translators: %s: unit of measurement, e.g. "s" or "cm" */
                    ? sprintf( _x( 'Result (%s)', 'monthly report tests column', 'talenttrack' ), $unit )
                    : _x( 'Result', 'monthly report tests column', 'talenttrack' )
            ) . '</th><th class="bb"></th>';
            $cols += 2;
        }
        if ( $standing ) {
            $head .= '<th class="st">' . esc_html_x( 'Standing', 'monthly report tests column: a reading against its target', 'talenttrack' ) . '</th>';
            $cols++;
        }
        if ( $trend ) {
            $head .= '<th class="pv">' . esc_html__( 'Previous', 'talenttrack' ) . '</th>'
                . '<th class="ch">' . esc_html_x( 'Change', 'monthly report tests column', 'talenttrack' ) . '</th>';
            $cols += 2;
        }
        if ( $values ) {
            $head .= '<th class="av">' . esc_html_x( 'vs avg.', 'monthly report tests column: difference to the squad average', 'talenttrack' ) . '</th>';
            $cols++;
        }
        $head .= '</tr>';

        $body    = '';
        $line    = false;
        $any_pb  = false;
        $any_flag = false;
        foreach ( $rows as $i => $row ) {
            if ( ! is_array( $row ) ) continue;
            if ( $ranked && ! $line && $i > 0 && ! empty( $row['worse_than_avg'] ) ) {
                $body .= '<tr class="avgline"><td colspan="' . $cols . '"></td></tr>';
                $line  = true;
            }
            $flag     = (string) ( $row['flag'] ?? '' );
            $any_flag  = $any_flag || $flag !== '';
            $pb       = ! empty( $row['pb'] );
            $any_pb   = $any_pb || $pb;
            $rank     = $row['rank'] ?? null;
            $body    .= '<tr><td class="rk">' . esc_html( is_int( $rank ) ? (string) $rank : '' ) . '</td>'
                . '<td>' . esc_html( self::cut( (string) ( $row['name'] ?? '' ), $pb ? 22 : 26 ) )
                . ( $pb ? '<span class="pb">' . esc_html_x( 'PB', 'personal best, short tag', 'talenttrack' ) . '</span>' : '' ) . '</td>';
            if ( $values ) {
                $fill  = [ 'ok' => 'fg', 'warn' => 'fa', 'bad' => 'fr' ][ $flag ] ?? 'fn';
                $body .= '<td class="v">' . esc_html( self::testValue( $row ) ) . '</td>'
                    . '<td class="bb">' . self::gauge( 'tb ' . $fill, (float) ( $row['bar_pct'] ?? 0 ) ) . '</td>';
            }
            if ( $standing ) {
                $label = (string) ( $row['verdict_label'] ?? '' );
                $tone  = (string) ( $row['verdict_tone'] ?? '' );
                $body .= '<td class="st">' . ( $label !== ''
                    ? '<span class="vc vc-' . esc_attr( in_array( $tone, [ 'ok', 'warn', 'bad' ], true ) ? $tone : 'none' ) . '">' . esc_html( self::cut( $label, 20 ) ) . '</span>'
                    : '' ) . '</td>';
            }
            if ( $trend ) {
                $first = ! empty( $row['first'] );
                $body .= '<td class="pv">' . esc_html( (string) ( $row['previous_display'] ?? '—' ) ) . '</td>'
                    . '<td class="ch ' . ( $first ? 'eq' : self::moveClass( (string) ( $row['trend'] ?? '' ) ) ) . '">'
                    . esc_html( $first ? _x( 'first', 'a first test reading, nothing to compare with', 'talenttrack' ) : self::testDelta( $row ) ) . '</td>';
            }
            if ( $values ) {
                $body .= '<td class="av">' . esc_html( (string) ( $row['vs_avg_display'] ?? '—' ) ) . '</td>';
            }
            $body .= '</tr>';
        }

        $legend = [];
        if ( $any_flag && $values ) {
            foreach ( [ 'ok' => 'fg', 'warn' => 'fa', 'bad' => 'fr' ] as $flag => $class ) {
                // #4093 — the key names the colours in the standing's own
                // words, so "just over target" on a timed test is not keyed
                // as "below target".
                $word     = TestVerdict::verdict( 'numeric', (string) ( $s['direction'] ?? '' ), $flag )['label'];
                $legend[] = '<span class="dot ' . $class . '"></span>' . esc_html( $word !== '' ? $word : MeasurementTargetsRepository::flagLabel( $flag ) );
            }
        }
        if ( $any_pb ) $legend[] = esc_html__( 'PB = personal best', 'talenttrack' );
        if ( $line )   $legend[] = esc_html__( 'Dashed line = squad average', 'talenttrack' );

        return '<table class="rd">' . $head . $body . '</table>'
            . ( $legend !== [] ? '<div class="muted recon">' . implode( ' &nbsp;·&nbsp; ', $legend ) . '</div>' : '' );
    }

    /** Green for an improvement, red for a decline, muted otherwise. */
    private static function moveClass( string $trend ): string {
        if ( $trend === 'up' ) return 'up';
        if ( $trend === 'down' ) return 'dn';
        return 'eq';
    }

    /** "Sep" — a test round's month, in the site's language. */
    private static function shortMonth( string $ymd ): string {
        $ts = strtotime( $ymd . ' 12:00:00' );
        return $ts === false || $ymd === '' ? '' : (string) wp_date( 'M', $ts );
    }

    /**
     * One test's readings per player (#3515), in the composer's order: best to worst on a test with a direction (#4063).
     *
     * @param array<string,mixed> $round
     */
    private static function testReadings( array $round, string $show ): string {
        $rows = is_array( $round['readings'] ?? null ) ? $round['readings'] : [];
        if ( $rows === [] ) {
            return '<span class="muted">' . esc_html__( 'No readings this period.', 'talenttrack' ) . '</span>';
        }

        $unit   = (string) ( $round['unit_label'] ?? $round['unit'] ?? '' );
        $values = TestsBlockOptions::showsValues( $show );
        $trend  = TestsBlockOptions::showsTrend( $show );

        $out = '<table class="tbl"><tr><th>' . esc_html__( 'Player', 'talenttrack' ) . '</th>';
        if ( $values ) {
            $out .= '<th class="r">' . esc_html(
                $unit !== ''
                    /* translators: %s: unit of measurement, e.g. "s" or "cm" */
                    ? sprintf( _x( 'Result (%s)', 'monthly report tests column', 'talenttrack' ), $unit )
                    : _x( 'Result', 'monthly report tests column', 'talenttrack' )
            ) . '</th>';
        }
        if ( $trend ) {
            $out .= '<th class="r">' . esc_html_x( 'Change', 'monthly report tests column', 'talenttrack' ) . '</th>';
        }
        $out .= '</tr>';

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $out .= '<tr><td>' . esc_html( self::cut( (string) ( $row['name'] ?? '' ), 28 ) ) . '</td>';
            if ( $values ) {
                $out .= '<td class="r">' . esc_html( self::testValue( $row ) ) . '</td>';
            }
            if ( $trend ) {
                $out .= '<td class="r">' . esc_html( self::testDelta( $row ) ) . '</td>';
            }
            $out .= '</tr>';
        }

        return $out . '</table>';
    }

    /**
     * A reading as the composer spelled it (#4063): m:ss for a duration, a
     * locale number otherwise. A snapshot frozen before the composer spelled
     * it falls back to the raw value.
     *
     * @param array<string,mixed> $row
     */
    private static function testValue( array $row ): string {
        if ( isset( $row['value_display'] ) && is_string( $row['value_display'] ) ) return $row['value_display'];
        $value = $row['value'] ?? null;
        return is_scalar( $value ) ? (string) $value : '—';
    }

    /**
     * A reading's change since the player's previous one, as the composer
     * spelled it (#4063). A first reading has nothing to compare with and gets
     * a dash, as everywhere else in this report.
     *
     * @param array<string,mixed> $row
     */
    private static function testDelta( array $row ): string {
        if ( ! empty( $row['first'] ) ) return '—';
        if ( isset( $row['delta_display'] ) && is_string( $row['delta_display'] ) ) return $row['delta_display'];

        $delta = (float) ( $row['delta'] ?? 0 );
        if ( abs( $delta ) < 0.0001 ) return '0';

        return ( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ), abs( $delta ) < 10 ? 2 : 1 );
    }

    /**
     * Player by player. The one-pager prints the compressed column set; the
     * landscape matrix prints both bars inline.
     *
     * @param array<string,mixed> $r
     */
    private static function roster( array $r, bool $wide ): string {
        $rows = is_array( $r['rows'] ?? null ) ? $r['rows'] : [];
        $out  = '<div class="sec">' . self::head( 'roster' );
        // A fixed-layout table takes its widths from the header cells
        // (`.w0`…, see css()), so a long name is cut, never squeezes its
        // neighbours. DomPDF ignores `<col>` widths.
        // [ label, alignment class ]; the matrix adds a bar column after
        // attendance and after share.
        $cols   = [];
        $cols[] = [ __( 'Player', 'talenttrack' ), '' ];
        $cols[] = [ _x( 'Status', 'team monthly report column', 'talenttrack' ), '' ];
        $cols[] = [ __( 'Attendance', 'talenttrack' ), 'r' ];
        if ( $wide ) $cols[] = [ '', '' ];
        $cols[] = [ __( 'Minutes', 'talenttrack' ), 'r' ];
        $cols[] = [ _x( 'Share', 'minutes share column', 'talenttrack' ), 'r' ];
        if ( $wide ) $cols[] = [ '', '' ];
        $cols[] = [ __( 'Open goals', 'talenttrack' ), 'r' ];
        $cols[] = [ _x( 'Injured', 'team monthly report column', 'talenttrack' ), 'c' ];

        $out .= '<table class="tbl"><thead><tr>';
        foreach ( $cols as $i => $col ) {
            $out .= '<th class="w' . $i . ( $col[1] !== '' ? ' ' . $col[1] : '' ) . '">' . esc_html( $col[0] ) . '</th>';
        }
        $out .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $att   = $row['attendance_pct'] ?? null;
            $share = $row['share_pct'] ?? null;
            $min   = $row['minutes'] ?? null;
            $name  = (string) ( $row['name'] ?? '' );
            $jersey = $row['jersey_number'] ?? null;
            if ( $jersey !== null ) $name = '#' . (int) $jersey . ' ' . $name;

            $out .= '<tr>'
                . '<td>' . esc_html( self::cut( $name, $wide ? 34 : 28 ) ) . '</td>'
                . '<td>' . esc_html( self::statusLabel( (string) ( $row['status'] ?? '' ) ) ) . '</td>'
                . '<td class="r">' . esc_html( self::pct( $att ) ) . '</td>'
                . ( $wide ? '<td class="bar">' . self::miniBar( $att ) . '</td>' : '' )
                . '<td class="r">' . esc_html( ( is_int( $min ) || is_float( $min ) ) ? number_format_i18n( (float) $min ) : '—' ) . '</td>'
                . '<td class="r">' . esc_html( self::pct( $share ) ) . '</td>'
                . ( $wide ? '<td class="bar">' . self::miniBar( $share ) . '</td>' : '' )
                . '<td class="r">' . (int) ( $row['open_goals'] ?? 0 ) . '</td>'
                . '<td class="c">' . esc_html( ! empty( $row['injured'] ) ? __( 'Yes', 'talenttrack' ) : '' ) . '</td>'
                . '</tr>';
        }
        return $out . '</tbody></table></div>';
    }

    private static function notes(): string {
        $lines = '';
        for ( $i = 0; $i < 5; $i++ ) $lines .= '<tr><td class="rule"></td></tr>';
        return '<div class="sec">' . self::head( 'notes' ) . '<table class="lines">' . $lines . '</table></div>';
    }

    private static function notesCompact(): string {
        $lines = '';
        for ( $i = 0; $i < 4; $i++ ) $lines .= '<tr><td class="rule"></td></tr>';
        return '<div class="sec">' . self::head( 'notes', true ) . '<table class="lines">' . $lines . '</table></div>';
    }

    /** @param array<string,mixed> $q */
    private static function quality( array $q ): string {
        $lines = [];
        $no_register = is_array( $q['activities_without_register'] ?? null ) ? count( $q['activities_without_register'] ) : 0;
        /* translators: %d: activities without an attendance register */
        if ( $no_register > 0 ) $lines[] = sprintf( _n( '%d completed activity has no attendance register.', '%d completed activities have no attendance register.', $no_register, 'talenttrack' ), $no_register );
        $never_closed = is_array( $q['activities_never_closed'] ?? null ) ? count( $q['activities_never_closed'] ) : 0;
        /* translators: %d: activities whose date has passed and that were never marked completed */
        if ( $never_closed > 0 ) $lines[] = sprintf( _n( '%d activity has passed without being marked completed.', '%d activities have passed without being marked completed.', $never_closed, 'talenttrack' ), $never_closed );
        $no_minutes = (int) ( $q['matches_without_minutes'] ?? 0 );
        /* translators: %d: matches played without minutes recorded */
        if ( $no_minutes > 0 ) $lines[] = sprintf( _n( '%d match played has no minutes recorded.', '%d matches played have no minutes recorded.', $no_minutes, 'talenttrack' ), $no_minutes );
        // #3860 — the printed report carries the same line as the screen:
        // a fixture printed as "Unknown opponent" has to be nameable from
        // the page somebody takes into the meeting.
        $no_opponent = is_array( $q['matches_without_opponent'] ?? null ) ? $q['matches_without_opponent'] : [];
        if ( $no_opponent !== [] ) {
            $fixtures = [];
            foreach ( $no_opponent as $match ) {
                if ( ! is_array( $match ) ) continue;
                $fixtures[] = trim( TTDate::date( (string) ( $match['date'] ?? '' ) ) . ' ' . trim( (string) ( $match['title'] ?? '' ) ) );
            }
            /* translators: 1: number of matches, 2: their dates and titles */
            $lines[] = sprintf(
                _n(
                    '%1$d match has no opponent stored, so it reads as "Unknown opponent" above: %2$s.',
                    '%1$d matches have no opponent stored, so they read as "Unknown opponent" above: %2$s.',
                    count( $fixtures ),
                    'talenttrack'
                ),
                count( $fixtures ),
                implode( '; ', $fixtures )
            );
        }
        $not_eval = is_array( $q['players_not_evaluated'] ?? null ) ? $q['players_not_evaluated'] : [];
        if ( $not_eval !== [] ) {
            $names = array_map( static fn( $p ): string => is_array( $p ) ? (string) ( $p['name'] ?? '' ) : '', $not_eval );
            /* translators: 1: number of players, 2: their names */
            $lines[] = sprintf( _n( '%1$d player has no evaluation this period: %2$s.', '%1$d players have no evaluation this period: %2$s.', count( $names ), 'talenttrack' ), count( $names ), implode( ', ', $names ) );
        }
        $incomplete = is_array( $q['players_with_incomplete_status'] ?? null ) ? count( $q['players_with_incomplete_status'] ) : 0;
        /* translators: %d: players whose status was computed on incomplete evidence */
        if ( $incomplete > 0 ) $lines[] = sprintf( _n( '%d player\'s status was computed on incomplete evidence.', '%d players\' statuses were computed on incomplete evidence.', $incomplete, 'talenttrack' ), $incomplete );

        if ( $lines === [] ) $lines[] = __( 'Nothing missing. Every register, minute and evaluation this report looks for is in.', 'talenttrack' );

        // Exactly `TeamMonthlyReportLayout::qualityLines()` rows, one line each.
        $out = '<div class="sec">' . self::head( 'quality' ) . '<table class="list">';
        foreach ( array_slice( $lines, 0, TeamMonthlyReportLayout::qualityLines( $q ) ) as $line ) {
            $out .= '<tr><td class="wrap">' . esc_html( $line ) . '</td></tr>';
        }
        return $out . '</table></div>';
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    private static function kpiCell( string $label, string $value, string $delta ): string {
        return '<td><div class="kn">' . esc_html( $value ) . '</div><div class="kl">' . esc_html( $label ) . '</div><div class="kd">' . esc_html( $delta ) . '</div></td>';
    }

    /** @param array<string,mixed> $k */
    private static function measureValue( array $k, string $key, string $suffix ): string {
        $m = is_array( $k[ $key ] ?? null ) ? $k[ $key ] : [];
        $v = $m['value'] ?? null;
        if ( ! is_int( $v ) && ! is_float( $v ) ) return '—';
        return number_format_i18n( (float) $v, is_float( $v ) && floor( $v ) != $v ? 1 : 0 ) . $suffix;
    }

    /** @param array<string,mixed> $k */
    private static function measureDelta( array $k, string $key, string $unit ): string {
        $m = is_array( $k[ $key ] ?? null ) ? $k[ $key ] : [];
        return self::deltaText( $m['delta'] ?? null, $unit );
    }

    /** @param mixed $delta */
    private static function deltaText( $delta, string $unit ): string {
        // No preceding period: a dash, never a zero.
        if ( ! is_int( $delta ) && ! is_float( $delta ) ) return '—';
        $sign = $delta > 0 ? '+' : ( $delta < 0 ? '−' : '' );
        $text = $sign . number_format_i18n( abs( (float) $delta ), is_float( $delta ) ? 1 : 0 );
        return $unit !== '' ? $text . ' ' . $unit : $text;
    }

    /** @param mixed $v */
    private static function pct( $v ): string {
        if ( ! is_int( $v ) && ! is_float( $v ) ) return '—';
        return number_format_i18n( (float) $v, is_float( $v ) && floor( $v ) != $v ? 1 : 0 ) . '%';
    }

    /** @param mixed $v */
    private static function miniBar( $v ): string {
        $w = ( is_int( $v ) || is_float( $v ) ) ? max( 0, min( 100, (int) round( (float) $v ) ) ) : 0;
        return '<div class="track"><div class="fill f-green" style="width:' . $w . '%"></div></div>'; /* tt-inline-ok */
    }

    private static function statusLabel( string $color ): string {
        switch ( $color ) {
            case 'green': return _x( 'On track', 'player status, staff report', 'talenttrack' );
            case 'amber': return _x( 'Watch', 'player status, staff report', 'talenttrack' );
            case 'red':   return _x( 'Needs action', 'player status, staff report', 'talenttrack' );
            default:      return _x( 'No read yet', 'player status, staff report', 'talenttrack' );
        }
    }

    private static function shortDate( string $ymd ): string {
        $ts = strtotime( $ymd . ' 12:00:00' );
        return $ts === false ? $ymd : (string) wp_date( 'j M', $ts );
    }

    /** One line, cut with an ellipsis — see the class docblock for why. */
    private static function cut( string $text, int $chars ): string {
        return mb_strlen( $text ) > $chars ? rtrim( mb_substr( $text, 0, $chars - 1 ) ) . '…' : $text;
    }

    /**
     * Roster column widths, mm: content widths, so with each cell's padding
     * they sum to the printable width (273 landscape, 186 portrait).
     */
    private static function columnWidths( bool $landscape ): string {
        $mm  = $landscape ? [ 60, 26, 20, 30, 18, 18, 30, 21, 18 ] : [ 48, 24, 18, 16, 16, 22, 20 ];
        $css = '';
        foreach ( $mm as $i => $w ) $css .= '.tbl .w' . $i . '{width:' . $w . 'mm}';
        return $css;
    }

    /**
     * Tables, floats and inline-block only. Row heights are fixed so the fit
     * estimate holds; see `TeamMonthlyReportLayout::MM`.
     */
    private static function css( string $layout ): string {
        $landscape = $layout === TeamMonthlyReportLayout::MATRIX;
        // CSS row heights; each prints 0.3 mm taller for its border, which the
        // estimate's `roster_row*` constants include.
        $roster_mm = $layout === TeamMonthlyReportLayout::PACK ? '5.2' : '4.4';
        $ink = '#0e1a14'; $muted = '#5b6470'; $line = '#d9dcd6';
        return '@page{margin:12mm}'
            . 'body{font-family:"DejaVu Sans",sans-serif;font-size:8.5pt;color:' . $ink . ';margin:0}'
            . '.footer{position:fixed;bottom:-9mm;left:0;right:0;height:6mm;font-size:6.5pt;color:' . $muted . ';text-align:center}'
            . '.break{page-break-before:always}'
            . '.muted{color:' . $muted . ';font-size:7.5pt}'
            . '.empty{font-size:10pt;margin-top:8mm}'
            . 'table{border-collapse:collapse;width:100%}'
            . '.lh{height:22mm;margin-bottom:2mm;border-bottom:2px solid ' . $ink . '}'
            . '.lh td{vertical-align:bottom;padding:0 0 2mm 0}'
            . '.lh-kicker{font-size:7.5pt;text-transform:uppercase;letter-spacing:1px;color:' . $muted . '}'
            . '.lh-team{font-size:16pt;font-weight:bold}'
            . '.lh-meta{text-align:right}'
            . '.lh-period{font-size:10pt;font-weight:bold}'
            . '.cov{margin-bottom:4mm}.cov td{height:7mm;padding:0 2mm;font-size:7.5pt;vertical-align:middle;white-space:nowrap;overflow:hidden}'
            . '.cov .cov-complete{background:#e3f1e8;border-left:3px solid #1f7a4a}'
            . '.cov .cov-partial{background:#fdf1d8;border-left:3px solid #c88a12}'
            . '.cov .cov-empty{background:#e6eef7;border-left:3px solid #2a6db0}'
            . '.kpi{height:17mm;margin-bottom:4mm;border-spacing:0}'
            . '.kpi td{width:16.66%;border:1px solid ' . $line . ';padding:1.5mm 2mm;vertical-align:top}'
            . '.kn{font-size:13pt;font-weight:bold}.kl{font-size:6.5pt;text-transform:uppercase;color:' . $muted . '}.kd{font-size:6.5pt}'
            . '.sec{margin-bottom:3mm}'
            // #3517 — a snapshot's note, marked off from the figures above it
            // so a reader can tell what the data said from what the meeting
            // said about it.
            . '.note{margin:0 0 3mm;padding:1.5mm 2mm;border-left:2px solid ' . $ink . ';font-size:7.5pt}'
            // #4096 — the section header: number, title and what the section
            // holds, on a band tinted with the club colour and a bar in it.
            . '.tt-mr-sh{margin:0 0 1.5mm;border-spacing:0;background:' . ReportBrandColour::tint( self::$primary ) . '}'
            . '.tt-mr-sh td{height:6.5mm;padding:0 2mm;vertical-align:middle;white-space:nowrap}'
            . '.tt-mr-sh td.tt-mr-sh__no{width:6mm;border-left:1.2mm solid ' . self::$primary . ';font-size:8pt;font-weight:bold;color:' . ReportBrandColour::numberColour( self::$primary ) . '}'
            . '.tt-mr-sh h2{margin:0;padding:0;font-size:11pt;font-weight:bold;color:' . $ink . '}'
            . '.tt-mr-sh td.tt-mr-sh__meta{text-align:right;font-size:7.5pt;color:#3d4a43}'
            . '.tt-mr-sh--compact h2{font-size:9.5pt}'
            . '.band{height:6mm;margin-bottom:1mm}.band td{color:#fff;font-weight:bold;text-align:center;font-size:7.5pt}'
            . '.b-green{background:#1f7a4a}.b-amber{background:#c88a12}.b-red{background:#b3261e}.b-unknown{background:#8a8f96}'
            . '.bars td{height:3.8mm;padding:0;font-size:7.5pt;line-height:1.1;vertical-align:middle}'
            . '.bars .nm{width:' . ( $landscape ? '40' : '38' ) . 'mm;white-space:nowrap;overflow:hidden}.bars .vl{width:14mm;text-align:right}'
            . '.track{height:2.6mm;background:#eceee9}.fill{height:2.6mm;background:#1f7a4a}'
            . '.f-amber{background:#c88a12}.f-red{background:#b3261e}.f-none{background:#8a8f96}'
            . '.elided{color:' . $muted . ';font-style:italic;text-align:center}'
            . '.att td{height:13mm;padding:1.5mm 2mm;border-left:3px solid ' . $line . ';vertical-align:top}'
            . '.att tr + tr td{border-top:1.5mm solid #fff}'
            . '.a-red{border-left-color:#b3261e;background:#fbecea}.a-amber{border-left-color:#c88a12;background:#fdf6e7}'
            . '.att-n{font-weight:bold}.att-w{font-size:7.5pt}'
            . '.list td{height:4.6mm;padding:0;vertical-align:middle;white-space:nowrap;overflow:hidden}'
            // #3970 — written text wraps rather than cuts; the layout estimate
            // counts the lines it wraps to.
            . '.list td.wrap{white-space:normal;vertical-align:top}'
            . '.tbl th{height:5mm;font-size:7pt;text-align:left;border-bottom:1px solid ' . $ink . '}'
            . '.tbl td{height:' . $roster_mm . 'mm;padding:0 1mm;border-bottom:1px solid ' . $line . ';white-space:nowrap;overflow:hidden;vertical-align:middle}'
            . '.tbl .r{text-align:right;padding-right:3mm}.tbl .c{text-align:center}.tbl td.bar{padding-right:4mm}'
            // #4069 — matches: record tiles, results beside ranked scorers.
            . '.mrec{margin:1.5mm 0 3mm;border-spacing:0}'
            . '.mrec td{width:14.28%;height:9mm;border:1px solid ' . $line . ';border-top:2px solid ' . $line . ';padding:1.2mm 1.5mm;vertical-align:top;text-align:center}'
            . '.mrec .n{font-size:13pt;font-weight:bold;line-height:1.15}'
            . '.mrec .l{font-size:6.2pt;text-transform:uppercase;color:' . $muted . ';white-space:nowrap;overflow:hidden}'
            . '.mrec td.t-w{border-top-color:#1f7a4a}.mrec .t-w .n{color:#1f7a4a}'
            . '.mrec td.t-d{border-top-color:#8a8f96}'
            . '.mrec td.t-l{border-top-color:#b3261e}.mrec .t-l .n{color:#b3261e}'
            . '.mrec .n.pos{color:#1f7a4a}.mrec .n.neg{color:#b3261e}'
            . '.cols2{border-spacing:0}.cols2 td.left{width:52%;padding-right:4mm;vertical-align:top}.cols2 td.right{width:48%;vertical-align:top}'
            . '.sub{height:4.5mm;font-size:7pt;font-weight:bold;text-transform:uppercase;color:' . $muted . ';border-bottom:1px solid ' . $ink . '}'
            . '.res td{height:5.2mm;padding:0 1mm;border-bottom:1px solid ' . $line . ';vertical-align:middle;white-space:nowrap;overflow:hidden}'
            . '.res .dt{width:11mm;color:' . $muted . '}.res .ha{width:5mm;text-align:center}.res .sc{width:16mm;text-align:right;padding-right:0}'
            . '.ha-chip{width:3.4mm;margin:0 auto;padding:0.3mm 0;border:1px solid #8a8f96;color:' . $muted . ';font-size:6pt;line-height:1;font-weight:bold;text-align:center}'
            . '.score{display:inline-block;min-width:10mm;padding:0.4mm 1mm;line-height:1.1;text-align:center;font-weight:bold;color:#fff;font-size:7.5pt;vertical-align:middle}'
            . '.s-w{background:#1f7a4a}.s-d{background:#8a8f96}.s-l{background:#b3261e}'
            . '.s-none{background:#fff;color:' . $muted . ';font-weight:normal;font-style:italic;font-size:6.5pt}'
            . '.res tr.sq td{height:auto;padding:0 1mm 0.8mm 17mm;white-space:normal;font-size:6.5pt;color:' . $muted . '}'
            . '.res tr.has-sq td{border-bottom:0}'
            . '.scr th{height:4.5mm;font-size:6.5pt;text-transform:uppercase;color:' . $muted . ';font-weight:bold;text-align:right;border-bottom:1px solid ' . $ink . ';padding:0 1mm}'
            . '.scr th.nm{text-align:left}'
            . '.scr td{height:5.2mm;padding:0 1mm;border-bottom:1px solid ' . $line . ';vertical-align:middle;white-space:nowrap;overflow:hidden}'
            . '.scr .rk{width:4mm;color:' . $muted . ';text-align:right;padding-right:1.5mm}.scr .num{width:7mm;text-align:right}.scr .g{font-weight:bold}.scr .bar{width:15mm;padding-left:2mm}'
            . '.gbar{height:2.4mm;background:#eceee9}.gbar div{height:2.4mm;background:#1f7a4a}.abar div{background:#7fb596}'
            . '.scr tr.top td{background:#f1f7f3}'
            . '.scr tr.tot td{border-bottom:0;border-top:1px solid ' . $ink . ';font-weight:bold;height:5mm}'
            . '.recon{margin-top:1mm}'
            // #4069 — tests: a card per test with a stat strip and the ranked readings.
            . '.tcard{margin-bottom:3mm}'
            . '.thead td{vertical-align:bottom;padding:0 0 1mm;white-space:nowrap}'
            . '.tname{font-size:9.5pt;font-weight:bold}'
            . '.tmeta{text-align:right;color:' . $muted . ';font-size:7.5pt}'
            . '.dirchip{display:inline-block;border:1px solid #8a8f96;padding:0 1.2mm;font-size:6.3pt;color:' . $muted . ';margin-left:1.5mm}'
            . '.tstat{border-spacing:0}.rd{margin-top:2mm}'
            . '.tstat td{height:11mm;border:1px solid ' . $line . ';padding:1.2mm 1.5mm;vertical-align:top;width:20%}'
            . '.tstat .n{font-size:11.5pt;font-weight:bold;line-height:1.15;white-space:nowrap}'
            . '.tstat .l{font-size:6.2pt;text-transform:uppercase;color:' . $muted . ';white-space:nowrap;overflow:hidden}'
            . '.tstat .d{font-size:6.5pt;white-space:nowrap;overflow:hidden}'
            . '.up{color:#1f7a4a}.dn{color:#b3261e}.eq{color:' . $muted . '}'
            . '.bandrow{height:3.2mm;margin-top:1mm}.tstat .bandrow td{height:3.2mm;padding:0;border:0;font-size:6pt;color:#fff;text-align:center;font-weight:bold}'
            . '.bandrow td.fg{background:#1f7a4a}.bandrow td.fa{background:#c88a12}.bandrow td.fr{background:#b3261e}'
            . '.hist{border-spacing:0;margin-top:0.6mm}'
            . '.tstat .hist td{height:auto;border:0;padding:0 0.8mm;width:25%;vertical-align:bottom}'
            . '.tstat .hist tr.hb td{height:9mm}'
            . '.hist .col{background:#b9cfc2}.hist .col.now{background:#1f7a4a}'
            . '.tstat .hist td.hl{font-size:5.8pt;color:' . $muted . ';text-align:center;padding-top:0.4mm}'
            . '.rd th{height:4.5mm;font-size:6.5pt;text-transform:uppercase;color:' . $muted . ';font-weight:bold;text-align:right;border-bottom:1px solid ' . $ink . ';padding:0 1mm;white-space:nowrap}'
            . '.rd th.nm{text-align:left}'
            . '.rd td{height:4.6mm;padding:0 1mm;border-bottom:1px solid ' . $line . ';vertical-align:middle;white-space:nowrap;overflow:hidden;font-size:7.5pt}'
            . '.rd .rk{width:5mm;text-align:right;color:' . $muted . ';padding-right:1.5mm}'
            . '.rd .v{width:21mm;text-align:right;font-weight:bold}.rd .pv{width:15mm;text-align:right;color:' . $muted . '}'
            . '.rd .ch{width:14mm;text-align:right}.rd .av{width:15mm;text-align:right;color:' . $muted . '}.rd .bb{width:28mm;padding-left:2mm}'
            // #4093 — the target in the test's header, and each reading's
            // standing against it in the profile's words and colours.
            . '.tgt{display:inline-block;margin-left:1.5mm;padding:0.3mm 1.6mm;font-size:7pt;font-weight:bold;color:#fff;background:'
            . ( ReportBrandColour::contrast( self::$primary, '#ffffff' ) >= 4.5 ? self::$primary : ReportBrandColour::INK ) . '}'
            . '.tgt-none{color:' . $muted . ';background:#fff;border:1px solid #8a8f96;font-weight:normal}'
            . '.rd th.st,.rd td.st{width:24mm;text-align:left;padding-left:2mm}'
            . '.vc{display:inline-block;padding:0.2mm 1mm;border:1px solid;font-size:6.2pt;line-height:1.1;font-weight:bold}'
            . '.vc-ok{color:#1f6d41;border-color:#1f6d41;background:#dff5e1}.vc-warn{color:#8a5300;border-color:#c88a12;background:#fdf3d8}'
            . '.vc-bad{color:#a32b22;border-color:#b3261e;background:#fde2e2}.vc-none{color:' . $muted . ';border-color:#8a8f96;background:#f4f6f3}'
            . '.pb{display:inline-block;font-size:5.8pt;line-height:1.1;font-weight:bold;color:#1f7a4a;border:1px solid #1f7a4a;padding:0.2mm 0.8mm;margin-left:1mm;vertical-align:middle}'
            . '.tb{height:2.6mm;background:#eceee9}.tb div{height:2.6mm}'
            . '.tb.fg div{background:#1f7a4a}.tb.fa div{background:#c88a12}.tb.fr div{background:#b3261e}.tb.fn div{background:#8a8f96}'
            . '.dot{display:inline-block;width:2mm;height:2mm;margin-right:1mm}.dot.fg{background:#1f7a4a}.dot.fa{background:#c88a12}.dot.fr{background:#b3261e}'
            . '.rd tr.avgline td{height:0;padding:0;border-bottom:1px dashed ' . $ink . '}'
            . self::columnWidths( $landscape )
            . '.lines td.rule{height:7mm;border-bottom:1px solid ' . $line . '}'
            . '.cols{border-spacing:0}.cols td.strip{width:33.3%;vertical-align:top;padding-right:4mm}';
    }
}
