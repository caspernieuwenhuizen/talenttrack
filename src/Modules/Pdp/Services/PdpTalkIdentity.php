<?php
namespace TT\Modules\Pdp\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\PdpConversationTemplate;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Players\Services\PlayerPhoto;

/**
 * PdpTalkIdentity (#5082) — who a PDP talk is about and which talk it is:
 * the player's name, photo, team, shirt number and first position, and the
 * talk's place in the cycle with its template and dates.
 *
 * The talk's page and the room display both read it, so the two cannot name
 * a talk differently. It derives; the callers format.
 */
final class PdpTalkIdentity {

    /**
     * The talk in one line, as every screen that names it writes it:
     * "Mid season · conversation 2 of 3 · planned …", or "held …" once
     * conducted. Takes `of()`'s answer, or the `talk` part of the room
     * display, which carries the same keys.
     *
     * @param array{sequence:int, total:int, template:string, scheduled_at:?string, conducted_at:?string} $talk
     */
    public static function line( array $talk ): string {
        if ( $talk['conducted_at'] !== null ) {
            return sprintf(
                /* translators: %1$s: talk template, %2$d: its number in the cycle, %3$d: talks in the cycle, %4$s: date it was held */
                __( '%1$s · conversation %2$d of %3$d · held %4$s', 'talenttrack' ),
                $talk['template'], $talk['sequence'], $talk['total'], \TT\Shared\Dates\TTDate::date( $talk['conducted_at'] )
            );
        }
        if ( $talk['scheduled_at'] !== null ) {
            return sprintf(
                /* translators: %1$s: talk template, %2$d: its number in the cycle, %3$d: talks in the cycle, %4$s: planned date and time */
                __( '%1$s · conversation %2$d of %3$d · planned %4$s', 'talenttrack' ),
                $talk['template'], $talk['sequence'], $talk['total'], \TT\Shared\Dates\TTDate::dateTime( $talk['scheduled_at'] )
            );
        }
        return sprintf(
            /* translators: %1$s: talk template, %2$d: its number in the cycle, %3$d: talks in the cycle */
            __( '%1$s · conversation %2$d of %3$d', 'talenttrack' ),
            $talk['template'], $talk['sequence'], $talk['total']
        );
    }

    /**
     * @return array{
     *   player_id:int, name:string, photo_url:string, status:string,
     *   team:string, shirt_number:?int, position:string,
     *   sequence:int, total:int, template:string,
     *   scheduled_at:?string, conducted_at:?string
     * }
     */
    public static function of( object $file, object $conv ): array {
        $f         = (array) $file;
        $c         = (array) $conv;
        $player_id = (int) ( $f['player_id'] ?? 0 );
        $player    = QueryHelpers::get_player( $player_id );

        $team     = '';
        $shirt    = null;
        $position = '';
        if ( $player ) {
            $p = (array) $player;
            $t = ! empty( $p['team_id'] ) ? QueryHelpers::get_team( (int) $p['team_id'] ) : null;
            if ( $t ) $team = (string) ( ( (array) $t )['name'] ?? '' );
            if ( ! empty( $p['jersey_number'] ) ) $shirt = (int) $p['jersey_number'];
            $positions = json_decode( (string) ( $p['preferred_positions'] ?? '' ), true );
            if ( is_array( $positions ) ) {
                foreach ( $positions as $code ) {
                    $code = trim( (string) $code );
                    if ( $code === '' ) continue;
                    $position = LabelTranslator::positionLabel( $code );
                    break;
                }
            }
        }

        $sequence = (int) ( $c['sequence'] ?? 0 );
        $total    = (int) ( $f['cycle_size'] ?? 0 );
        if ( $total <= 0 ) {
            $total = count( ( new PdpConversationsRepository() )->listForFile( (int) ( $f['id'] ?? 0 ) ) );
        }

        return [
            'player_id'    => $player_id,
            'name'         => $player ? QueryHelpers::player_display_name( $player ) : __( 'Player', 'talenttrack' ),
            'photo_url'    => $player ? PlayerPhoto::url( $player ) : '',
            'status'       => $player ? (string) ( ( (array) $player )['status'] ?? '' ) : '',
            'team'         => $team,
            'shirt_number' => $shirt,
            'position'     => $position,
            'sequence'     => $sequence,
            'total'        => max( $total, $sequence ),
            'template'     => PdpConversationTemplate::label( (string) ( $c['template_key'] ?? '' ) ),
            'scheduled_at' => ! empty( $c['scheduled_at'] ) ? (string) $c['scheduled_at'] : null,
            'conducted_at' => ! empty( $c['conducted_at'] ) ? (string) $c['conducted_at'] : null,
        ];
    }
}
