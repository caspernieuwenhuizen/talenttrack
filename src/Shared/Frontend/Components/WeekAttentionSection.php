<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\Blocks\InjuriesBlock;

/**
 * #4496 — the "Players who need attention" section of the This week panel,
 * rendered from the `attention_*` lines of `CoachWeek::forUser()`.
 *
 * The service has already applied the privacy rules: a line holds named
 * players the viewer may open and a count of the others, and nothing
 * medical, no age and no date of birth. This view only turns them into
 * words and links, and hides the section when every line is empty.
 */
final class WeekAttentionSection {

    /** Block key => the payload field its line is under, in display order. */
    private const LINES = [
        'injuries'  => 'attention_injuries',
        'missed'    => 'attention_missed',
        'trial'     => 'attention_trial',
        'birthdays' => 'attention_birthdays',
    ];

    /** @param array<string, mixed> $week */
    public static function render( array $week ): void {
        $blocks  = is_array( $week['blocks'] ?? null ) ? $week['blocks'] : [];
        $parts   = [];
        $desktop = false;
        $phone   = false;
        foreach ( self::LINES as $key => $field ) {
            $on_desktop = in_array( $key, (array) ( $blocks['desktop'] ?? [] ), true );
            $on_phone   = in_array( $key, (array) ( $blocks['phone'] ?? [] ), true );
            $class      = self::deviceClass( $on_desktop, $on_phone );
            if ( $class === null ) continue;
            $line    = is_array( $week[ $field ] ?? null ) ? $week[ $field ] : [];
            $players = is_array( $line['players'] ?? null ) ? $line['players'] : [];
            $others  = (int) ( $line['others'] ?? 0 );
            if ( $players === [] && $others === 0 ) continue;
            $parts[]  = self::line( $key, $players, $others, $class );
            $desktop  = $desktop || $on_desktop;
            $phone    = $phone || $on_phone;
        }
        if ( $parts === [] ) return;
        // The heading shows on a device only when a line with content does.
        $wrapper = trim( 'tt-week-attention ' . (string) self::deviceClass( $desktop, $phone ) );
        ?>
        <div class="<?php echo esc_attr( $wrapper ); ?>">
            <h3 class="tt-week-attention-title"><?php esc_html_e( 'Players who need attention', 'talenttrack' ); ?></h3>
            <?php echo implode( '', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped parts. ?>
        </div>
        <?php
    }

    /** @param array<mixed> $players the line's named players, as the payload carries them */
    private static function line( string $key, array $players, int $others, string $class ): string {
        $items = '';
        foreach ( $players as $player ) {
            $items .= '<li class="tt-week-attention-item">' . self::name( (array) $player, $key ) . self::detail( $key, (array) $player ) . '</li>';
        }
        if ( $others > 0 ) {
            $items .= '<li class="tt-week-attention-item is-count">' . esc_html( self::countText( $key, $others, $players !== [] ) ) . '</li>';
        }
        return '<div class="' . esc_attr( trim( 'tt-week-attention-line ' . $class ) ) . '" data-tt-week-attention="' . esc_attr( $key ) . '">'
            . '<h4 class="tt-week-attention-label">' . esc_html( self::label( $key ) ) . '</h4>'
            . '<ul class="tt-week-attention-list">' . $items . '</ul>'
            . '</div>';
    }

    /** @param array<string, mixed> $player */
    private static function name( array $player, string $key ): string {
        $label = (string) ( $key === 'birthdays' ? ( $player['first_name'] ?? '' ) : ( $player['name'] ?? '' ) );
        $pid   = (int) ( $player['player_id'] ?? 0 );
        $url   = $pid > 0 && CrossViewLink::allows( 'players' ) ? RecordLink::detailUrlForWithBack( 'players', $pid ) : '';
        return $url !== ''
            ? '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>'
            : '<span>' . esc_html( $label ) . '</span>';
    }

    /**
     * The words after the name: a neutral marker, never a medical detail.
     *
     * @param array<string, mixed> $player
     */
    private static function detail( string $key, array $player ): string {
        $bits = [];
        switch ( $key ) {
            case 'injuries':
                if ( ( $player['state'] ?? '' ) === InjuriesBlock::RETURNING ) {
                    $bits[] = _x( 'returning', 'This week: a player expected back', 'talenttrack' );
                    $bits[] = _x( 'this week', 'This week: a player expected back this week', 'talenttrack' );
                } else {
                    // The planning screens' neutral word (#4005 decision 3).
                    $bits[] = \TT\Modules\Activities\Services\PlayerAvailability::label();
                    $back = (string) ( $player['back'] ?? '' );
                    $ts   = $back !== '' ? strtotime( $back . ' 12:00:00' ) : false;
                    /* translators: %s: the expected return date, e.g. "8 Nov" */
                    if ( $ts ) $bits[] = sprintf( __( 'back ~ %s', 'talenttrack' ), date_i18n( 'j M', $ts ) );
                }
                break;
            case 'trial':
                $days = [];
                foreach ( (array) ( $player['days'] ?? [] ) as $day ) {
                    $ts = strtotime( (string) $day . ' 12:00:00' );
                    if ( $ts ) $days[] = date_i18n( 'D', $ts );
                }
                if ( $days !== [] ) $bits[] = implode( ', ', $days );
                break;
            case 'birthdays':
                $ts = strtotime( (string) ( $player['date'] ?? '' ) . ' 12:00:00' );
                if ( $ts ) $bits[] = date_i18n( 'D j', $ts );
                break;
        }
        return $bits === [] ? '' : '<span class="tt-week-attention-meta"> · ' . esc_html( implode( ' · ', $bits ) ) . '</span>';
    }

    private static function label( string $key ): string {
        switch ( $key ) {
            case 'injuries':  return __( 'Unavailable and returning this week', 'talenttrack' );
            case 'missed':    return __( 'Missed several trainings', 'talenttrack' );
            case 'trial':     return __( 'Trial players this week', 'talenttrack' );
            default:          return __( 'Birthdays', 'talenttrack' );
        }
    }

    /** "2 players unavailable", or "1 more player unavailable" after named ones. */
    private static function countText( string $key, int $n, bool $more ): string {
        switch ( $key ) {
            case 'injuries':
                /* translators: %d: number of players */
                return $more ? sprintf( _n( '%d more player unavailable', '%d more players unavailable', $n, 'talenttrack' ), $n )
                    /* translators: %d: number of players */
                    : sprintf( _n( '%d player unavailable', '%d players unavailable', $n, 'talenttrack' ), $n );
            case 'missed':
                /* translators: %d: number of players */
                return $more ? sprintf( _n( '%d more player missed them', '%d more players missed them', $n, 'talenttrack' ), $n )
                    /* translators: %d: number of players */
                    : sprintf( _n( '%d player missed the last three trainings', '%d players missed the last three trainings', $n, 'talenttrack' ), $n );
            case 'trial':
                /* translators: %d: number of players */
                return $more ? sprintf( _n( '%d more trial player', '%d more trial players', $n, 'talenttrack' ), $n )
                    /* translators: %d: number of players */
                    : sprintf( _n( '%d trial player', '%d trial players', $n, 'talenttrack' ), $n );
            default:
                /* translators: %d: number of birthdays */
                return $more ? sprintf( _n( '%d more birthday', '%d more birthdays', $n, 'talenttrack' ), $n )
                    /* translators: %d: number of birthdays */
                    : sprintf( _n( '%d birthday', '%d birthdays', $n, 'talenttrack' ), $n );
        }
    }

    /** '' on both devices, a hiding class for one, null when off for both. */
    private static function deviceClass( bool $desktop, bool $phone ): ?string {
        if ( ! $desktop && ! $phone ) return null;
        if ( $desktop && $phone ) return '';
        return $desktop ? 'tt-week-off-phone' : 'tt-week-off-desktop';
    }
}
