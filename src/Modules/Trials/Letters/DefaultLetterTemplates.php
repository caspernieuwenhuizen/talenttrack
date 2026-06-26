<?php
namespace TT\Modules\Trials\Letters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Trials\Repositories\TrialLetterTemplatesRepository;

/**
 * Plugin-shipped letter templates for the three decision outcomes.
 *
 * Returned as raw HTML containing `{var}` substitution markers. The
 * `LetterTemplateEngine` resolves those against the case context.
 *
 * Locale handling: an exact match wins; otherwise English. nl_NL is the
 * second locale shipped with the plugin since the dashboard already
 * supports it; clubs in other languages use the editor surface to add
 * their own.
 */
final class DefaultLetterTemplates {

    public static function get( string $template_key, string $locale ): string {
        $is_nl = strpos( $locale, 'nl' ) === 0;
        switch ( $template_key ) {
            case TrialLetterTemplatesRepository::KEY_ADMITTANCE:
                return $is_nl ? self::admittanceNl() : self::admittanceEn();
            case TrialLetterTemplatesRepository::KEY_DENY_FINAL:
                return $is_nl ? self::denyFinalNl() : self::denyFinalEn();
            case TrialLetterTemplatesRepository::KEY_DENY_ENC:
                return $is_nl ? self::denyEncNl() : self::denyEncEn();
        }
        return '';
    }

    public static function listKeys(): array {
        return [
            TrialLetterTemplatesRepository::KEY_ADMITTANCE,
            TrialLetterTemplatesRepository::KEY_DENY_FINAL,
            TrialLetterTemplatesRepository::KEY_DENY_ENC,
        ];
    }

    public static function variableLegend(): array {
        return [
            'player_first_name'        => __( 'Player first name', 'talenttrack' ),
            'player_last_name'         => __( 'Player last name', 'talenttrack' ),
            'player_full_name'         => __( 'Player full name', 'talenttrack' ),
            'player_age'               => __( 'Player age in years', 'talenttrack' ),
            'trial_start_date'         => __( 'Trial start date (formatted)', 'talenttrack' ),
            'trial_end_date'           => __( 'Trial end date (formatted)', 'talenttrack' ),
            'club_name'                => __( 'Club name from configuration', 'talenttrack' ),
            'club_address'             => __( 'Club return address (acceptance slip)', 'talenttrack' ),
            'head_of_development_name' => __( 'Name of the user who recorded the decision', 'talenttrack' ),
            'signatory_title'          => __( 'Signatory title (Head of Development by default)', 'talenttrack' ),
            'current_season'           => __( 'Current season label, e.g. 2025/2026', 'talenttrack' ),
            'next_season'              => __( 'Next season label, e.g. 2026/2027', 'talenttrack' ),
            'track_name'                => __( 'Track template name', 'talenttrack' ),
            'today'                     => __( 'Today’s date (formatted)', 'talenttrack' ),
            'strengths_summary'         => __( 'HoD-written strengths summary (encouragement letter)', 'talenttrack' ),
            'growth_areas'              => __( 'HoD-written growth areas (encouragement letter)', 'talenttrack' ),
            'response_deadline'         => __( 'Acceptance-slip return deadline', 'talenttrack' ),
        ];
    }

