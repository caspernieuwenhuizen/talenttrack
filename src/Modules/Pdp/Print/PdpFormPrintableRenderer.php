<?php
namespace TT\Modules\Pdp\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\Forms\PdpFormAccess;
use TT\Modules\Pdp\Forms\PdpFormReader;
use TT\Modules\Pdp\Forms\PdpFormRegistry;
use TT\Shared\Dates\TTDate;

/**
 * PdpFormPrintableRenderer (#4982, epic #4975) — a signed PDP conversation
 * form as the academy's A4 form: header, the scale, every item with the
 * player's (S) and the trainer's (T) score, the notes, the strongest quality,
 * the two development points, and who confirmed it when.
 *
 * One renderer for the print route and the PDF exporter, so paper and PDF
 * cannot drift. What prints is `PdpFormReader`'s answer for the reader, so
 * the printout follows `PdpFormAccess` exactly as the screens do: a reader
 * the screen refuses is refused here, and a part the reader may not read is
 * not printed. Tables only, no flex or grid, because DomPDF lays out neither.
 */
final class PdpFormPrintableRenderer {

    public const OK        = 'ok';
    public const NOT_FOUND = 'not_found';
    public const REFUSED   = 'refused';
    public const NO_FORM   = 'no_form';
    public const UNSIGNED  = 'unsigned';

    /**
     * The printable body for a conversation, as this user may read it.
     *
     * `status` says what the caller does: print `body` (OK, or UNSIGNED with
     * its one-line notice and no form draft), fall back to the file print
     * (NO_FORM), or refuse (NOT_FOUND, REFUSED).
     *
     * @return array{status:string, body:string, title:string, file_id:int}
     */
    public static function forConversation( int $conversation_id, int $user_id ): array {
        $access = PdpFormAccess::forConversation( $user_id, $conversation_id );
        if ( $access === null ) return self::result( self::NOT_FOUND );
        if ( ! $access->canRead() ) return self::result( self::REFUSED );

        $conv    = (array) $access->conversation();
        $file_id = (int) ( $conv['pdp_file_id'] ?? 0 );
        $form    = ( new PdpFormReader() )->read( $access, $access->conversation() );
        if ( $form['form'] === null || (string) ( $conv['form_key'] ?? '' ) === '' ) {
            return self::result( self::NO_FORM, '', '', $file_id );
        }

        $player = QueryHelpers::get_player( $access->playerId() );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '';
        $title  = sprintf(
            /* translators: %s: the player's name */
            __( 'Development talk — %s', 'talenttrack' ),
            $name
        );

        if ( empty( $conv['coach_signoff_at'] ) ) {
            // An unfinished talk prints no part of its form.
            $body = '<div class="tt-pdpform-print"><h1>' . esc_html( $title ) . '</h1><p class="tt-pdpform-print__notice">'
                . esc_html__( 'This conversation has not been signed off yet. Its form prints once the trainer has signed it off.', 'talenttrack' )
                . '</p></div>';
            return self::result( self::UNSIGNED, $body, $title, $file_id );
        }

        return self::result( self::OK, self::body( $form, $conv, $player, $name, $title ), $title, $file_id );
    }

