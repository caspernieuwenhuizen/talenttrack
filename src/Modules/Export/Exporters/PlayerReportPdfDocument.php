<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Shared\Dates\TTDate;

/**
 * PlayerReportPdfDocument (#3874, epic #3871) — the player report as printable
 * HTML for DomPDF.
 *
 * The rules `TeamMonthlyReportPdfDocument` settled, for the same reasons:
 *
 * - **Tables only.** DomPDF implements CSS 2.1; flexbox and grid print as one
 *   collapsed column. A test greps the output for both.
 * - **Fixed row heights, and written text in full.** `PlayerReportLayout::fit()`
 *   predicts pages and the panel shows that prediction. Names, dates and labels
 *   keep one line each. Written text (notes, journey entries, talking points,
 *   agreed actions) prints whole and wraps, because a sentence cut off on paper
 *   cannot be finished; the estimate counts the lines it wraps to, per column,
 *   from characters per line measured on DomPDF.
 * - **No photo.** The renderer fetches no remote asset, by design.
 *
 * Pure rendering: every figure arrives in the composer's payload.
 */
final class PlayerReportPdfDocument {

    /**
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     *        already shortened by `PlayerReportLayout::degrade()`.
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     *        a snapshot's section notes, printed under their section (#3890).
     */
    public static function html( array $report, array $notes = [] ): string {
        return self::wrap( self::body( $report, $notes ) );
    }

    /**
     * Several players in one document, each starting on a new page — the
     * team-batched schedule's round (#3891). One stylesheet and one footer,
     * so the file is a single printable pack rather than documents glued
     * together.
     *
     * @param list<array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string}> $reports
     *        each already shortened by `PlayerReportLayout::degrade()`.
     */
    public static function batchHtml( array $reports ): string {
        $bodies = [];
        foreach ( $reports as $i => $report ) {
            $bodies[] = '<div class="player' . ( $i > 0 ? ' break' : '' ) . '">' . self::body( $report, [] ) . '</div>';
        }
        return self::wrap( implode( '', $bodies ) );
    }

    /**
     * The report as an HTML fragment carrying its own styles — for a page that
     * already has a shell, such as the scout's one-time link page (#3876),
     * which stores the rendered report and echoes it inside its own body.
     *
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     */
    public static function fragment( array $report ): string {
        return '<div class="tt-pr-doc"><style>' . self::css() . '</style>' . self::body( $report, [] ) . '</div>'; /* tt-inline-ok */
    }

    private static function wrap( string $body ): string {
        // DomPDF reads no enqueued stylesheet; the document carries its own.
        return '<!doctype html><html><head><meta charset="UTF-8"><style>' . self::css() . '</style></head><body>' /* tt-inline-ok */
            . '<div class="footer">' . esc_html__( 'Confidential — staff only. This report describes a minor\'s development. Do not share it with the player, their parents or anyone outside the coaching staff.', 'talenttrack' ) . '</div>'
            . $body
            . '</body></html>';
    }

    /**
     * @param array{data:array<string,array<string,mixed>>, blocks:list<string>, from:string, to:string} $report
     * @param array<string,array{body:string, author:int, updated_at:string}>                            $notes
     */
    private static function body( array $report, array $notes ): string {
        $data = $report['data'];
        $body = self::letterhead( $data['letterhead'] ?? [], $report['from'], $report['to'] );

        // Attendance and playing time are two short strips of figures that a
        // coach reads together; side by side they cost one strip of paper.
        $paired = in_array( PlayerReportBlock::ATTENDANCE, $report['blocks'], true )
            && in_array( PlayerReportBlock::MINUTES, $report['blocks'], true );

        foreach ( $report['blocks'] as $block ) {
            if ( $block === PlayerReportBlock::LETTERHEAD ) continue;
            if ( $paired && $block === PlayerReportBlock::MINUTES ) continue;
            if ( $paired && $block === PlayerReportBlock::ATTENDANCE ) {
                $body .= '<table class="pair"><tr>'
                    . '<td class="half">' . self::attendance( $data[ PlayerReportBlock::ATTENDANCE ] ?? [] ) . '</td>'
                    . '<td class="half">' . self::minutes( $data[ PlayerReportBlock::MINUTES ] ?? [] ) . '</td>'
                    . '</tr></table>'
                    . self::note( $notes, PlayerReportBlock::ATTENDANCE ) . self::note( $notes, PlayerReportBlock::MINUTES );
                continue;
            }
            $body .= self::section( $block, $data[ $block ] ?? [] ) . self::note( $notes, $block );
        }

        return $body;
    }

