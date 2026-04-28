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

<p>It is our pleasure to confirm that {player_first_name} {player_last_name} has been offered a place at {club_name} for the upcoming {next_season} season.</p>

<p>{player_first_name} took part in our {track_name} trial from {trial_start_date} to {trial_end_date}. The coaching staff have been impressed by their attitude, effort and growth across that period, and we look forward to welcoming them as a full member of the squad.</p>

<h2>Next steps</h2>
<ul>
    <li>Pre-season training begins the first week of August. The age-group coach will share the schedule directly.</li>
    <li>Registration paperwork and medical forms will be sent through the club office over the coming weeks.</li>
    <li>If we have offered an acceptance slip on the next page, please return it signed by the date shown so we can confirm the place.</li>
</ul>

<p>Should you have questions before the season starts, do not hesitate to reach out.</p>

<p class="tt-letter-signature">With warm regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function admittanceNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Met veel plezier bevestigen wij dat {player_first_name} {player_last_name} een plek krijgt aangeboden bij {club_name} voor het komende seizoen {next_season}.</p>

<p>{player_first_name} heeft van {trial_start_date} tot {trial_end_date} meegetraind in onze {track_name}-stage. De staf is onder de indruk van zijn/haar inzet, houding en ontwikkeling in die periode, en we kijken er naar uit om hem/haar volledig op te nemen in de selectie.</p>

<h2>De volgende stappen</h2>
<ul>
    <li>De voorbereiding start in de eerste week van augustus. De leeftijdsgroep-trainer deelt het programma rechtstreeks.</li>
    <li>Inschrijf- en medische formulieren ontvangt u in de komende weken via het clubsecretariaat.</li>
    <li>Als de bevestigingsstrook op pagina 2 is meegestuurd, willen we deze graag ondertekend retour ontvangen vóór de aangegeven datum.</li>
</ul>

<p>Mocht u vragen hebben, neem dan gerust contact met ons op.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyFinalEn(): string {
        return <<<'HTML'
<p>Dear parents/guardians of {player_first_name},</p>

<p>Thank you for letting {player_first_name} {player_last_name} take part in our {track_name} trial from {trial_start_date} to {trial_end_date}.</p>

<p>After careful consideration of the input from all the coaching staff who saw {player_first_name} during the trial period, we have concluded that {club_name} is not the right place to continue {player_first_name}'s development at this time.</p>

<p>This decision is final for the {next_season} season. We thank {player_first_name} sincerely for the energy and commitment shown during the trial, and we wish {him_her} every success on the next steps of {his_her} football journey.</p>

<p class="tt-letter-signature">With kind regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyFinalNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Hartelijk dank dat {player_first_name} {player_last_name} heeft mogen deelnemen aan onze {track_name}-stage van {trial_start_date} tot {trial_end_date}.</p>

<p>Na zorgvuldige weging van de input van alle stafleden die {player_first_name} hebben gezien tijdens de stage, hebben wij besloten dat {club_name} op dit moment niet de juiste plek is om {his_her} ontwikkeling voort te zetten.</p>

<p>Dit besluit is definitief voor seizoen {next_season}. Wij danken {player_first_name} oprecht voor de inzet en het plezier waarmee aan de stage is deelgenomen, en wensen {him_her} veel succes op de volgende stappen in zijn/haar voetbalweg.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyEncEn(): string {
        return <<<'HTML'
<p>Dear parents/guardians of {player_first_name},</p>

<p>Thank you for letting {player_first_name} {player_last_name} take part in our {track_name} trial from {trial_start_date} to {trial_end_date}.</p>

<p>After careful weighing of all the input we received during the trial, we have decided not to offer {player_first_name} a place at {club_name} for the {next_season} season. We appreciate that this is not the news you were hoping for, and we want to share our thinking openly so that this trial period is useful regardless of the outcome.</p>

<h2>What stood out positively</h2>
<p>{strengths_summary}</p>

<h2>Areas to keep working on</h2>
<p>{growth_areas}</p>

<p>We would warmly invite {player_first_name} to apply again next year. Players grow at different paces, and a "no" today is not a "no" forever. Keep training, keep playing, and have fun with football.</p>

<p class="tt-letter-signature">With kind regards,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }

    private static function denyEncNl(): string {
        return <<<'HTML'
<p>Beste ouders/verzorgers van {player_first_name},</p>

<p>Hartelijk dank dat {player_first_name} {player_last_name} heeft mogen deelnemen aan onze {track_name}-stage van {trial_start_date} tot {trial_end_date}.</p>

<p>Na zorgvuldige weging van alle input die we tijdens de stage hebben ontvangen, hebben we besloten {player_first_name} geen plek aan te bieden bij {club_name} voor seizoen {next_season}. We beseffen dat dit niet het bericht is waarop u hoopte. Daarom delen we hier graag onze gedachten openhartig, zodat de stageperiode ook nuttig blijft.</p>

<h2>Wat positief opviel</h2>
<p>{strengths_summary}</p>

<h2>Punten om aan te blijven werken</h2>
<p>{growth_areas}</p>

<p>We nodigen {player_first_name} van harte uit om volgend jaar opnieuw te solliciteren. Spelers groeien in verschillend tempo — een "nee" vandaag is geen "nee" voor altijd. Blijf trainen, blijf spelen en houd plezier in het voetbal.</p>

<p class="tt-letter-signature">Met vriendelijke groet,<br>{head_of_development_name}<br>{signatory_title} — {club_name}</p>
HTML;
    }
}
