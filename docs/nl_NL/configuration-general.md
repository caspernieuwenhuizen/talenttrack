---
title: Configuratie — Algemeen
group: configuration
summary: Naam van de academie, taal, beoordelingsschaal en de overige installatiebrede instellingen.
audience: [admin]
order: 110
---

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

**Geplande activiteiten dragen hun weekdag.** Een activiteit staat in de agenda, en een coach denkt in "de training van vrijdag" in plaats van "de 11e" — daarom tonen de detailpagina van een activiteit, het datumgegeven, wedstrijdvoorbereiding, wedstrijdanalyse, de wedstrijdenlijst en het beoordelingsraster de weekdag *vóór* de notatie die jij hebt gekozen: `vr 11-09-2026` als je `31-12-2026` koos, `vr 2026-09-11` als je ISO koos. De weekdag komt erbij, nooit in plaats van je notatie, en wordt geschreven zoals jouw taal hem schrijft (Nederlands `vr`, niet `Vr`). Staat de instelling op **Systeemstandaard** en bevat je WordPress-datumnotatie al een weekdag, dan wordt er niets toegevoegd — hij verschijnt niet dubbel.

Datums die géén geplande activiteit zijn, houden de kale notatie: een geboortedatum van een speler, een ondertekenstempel, een auditregel. Een weekdag is daar ruis.

De datumnotatie wordt toegepast overal waar een **volledige datum** wordt getoond — spelersprofielen, evaluaties, activiteiten, doelen, PDP-ondertekeningen, rapporten, scoutingbezoeken, blessures, metingen, de prullenbak en de audit-stempels "aangemaakt / bijgewerkt". Ze geldt ook voor alles wat het scherm verlaat: **gegenereerde pdf's, spreadsheet-exports, stagebrieven en de e-mails die TalentTrack verstuurt**. Een afgedrukt rapport en de pagina waar het vandaan komt tonen de datum op dezelfde manier.

**Compacte kalenderlabels** (de `ma 31` / `31 dec`-dagcellen van de teamplanner en de afgekorte `31 dec '26`-kerngegevensdatums) houden bewust hun compacte notatie — de preset bepaalt volledige datums, niet ruimtebeperkte labels. De **teamplanner** respecteert ook de eerste dag van de week.

**Tijden staan los.** De preset bepaalt de datum; de klok volgt de eigen instelling **Tijdnotatie** van WordPress. Een tijdstempel bij een bericht is jouw datumnotatie gevolgd door jouw tijdnotatie.

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

## Navigatie-indeling

Kiest de standaard applicatie-shell voor de hele academie — **Klassiek**
(tegeloverzicht, geen navigatiebalk) of **App-shell** (een navigatiezijbalk op
een laptop, een uitschuifmenu op een telefoon). Klassiek is de standaard.

Dit is de standaard die mensen overerven, geen slot: iedereen kan onder *Mijn
instellingen → Indeling* zijn eigen keuze maken, inclusief "gebruik de standaard
van de academie", die deze instelling blijft volgen wanneer je haar wijzigt.

Volledige uitleg in [Navigatie-indeling (de frontend-shell)](frontend-shell.md).

## Wedstrijddag

Kiest de standaardindeling van het livewedstrijdscherm voor de hele academie —
**Klassiek** (alles op één lange pagina) of **Secties** (de stand en de klok
staan vast bovenaan, met een rij tabs binnen duimbereik onderaan, zodat de bank
één tik ver is in plaats van drie keer scrollen). Klassiek is de standaard.

Net als bij de navigatie-indeling hierboven is dit de standaard die mensen
overerven, geen slot: elke trainer kan onder *Mijn instellingen →
Livewedstrijdscherm* zijn eigen keuze maken, inclusief "gebruik de standaard van
de academie", die deze instelling blijft volgen wanneer je haar wijzigt.

Dat telt hier zwaarder dan bijna overal elders in het product. Een trainer
bedient dit scherm met één hand, op een telefoon, langs de lijn, met een lopende
klok — hem halverwege het seizoen op een onbekende indeling zetten is precies hoe
speelminuten en wissels niet worden vastgelegd. Zet één trainer een zaterdag op
Secties, kijk hoe het bevalt, en zet daarna de academie om.

## Zie ook

- [Configuratie en branding](configuration-branding.md)
- [Navigatie-indeling (de frontend-shell)](frontend-shell.md)
- [Modules](modules.md)
