<?php
namespace TT\Modules\Trials\Letters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialLetterTemplatesRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Shared\Dates\TTDate;

/**
 * Renders a trial letter from a custom or default template.
 *
 * Variable substitution uses simple `{var}` markers. Unknown variables
 * are left literal so the HoD spots them in the preview rather than
 * silently disappearing (a missing `{player_first_name}` is a more
 * helpful failure mode than "blank section, no idea why").
 */
final class LetterTemplateEngine {

    private TrialLetterTemplatesRepository $templates;

    public function __construct() {
        $this->templates = new TrialLetterTemplatesRepository();
    }

    /**
     * @param array<string,scalar|null> $extra_context
     */
    public function render( string $audience, object $case, array $extra_context = [] ): string {
        $key    = self::audienceToKey( $audience );
        $locale = get_locale() ?: 'en_US';
        $tpl    = $this->templates->getForKey( $key, $locale );

        $context = $this->buildContext( $case, $extra_context );

        $body = self::apply( $tpl, $context );

        if ( $audience === AudienceType::TRIAL_ADMITTANCE && self::acceptanceSlipEnabled() ) {
            $body .= self::acceptanceSlipPage( $context, $locale );
        }

        return self::wrapDocument( $body, $context );
    }

    public static function audienceToKey( string $audience ): string {
        switch ( $audience ) {
            case AudienceType::TRIAL_ADMITTANCE:        return TrialLetterTemplatesRepository::KEY_ADMITTANCE;
            case AudienceType::TRIAL_DENIAL_FINAL:      return TrialLetterTemplatesRepository::KEY_DENY_FINAL;
            case AudienceType::TRIAL_DENIAL_ENCOURAGE:  return TrialLetterTemplatesRepository::KEY_DENY_ENC;
            default: return TrialLetterTemplatesRepository::KEY_ADMITTANCE;
        }
    }

