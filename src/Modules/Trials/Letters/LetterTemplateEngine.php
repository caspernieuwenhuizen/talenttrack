<?php
namespace TT\Modules\Trials\Letters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialLetterTemplatesRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;

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
            'today'                     => date_i18n( get_option( 'date_format' ) ?: 'Y-m-d', $now ),
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
        return $ts ? date_i18n( get_option( 'date_format' ) ?: 'Y-m-d', $ts ) : $sql_date;
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
        return date_i18n( get_option( 'date_format' ) ?: 'Y-m-d', $start + $days * 86400 );
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
     * @param array<string,string> $context
     */
    private static function wrapDocument( string $body, array $context ): string {
        $css = '<style>
            .tt-letter { max-width: 720px; margin: 0 auto; padding: 32px; font-family: Georgia, "Times New Roman", serif; line-height: 1.55; color: #1a1a1a; }
            .tt-letter h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
            .tt-letter h2 { font-size: 1.2rem; margin: 1.5rem 0 .5rem; }
            .tt-letter .tt-letter-club { font-size: .95rem; color: #4a4a4a; margin-bottom: 1.25rem; }
            .tt-letter p { margin: 0 0 .75rem; }
            .tt-letter-page { margin-top: 3rem; padding-top: 2rem; border-top: 1px dashed #999; page-break-before: always; }
            .tt-letter-line { margin: 1.25rem 0 .25rem; font-size: .95rem; color: #333; }
            .tt-letter-instructions { margin-top: 1.5rem; font-style: italic; color: #555; }
            .tt-letter-signature { margin-top: 2rem; }
            @media print { .tt-letter { padding: 0; } }
        </style>';
        return $css . sprintf(
            '<article class="tt-letter">' .
            '<header><h1>%s</h1><div class="tt-letter-club">%s</div></header>%s</article>',
            esc_html( $context['club_name'] ),
            esc_html( $context['today'] ),
            $body
        );
    }
}
