<!-- audience: admin -->

# Wedstrijdminuten per leeftijdscategorie

Jeugdvoetbal kent per leeftijdsband een andere wedstrijdduur — U8 speelt
misschien 2 x 20, U13 2 x 30, U17 2 x 35. Met TalentTrack stel je die standaard
**eenmalig per leeftijdscategorie** in, zodat de geregistreerde minuten kloppen
zonder dat je voor elke wedstrijd opnieuw een wedstrijdduur hoeft in te typen.

Kloppende minuten zijn belangrijk omdat ze het belastings- en ontwikkelbeeld van
elke speler voeden: het minutenrapport, de wedstrijduitvoering en de directe
invoer bij wedstrijdafronding bouwen er allemaal op voort.

## Waar stel je het in

Configuratie -> **Wedstrijdminuten**.

Het formulier toont elke leeftijdscategorie uit je opzoeklijst
**Leeftijdsgroepen** (Configuratie -> Opzoeklijsten -> Leeftijdsgroepen). Vul
voor elke categorie de **minuten per helft (N)** in. De volledige wedstrijdduur
— **2 x N** — verschijnt naast het invoerveld terwijl je typt.

- Laat een rij **leeg** om de globale terugval van **35 minuten per helft**
  (70 totaal) over te nemen.
- Waarden zijn hele minuten per helft, 0-60.
- De instelling wordt opgeslagen zodra je op **Wedstrijdminuten opslaan** drukt.

Bestaan er nog geen leeftijdscategorieën, voeg ze dan eerst toe onder
Leeftijdsgroepen en kom daarna terug.

## Waar de standaard wordt gebruikt

Eenmaal ingesteld is de standaard per leeftijdscategorie de enige bron van
waarheid voor de wedstrijdduur. De standaard vult alvast in:

- **Wedstrijdvoorbereiding** — een nieuwe wedstrijdvoorbereiding voor een team
  start op de helft-duur van de leeftijdscategorie van dat team in plaats van een
  vaste 35.
- **Wedstrijdafronding** — wanneer een wedstrijdactiviteit op Afgerond wordt
  gezet, wordt het veld **Wedstrijdduur** boven de aanwezigheidstabel vanuit
  dezelfde bron ingevuld (een expliciete waarde die al op de activiteit of haar
  wedstrijdvoorbereiding is opgeslagen, wint nog steeds).

In alle gevallen blijft de ingevulde waarde **per wedstrijd aanpasbaar** — de
laatste invoer wint. Het wijzigen van de centrale standaard herschrijft geen
wedstrijdduren die al voor eerdere wedstrijden zijn vastgelegd.

## Volgorde van bepaling

Voor een gegeven wedstrijd wordt de helft-duur van meest specifiek naar minst
specifiek bepaald:

1. een expliciete waarde per wedstrijd die al op de activiteit of haar
   wedstrijdvoorbereiding is opgeslagen;
2. de **standaard per leeftijdscategorie** voor de leeftijdsgroep van het team
   (`match_minutes_by_age_group`);
3. de globale terugval van **35** minuten per helft.

## Minuten per speler vastleggen

Je hebt de match-execution-flow vanaf de zijlijn niet nodig. Op elke
wedstrijdactiviteit met de status **Voltooid** toont de aanwezigheidstabel een
kolom **Minuten** per speler naast de status. Vul de minuten in die elke speler
op het veld stond en sla de activiteit op. Die minuten worden opgeslagen als de
vastgelegde aanwezigheid van de speler voor die wedstrijd en zijn precies wat de
minutenrapporten tellen.

Dit is de "papieren wedstrijd"-route: een coach die de wedstrijd zonder live
tracking speelde, kan toch accurate minuten op één plek vastleggen.

**Voorrang.** Voer je later de volledige match-execution-flow voor dezelfde
wedstrijd uit, dan is de herberekening uit de execution leidend en overschrijft
ze de handmatig ingevoerde minuten. Handmatige invoer vult de leemte tot (of
tenzij) er execution-gegevens bestaan.

Alleen **werkelijke**, niet-gast-aanwezigheid telt mee voor de rapporten —
geplande (verwachte) selectierijen en gastoptredens verhogen de minuten van een
speler nooit.

## API

De standaarden worden opgeslagen in `tt_config` onder de JSON-sleutel
`match_minutes_by_age_group` (een toewijzing van leeftijdsgroepnaam naar minuten
per helft) en zijn leesbaar en schrijfbaar via het configuratie-REST-eindpunt:

- `GET /wp-json/talenttrack/v1/config`
- `POST /wp-json/talenttrack/v1/config`

Zo krijgt een toekomstige front-end exact dezelfde standaarden die de
weergegeven formulieren gebruiken.
