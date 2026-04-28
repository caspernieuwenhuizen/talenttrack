<!-- audience: user -->

# Wizards voor het aanmaken van records

De "+ Nieuw"-knoppen op Spelers, Teams, Evaluaties en Doelen kunnen óf het bekende formulier op één pagina openen, óf een stapsgewijze **wizard** die per scherm één of twee gerichte vragen stelt. De wizard is mobielvriendelijk, splitst de flow bij keuzes die de rest beïnvloeden (stage- versus selectie-speler) en vult zinnige standaardwaarden voor.

## Wie ziet wat

- **Iedereen met rechten om het record aan te maken** ziet de wizard zodra die aan staat. De capability-controle is dezelfde als bij het platte formulier.
- **Beheerders** kunnen onder **Beheer → Wizards** kiezen welke wizards aan staan en zien per wizard de completion-statistieken.

## Hoe de wizards werken

Elke wizard is een korte serie pagina's:

- Een voortgangsbalk laat zien waar je bent.
- De knop rechtsonder gaat naar de volgende stap. De knop in het midden slaat de huidige stap over. De link annuleert.
- Sluit je tussentijds af, dan blijven antwoorden een uur bewaard zodat je later op dezelfde URL terug kunt komen.
- Veranderen je antwoorden onderweg — bijvoorbeeld een wisseling van "selectie" naar "stage" — dan worden alleen de nog relevante stappen opnieuw doorlopen.

## Wat zit er in elke wizard

### Nieuwe speler

1. **Type speler** — selectie (sluit aan bij het team) of stage (komt 2 tot 6 weken meedoen). De rest van de flow vertakt hier.
2. **Spelerdetails** (selectie) — naam, geboortedatum, team, rugnummer, voorkeursvoet.
2. **Stagedetails** (stage) — naam, geboortedatum, team waar voor stage gelopen wordt, traject (Standaard / Scout / Keeper), start- en einddatum.
3. **Controle** — bevestig en maak aan.

De stagebranche opent automatisch een echt stagedossier, zodat de zojuist toegevoegde speler meteen onder **Stagedossiers** verschijnt met de datums ingevuld. (Zonder de Stagemodule krijgt de speler in elk geval status "Stage" zodat je later terug kunt komen.)

### Nieuw team

1. **Basis** — teamnaam, leeftijdsgroep, eventuele notities.
2. **Staf** — hoofdcoach, assistent-coach, teammanager, fysio. Elke positie is afzonderlijk over te slaan.
3. **Controle** — bevestig en maak aan. Elke ingevulde stafpositie wordt een `tt_team_people`-rij gekoppeld aan de bijbehorende functionele rol; ontbrekende `tt_people`-records worden automatisch aangemaakt vanuit de WP-gebruiker.

### Nieuwe evaluatie

1. **Speler** — kies de speler.
2. **Type** — kies het evaluatietype en de datum.

De wizard opent vervolgens het bestaande evaluatieformulier, voorgevuld met die keuzes. Het volledige formulier (categorieën, sub-ratings, bijlagen) is hetzelfde als voorheen — de wizard zorgt alleen dat je in één keer op het juiste formulier landt.

### Nieuw doel

1. **Speler** — kies de speler.
2. **Methodologie-link** — optioneel: koppel het doel aan een principe, voetbalhandeling, positie of waarde. (Overslaan = doel zonder link.)
3. **Details** — titel, omschrijving, prioriteit, einddatum.

De wizard maakt het doel direct aan. Heb je in stap 2 een link gekozen, dan wordt ook een `tt_goal_links`-rij toegevoegd.

## Wizards aan- of uitzetten

Beheerders gaan naar **Beheer → Wizards**:

- `all` — alle geregistreerde wizards staan aan (standaard).
- `off` — alle wizards uit; de oorspronkelijke formulieren komen terug.
- Een door komma's gescheiden lijst van wizard-slugs (bijv. `new-player,new-team`) — alleen de genoemde staan aan.

Beschikbare slugs: `new-player`, `new-team`, `new-evaluation`, `new-goal`.

## Completion-analytics

Dezelfde beheerpagina toont per wizard:

- **Gestart** — hoe vaak de wizard is geopend.
- **Afgerond** — hoe vaak de laatste stap succesvol is opgeslagen.
- **Completion-percentage** — afgerond ÷ gestart.
- **Meest overgeslagen stap** — welke stap het vaakst wordt overgeslagen (vaak een hint dat die stap overbodig is of de verkeerde vraag stelt).

Tellers staan in `wp_options` en blijven optellen; reset ze door die opties te legen wanneer je een wizard hebt verfijnd.