    /**
     * @param array<string,mixed> $form   PdpFormReader::read()
     * @param array<string,mixed> $conv   the conversation row
     */
    private static function body( array $form, array $conv, ?object $player, string $name, string $title ): string {
        $definition = (array) $form['form']['definition'];
        $p_part     = is_array( $form['player'] ) ? $form['player'] : null;
        $c_part     = is_array( $form['coach'] ) ? $form['coach'] : null;
        $prepared   = ( $form['state']['player_prepared_at'] ?? null ) !== null && $p_part !== null;
        $not_filled = __( 'not filled in', 'talenttrack' );

        $prow    = $player ? (array) $player : [];
        $team_id = (int) ( $prow['team_id'] ?? 0 );
        $team    = $team_id > 0 ? (array) QueryHelpers::get_team( $team_id ) : [];
        $trainer = self::trainerName( (int) $conv['id'] );
        $when    = (string) ( $conv['conducted_at'] ?? '' ) !== '' ? (string) $conv['conducted_at'] : (string) ( $conv['scheduled_at'] ?? '' );

        $scale = [];
        for ( $i = 1; $i <= 5; $i++ ) $scale[ $i ] = (string) ( $definition['scale'][ $i ]['label'] ?? '' );

        ob_start();
        echo '<div class="tt-pdpform-print">';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<table class="tt-pdpform-print__head"><tr>'
            . '<td><span>' . esc_html__( 'Player', 'talenttrack' ) . '</span>' . esc_html( $name ) . '</td>'
            . '<td><span>' . esc_html__( 'Date', 'talenttrack' ) . '</span>' . esc_html( $when !== '' ? TTDate::date( get_date_from_gmt( $when ) ) : '—' ) . '</td>'
            . '<td><span>' . esc_html__( 'Team', 'talenttrack' ) . '</span>' . esc_html( (string) ( $team['name'] ?? '' ) !== '' ? (string) $team['name'] : '—' ) . '</td>'
            . '<td><span>' . esc_html__( 'Trainer', 'talenttrack' ) . '</span>' . esc_html( $trainer !== '' ? $trainer : '—' ) . '</td>'
            . '</tr></table>';

        echo '<table class="tt-pdpform-print__scale"><tr>';
        foreach ( $scale as $n => $label ) {
            echo '<td><strong>' . (int) $n . '</strong> ' . esc_html( $label ) . '</td>';
        }
        echo '</tr></table>';
        echo '<p class="tt-pdpform-print__key">' . esc_html__( 'S = the player\'s own score, T = the trainer\'s score.', 'talenttrack' ) . '</p>';

        foreach ( (array) $definition['sections'] as $section ) {
            echo '<table class="tt-pdpform-print__items"><thead><tr>'
                . '<th>' . esc_html( (string) $section['title'] ) . '</th>'
                . '<th class="tt-pdpform-print__s">' . esc_html_x( 'S', 'PDP form print: the player column', 'talenttrack' ) . '</th>'
                . ( $c_part !== null ? '<th class="tt-pdpform-print__t">' . esc_html_x( 'T', 'PDP form print: the trainer column', 'talenttrack' ) . '</th>' : '' )
                . '</tr></thead><tbody>';
            foreach ( (array) $section['items'] as $item ) {
                $key = (string) $item['key'];
                $s   = $prepared ? ( $p_part['scores'][ $key ] ?? null ) : null;
                $t   = $c_part !== null ? ( $c_part['scores'][ $key ] ?? null ) : null;
                echo '<tr><td>' . esc_html( (string) $item['label'] ) . '</td>'
                    . '<td class="tt-pdpform-print__s">' . ( $s === null ? '<em>' . esc_html( $not_filled ) . '</em>' : (int) $s ) . '</td>'
                    . ( $c_part !== null ? '<td class="tt-pdpform-print__t">' . ( $t === null ? '—' : (int) $t ) . '</td>' : '' )
                    . '</tr>';
            }
            echo '</tbody></table>';
            $note = $c_part !== null ? trim( (string) ( $c_part['answers'][ 'notes.' . (string) $section['key'] ] ?? '' ) ) : '';
            if ( $note !== '' ) {
                echo '<p class="tt-pdpform-print__note"><strong>' . esc_html__( 'Trainer\'s notes:', 'talenttrack' ) . '</strong> ' . esc_html( $note ) . '</p>';
            }
        }

        $prompts = [];
        foreach ( (array) $definition['prompts'] as $prompt ) $prompts[ (string) $prompt['key'] ] = (string) $prompt['label'];
        foreach ( PdpFormRegistry::sharedFields( $definition ) as $field ) {
            $wrote  = $prepared ? trim( (string) ( $p_part['answers'][ $field ] ?? '' ) ) : '';
            $agreed = $c_part !== null ? trim( (string) ( $c_part['answers'][ $field ] ?? '' ) ) : '';
            echo '<h2>' . esc_html( $prompts[ $field ] ?? $field ) . '</h2>';
            echo '<table class="tt-pdpform-print__pair"><tr><th>' . esc_html__( 'The player wrote', 'talenttrack' ) . '</th><td>'
                . ( $wrote === '' ? '<em>' . esc_html( $not_filled ) . '</em>' : esc_html( $wrote ) ) . '</td></tr>';
            if ( $c_part !== null ) {
                echo '<tr><th>' . esc_html__( 'Agreed', 'talenttrack' ) . '</th><td>' . esc_html( $agreed !== '' ? $agreed : '—' ) . '</td></tr>';
            }
            echo '</table>';
        }

        $goals = is_array( $form['goals'] ) ? $form['goals'] : [];
        if ( $goals !== [] ) {
            echo '<h2>' . esc_html__( 'Development points', 'talenttrack' ) . '</h2>';
            echo '<table class="tt-pdpform-print__goals"><thead><tr><th></th>'
                . '<th>' . esc_html__( 'What the player does', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'How the trainer helps', 'talenttrack' ) . '</th>'
                . '<th>' . esc_html__( 'Deadline', 'talenttrack' ) . '</th></tr></thead><tbody>';
            foreach ( $goals as $goal ) {
                echo '<tr><td>' . (int) $goal['slot'] . '</td>'
                    . '<td>' . esc_html( (string) $goal['action'] ) . '</td>'
                    . '<td>' . esc_html( (string) $goal['support'] ) . '</td>'
                    . '<td>' . esc_html( ! empty( $goal['due_date'] ) ? TTDate::date( (string) $goal['due_date'] ) : '—' ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $pending = __( 'not yet confirmed', 'talenttrack' );
        $stamp   = static function ( $at ) use ( $pending ): string {
            return empty( $at ) ? $pending : TTDate::dateTimeFromGmt( (string) $at );
        };
        echo '<h2>' . esc_html_x( 'Confirmed', 'PDP form print: heading over who confirmed the talk when', 'talenttrack' ) . '</h2>';
        echo '<table class="tt-pdpform-print__confirm"><tr>'
            . '<td><span>' . esc_html__( 'Player', 'talenttrack' ) . '</span>' . esc_html( $name ) . '<br />' . esc_html( $stamp( $conv['player_ack_at'] ?? null ) ) . '</td>'
            . '<td><span>' . esc_html__( 'Trainer', 'talenttrack' ) . '</span>' . esc_html( $trainer !== '' ? $trainer : '—' ) . '<br />' . esc_html( $stamp( $conv['coach_signoff_at'] ?? null ) ) . '</td>'
            . '<td><span>' . esc_html__( 'Parent', 'talenttrack' ) . '</span>' . esc_html( self::parentNames( (int) ( $prow['id'] ?? 0 ) ) ) . '<br />' . esc_html( $stamp( $conv['parent_ack_at'] ?? null ) ) . '</td>'
            . '</tr></table>';
        echo '</div>';
        return (string) ob_get_clean();
    }

    /** Who signed it off: the author of the evaluation sign-off wrote. */
    private static function trainerName( int $conversation_id ): string {
        global $wpdb;
        $coach = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT coach_id FROM {$wpdb->prefix}tt_evaluations WHERE pdp_conversation_id = %d AND club_id = %d LIMIT 1",
            $conversation_id, CurrentClub::id()
        ) );
        $user = $coach > 0 ? get_userdata( $coach ) : false;
        return $user ? (string) $user->display_name : '';
    }

    /** The player's linked parents, by name, or a dash. */
    private static function parentNames( int $player_id ): string {
        if ( $player_id <= 0 ) return '—';
        $names = [];
        foreach ( \TT\Infrastructure\Players\ParentChildResolver::guardiansOf( $player_id, true ) as $uid ) {
            $user = get_userdata( (int) $uid );
            if ( $user ) $names[] = (string) $user->display_name;
        }
        return $names === [] ? '—' : implode( ', ', $names );
    }

    /**
     * The form's CSS, scoped to its wrapper, for the standalone print
     * document and the PDF. Tables and blocks only, for DomPDF.
     */
    public static function styleBlock(): string {
        return <<<CSS
        @page { size: A4; margin: 12mm; }
        .tt-pdpform-print { font-family: "DejaVu Sans", -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; color: #1a1d21; font-size: 8.5pt; line-height: 1.25; }
        .tt-pdpform-print h1 { font-size: 13pt; margin: 0 0 2mm; }
        .tt-pdpform-print h2 { font-size: 9.5pt; margin: 2.5mm 0 1mm; }
        .tt-pdpform-print table { width: 100%; border-collapse: collapse; margin: 0 0 1.5mm; }
        .tt-pdpform-print th, .tt-pdpform-print td { padding: 0.4mm 1.5mm; font-size: 8pt; line-height: 1.2; text-align: left; vertical-align: top; border-bottom: 0.2mm solid #d6dadd; }
        .tt-pdpform-print th { font-weight: 700; background: #f0f2f4; }
        .tt-pdpform-print__head td, .tt-pdpform-print__confirm td { width: 25%; border: 0.2mm solid #d6dadd; }
        .tt-pdpform-print__confirm td { width: 33%; }
        .tt-pdpform-print__head span, .tt-pdpform-print__confirm span { display: block; font-size: 6.5pt; color: #5b6e75; text-transform: uppercase; }
        .tt-pdpform-print__scale td { width: 20%; font-size: 7.5pt; border: 0.2mm solid #d6dadd; }
        .tt-pdpform-print__key { margin: 0 0 1mm; font-size: 7.5pt; color: #5b6e75; }
        .tt-pdpform-print__s { width: 24mm; text-align: center; white-space: nowrap; }
        .tt-pdpform-print__t { width: 10mm; text-align: center; }
        .tt-pdpform-print__items th.tt-pdpform-print__s, .tt-pdpform-print__items th.tt-pdpform-print__t, .tt-pdpform-print__items td.tt-pdpform-print__s, .tt-pdpform-print__items td.tt-pdpform-print__t { text-align: center; }
        .tt-pdpform-print__items td.tt-pdpform-print__s em { font-size: 7pt; }
        .tt-pdpform-print__note { margin: 0 0 1.5mm; font-size: 8pt; }
        .tt-pdpform-print__pair th { width: 32mm; }
        .tt-pdpform-print__goals td:first-child { width: 6mm; }
        .tt-pdpform-print__notice { font-size: 10pt; }
        .tt-pdpform-print em { color: #5b6e75; }
CSS;
    }

    /** @return array{status:string, body:string, title:string, file_id:int} */
    private static function result( string $status, string $body = '', string $title = '', int $file_id = 0 ): array {
        return [ 'status' => $status, 'body' => $body, 'title' => $title, 'file_id' => $file_id ];
    }
}