    private static function admittanceEn(): string {
        return <<<'HTML'
<p>Dear parents/guardians of {player_first_name},</p>

<p>It is our pleasure to confirm that {player_first_name} {player_last_name} has been offered a place at {club_name} for the {next_season} season.</p>

<p>{player_first_name} took part in our {track_name} trial from {trial_start_date} to {trial_end_date}. The coaching staff were impressed by {player_first_name}'s attitude, effort and growth across that period, and we look forward to a warm welcome into the squad.</p>

<h2>Next steps</h2>
<ul>
    <li>Pre-season training begins in the first week of August. The age-group coach will share the schedule with you directly.</li>
    <li>Registration paperwork and medical forms will follow through the club office over the coming weeks.</li>
    <li>If an acceptance slip is included on the next page, please return it signed by the date shown so we can confirm the place.</li>
</ul>

<p>If you have any questions before the season starts, please don't hesitate to get in touch — we're happy to help.</p>

<p class="tt-letter-signature">With warm regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function admittanceNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Met heel veel plezier laten we weten dat {player_first_name} {player_last_name} een plek krijgt aangeboden bij {club_name} voor het seizoen {next_season}. Van harte gefeliciteerd!</p>

<p>{player_first_name} heeft van {trial_start_date} tot {trial_end_date} meegetraind in onze {track_name}-stage. De staf was onder de indruk van de inzet, de houding en de ontwikkeling van {player_first_name} in die periode. We kijken er enorm naar uit om {player_first_name} straks helemaal welkom te heten in de selectie.</p>

<h2>Wat er nu gebeurt</h2>
<ul>
    <li>De voorbereiding begint in de eerste week van augustus. De trainer van de leeftijdsgroep stuurt het programma rechtstreeks naar jullie.</li>
    <li>De inschrijf- en medische formulieren ontvangen jullie de komende weken via het clubsecretariaat.</li>
    <li>Zit er een bevestigingsstrook op de volgende pagina? Stuur die dan vóór de aangegeven datum ondertekend terug, dan zetten we de plek definitief vast.</li>
</ul>

<p>Hebben jullie nog vragen voordat het seizoen begint? Neem gerust contact met ons op — we helpen jullie graag.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyFinalEn(): string {
        return <<<'HTML'
<p>Dear parents/guardians of {player_first_name},</p>

<p>Thank you for letting {player_first_name} {player_last_name} take part in our {track_name} trial from {trial_start_date} to {trial_end_date}.</p>

<p>After carefully weighing the input from all the coaching staff who saw {player_first_name} during the trial period, we have concluded that {club_name} is not the right place to continue {player_first_name}'s development at this time.</p>

<p>This decision is final for the {next_season} season. We thank {player_first_name} sincerely for the energy and commitment shown during the trial, and we wish {player_first_name} every success on the road ahead.</p>

<p class="tt-letter-signature">With kind regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyFinalNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Hartelijk dank dat {player_first_name} {player_last_name} heeft mogen meetrainen tijdens onze {track_name}-stage van {trial_start_date} tot {trial_end_date}.</p>

<p>Na een zorgvuldige afweging van wat alle trainers en stafleden tijdens de stage hebben gezien, hebben we besloten dat {club_name} op dit moment niet de juiste plek is om {player_first_name} verder te laten ontwikkelen.</p>

<p>Dit besluit is definitief voor het seizoen {next_season}. We willen {player_first_name} oprecht bedanken voor alle inzet en het plezier tijdens de stage, en we wensen {player_first_name} heel veel succes met de volgende stappen op het voetbalpad.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyEncEn(): string {
        return <<<'HTML'
<p>Dear parents/guardians of {player_first_name},</p>

<p>Thank you for letting {player_first_name} {player_last_name} take part in our {track_name} trial from {trial_start_date} to {trial_end_date}.</p>

<p>After carefully weighing all the input we gathered during the trial, we have decided not to offer {player_first_name} a place at {club_name} for the {next_season} season. We know this isn't the news you were hoping for, and we'd like to share our thinking openly so the trial is worthwhile whatever the outcome.</p>

<h2>What stood out positively</h2>
<p>{strengths_summary}</p>

<h2>Areas to keep working on</h2>
<p>{growth_areas}</p>

<p>We'd warmly encourage {player_first_name} to try out again next year. Players grow at very different speeds, and a "no" today is not a "no" forever. Keep training, keep playing, and above all keep enjoying football.</p>

<p class="tt-letter-signature">With kind regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyEncNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Hartelijk dank dat {player_first_name} {player_last_name} heeft mogen meetrainen tijdens onze {track_name}-stage van {trial_start_date} tot {trial_end_date}.</p>

<p>Na een zorgvuldige afweging van alles wat we tijdens de stage hebben gezien, hebben we besloten om {player_first_name} voor het seizoen {next_season} geen plek bij {club_name} aan te bieden. We snappen heel goed dat dit niet het bericht is waarop jullie hoopten. Daarom delen we hieronder graag eerlijk onze gedachten, zodat de stageperiode ook echt iets oplevert.</p>

<h2>Wat positief opviel</h2>
<p>{strengths_summary}</p>

<h2>Waar {player_first_name} aan kan blijven werken</h2>
<p>{growth_areas}</p>

<p>We moedigen {player_first_name} van harte aan om het volgend jaar opnieuw te proberen. Spelers groeien echt in een heel verschillend tempo — een "nee" van nu is geen "nee" voor altijd. Blijf lekker trainen, blijf spelen en houd vooral plezier in het voetbal.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }
}