    /** @param array<string,mixed> $d */
    private static function section( string $block, array $d ): string {
        switch ( $block ) {
            case PlayerReportBlock::STATUS:         return self::status( $d );
            case PlayerReportBlock::TALKING_POINTS: return self::talkingPoints( $d );
            case PlayerReportBlock::RATINGS:        return self::ratings( $d );
            case PlayerReportBlock::ATTENDANCE:     return self::attendance( $d );
            case PlayerReportBlock::MINUTES:        return self::minutes( $d );
            case PlayerReportBlock::GOALS:          return self::goals( $d );
            case PlayerReportBlock::PDP:            return self::pdp( $d );
            case PlayerReportBlock::NOTES:          return self::notes( $d );
            case PlayerReportBlock::MATCHES:        return self::matches( $d );
            case PlayerReportBlock::TESTS:          return self::tests( $d );
            case PlayerReportBlock::JOURNEY:        return self::journey( $d );
            case PlayerReportBlock::INJURIES:       return self::injuries( $d );
            case PlayerReportBlock::BEHAVIOUR:      return self::behaviour( $d );
            case PlayerReportBlock::POTENTIAL:      return self::potential( $d );
            case PlayerReportBlock::THREAD_NOTES:   return self::threadNotes( $d );
        }
        return '';
    }

    /* ---------------------------------------------------------------
     * Blocks
     * ------------------------------------------------------------- */

    /** @param array<string,mixed> $h */
    private static function letterhead( array $h, string $from, string $to ): string {
        $from_ts = strtotime( $from . ' 12:00:00' );
        $to_ts   = strtotime( $to . ' 12:00:00' );
        // Month names through wp_date(), so they print in the site's language.
        $period = ( $from_ts !== false && $to_ts !== false )
            ? (string) wp_date( 'j F Y', $from_ts ) . ' – ' . (string) wp_date( 'j F Y', $to_ts )
            : $from . ' – ' . $to;

        // The team on its own line: a club name and an age group can fill it,
        // and sharing it cut the shirt number and year of birth off the end.
        $team      = (string) ( $h['team_name'] ?? '' );
        $team_line = '';
        if ( $team !== '' ) {
            $age       = (string) ( $h['age_group'] ?? '' );
            $team_line = $age !== '' ? $team . ' (' . LookupTranslator::byTypeAndName( 'age_group', $age ) . ')' : $team;
        }

        $meta  = [];
        $coach = (string) ( $h['head_coach'] ?? '' );
        /* translators: %s: head coach's name */
        if ( $coach !== '' ) $meta[] = sprintf( __( 'Head coach %s', 'talenttrack' ), $coach );
        $jersey = $h['jersey_number'] ?? null;
        /* translators: %d: shirt number */
        if ( is_int( $jersey ) ) $meta[] = sprintf( __( 'No. %d', 'talenttrack' ), $jersey );
        $born = $h['birth_year'] ?? null;
        /* translators: %d: year of birth */
        if ( is_int( $born ) ) $meta[] = sprintf( __( 'Born %d', 'talenttrack' ), $born );

        return '<table class="lh"><tr>'
            . '<td class="lh-title"><div class="lh-kicker">' . esc_html__( 'Player report', 'talenttrack' ) . '</div>'
            . '<div class="lh-name">' . esc_html( self::cut( (string) ( $h['name'] ?? '' ), 40 ) ) . '</div></td>'
            . '<td class="lh-meta"><div class="lh-period">' . esc_html( $period ) . '</div>'
            . ( $team_line !== '' ? '<div>' . esc_html( self::cut( $team_line, 70 ) ) . '</div>' : '' )
            . '<div>' . esc_html( self::cut( implode( ' · ', $meta ), 70 ) ) . '</div>'
            /* translators: %s: date and time the report was generated */
            . '<div class="muted">' . esc_html( sprintf( __( 'Generated %s', 'talenttrack' ), (string) wp_date( 'j F Y H:i' ) ) ) . '</div></td>'
            . '</tr></table>';
    }

