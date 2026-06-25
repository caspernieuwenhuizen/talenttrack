<!-- audience: admin -->

# Seizoensovergang

Met de seizoensovergang verplaats je aan het einde van een seizoen in één
begeleide stap hele teams naar een hogere leeftijdsgroep, en leg je voor elke
betrokken speler een gedateerde gebeurtenis in de tijdlijn vast. Je opent het
scherm vanaf het dashboard via **Mensen → Seizoensovergang**
(`?tt_view=season-rollover`); je hebt de rechten **Spelers beheren**
(`tt_manage_players`) nodig.

## Wat het wel en niet doet

In deze versie doet de overgang per speler precies twee dingen:

1. **De speler naar een doelteam verplaatsen** (bij een promotie), en
2. **Een gedateerde tijdlijngebeurtenis vastleggen** die de wijziging
   beschrijft.

Het maakt **geen** seizoensentiteit aan en wijst die ook niet toe, en het
**archiveert niemand**. Een speler die je als **Vrijgegeven** markeert, krijgt
een gedateerde gebeurtenis `released`, maar blijft **actief** — een speler hier
vrijgeven start nooit de bewaartermijn en verwijdert hem niet uit de selectie.
Archiveren blijft een aparte, bewuste handeling.

## De drie stappen

Het proces is een apart scherm met meerdere stappen. Alleen de laatste stap
wijzigt gegevens.

### 1. Teams koppelen

Voor elk bestaand (niet-gearchiveerd) team kies je een **doelteam** om de
spelers naartoe te promoveren, of je laat het op *Geen promotie / blijft*
staan. Je stelt ook in:

- **Ingangsdatum** — de datum op elke tijdlijngebeurtenis die deze ronde
  aanmaakt. Standaard is dat de einddatum van het huidige seizoen als die is
  ingesteld, anders vandaag.
- **Reden** (optioneel) — vrije tekst die wordt toegevoegd aan de samenvatting
  van vrijgave- en afstudeergebeurtenissen (bijvoorbeeld *Einde seizoen
  2025/26*).

### 2. Spelers kiezen

Voor elk gekoppeld team krijg je een lijst met de actieve spelers. Elke
geselecteerde speler heeft een actie:

- **Promoveren** — naar het gekoppelde doelteam verplaatsen (standaard als het
  team een doelteam heeft). Legt een gebeurtenis *Leeftijdsgroep gepromoveerd*
  vast.
- **Vrijgeven** — legt een gebeurtenis *Vrijgegeven* vast. De speler **blijft
  actief**.
- **Afstuderen** — legt een gebeurtenis *Afgestudeerd* vast.
- **Overslaan** — doe niets voor deze speler.

Vink een speler uit om hem ongemoeid te laten.

### 3. Controleren en bevestigen

Een alleen-lezen tabel toont de exacte wijziging voor elke geselecteerde
speler — speler, vanaf-team, naar-team, actie en de tijdlijngebeurtenis die
wordt vastgelegd. Wanneer je bevestigt:

- Wordt er **eerst een volledige back-up gemaakt**. Mislukt de back-up, dan
  wordt de hele overgang **afgebroken** en wordt er niets gewijzigd.
- Pas na een geslaagde back-up worden de teamverplaatsingen en
  tijdlijngebeurtenissen weggeschreven.
- Word je teruggeleid naar een samenvattingsbalk (aantallen gepromoveerd /
  vrijgegeven / afgestudeerd / overgeslagen). De pagina vernieuwen kan de
  overgang niet opnieuw uitvoeren.

## REST-API

Dezelfde logica is via REST beschikbaar voor front-ends buiten WordPress:

- `POST /wp-json/talenttrack/v1/season-rollover/plan` — testronde. Geeft de
  wijzigingenlijst per speler en de aantallen terug zonder iets te wijzigen.
- `POST /wp-json/talenttrack/v1/season-rollover/execute` — voert de overgang
  uit (eerst back-up) en geeft de aantallen en de back-upbestandsnaam terug.

Beide vereisen het recht `tt_manage_players`. De aanvraag bevat een object
`mapping` (`source_team_id` → `target_team_id`), een object `selections`
(`player_id` → actie), een `effective_date` (`JJJJ-MM-DD`) en een optionele
`reason`.
