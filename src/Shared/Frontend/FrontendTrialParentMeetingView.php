<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Trials\Security\TrialCaseAccessPolicy;

/**
 * FrontendTrialParentMeetingView (#0017 Sprint 5) — sanitized fullscreen
 * view for parent conversations.
 *
 * Allow-list only. The page omits all internal staff data, attendance
 * stats, justification notes, and aggregation. What's shown is
 * exactly: photo, name+age, trial dates, decision outcome, the
 * appropriate "what's next" framing, and a button to open the
 * generated letter. New fields added to the case in the future will
 * default to NOT being shown here.
 */
class FrontendTrialParentMeetingView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $case_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        if ( $case_id <= 0 || ! TrialCaseAccessPolicy::isManager( $user_id ) ) {
            self::renderHeader( __( 'Parent meeting', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to use parent-meeting mode.', 'talenttrack' ) . '</p>';
            return;
        }

        $cases = new TrialCasesRepository();
        $case  = $cases->find( $case_id );
        if ( ! $case || $case->status !== TrialCasesRepository::STATUS_DECIDED ) {
            self::renderHeader( __( 'Parent meeting', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Parent-meeting mode is only available after a decision has been recorded.', 'talenttrack' ) . '</p>';
            return;
        }

        $player = QueryHelpers::get_player( (int) $case->player_id );
        $name   = $player ? QueryHelpers::player_display_name( $player ) : '';
        $tracks = new TrialTracksRepository();
        $track  = $tracks->find( (int) $case->track_id );

        $age = '';
        if ( $player && ! empty( $player->date_of_birth ) ) {
            $dob = strtotime( (string) $player->date_of_birth );
            if ( $dob ) $age = (int) floor( ( time() - $dob ) / ( 365.25 * 86400 ) );
        }

        $svc      = new TrialLetterService();
        $letter   = $svc->findActiveForCase( $case_id );
        $letter_url = '';
        if ( $letter ) {
            $letter_url = add_query_arg( [
                'tt_view' => 'trial-case', 'id' => $case_id, 'tab' => 'letter', 'print' => 1,
            ], home_url( '/' ) );
        }

        $headline_class = 'tt-meeting-decision';
        $headline_text  = '';
        $sub_text       = '';
        switch ( $case->decision ) {
            case TrialCasesRepository::DECISION_ADMIT:
                $headline_class .= ' tt-meeting-decision-admit';
                $headline_text   = __( 'We are offering a place', 'talenttrack' );
                $sub_text        = __( 'Welcome to the squad. Pre-season starts in early August; the age-group coach will share the schedule.', 'talenttrack' );
                break;
            case TrialCasesRepository::DECISION_DENY_ENCOURAGE:
                $headline_class .= ' tt-meeting-decision-encourage';
                $headline_text   = __( 'Not this season — but we encourage trying again', 'talenttrack' );
                $sub_text        = __( 'A "no" today is not a "no" forever. We will share what stood out and where to keep working.', 'talenttrack' );
                break;
            default:
                $headline_class .= ' tt-meeting-decision-final';
                $headline_text   = __( 'We are not offering a place this season', 'talenttrack' );
                $sub_text        = __( 'Thank you for the energy and commitment shown during the trial period.', 'talenttrack' );
                break;
        }

        echo '<style>
            .tt-meeting-wrap { max-width: 960px; margin: 0 auto; padding: 2rem; text-align: center; font-size: 1.15rem; }
            .tt-meeting-photo { width: 200px; height: 200px; border-radius: 50%; object-fit: cover; margin: 0 auto 1.5rem; display: block; box-shadow: 0 4px 12px rgba(0,0,0,.15); }
            .tt-meeting-name { font-size: 2.4rem; font-weight: 600; margin: 0 0 .25rem; color: #1a1d21; }
            .tt-meeting-meta { color: #555; margin-bottom: 2rem; }
            .tt-meeting-decision { padding: 1.75rem; border-radius: 12px; margin: 0 auto 1.5rem; max-width: 720px; }
            .tt-meeting-decision-admit { background: #e6f4ea; color: #137333; }
            .tt-meeting-decision-encourage { background: #e7f1fb; color: #1c5392; }
            .tt-meeting-decision-final { background: #f1f3f4; color: #444; }
            .tt-meeting-headline { font-size: 1.6rem; font-weight: 600; margin: 0 0 .5rem; }
            .tt-meeting-sub { font-size: 1.1rem; line-height: 1.5; margin: 0; }
            .tt-meeting-strengths { background: #fdf6e3; border-radius: 12px; padding: 1.25rem; margin: 1rem auto; max-width: 720px; text-align: left; }
            .tt-meeting-actions { margin-top: 2rem; }
            .tt-meeting-actions .tt-button { font-size: 1.1rem; padding: .85rem 1.5rem; }
            .tt-meeting-fullscreen-launcher { display: inline-block; margin-top: 1.5rem; }
        </style>';

        echo '<div class="tt-meeting-wrap" id="tt-meeting-root">';

        $photo_url = $player && ! empty( $player->photo_url ) ? (string) $player->photo_url : '';
        if ( $photo_url ) {
            echo '<img class="tt-meeting-photo" src="' . esc_url( $photo_url ) . '" alt="" loading="lazy">';
        }
        echo '<h1 class="tt-meeting-name">' . esc_html( $name ) . '</h1>';
        $meta_bits = [];
        if ( $age !== '' ) $meta_bits[] = sprintf( __( 'Age %d', 'talenttrack' ), $age );
        if ( $track )      $meta_bits[] = sprintf( __( 'Track: %s', 'talenttrack' ), \TT\Infrastructure\Query\LabelTranslator::trialTrackName( (string) $track->name ) );
        $meta_bits[] = sprintf( __( '%s — %s', 'talenttrack' ), (string) $case->start_date, (string) $case->end_date );
        echo '<p class="tt-meeting-meta">' . esc_html( implode( '  •  ', $meta_bits ) ) . '</p>';

        echo '<div class="' . esc_attr( $headline_class ) . '">';
        echo '<p class="tt-meeting-headline">' . esc_html( $headline_text ) . '</p>';
        echo '<p class="tt-meeting-sub">' . esc_html( $sub_text ) . '</p>';
        echo '</div>';

        if ( $case->decision === TrialCasesRepository::DECISION_DENY_ENCOURAGE ) {
            if ( $case->strengths_summary ) {
                echo '<div class="tt-meeting-strengths"><strong>' . esc_html__( 'What stood out positively', 'talenttrack' ) . '</strong><p>' . esc_html( (string) $case->strengths_summary ) . '</p></div>';
            }
            if ( $case->growth_areas ) {
                echo '<div class="tt-meeting-strengths"><strong>' . esc_html__( 'Areas to keep working on', 'talenttrack' ) . '</strong><p>' . esc_html( (string) $case->growth_areas ) . '</p></div>';
            }
        }

        echo '<div class="tt-meeting-actions">';
        if ( $letter_url ) {
            echo '<a class="tt-button tt-button-primary" target="_blank" rel="noopener" href="' . esc_url( $letter_url ) . '">' . esc_html__( 'Open letter', 'talenttrack' ) . '</a> ';
            $email_subject = rawurlencode( sprintf( __( 'Letter regarding %s', 'talenttrack' ), $name ) );
            echo '<a class="tt-button" href="mailto:?subject=' . $email_subject . '">' . esc_html__( 'Email letter', 'talenttrack' ) . '</a>';
        }
        echo '<button class="tt-button tt-meeting-fullscreen-launcher" onclick="(function(){var e=document.getElementById(\'tt-meeting-root\');if(e.requestFullscreen)e.requestFullscreen();})();">' . esc_html__( 'Enter fullscreen', 'talenttrack' ) . '</button>';
        echo '</div>';

        echo '</div>';
    }
}
