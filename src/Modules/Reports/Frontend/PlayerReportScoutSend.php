<?php
namespace TT\Modules\Reports\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\ReportConfig;
use TT\Modules\Reports\ScoutDelivery;
use TT\Shared\Frontend\FlashMessages;

/**
 * PlayerReportScoutSend (#3955, epic #3871) — "Send to a scout" on the player
 * report: the one-time emailed link that lived in the retired report wizard.
 *
 * The document is unchanged: `ScoutDelivery` composes the scout audience from
 * the player report engine (#3876), so the sections ticked on the report
 * narrow what the scout gets and can never widen it. Who may send is who could
 * send from the wizard: `tt_generate_scout_report`, on a player whose report
 * the sender may read.
 */
final class PlayerReportScoutSend {

    public const ACTION = 'tt_pr_scout_send';

    /** Link lifetimes a sender can pick, in days. */
    private const EXPIRY_DAYS = [ 7, 14, 30 ];

    public static function init(): void {
        add_action( 'template_redirect', [ __CLASS__, 'handlePost' ], 5 );
    }

    public static function canSend( int $user_id, int $player_id ): bool {
        return user_can( $user_id, 'tt_generate_scout_report' )
            && PlayerReportAccess::canRead( $user_id, $player_id );
    }

    /** Send, say what became of it, and go back to the report. Before any output. */
    public static function handlePost(): void {
        if ( ! is_user_logged_in() ) return;
        $action = isset( $_POST['tt_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['tt_action'] ) ) : '';
        if ( $action !== self::ACTION ) return;

        check_admin_referer( self::ACTION );

        $user_id   = get_current_user_id();
        $player_id = isset( $_POST['player_id'] ) ? absint( $_POST['player_id'] ) : 0;
        $back      = isset( $_POST['return_to'] ) ? esc_url_raw( wp_unslash( (string) $_POST['return_to'] ) ) : '';
        $back      = wp_validate_redirect( $back, \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() );

        $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
        if ( ! $player || ! self::canSend( $user_id, $player_id ) ) {
            wp_die( esc_html__( 'You do not have access to a report on this player.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $email   = isset( $_POST['scout_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['scout_email'] ) ) : '';
        $expiry  = isset( $_POST['scout_expiry_days'] ) ? absint( $_POST['scout_expiry_days'] ) : 14;
        $message = isset( $_POST['scout_message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['scout_message'] ) ) : '';
        if ( ! in_array( $expiry, self::EXPIRY_DAYS, true ) ) $expiry = 14;

        if ( $email === '' || ! is_email( $email ) ) {
            FlashMessages::add( FlashMessages::TYPE_ERROR, __( 'Recipient email is missing or invalid.', 'talenttrack' ) );
            wp_safe_redirect( $back );
            exit;
        }

        $config = self::config(
            $player_id,
            $user_id,
            isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from'] ) ) : '',
            isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to'] ) ) : '',
            explode( ',', isset( $_POST['blocks'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['blocks'] ) ) : '' )
        );

        $result = ( new ScoutDelivery() )->emailLink( $player, $config, $email, $expiry, $message );
        if ( $result['ok'] ) {
            FlashMessages::add( FlashMessages::TYPE_SUCCESS, sprintf(
                /* translators: 1: recipient email, 2: expiry days */
                __( 'Link emailed to %1$s. Expires in %2$d days.', 'talenttrack' ),
                $email,
                $expiry
            ) );
        } else {
            FlashMessages::add( FlashMessages::TYPE_ERROR, __( 'Could not send the link. Check the email address and your site mail settings, then try again.', 'talenttrack' ) );
        }
        // #2602 / #2604 — what became of the send, not just whether it worked.
        foreach ( $result['outcome'] as $line ) {
            FlashMessages::add( FlashMessages::TYPE_INFO, (string) $line );
        }

        wp_safe_redirect( $back );
        exit;
    }

    /**
     * The scout document's composition, from the report as it stands: its
     * window, and its sections in the vocabulary `ScoutDelivery` reads.
     *
     * @param list<string> $blocks
     */
    public static function config( int $player_id, int $user_id, string $from, string $to, array $blocks ): ReportConfig {
        $map      = [ PlayerReportBlock::RATINGS => 'ratings', PlayerReportBlock::ATTENDANCE => 'attendance', PlayerReportBlock::MINUTES => 'sessions' ];
        $sections = [ 'profile' ];
        foreach ( $blocks as $block ) {
            $block = sanitize_key( trim( $block ) );
            if ( isset( $map[ $block ] ) ) $sections[] = $map[ $block ];
        }
        // None of the scout's sections ticked: send all of them rather than a
        // page with a name on it and nothing else.
        if ( $sections === [ 'profile' ] ) $sections = array_merge( $sections, array_values( $map ) );

        return new ReportConfig(
            AudienceType::SCOUT,
            [ 'date_from' => $from, 'date_to' => $to, 'eval_type_id' => 0 ],
            $sections,
            $player_id,
            $user_id
        );
    }

    /**
     * The send form, behind a disclosure so it takes a deliberate step.
     *
     * @param array{from:string,to:string,period:string} $window
     * @param list<string>                               $blocks
     */
    public static function render( int $player_id, array $window, array $blocks ): void {
        if ( ! self::canSend( get_current_user_id(), $player_id ) ) return;

        $return_to = remove_query_arg( [ '_wpnonce' ] );
        $field     = 'tt-pr-scout-' . $player_id;

        echo '<details class="tt-pr-share">';
        echo '<summary class="tt-btn tt-btn-secondary">' . esc_html__( 'Send to a scout…', 'talenttrack' ) . '</summary>';
        echo '<form method="post" class="tt-pr-share__form">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="tt_action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="player_id" value="' . esc_attr( (string) $player_id ) . '">';
        echo '<input type="hidden" name="from" value="' . esc_attr( $window['from'] ) . '">';
        echo '<input type="hidden" name="to" value="' . esc_attr( $window['to'] ) . '">';
        echo '<input type="hidden" name="blocks" value="' . esc_attr( implode( ',', $blocks ) ) . '">';
        echo '<input type="hidden" name="return_to" value="' . esc_attr( $return_to ) . '">';
        echo '<p class="tt-mr-panel__hint">' . esc_html__( 'Emails a one-time link to the scout. The scout sees evaluation scores without your notes, attendance and playing time: the ones ticked above, or all three when none of them is. Nothing else leaves the academy.', 'talenttrack' ) . '</p>';

        echo '<label class="tt-label" for="' . esc_attr( $field . '-email' ) . '">' . esc_html__( 'Recipient email', 'talenttrack' ) . '</label>';
        echo '<input class="tt-input" type="email" inputmode="email" autocomplete="email" required id="' . esc_attr( $field . '-email' ) . '" name="scout_email">';

        echo '<label class="tt-label" for="' . esc_attr( $field . '-expiry' ) . '">' . esc_html__( 'Link expires after', 'talenttrack' ) . '</label>';
        echo '<select class="tt-input" id="' . esc_attr( $field . '-expiry' ) . '" name="scout_expiry_days">';
        foreach ( self::EXPIRY_DAYS as $days ) {
            echo '<option value="' . esc_attr( (string) $days ) . '"' . selected( $days, 14, false ) . '>' . esc_html( sprintf(
                /* translators: %d: number of days */
                _n( '%d day', '%d days', $days, 'talenttrack' ),
                $days
            ) ) . '</option>';
        }
        echo '</select>';

        echo '<label class="tt-label" for="' . esc_attr( $field . '-message' ) . '">' . esc_html__( 'Optional message to scout', 'talenttrack' ) . '</label>';
        echo '<textarea class="tt-input" id="' . esc_attr( $field . '-message' ) . '" name="scout_message" rows="3"></textarea>';

        echo '<button type="submit" class="tt-btn tt-btn-primary">' . esc_html__( 'Send link', 'talenttrack' ) . '</button>';
        echo '</form>';
        echo '</details>';
    }
}
