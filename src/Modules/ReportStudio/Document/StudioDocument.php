<?php
namespace TT\Modules\ReportStudio\Document;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\PageBreakBlock;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\RendersDocument;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Shared\Frontend\Components\StackTable;

/**
 * StudioDocument (#5021, epic #4564) — a Report Studio report in Rows as one
 * document: its blocks in order, two half blocks side by side, and its pages
 * where the page estimate breaks them.
 *
 * ## One document, three places
 *
 * The reader's view, the canvas's A4 view and the PDF all show `body()`. The
 * PDF wraps it in a page with the stylesheet (`html()`); the screen gets the
 * same stylesheet from the desktop up (`screenCss()`) and stacks the same
 * markup on a phone from an enqueued sheet. The JSON `data` route stays the
 * contract for any other front end; this renders what that route returns.
 *
 * A block prints from its payload. One that is a section of another report
 * prints with that report's renderer (`RendersDocument`); every other block
 * prints here, by the shape of its payload: a figure, a table, or one of the
 * layout blocks.
 *
 * ## Built for DomPDF
 *
 * Tables, floats and inline-block only: DomPDF prints flex and grid as plain
 * blocks. A pair of half blocks is a two-cell table, and a data table is on
 * the stack-table primitive, so a phone reads it as labelled cards.
 */
final class StudioDocument {

    /** The most blocks one document prints: what one definition can hold. */
    public const MAX_BLOCKS = ReportDefinition::MAX_BLOCKS;

    /**
     * The document's markup, for every place it is shown.
     *
     * @param array<string,mixed> $doc a `ReportData::compose()` answer, or a
     *        snapshot's frozen copy of one: `context`, `blocks`, `page_fit`.
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     *        a snapshot's notes, keyed by block id; empty for a live report.
     */
    public static function body( array $doc, int $user_id, array $notes = [] ): string {
        $context = self::keyed( $doc['context'] ?? null );
        $fit     = self::keyed( $doc['page_fit'] ?? null );
        $blocks  = self::blocks( $doc );
        $breaks  = array_map( [ self::class, 'text' ], is_array( $fit['breaks'] ?? null ) ? $fit['breaks'] : [] );
        $words   = new DocumentWords( $context, $user_id );

        $pages = [ '' ];
        $count = count( $blocks );
        for ( $i = 0; $i < $count; $i++ ) {
            $block = $blocks[ $i ];
            if ( $block['key'] === PageBreakBlock::KEY ) continue;

            $next = $blocks[ $i + 1 ] ?? null;
            $pair = $block['width'] === ReportDefinition::WIDTH_HALF;
            $row  = $pair && $next !== null && $next['key'] !== PageBreakBlock::KEY && $next['width'] === ReportDefinition::WIDTH_HALF
                ? [ $block, $next ]
                : [ $block ];

            if ( in_array( $block['id'], $breaks, true ) && $pages[ count( $pages ) - 1 ] !== '' ) $pages[] = '';
            $pages[ count( $pages ) - 1 ] .= $pair ? self::pair( $row, $words, $notes ) : self::block( $block, $words, $notes );
            if ( count( $row ) === 2 ) $i++;
        }

        $out = '';
        foreach ( $pages as $n => $page ) {
            if ( $n > 0 && $page === '' ) continue;
            $out .= '<div class="tt-sd-page' . ( $n > 0 ? ' tt-sd-break' : '' ) . '">' . $page . '</div>';
        }
        if ( $blocks === [] ) {
            $out = '<div class="tt-sd-page"><p class="tt-sd-msg">' . esc_html__( 'This report has no blocks yet.', 'talenttrack' ) . '</p></div>';
        }

        $family = ( $context['audience'] ?? '' ) === PlayerReportAudience::FAMILY;
        return '<div class="tt-sd">' . $out . '<p class="tt-sd-foot">' . esc_html( PlayerReportAudience::footerText( $family ) ) . '</p></div>';
    }