    public static function apply( string $template, array $context ): string {
        return preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function ( $m ) use ( $context ) {
                return array_key_exists( $m[1], $context ) && $context[ $m[1] ] !== null
                    ? (string) $context[ $m[1] ]
                    : $m[0];
            },
            $template
        ) ?? $template;
    }

    /**
     * @param array<string,scalar|null> $extra
     * @return array<string,string>
     */
    private function buildContext( object $case, array $extra = [] ): array {
        $player = QueryHelpers::get_player( (int) ( $case->player_id ?? 0 ) );
        $tracks = new TrialTracksRepository();
        $track  = $tracks->find( (int) ( $case->track_id ?? 0 ) );

        $first_name = '';
        $last_name  = '';
        $age        = '';
        if ( $player ) {
            $first_name = (string) ( $player->first_name ?? '' );
            $last_name  = (string) ( $player->last_name ?? '' );
            if ( ! empty( $player->date_of_birth ) ) {
                $dob = strtotime( (string) $player->date_of_birth );
                if ( $dob ) {
                    $age = (string) (int) floor( ( time() - $dob ) / ( 365.25 * 86400 ) );
                }
            }
        }

        $hod_user_id = isset( $case->decision_made_by ) ? (int) $case->decision_made_by : 0;
        $hod_name    = '';
        if ( $hod_user_id > 0 ) {
            $u = get_userdata( $hod_user_id );
            if ( $u ) $hod_name = (string) $u->display_name;
        }

        $club_name = (string) QueryHelpers::get_config( 'club_name', get_bloginfo( 'name' ) ?: __( 'The club', 'talenttrack' ) );
        $club_addr = (string) QueryHelpers::get_config( 'club_address', '' );

        $now           = time();
        $current_year  = (int) date_i18n( 'Y', $now );
        $current_month = (int) date_i18n( 'n', $now );
        $season_start  = $current_month >= 7 ? $current_year : $current_year - 1;
        $current_season = sprintf( '%d/%d', $season_start, $season_start + 1 );
        $next_season    = sprintf( '%d/%d', $season_start + 1, $season_start + 2 );

        $context = [
            'player_first_name'         => $first_name,
            'player_last_name'          => $last_name,
            'player_full_name'          => trim( $first_name . ' ' . $last_name ),
            'player_age'                => $age,
            'trial_start_date'          => self::formatDate( (string) ( $case->start_date ?? '' ) ),
            'trial_end_date'            => self::formatDate( (string) ( $case->end_date ?? '' ) ),
            'club_name'                 => $club_name,
            'club_address'              => $club_addr,
            'head_of_development_name'  => $hod_name,
            'signatory_title'           => __( 'Head of Development', 'talenttrack' ),
            'current_season'            => $current_season,
            'next_season'               => $next_season,
            'track_name'                => $track ? \TT\Infrastructure\Query\LabelTranslator::trialTrackName( (string) $track->name ) : '',
            'today'                     => TTDate::date( $now ),
            'strengths_summary'         => (string) ( $case->strengths_summary ?? '' ),
            'growth_areas'              => (string) ( $case->growth_areas ?? '' ),
            'response_deadline'         => self::responseDeadlineFor( $case ),
        ];

        foreach ( $extra as $k => $v ) {
            if ( is_string( $k ) ) $context[ $k ] = $v === null ? '' : (string) $v;
        }
        return $context;
    }

    private static function formatDate( string $sql_date ): string {
        if ( $sql_date === '' ) return '';
        $ts = strtotime( $sql_date );
        return $ts ? TTDate::date( $ts ) : $sql_date;
    }

    private static function responseDeadlineFor( object $case ): string {
        // #0052 PR-A — moved from wp_options into tt_config (per-tenant).
        $days  = (int) \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_trial_acceptance_response_days', '14' );
        if ( $days <= 0 ) $days = 14;
        $start = time();
        if ( ! empty( $case->decision_made_at ) ) {
            $ts = strtotime( (string) $case->decision_made_at );
            if ( $ts ) $start = $ts;
        }
        return TTDate::date( $start + $days * 86400 );
    }

    public static function acceptanceSlipEnabled(): bool {
        $val = \TT\Infrastructure\Query\QueryHelpers::get_config( 'tt_trial_admittance_include_acceptance_slip', '' );
        return $val === '1' || $val === 'true' || $val === 'on';
    }

    /**
     * @param array<string,string> $context
     */
    private static function acceptanceSlipPage( array $context, string $locale ): string {
        $is_nl = strpos( $locale, 'nl' ) === 0;
        $heading = $is_nl
            ? sprintf( __( 'Acceptatie van het aanbod voor %s', 'talenttrack' ), $context['player_full_name'] )
            : sprintf( __( 'Acceptance of trial offer for %s', 'talenttrack' ), $context['player_full_name'] );
        $confirm = $is_nl
            ? sprintf( __( 'Ik bevestig de acceptatie van het aanbod voor het seizoen %s.', 'talenttrack' ), $context['next_season'] )
            : sprintf( __( 'I confirm acceptance of the trial offer for the %s season.', 'talenttrack' ), $context['next_season'] );
        $instructions = $is_nl
            ? sprintf( __( 'Lever deze pagina aan bij %s vóór %s.', 'talenttrack' ), $context['club_address'] ?: __( 'het secretariaat', 'talenttrack' ), $context['response_deadline'] )
            : sprintf( __( 'Please return this page to %s by %s.', 'talenttrack' ), $context['club_address'] ?: __( 'the club office', 'talenttrack' ), $context['response_deadline'] );

        return sprintf(
            '<section class="tt-letter-page tt-letter-acceptance"><h2>%s</h2><p>%s</p>'
            . '<p class="tt-letter-line">%s ____________________________</p>'
            . '<p class="tt-letter-line">%s ____________________________</p>'
            . '<p class="tt-letter-line">%s ____________________________</p>'
            . '<p class="tt-letter-instructions">%s</p></section>',
            esc_html( $heading ),
            esc_html( $confirm ),
            esc_html( $is_nl ? __( 'Naam ouder/verzorger:', 'talenttrack' ) : __( 'Parent/guardian name:', 'talenttrack' ) ),
            esc_html( $is_nl ? __( 'Handtekening:', 'talenttrack' ) : __( 'Signature:', 'talenttrack' ) ),
            esc_html( $is_nl ? __( 'Datum:', 'talenttrack' ) : __( 'Date:', 'talenttrack' ) ),
            esc_html( $instructions )
        );
    }

    /**
     * The letter as it should be shown, from the HTML stored for it.
     *
     * Letters generated before #3661 were stored with their stylesheet
     * prefixed as a `<style>` element. `wp_kses_post()` drops the tags but
     * keeps their text, so on screen that stylesheet appeared as a block
     * of CSS above the letter. The rules live in `assets/css/trial-letter.css`
     * now; every surface that shows a stored letter reads it through here
     * so the old rows display the same as the new ones.
     */
    public static function displayHtml( string $stored ): string {
        $clean = preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $stored );
        return trim( is_string( $clean ) ? $clean : $stored );
    }

    /**
     * No `<style>` element: the stored HTML is content, and the rules that
     * dress it live in `assets/css/trial-letter.css` (#3661).
     *
     * @param array<string,string> $context
     */
    private static function wrapDocument( string $body, array $context ): string {
        return sprintf(
            '<article class="tt-letter">' .
            '<header><h1>%s</h1><div class="tt-letter-club">%s</div></header>%s</article>',
            esc_html( $context['club_name'] ),
            esc_html( $context['today'] ),
            $body
        );
    }
}