    /** @param array<string,mixed> $s */
    private static function status( array $s ): string {
        $color = (string) ( $s['color'] ?? 'unknown' );
        $rows  = [ '<span class="pill p-' . esc_attr( $color ) . '">' . esc_html( self::statusLabel( $color ) ) . '</span>' ];

        $missing = is_array( $s['missing_inputs'] ?? null ) ? $s['missing_inputs'] : [];
        if ( $missing !== [] ) {
            $labels = array_map( static fn( $k ): string => \TT\Infrastructure\PlayerStatus\StatusVerdict::inputLabel( (string) $k ), $missing );
            $rows[] = '<span class="muted">' . esc_html( self::cut( sprintf(
                /* translators: %s: comma-separated list of missing inputs */
                __( 'Computed without %s.', 'talenttrack' ),
                implode( ', ', $labels )
            ), 110 ) ) . '</span>';
        }
        return self::open( _x( 'Status', 'player report section', 'talenttrack' ) ) . self::lines( $rows ) . '</div>';
    }

    /** @param array<string,mixed> $t */
    private static function talkingPoints( array $t ): string {
        $items = is_array( $t['items'] ?? null ) ? $t['items'] : [];
        $out   = self::open( _x( 'Talking points', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'Nothing in the data asks to be raised this period.', 'talenttrack' ) ] ) . '</div>';
        }
        $out .= '<table class="pts">';
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $level = (string) ( $item['level'] ?? 'amber' );
            $out  .= '<tr><td class="wrap pt-' . esc_attr( $level ) . '">'
                . '<div class="pt-t">' . esc_html( (string) ( $item['text'] ?? '' ) ) . '</div>'
                . '<div class="pt-e">' . esc_html( (string) ( $item['evidence'] ?? '' ) ) . '</div>'
                . '</td></tr>';
        }
        return $out . '</table>' . self::shortened( $t, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $r */
    private static function ratings( array $r ): string {
        $evals = is_array( $r['evaluations'] ?? null ) ? $r['evaluations'] : [];
        $out   = self::open( _x( 'Evaluations', 'player report section', 'talenttrack' ) );
        if ( (int) ( $r['evaluation_count'] ?? 0 ) === 0 || $evals === [] ) {
            return $out . self::lines( [ esc_html__( 'No evaluations in this window.', 'talenttrack' ) ] ) . '</div>';
        }

        $out .= self::stats( [
            __( 'Evaluations', 'talenttrack' )                     => number_format_i18n( (int) ( $r['evaluation_count'] ?? 0 ) ),
            _x( 'Latest', 'player report rating', 'talenttrack' )  => self::rating( $r['latest'] ?? null ),
            _x( 'Average', 'player report rating', 'talenttrack' ) => self::rating( $r['average'] ?? null ),
        ] );

        $cats = is_array( $r['categories'] ?? null ) ? $r['categories'] : [];
        if ( $cats !== [] ) {
            $rows = [];
            foreach ( $cats as $c ) {
                if ( ! is_array( $c ) ) continue;
                $rows[] = [ self::cut( (string) ( $c['label'] ?? '' ), 40 ), self::rating( $c['latest'] ?? null ), self::rating( $c['average'] ?? null ) ];
            }
            $out .= self::table(
                [ __( 'Category', 'talenttrack' ), _x( 'Latest', 'player report rating', 'talenttrack' ), _x( 'Average', 'player report rating', 'talenttrack' ) ],
                $rows,
                [ 'w-wide', 'w-num', 'w-num' ]
            );
        }

        $rows = [];
        foreach ( $evals as $e ) {
            if ( ! is_array( $e ) ) continue;
            $rows[] = [
                TTDate::date( (string) ( $e['eval_date'] ?? '' ) ),
                self::rating( $e['rating'] ?? null ),
                self::cut( (string) ( $e['assessor_name'] ?? '' ), 22 ),
                trim( wp_strip_all_tags( (string) ( $e['notes'] ?? '' ) ) ),
            ];
        }
        $out .= self::table(
            [ __( 'Date', 'talenttrack' ), __( 'Rating', 'talenttrack' ), __( 'Assessor', 'talenttrack' ), __( 'Notes', 'talenttrack' ) ],
            $rows,
            [ 'w-date', 'w-num', 'w-name', 'w-rest' ],
            3
        );
        return $out . self::shortened( $r, 'evaluations' ) . '</div>';
    }

    /** @param array<string,mixed> $a */
    private static function attendance( array $a ): string {
        $out        = self::open( _x( 'Attendance', 'player report section', 'talenttrack' ) );
        $activities = (int) ( $a['activities'] ?? 0 );
        if ( $activities === 0 ) {
            return $out . self::lines( [ esc_html__( 'No training or matches recorded in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rate    = $a['rate'] ?? null;
        $present = (int) ( $a['present'] ?? 0 );
        return $out . self::stats( [
            __( 'Activities', 'talenttrack' ) => (string) $activities,
            __( 'Present', 'talenttrack' )    => $rate === null
                ? (string) $present
                /* translators: 1: present count, 2: attendance percentage */
                : sprintf( __( '%1$d (%2$d%%)', 'talenttrack' ), $present, (int) $rate ),
            __( 'Absent', 'talenttrack' )     => (string) (int) ( $a['absent'] ?? 0 ),
            __( 'Excused', 'talenttrack' )    => (string) (int) ( $a['excused'] ?? 0 ),
        ] ) . '</div>';
    }

    /** @param array<string,mixed> $m */
    private static function minutes( array $m ): string {
        $out = self::open( _x( 'Playing time', 'player report section', 'talenttrack' ) );
        if ( (int) ( $m['apps'] ?? 0 ) === 0 && (int) ( $m['minutes'] ?? 0 ) === 0 ) {
            return $out . self::lines( [ esc_html__( 'No per-match minutes recorded in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        return $out . self::stats( [
            __( 'Matches played', 'talenttrack' ) => (string) (int) ( $m['apps'] ?? 0 ),
            __( 'Minutes played', 'talenttrack' ) => number_format_i18n( (int) ( $m['minutes'] ?? 0 ) ),
        ] ) . '</div>';
    }

    /** @param array<string,mixed> $g */
    private static function goals( array $g ): string {
        $items = is_array( $g['items'] ?? null ) ? $g['items'] : [];
        $out   = self::open( _x( 'Goals', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No goals open or closed in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $goal ) {
            if ( ! is_array( $goal ) ) continue;
            $movement = ! empty( $goal['changed_in_window'] )
                ? ( ! empty( $goal['created_in_window'] ) ? __( 'Set in this window', 'talenttrack' ) : __( 'Moved in this window', 'talenttrack' ) )
                : __( 'No movement in this window', 'talenttrack' );
            $due = (string) ( $goal['due_date'] ?? '' );
            $rows[] = [
                self::cut( (string) ( $goal['title'] ?? '' ), 48 ),
                self::cut( LookupTranslator::byTypeAndName( 'goal_status', (string) ( $goal['status'] ?? '' ) ), 18 ),
                self::cut( $movement, 26 ),
                $due !== '' ? TTDate::date( $due ) : '—',
            ];
        }
        return $out . self::table(
            [ _x( 'Goal', 'player report goals column', 'talenttrack' ), __( 'Status', 'talenttrack' ), _x( 'Movement', 'player report goals column', 'talenttrack' ), _x( 'Due', 'player report goals column', 'talenttrack' ) ],
            $rows,
            [ 'w-wide', 'w-name', 'w-name', 'w-date' ]
        ) . self::shortened( $g, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $p */
    private static function pdp( array $p ): string {
        $out = self::open( _x( 'Development plan', 'player report section', 'talenttrack' ) );
        if ( empty( $p['available'] ) ) {
            return $out . self::lines( [ esc_html__( 'The development plan is not available to you, or is switched off for your academy.', 'talenttrack' ) ] ) . '</div>';
        }
        if ( ! is_array( $p['file'] ?? null ) ) {
            return $out . self::lines( [ esc_html__( 'This player has no development plan file yet.', 'talenttrack' ) ] ) . '</div>';
        }

        $convs = is_array( $p['conversations'] ?? null ) ? $p['conversations'] : [];
        $lines = [];
        if ( $convs !== [] && ! empty( $p['compact'] ) ) {
            // The shortened one-pager says where the cycle stands in a line
            // rather than listing every conversation.
            $lines[] = esc_html( self::cycleLine( $convs ) );
        } elseif ( $convs !== [] ) {
            $rows = [];
            foreach ( $convs as $c ) {
                if ( ! is_array( $c ) ) continue;
                $held   = (string) ( $c['conducted_at'] ?? '' );
                $rows[] = [
                    (string) (int) ( $c['sequence'] ?? 0 ),
                    TTDate::date( (string) ( $c['scheduled_at'] ?? '' ) ),
                    $held !== '' ? TTDate::date( $held ) : '—',
                    ! empty( $c['signed_off'] ) ? __( 'Yes', 'talenttrack' ) : '—',
                ];
            }
            $out .= self::table(
                [
                    _x( 'Conversation', 'player report pdp column', 'talenttrack' ),
                    _x( 'Planned', 'player report pdp column', 'talenttrack' ),
                    _x( 'Held', 'player report pdp column', 'talenttrack' ),
                    _x( 'Signed off', 'player report pdp column', 'talenttrack' ),
                ],
                $rows,
                [ 'w-name', 'w-date', 'w-date', 'w-rest' ]
            );
        }

        $actions = trim( wp_strip_all_tags( (string) ( $p['last_agreed_actions'] ?? '' ) ) );
        if ( $actions !== '' ) {
            $lines[] = '<span class="muted">' . esc_html__( 'Agreed at the last conversation:', 'talenttrack' ) . '</span>';
            $lines[] = esc_html( $actions );
        }
        $verdict = is_array( $p['verdict'] ?? null ) ? $p['verdict'] : null;
        if ( $verdict !== null ) {
            $lines[] = esc_html( sprintf(
                /* translators: %s: end-of-season verdict, e.g. "Renew" */
                __( 'End-of-season verdict: %s', 'talenttrack' ),
                (string) ( $verdict['label'] ?? '' )
            ) );
        }
        return $out . ( $lines !== [] ? self::lines( $lines, true ) : '' ) . '</div>';
    }

    /** @param array<string,mixed> $d */
    private static function notes( array $d ): string {
        $rules = '';
        $count = max( 1, (int) ( $d['lines'] ?? 6 ) );
        for ( $i = 0; $i < $count; $i++ ) $rules .= '<tr><td class="rule"></td></tr>';
        return self::open( _x( 'Notes', 'player report section', 'talenttrack' ) ) . '<table class="rules">' . $rules . '</table></div>';
    }

    /** @param array<string,mixed> $m */
    private static function matches( array $m ): string {
        $items = is_array( $m['items'] ?? null ) ? $m['items'] : [];
        $out   = self::open( _x( 'Match by match', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No per-match minutes recorded in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $match ) {
            if ( ! is_array( $match ) ) continue;
            $title  = (string) ( $match['title'] ?? '' );
            $rows[] = [
                TTDate::date( (string) ( $match['session_date'] ?? '' ) ),
                self::cut( $title !== '' ? $title : __( 'Match', 'talenttrack' ), 70 ),
                (string) (int) ( $match['minutes'] ?? 0 ),
            ];
        }
        return $out . self::table( [ __( 'Date', 'talenttrack' ), __( 'Match', 'talenttrack' ), __( 'Minutes', 'talenttrack' ) ], $rows, [ 'w-date', 'w-rest', 'w-num' ] )
            . self::shortened( $m, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $t */
    private static function tests( array $t ): string {
        $items = is_array( $t['items'] ?? null ) ? $t['items'] : [];
        $out   = self::open( _x( 'Tests', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No tests taken this period.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $value = $row['value'] ?? null;
            $text  = $row['text'] ?? null;
            $unit  = (string) ( $row['unit'] ?? '' );
            $shown = is_int( $value ) || is_float( $value )
                ? trim( number_format_i18n( (float) $value, floor( (float) $value ) == $value ? 0 : 2 ) . ' ' . $unit )
                : ( is_string( $text ) && $text !== '' ? $text : '—' );
            // The score in words: on paper a colour alone is not an answer.
            $score = \TT\Modules\Measurements\Repositories\MeasurementTargetsRepository::flagLabel( (string) ( $row['score'] ?? '' ) );
            if ( $score === '' && (string) ( $row['level_token'] ?? '' ) !== '' && is_string( $text ) ) {
                $score = $text;
            }
            $rows[] = [
                self::cut( (string) ( $row['name'] ?? '' ), 22 ),
                self::cut( $shown, 14 ),
                self::cut( $score !== '' ? $score : '—', 20 ),
                TTDate::date( (string) ( $row['date'] ?? '' ) ),
                self::cut( self::testChange( $row ), 30 ),
            ];
        }
        return $out . self::table(
            [ _x( 'Test', 'player report tests column', 'talenttrack' ), _x( 'Result', 'monthly report tests column', 'talenttrack' ), _x( 'Score', 'player report tests column', 'talenttrack' ), __( 'Date', 'talenttrack' ), _x( 'Change', 'monthly report tests column', 'talenttrack' ) ],
            $rows,
            [ 'w-name', 'w-date', 'w-name', 'w-date', 'w-rest' ]
        ) . self::shortened( $t, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $j */
    private static function journey( array $j ): string {
        $items = is_array( $j['items'] ?? null ) ? $j['items'] : [];
        $out   = self::open( _x( 'Journey', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'Nothing recorded on the journey in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $e ) {
            if ( ! is_array( $e ) ) continue;
            $rows[] = [ TTDate::date( (string) ( $e['date'] ?? '' ) ), \TT\Modules\Analytics\Reports\PlayerReport::journeyPrintText( $e ) ];
        }
        return $out . self::table( [], $rows, [ 'w-date', 'w-rest' ], 1 ) . self::shortened( $j, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $i */
    private static function injuries( array $i ): string {
        $items = is_array( $i['items'] ?? null ) ? $i['items'] : [];
        $out   = self::open( _x( 'Injuries', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No injuries in this window, or none you have access to.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $injury ) {
            if ( ! is_array( $injury ) ) continue;
            $rows[] = [
                TTDate::date( (string) ( $injury['started_on'] ?? '' ) ),
                ! empty( $injury['is_open'] )
                    ? __( 'Still out', 'talenttrack' )
                    /* translators: %s = return-to-play date */
                    : sprintf( __( 'Back on %s', 'talenttrack' ), TTDate::date( (string) ( $injury['actual_return'] ?? '' ) ) ),
                trim( wp_strip_all_tags( (string) ( $injury['notes'] ?? '' ) ) ),
            ];
        }
        return $out . self::table( [ __( 'Date', 'talenttrack' ), __( 'Status', 'talenttrack' ), __( 'Notes', 'talenttrack' ) ], $rows, [ 'w-date', 'w-name', 'w-rest' ], 2 )
            . self::shortened( $i, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $b */
    private static function behaviour( array $b ): string {
        $items = is_array( $b['items'] ?? null ) ? $b['items'] : [];
        $out   = self::open( _x( 'Behaviour', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No behaviour ratings in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $rows[] = [
                TTDate::date( substr( (string) ( $row['rated_at'] ?? '' ), 0, 10 ) ),
                number_format_i18n( (float) ( $row['rating'] ?? 0 ), 1 ),
                trim( wp_strip_all_tags( (string) ( $row['notes'] ?? '' ) ) ),
            ];
        }
        return $out . self::table( [ __( 'Date', 'talenttrack' ), __( 'Rating', 'talenttrack' ), __( 'Notes', 'talenttrack' ) ], $rows, [ 'w-date', 'w-num', 'w-rest' ], 2 )
            . self::shortened( $b, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $p */
    private static function potential( array $p ): string {
        $items = is_array( $p['items'] ?? null ) ? $p['items'] : [];
        $out   = self::open( _x( 'Potential', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No potential set in this window.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $row ) {
            if ( ! is_array( $row ) ) continue;
            $rows[] = [
                TTDate::date( substr( (string) ( $row['set_at'] ?? '' ), 0, 10 ) ),
                self::cut( LookupTranslator::byTypeAndName( 'potential_band', (string) ( $row['potential_band'] ?? '' ) ), 60 ),
            ];
        }
        return $out . self::table( [ __( 'Date', 'talenttrack' ), _x( 'Potential', 'player report section', 'talenttrack' ) ], $rows, [ 'w-date', 'w-rest' ] )
            . self::shortened( $p, 'items' ) . '</div>';
    }

    /** @param array<string,mixed> $n */
    private static function threadNotes( array $n ): string {
        $items = is_array( $n['items'] ?? null ) ? $n['items'] : [];
        $out   = self::open( _x( 'Staff notes', 'player report section', 'talenttrack' ) );
        if ( $items === [] ) {
            return $out . self::lines( [ esc_html__( 'No staff notes in this window, or none you have access to.', 'talenttrack' ) ] ) . '</div>';
        }
        $rows = [];
        foreach ( $items as $note ) {
            if ( ! is_array( $note ) ) continue;
            $rows[] = [
                TTDate::date( substr( (string) ( $note['created_at'] ?? '' ), 0, 10 ) ),
                self::cut( (string) ( $note['author_name'] ?? '' ), 20 ),
                trim( wp_strip_all_tags( (string) ( $note['body'] ?? '' ) ) ),
            ];
        }
        return $out . self::table( [], $rows, [ 'w-date', 'w-name', 'w-rest' ], 2 ) . self::shortened( $n, 'items' ) . '</div>';
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    /**
     * A snapshot's note on one section, marked off from the figures above it
     * so a reader can tell what the data said from what the conversation said.
     *
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    private static function note( array $notes, string $block ): string {
        $note = $notes[ $block ] ?? null;
        if ( $note === null || trim( $note['body'] ) === '' ) return '';
        return '<div class="note">' . nl2br( esc_html( $note['body'] ) ) . '</div>';
    }

    private static function open( string $title ): string {
        return '<div class="sec"><div class="h">' . esc_html( $title ) . '</div>';
    }

    /**
     * One-line rows, each already escaped by the caller.
     *
     * @param list<string> $html_rows
     */
    private static function lines( array $html_rows, bool $wrap = false ): string {
        $out = '<table class="ln">';
        foreach ( $html_rows as $row ) $out .= '<tr><td' . ( $wrap ? ' class="wrap"' : '' ) . '>' . $row . '</td></tr>';
        return $out . '</table>';
    }

    /**
     * A strip of figures, one cell each.
     *
     * @param array<string,string> $figures label => value
     */
    private static function stats( array $figures ): string {
        $out = '<table class="kpi"><tr>';
        foreach ( $figures as $label => $value ) {
            $out .= '<td><div class="kn">' . esc_html( $value ) . '</div><div class="kl">' . esc_html( (string) $label ) . '</div></td>';
        }
        return $out . '</tr></table>';
    }

    /**
     * @param list<string>       $head  column titles; empty prints no header row
     * @param list<list<string>> $rows  plain text, escaped here
     * @param list<string>       $width a width class per column
     */
    private static function table( array $head, array $rows, array $width, ?int $wrap = null ): string {
        $out = '<table class="tbl">';
        if ( $head !== [] ) {
            $out .= '<tr>';
            foreach ( $head as $i => $title ) $out .= '<th class="' . esc_attr( $width[ $i ] ?? '' ) . '">' . esc_html( $title ) . '</th>';
            $out .= '</tr>';
        }
        foreach ( $rows as $row ) {
            $out .= '<tr>';
            foreach ( $row as $i => $cell ) {
                $class = trim( ( $width[ $i ] ?? '' ) . ( $i === $wrap ? ' wrap' : '' ) );
                $out  .= '<td class="' . esc_attr( $class ) . '">' . esc_html( $cell ) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</table>';
    }

    /**
     * "… and 4 more in the period" under a list the one-pager shortened, so
     * the printed copy never looks more complete than it is.
     *
     * @param array<string,mixed> $d
     */
    private static function shortened( array $d, string $key ): string {
        $n = (int) ( is_array( $d['shortened'] ?? null ) ? ( $d['shortened'][ $key ] ?? 0 ) : 0 );
        if ( $n <= 0 ) return '';
        return self::lines( [ '<span class="muted">' . esc_html( sprintf(
            /* translators: %d: entries left off the printed one-pager */
            _n( '… and %d more in this period, on screen and in the two-page pack.', '… and %d more in this period, on screen and in the two-page pack.', $n, 'talenttrack' ),
            $n
        ) ) . '</span>' ] );
    }

    /**
     * "2 of 4 conversations held · next planned 12 November 2026".
     *
     * @param array<int|string,mixed> $convs
     */
    private static function cycleLine( array $convs ): string {
        $held = 0;
        $next = '';
        foreach ( $convs as $c ) {
            if ( ! is_array( $c ) ) continue;
            if ( (string) ( $c['conducted_at'] ?? '' ) !== '' ) {
                $held++;
            } elseif ( $next === '' ) {
                $next = (string) ( $c['scheduled_at'] ?? '' );
            }
        }
        $line = sprintf(
            /* translators: 1: conversations held, 2: conversations in the cycle */
            __( '%1$d of %2$d conversations held', 'talenttrack' ),
            $held,
            count( $convs )
        );
        if ( $next !== '' ) {
            /* translators: %s: date of the next planned conversation */
            $line .= ' · ' . sprintf( __( 'next planned %s', 'talenttrack' ), TTDate::date( $next ) );
        }
        return $line;
    }

    /** @param array<string,mixed> $row */
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

    /** @param mixed $v */
    private static function rating( $v ): string {
        return is_int( $v ) || is_float( $v ) ? number_format_i18n( (float) $v, 1 ) : '—';
    }

    private static function statusLabel( string $color ): string {
        switch ( $color ) {
            case 'green': return _x( 'On track', 'player status, staff report', 'talenttrack' );
            case 'amber': return _x( 'Watch', 'player status, staff report', 'talenttrack' );
            case 'red':   return _x( 'Needs action', 'player status, staff report', 'talenttrack' );
            default:      return _x( 'No read yet', 'player status, staff report', 'talenttrack' );
        }
    }

    private static function cut( string $text, int $chars ): string {
        return mb_strlen( $text ) > $chars ? rtrim( mb_substr( $text, 0, $chars - 1 ) ) . '…' : $text;
    }

    /**
     * Tables, floats and inline-block only. Row heights are fixed so the fit
     * estimate holds; see `PlayerReportLayout::MM`. Colour values rather than
     * tokens: DomPDF resolves no custom properties.
     */
    private static function css(): string {
        $ink = '#0e1a14'; $muted = '#5b6470'; $line = '#d9dcd6';
        return '@page{margin:12mm}'
            . 'body{font-family:"DejaVu Sans",sans-serif;font-size:8.5pt;color:' . $ink . ';margin:0}'
            . '.footer{position:fixed;bottom:-9mm;left:0;right:0;height:6mm;font-size:6.5pt;color:' . $muted . ';text-align:center}'
            . '.muted{color:' . $muted . '}'
            . 'table{border-collapse:collapse;width:100%}'
            . '.break{page-break-before:always}'
            . '.lh{height:22mm;margin-bottom:2mm;border-bottom:2px solid ' . $ink . '}'
            . '.lh td{vertical-align:bottom;padding:0 0 2mm 0}'
            . '.lh-kicker{font-size:7.5pt;text-transform:uppercase;letter-spacing:1px;color:' . $muted . '}'
            . '.lh-name{font-size:16pt;font-weight:bold}'
            . '.lh-meta{text-align:right}'
            . '.lh-period{font-size:10pt;font-weight:bold}'
            . '.sec{margin-bottom:3mm}'
            . '.h{height:5mm;font-size:9.5pt;font-weight:bold;border-bottom:1px solid ' . $line . ';margin-bottom:1mm}'
            . '.ln td{height:4.6mm;padding:0;vertical-align:middle;white-space:nowrap;overflow:hidden}'
            . '.pill{font-weight:bold}.p-green{color:#1f7a4a}.p-amber{color:#9a6a0a}.p-red{color:#b3261e}.p-unknown{color:' . $muted . '}'
            . '.pts td{height:9.8mm;padding:0.8mm 2mm;border-left:3px solid ' . $line . ';vertical-align:top;white-space:nowrap;overflow:hidden}'
            . '.pts tr + tr td{border-top:1.5mm solid #fff}'
            . '.pt-red{border-left-color:#b3261e;background:#fbecea}.pt-amber{border-left-color:#c88a12;background:#fdf6e7}.pt-info{border-left-color:#2a6db0;background:#e6eef7}'
            . '.pt-t{font-weight:bold}.pt-e{font-size:7.5pt;color:' . $muted . '}'
            . '.kpi{height:11mm;margin-bottom:3mm;border-spacing:0}'
            . '.kpi td{border:1px solid ' . $line . ';padding:1.2mm 2mm;vertical-align:top}'
            . '.kn{font-size:12pt;font-weight:bold}.kl{font-size:6.5pt;text-transform:uppercase;color:' . $muted . '}'
            . '.tbl th{height:5mm;font-size:7pt;text-align:left;border-bottom:1px solid ' . $ink . ';padding:0 1mm}'
            . '.tbl td{height:4.7mm;padding:0 1mm;border-bottom:1px solid ' . $line . ';white-space:nowrap;overflow:hidden;vertical-align:middle}'
            // Written text wraps rather than cuts: an ellipsis on paper is a
            // sentence nobody can finish. A one-line row keeps the fixed row
            // height; each further line costs what `PlayerReportLayout` counts.
            . '.tbl td.wrap,.ln td.wrap,.pts td.wrap{white-space:normal;vertical-align:top}'
            . '.w-date{width:26mm}.w-num{width:16mm}.w-name{width:34mm}.w-wide{width:62mm}'
            . '.tbl + .tbl{margin-top:2.5mm}'
            . '.rules td.rule{height:7mm;border-bottom:1px solid ' . $line . '}'
            . '.note{margin:0 0 3mm;padding:1.5mm 2mm;border-left:2px solid ' . $ink . ';font-size:7.5pt}'
            . '.pair{border-spacing:0}.pair td.half{width:50%;vertical-align:top;padding:0}.pair td.half + td.half{padding-left:4mm}';
    }
}