    /**
     * The printable document, for DomPDF: the same body, in a page that
     * carries its own stylesheet, because DomPDF reads no enqueued one.
     *
     * @param array<string,mixed> $doc
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    public static function html( array $doc, int $user_id, string $title, array $notes = [] ): string {
        $css = '@page{margin:12mm}'
            . 'body{font-family:"DejaVu Sans",Verdana,sans-serif;font-size:9pt;color:#0e1a14;margin:0}'
            . self::rules()
            . TeamMonthlyReportDocument::scopedCss( TeamMonthlyReportLayout::PACK );

        return '<!doctype html><html><head><meta charset="UTF-8"><title>' . esc_html( $title ) . '</title>'
            . '<style>' . $css . '</style></head><body>' /* tt-inline-ok */
            . self::body( $doc, $user_id, $notes )
            . '</body></html>';
    }

    /**
     * The document's stylesheet for the browser, from the tablet up, where
     * the screen shows the paper. Below that the enqueued sheet stacks the
     * same markup. One PHP source of the sizes feeds paper and screen.
     */
    public static function screenCss(): string {
        return '@media (min-width: 768px){' . self::rules() . '}';
    }

    /**
     * The blocks to print, at most `MAX_BLOCKS`, each with an id, a key and
     * a width.
     *
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    private static function blocks( array $doc ): array {
        $out = [];
        foreach ( is_array( $doc['blocks'] ?? null ) ? $doc['blocks'] : [] as $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['key'] ?? null ) ) continue;
            $keyed          = self::keyed( $block );
            $keyed['id']    = is_string( $keyed['id'] ?? null ) ? (string) $keyed['id'] : 'b' . ( count( $out ) + 1 );
            $keyed['width'] = ( $keyed['width'] ?? '' ) === ReportDefinition::WIDTH_HALF ? ReportDefinition::WIDTH_HALF : ReportDefinition::WIDTH_FULL;
            $out[] = $keyed;
            if ( count( $out ) >= self::MAX_BLOCKS ) break;
        }
        return $out;
    }

    /**
     * Two half blocks side by side, or one half block beside an empty cell.
     *
     * @param list<array<string,mixed>> $row
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    private static function pair( array $row, DocumentWords $words, array $notes ): string {
        $cells = '';
        foreach ( $row as $block ) $cells .= '<td class="tt-sd-half">' . self::block( $block, $words, $notes ) . '</td>';
        if ( count( $row ) === 1 ) $cells .= '<td class="tt-sd-half tt-sd-half--empty"></td>';
        return '<table class="tt-sd-pair"><tr>' . $cells . '</tr></table>';
    }

    /**
     * One block: a marker a test can find, its heading unless it is its own,
     * its body, and a snapshot's note on it.
     *
     * @param array<string,mixed> $block
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    private static function block( array $block, DocumentWords $words, array $notes ): string {
        $id       = self::text( $block['id'] ?? '' );
        $key      = self::text( $block['key'] ?? '' );
        $renderer = BlockRegistry::find( $key );
        $data     = self::keyed( $block['data'] ?? null );
        $error    = is_array( $block['error'] ?? null ) ? self::keyed( $block['error'] ) : null;

        $out = '<!--tt-sd-block:' . esc_html( $id ) . '-->';
        if ( $error === null && $renderer instanceof RendersDocument ) {
            $out .= $renderer->document( $data );
        } else {
            if ( $error !== null || ! in_array( $key, self::UNTITLED, true ) ) {
                $out .= '<h2 class="tt-sd-title">' . esc_html( $renderer !== null ? $renderer->label() : _x( 'Block', 'report studio', 'talenttrack' ) ) . '</h2>';
            }
            $out .= $error !== null ? self::refusal( $error ) : self::content( $key, $block, $data, $words );
        }

        $note = $notes[ $id ] ?? null;
        if ( $note !== null && trim( $note['body'] ) !== '' ) {
            $out .= '<div class="tt-sd-note">' . nl2br( esc_html( $note['body'] ) ) . '</div>';
        }
        return '<div class="tt-sd-block" id="tt-sd-' . esc_attr( $id ) . '">' . $out . '</div>';
    }

    /** Blocks that are their own heading, so no block name prints above them. */
    private const UNTITLED = [ LetterheadBlock::KEY, 'heading', 'text', PageBreakBlock::KEY ];

    /** @param array<string,mixed> $error */
    private static function refusal( array $error ): string {
        // A field the block names is gone (#4860): the server's words, to
        // everyone. Any other refusal reads the same for every reason.
        $text = ( $error['code'] ?? '' ) === 'unknown_dimension' && self::text( $error['message'] ?? '' ) !== ''
            ? self::text( $error['message'] ?? '' )
            : __( 'Not available to you.', 'talenttrack' );
        return '<p class="tt-sd-msg tt-sd-msg--off">' . esc_html( $text ) . '</p>';
    }

    /**
     * @param array<string,mixed> $block
     * @param array<string,mixed> $data
     */
    private static function content( string $key, array $block, array $data, DocumentWords $words ): string {
        switch ( $key ) {
            case LetterheadBlock::KEY: return self::letterhead( $data, $words );
            case 'heading':
            case 'text':
                $heading = self::text( $data['heading'] ?? '' );
                $text    = $key === 'text' ? self::text( $data['text'] ?? '' ) : '';
                return ( $heading !== '' ? '<h2 class="tt-sd-heading">' . esc_html( $heading ) . '</h2>' : '' )
                    . ( $text !== '' ? '<p class="tt-sd-text">' . esc_html( $text ) . '</p>' : '' );
        }

        if ( array_key_exists( 'value', $data ) && ! is_array( $data['rows'] ?? null ) ) {
            $value = $data['value'] === null ? '–' : self::text( $data['value'] ) . ( ( $data['unit'] ?? '' ) === 'percent' ? '%' : '' );
            return '<p class="tt-sd-figure"><span class="tt-sd-figure-value">' . esc_html( $value ) . '</span> '
                . '<span class="tt-sd-figure-label">' . esc_html( self::text( $data['measure_label'] ?? '' ) ) . '</span></p>'
                . self::target( $data );
        }

        $rows = is_array( $data['rows'] ?? null ) ? array_values( $data['rows'] ) : [];
        if ( $rows === [] ) {
            // A flags block with nobody flagged is an answer, not missing data.
            $none = isset( $data['threshold'] )
                /* translators: 1: first day, 2: last day of the period */
                ? __( 'No player was flagged on attendance between %1$s and %2$s.', 'talenttrack' )
                /* translators: 1: first day, 2: last day of the period */
                : __( 'Nothing recorded between %1$s and %2$s.', 'talenttrack' );
            return '<p class="tt-sd-msg">' . esc_html( sprintf( $none, $words->day( $words->from() ), $words->day( $words->to() ) ) ) . '</p>'
                . self::attendanceNotes( $data );
        }

        if ( $key === 'progress_radar' ) return self::progress( $data, $rows, $words );

        $first = self::keyed( $rows[0] );
        $cols  = array_values( array_filter( array_keys( $first ), static fn( string $c ): bool => $c !== 'player_id' && $c !== 'key' ) );
        $head  = [];
        foreach ( $cols as $c ) $head[ $c ] = $words->column( $c, $key, $block, $data );

        $out = '<table class="tt-sd-table ' . esc_attr( StackTable::classes( StackTable::TO_768 ) ) . '"' . StackTable::attrs( 'table' ) . '>'
            . '<thead' . StackTable::attrs( 'rowgroup' ) . '><tr' . StackTable::attrs( 'row' ) . '>';
        foreach ( $cols as $c ) $out .= '<th' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $head[ $c ] ) . '</th>';
        $out .= '</tr></thead><tbody' . StackTable::attrs( 'rowgroup' ) . '>';
        foreach ( $rows as $row ) {
            $row  = self::keyed( $row );
            $out .= '<tr' . StackTable::attrs( 'row' ) . '>';
            foreach ( $cols as $c ) $out .= '<td' . StackTable::attrs( 'cell', $head[ $c ] ) . '>' . esc_html( self::cell( $c, $row[ $c ] ?? null, $words ) ) . '</td>';
            $out .= '</tr>';
        }
        $out .= '</tbody></table>';

        if ( ! empty( $data['truncated'] ) ) $out .= '<p class="tt-sd-msg">' . esc_html__( 'Only the first rows are shown.', 'talenttrack' ) . '</p>';
        return $out . self::target( $data ) . self::attendanceNotes( $data ) . self::ratingNotes( $data ) . self::matchNotes( $data );
    }

    /** @param array<string,mixed> $data */
    private static function letterhead( array $data, DocumentWords $words ): string {
        $title = self::text( $data['name'] ?? '' );
        if ( $title === '' ) $title = self::text( $data['team_name'] ?? '' );
        $meta  = array_filter( [
            ( $data['subject'] ?? '' ) === 'player' ? self::text( $data['team_name'] ?? '' ) : '',
            self::text( $data['age_group'] ?? '' ),
            self::text( $data['head_coach'] ?? '' ),
        ], static fn( string $s ): bool => $s !== '' );

        $out = '<table class="tt-sd-lh"><tr><td><h2 class="tt-sd-lh-title">' . esc_html( $title ) . '</h2>';
        if ( $meta !== [] ) $out .= '<p class="tt-sd-lh-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        if ( is_string( $data['from'] ?? null ) && is_string( $data['to'] ?? null ) && $data['from'] !== '' && $data['to'] !== '' ) {
            /* translators: 1: first day, 2: last day of the period */
            $out .= '<p class="tt-sd-lh-meta">' . esc_html( sprintf( __( '%1$s to %2$s', 'talenttrack' ), $words->day( $data['from'] ), $words->day( $data['to'] ) ) ) . '</p>';
        }
        return $out . '</td></tr></table>';
    }

    /**
     * The progress block: one row per evaluation, one column per main
     * category, a dash where a category was not rated.
     *
     * @param array<string,mixed> $data
     * @param list<mixed>         $rows
     */
    private static function progress( array $data, array $rows, DocumentWords $words ): string {
        $cats  = array_map( [ self::class, 'text' ], is_array( $data['categories'] ?? null ) ? array_values( $data['categories'] ) : [] );
        $first = $words->column( 'eval_date', 'progress_radar', [], $data );

        $out = '<table class="tt-sd-table ' . esc_attr( StackTable::classes( StackTable::TO_768 ) ) . '"' . StackTable::attrs( 'table' ) . '>'
            . '<thead' . StackTable::attrs( 'rowgroup' ) . '><tr' . StackTable::attrs( 'row' ) . '><th' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $first ) . '</th>';
        foreach ( $cats as $c ) $out .= '<th' . StackTable::attrs( 'columnheader' ) . '>' . esc_html( $c ) . '</th>';
        $out .= '</tr></thead><tbody' . StackTable::attrs( 'rowgroup' ) . '>';
        foreach ( $rows as $row ) {
            $row    = self::keyed( $row );
            $values = is_array( $row['values'] ?? null ) ? array_values( $row['values'] ) : [];
            $out   .= '<tr' . StackTable::attrs( 'row' ) . '><td' . StackTable::attrs( 'cell', $first ) . '>' . esc_html( $words->day( $row['eval_date'] ?? '' ) ) . '</td>';
            foreach ( $cats as $i => $c ) {
                $v    = $values[ $i ] ?? null;
                $out .= '<td' . StackTable::attrs( 'cell', $c ) . '>' . esc_html( $v === null ? '–' : self::text( $v ) ) . '</td>';
            }
            $out .= '</tr>';
        }
        return $out . '</tbody></table>';
    }

    /** A cell's text: a date column reads as a date. */
    private static function cell( string $column, mixed $value, DocumentWords $words ): string {
        if ( $value === null ) return '–';
        if ( preg_match( '/(^|_)date$|^window_(start|end)$/', $column ) ) return $value === '' ? '–' : $words->day( $value );
        return self::text( $value );
    }

    /** @param array<string,mixed> $data */
    private static function target( array $data ): string {
        if ( ! isset( $data['target_pct'] ) ) return '';
        /* translators: %s: the academy's minimum share of the minutes, in percent */
        return '<p class="tt-sd-msg">' . esc_html( sprintf( __( 'Target: at least %s%% of the minutes available.', 'talenttrack' ), self::text( $data['target_pct'] ) ) ) . '</p>';
    }

    /** @param array<string,mixed> $data */
    private static function attendanceNotes( array $data ): string {
        $out = '';
        if ( isset( $data['team_avg_pct'] ) ) {
            /* translators: %s: the squad's attendance, in percent */
            $out .= '<p class="tt-sd-msg">' . esc_html( sprintf( __( 'Squad attendance: %s%%.', 'talenttrack' ), self::text( $data['team_avg_pct'] ) ) ) . '</p>';
        }
        if ( isset( $data['threshold'] ) ) {
            /* translators: 1: missed activities that flag a player, 2: late arrivals that flag a player */
            $out .= '<p class="tt-sd-msg">' . esc_html( sprintf( __( 'Flagged at %1$s missed activities or %2$s late arrivals.', 'talenttrack' ), self::text( $data['threshold'] ), self::text( $data['late_threshold'] ?? '' ) ) ) . '</p>';
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function ratingNotes( array $data ): string {
        $out = '';
        if ( isset( $data['squad_avg'] ) ) {
            /* translators: %s: the squad's average rating */
            $out .= '<p class="tt-sd-msg">' . esc_html( sprintf( __( 'Squad rating: %s.', 'talenttrack' ), self::text( $data['squad_avg'] ) ) ) . '</p>';
        }
        if ( isset( $data['average'] ) && ! empty( $data['evaluation_count'] ) ) {
            /* translators: 1: the player's average rating, 2: number of evaluations */
            $out .= '<p class="tt-sd-msg">' . esc_html( sprintf( __( 'Average over all ratings: %1$s, from %2$s evaluations.', 'talenttrack' ), self::text( $data['average'] ), self::text( $data['evaluation_count'] ) ) ) . '</p>';
        }
        return $out;
    }

    /**
     * The record under the results, and the tournaments left out.
     *
     * @param array<string,mixed> $data
     */
    private static function matchNotes( array $data ): string {
        $out    = '';
        $record = self::keyed( $data['record'] ?? null );
        if ( $record !== [] ) {
            $gd   = (int) ( $record['goal_difference'] ?? 0 );
            $out .= '<p class="tt-sd-msg">' . esc_html( sprintf(
                /* translators: 1: played, 2: won, 3: drawn, 4: lost, 5: goals for, 6: goals against, 7: signed goal difference */
                __( 'Played %1$d · W %2$d D %3$d L %4$d · %5$d–%6$d (%7$s)', 'talenttrack' ),
                (int) ( $record['played'] ?? 0 ),
                (int) ( $record['won'] ?? 0 ),
                (int) ( $record['drawn'] ?? 0 ),
                (int) ( $record['lost'] ?? 0 ),
                (int) ( $record['goals_for'] ?? 0 ),
                (int) ( $record['goals_against'] ?? 0 ),
                ( $gd > 0 ? '+' : '' ) . $gd
            ) ) . '</p>';
        }
        $note = self::text( $data['note'] ?? '' );
        if ( $note !== '' ) $out .= '<p class="tt-sd-msg">' . esc_html( $note ) . '</p>';
        return $out;
    }

    /**
     * A stored or decoded value as an array keyed by strings; anything else
     * as an empty one.
     *
     * @return array<string,mixed>
     */
    private static function keyed( mixed $value ): array {
        $out = [];
        foreach ( is_array( $value ) ? $value : [] as $k => $v ) $out[ (string) $k ] = $v;
        return $out;
    }

    /** A payload value as text: a list reads as its items. */
    public static function text( mixed $value ): string {
        if ( is_array( $value ) ) return implode( ', ', array_map( [ self::class, 'text' ], $value ) );
        if ( is_bool( $value ) ) return $value ? '1' : '';
        return is_scalar( $value ) ? (string) $value : '';
    }

    /**
     * The document's own rules, scoped under its wrapper. Tables, fixed
     * margins and no flex or grid, so DomPDF and the browser lay it out alike.
     */
    private static function rules(): string {
        $ink     = '#0e1a14';
        $muted   = '#5b6470';
        $line    = '#d9dcd6';
        $primary = ReportBrandColour::primary();
        $tint    = ReportBrandColour::tint( $primary );
        $number  = ReportBrandColour::numberColour( $primary );

        return '.tt-sd{color:' . $ink . '}'
            . '.tt-sd .tt-sd-break{page-break-before:always}'
            . '.tt-sd .tt-sd-block{margin:0 0 4mm}'
            . '.tt-sd .tt-sd-pair{width:100%;border-collapse:separate;border-spacing:0;margin:0}'
            . '.tt-sd .tt-sd-pair>tbody>tr>td,.tt-sd .tt-sd-pair>tr>td{width:50%;vertical-align:top;padding:0 2mm 0 0}'
            . '.tt-sd .tt-sd-pair td.tt-sd-half+td.tt-sd-half{padding:0 0 0 2mm}'
            . '.tt-sd .tt-sd-title{margin:0 0 1.5mm;padding:1mm 2mm;font-size:11pt;font-weight:bold;background:' . $tint . ';border-left:1.2mm solid ' . $primary . '}'
            . '.tt-sd .tt-sd-heading{margin:0 0 1.5mm;font-size:13pt;font-weight:bold}'
            . '.tt-sd .tt-sd-text{margin:0 0 2mm;line-height:1.4}'
            . '.tt-sd .tt-sd-lh{width:100%;border-collapse:collapse;margin:0 0 2mm;border-bottom:2px solid ' . $primary . '}'
            . '.tt-sd .tt-sd-lh td{padding:0 0 2mm 0;vertical-align:bottom}'
            . '.tt-sd .tt-sd-lh-title{margin:0;font-size:16pt;font-weight:bold;color:' . $number . '}'
            . '.tt-sd .tt-sd-lh-meta{margin:1mm 0 0;font-size:8.5pt;color:' . $muted . '}'
            . '.tt-sd .tt-sd-figure{margin:0 0 1.5mm}'
            . '.tt-sd .tt-sd-figure-value{font-size:16pt;font-weight:bold}'
            . '.tt-sd .tt-sd-figure-label{font-size:8pt;color:' . $muted . '}'
            . '.tt-sd .tt-sd-table{width:100%;border-collapse:collapse;margin:0 0 1.5mm}'
            . '.tt-sd .tt-sd-table th{padding:1mm 1.5mm;font-size:7.5pt;text-align:left;border-bottom:1px solid ' . $ink . '}'
            . '.tt-sd .tt-sd-table td{padding:1mm 1.5mm;font-size:8pt;border-bottom:1px solid ' . $line . '}'
            . '.tt-sd .tt-sd-msg{margin:0 0 1mm;font-size:8pt;color:' . $muted . '}'
            . '.tt-sd .tt-sd-msg--off{font-style:italic}'
            . '.tt-sd .tt-sd-note{margin:1.5mm 0 0;padding:1.5mm 2mm;border-left:2px solid ' . $ink . ';font-size:8pt}'
            . '.tt-sd .tt-sd-foot{margin:4mm 0 0;font-size:6.5pt;color:' . $muted . ';text-align:center}';
    }
}
