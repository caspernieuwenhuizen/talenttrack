<!-- audience: admin -->

# Configuratie — Algemeen

**Dashboard → Configuratie → Algemeen** (`?config_sub=general`)

Academiebrede basisinstellingen die bepalen hoe datums en de kalender in heel TalentTrack worden weergegeven. De instellingen staan per club in `tt_config`, zodat een toekomstige multi-tenant-installatie de keuze van elke academie apart houdt. Opslaan gaat via `POST /wp-json/talenttrack/v1/config`, net als de andere inline-configuratieformulieren; de pagina is alleen voor beheerders / clubbeheerders (`tt_edit_settings`).

## Instellingen

| Instelling | Wat het doet |
| --- | --- |
| **Datumnotatie** | Hoe datums worden geschreven — Systeemstandaard (de WordPress-datumnotatie-optie), `31-12-2026`, `31/12/2026`, `31.12.2026`, `12/31/2026`, ISO `2026-12-31` of lang `31 December 2026`. Het formulier toont een live voorbeeld van de datum van vandaag terwijl je kiest. |
| **Eerste dag van de week** | Maandag (standaard) of Zondag — de dag waarop het weekraster van de **teamplanner** begint. |
| **Tijdzone** | Academiebrede standaardtijdzone (de standaard WordPress-tijdzonelijst). |
| **Locale** | Standaardtaal voor datum- en getalnotatie. Alleen geïnstalleerde talen worden getoond. |
| **Tegelbreedte (px)** | Exacte breedte van de dashboardtegelkolom in pixels (140–400). Een expliciete override die **voorrang heeft** op het grootte-preset en de %-tegelschaal voor de breedte; laat leeg om het preset + de schaal te gebruiken. |
| **Tegelpictogram-grootte (px)** | Exacte grootte van het tegelpictogram in pixels (14–64); de pictogramchip schaalt eromheen. Laat leeg om de preset-/%-schaalgrootte te gebruiken. |
| **E-mailafzender — Afzendernaam** | De naam waarvandaan plugin-e-mails worden verzonden. Leeg = de standaard WordPress-afzendernaam. |
| **E-mailafzender — Afzenderadres** | Het adres waarvandaan plugin-e-mails worden verzonden. Moet een geldig e-mailadres zijn; leeg of ongeldig valt terug op de WordPress-standaard. |

**Voorrang bij tegelgrootte:** het grootte-preset (compact / comfortabel / ruim) is de basis; de **%-tegelschaal** vermenigvuldigt dit; de px-velden voor **breedte / pictogram** overschrijven dat, indien ingesteld, voor respectievelijk de kolombreedte en de pictogramgrootte. Lege px-velden veranderen niets — het preset + de schaal bepalen het zoals voorheen.

## Hoe de datumnotatie wordt toegepast

Datumnotatie loopt via één helper, `TT\Shared\Dates\TTDate`, zodat de keuze van de academie op één plek wordt nageleefd in plaats van bij elke aanroep opnieuw te worden bepaald. De preset **Systeemstandaard** reproduceert exact de WordPress-datumnotatie, zodat een installatie die de instelling nooit aanraakt ongewijzigd blijft.

De datumnotatie wordt in de hele frontend toegepast overal waar een **volledige datum** wordt getoond — spelersprofielen, evaluaties, activiteiten, doelen, PDP-ondertekeningen, rapporten, scoutingbezoeken en de audit-stempels "aangemaakt / bijgewerkt". **Compacte kalenderlabels** (de `ma 31` / `31 dec`-dagcellen van de teamplanner en de afgekorte `31 dec '26`-kerngegevensdatums) houden bewust hun compacte notatie — de preset bepaalt volledige datums, niet ruimtebeperkte labels. De **teamplanner** respecteert ook de eerste dag van de week.

## De prompt om de app op mobiel te installeren

Spelers en ouders zien na het inloggen een banner die hen uitnodigt om
TalentTrack op hun telefoon te installeren (en pushmeldingen aan te zetten). De
toggle **Toon de prompt om de app op mobiel te installeren** in de Algemene
instellingen regelt dit academie-breed. Hij staat standaard **aan**. Zet hem uit
om de banner voor iedereen in je academie te verbergen — handig zodra je
gezinnen zijn ingericht en de aansporing niet meer nodig is. De per-apparaat
"sluiten" die een gebruiker kan aantikken blijft onafhankelijk werken.

## E-mailafzender

Standaard worden alle e-mails die TalentTrack verstuurt — accountuitnodigingen,
meldingen en Comms-berichten — verzonden als de standaard WordPress-afzender,
meestal **WordPress &lt;wordpress@jouwdomein&gt;**. Met de groep **E-mailafzender**
in de Algemene instellingen stel je academie-breed een vriendelijkere
afzenderidentiteit in:

- **Afzendernaam** — wat ontvangers als afzender zien, bijv. *Ajax Academy*.
- **Afzenderadres** — het From-adres, bijv. *noreply@academy.example*. Dit moet
  een geldig e-mailadres zijn.

Beide worden toegepast via de WordPress-filters `wp_mail_from` /
`wp_mail_from_name`, zodat elke plugin-e-mail ze oppikt. Laat een veld leeg om
voor dat deel de WordPress-standaard te behouden. Is het adres leeg of geen
geldig e-mailadres, dan valt TalentTrack terug op de standaardafzender — je
e-mail wordt nooit met een kapotte From-header verzonden. De waarden staan per
club in `tt_config`, zodat een toekomstige multi-tenant-installatie de afzender
van elke academie apart houdt.

> Deze instelling regelt alleen de **afzenderidentiteit**. Voor
> bezorgbaarheid (SPF / DKIM / een echte SMTP-relay) blijft een standaard
> WordPress-SMTP-plugin een goede aanvulling — die regelt het transport, dit
> regelt de afzendernaam en het adres.

## Zie ook

- [Configuratie en branding](configuration-branding.md)
- [Modules](modules.md)
