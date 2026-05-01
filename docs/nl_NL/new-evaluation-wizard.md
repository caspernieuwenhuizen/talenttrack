<!-- audience: user, admin -->

# Nieuwe Evaluatie wizard

> Operator + coach referentie voor de activity-first nieuwe-evaluatie wizard opgeleverd in v3.75.0 (#0072).

## Wat het doet

Eén wizard met twee paden. Kies het pad dat past bij wat je daadwerkelijk hebt gedaan:

- **Activiteit-eerst** — *"Ik ben net klaar met de training met U14, laat me de spelers beoordelen die er waren."* Kies een recente rateable activiteit, de wizard toont aanwezige + te-laat-spelers, je beoordeelt elk, één Submit maakt N evaluaties.
- **Speler-eerst (ad-hoc)** — *"Ik zag iets in een toernooi dat ik wil vastleggen."* Kies een speler, vul datum + context + ratings in, één Submit maakt één evaluatie zonder activiteit-koppeling.

De wizard kiest automatisch het pad. Heb je minstens één rateable activiteit in de laatste 30 dagen op een ploeg die je traint, dan land je op de activiteitkiezer. Anders land je direct op de spelerkiezer. Beide landingen hebben een ontsnappingslink naar het andere pad.

## Pad 1 — activiteit-eerst (de dagelijkse flow)

### Stap 1 · Activiteit kiezen

Toont rateable activiteiten van de laatste 30 dagen, op ploegen waaraan je via Functionele Rollen bent toegewezen (of alle ploegen als je HoD / Academy Admin bent), waar het activiteitstype als **rateable** is gemarkeerd in de lookups-admin (standaard: ja; standaard uit voor clinics, methodology-lectures en team-meetings).

Klik een activiteit aan en **Doorgaan**. Of klik **→ Beoordeel direct een speler** om naar het speler-eerst-pad over te schakelen.

### Stap 2 · Aanwezigheid

Stilletjes overgeslagen als de aanwezigheid al is geregistreerd. Anders: vink de status aan voor elke speler (aanwezig / te laat / afwezig / verontschuldigd). Standaard is **aanwezig**. Deze stap schrijft echte aanwezigheidsrijen, dus de activiteit zelf weerspiegelt ze daarna.

Alleen **aanwezig** + **te laat** spelers stromen door naar de beoordeelstap. Afwezig en verontschuldigd worden vastgelegd voor rapporten maar overgeslagen bij beoordelen.

### Stap 3 · Spelers beoordelen

Voor elke aanwezige/te-late speler krijg je een rij per **snelbeoordeel-categorie** (standaard Technisch / Tactisch / Fysiek / Mentaal — clubs kunnen individuele categorieën aan/uit zetten via Configuratie → Evaluatiecategorieën). Typ een getal 1-5 (of wat je rating-schaal-max ook is).

Elke speler heeft een **Overslaan**-checkbox als je echt niet wilt beoordelen dit ronde — overslaan schrijft geen evaluatie-rij, maar de speler verschijnt nog steeds in aanwezigheid.

Voeg per-speler notities inline toe. Het deep-rate-panel voor een enkele speler is een follow-up — voor v1 zijn de snelbeoordeel-rij + het notities-tekstveld het oppervlak.

### Stap 4 · Controleren

Toont hoeveel evaluaties er gemaakt zullen worden. Is er een aanwezige speler ongerated en niet overgeslagen, dan krijg je bovenaan een zachte waarschuwing: *"X spelers waren aanwezig maar niet beoordeeld. Toch versturen, of terug?"* Beide knoppen beschikbaar.

Klik **Versturen**. De wizard schrijft één `tt_evaluations`-rij per beoordeelde speler met `activity_id` ingesteld, plus de per-categorie ratings.

## Pad 2 — speler-eerst (ad-hoc)

### Stap 1 · Speler kiezen

Zoek-gebaseerde picker (autocomplete op spelernaam + ploeg). Selecteer de speler die je hebt geobserveerd.

### Stap 2 · Hybride deep-rate

Datumkiezer (standaard vandaag), context-dropdown (training / match / toernooi / observatie / anders — gestuurd door de `evaluation_setting`-lookup), vrije-tekst-context (max 500 tekens), dan de rating-velden per categorie.

### Stap 3 · Controleren + Versturen

Eén evaluatie-rij. Versturen maakt één `tt_evaluations`-rij met `activity_id = NULL`.

## Cross-device concepten

Concepten blijven bewaard over browsers en apparaten. Begin je met beoordelen op je telefoon en maak je het niet af, dan kun je later op je desktop verder waar je gebleven was — zelfde activiteit, zelfde gedeeltelijke ratings, zelfde notities.

Het persistent-opslag bewaart concepten **14 dagen**. Verouderde concepten worden door een dagelijkse cron opgeruimd. Wil je club een andere TTL, dan kan dat met een `tt_wizard_draft_ttl_days`-filter in een eigen plugin.

## Wie kan dit gebruiken

- **Assistent-trainer** — RC team op evaluaties. Kan ratings maken + bewerken op ploegen waaraan hij/zij is toegewezen.
- **Hoofdtrainer** — RCD team. Hetzelfde plus verwijderen.
- **Hoofd Ontwikkeling / Academy Admin** — RCD global. Overal.
- **Teammanager** — alleen R team. De wizard is correct ontoegankelijk.
- **Speler / Ouder** — geen toegang (de wizard is alleen voor staf).

## Activiteitstypes als rateable markeren

In Configuratie → Lookups → Activity Types heeft elke rij een **Rateable**-checkbox. Wanneer uitgevinkt verdwijnen activiteiten van dat type uit de activiteitkiezer van de nieuwe-evaluatie-wizard — ze blijven overal anders zichtbaar (de activiteit zelf, statistieken, rapporten). Handig voor clinics, methodology-lectures, team-meetings.

## Categorieën als snelbeoordeel markeren

In Configuratie → Evaluatiecategorieën hebben hoofd-categorieën een **Snelbeoordelen**-vlag (in `meta.quick_rate`). Snelbeoordeel-categorieën verschijnen als één-regel-rij in de beoordeelstap. Niet-snel categorieën leven in het deep-rate-panel (follow-up). Standaard seed: Technisch / Tactisch / Fysiek / Mentaal.

## Wat staat nog op de roadmap

De v1-wizard is functioneel + data-correct. Deze polish-items staan in de wachtrij als follow-ups:

- Per-speler voortgangsindicator bij Versturen (N × POST voortgang).
- Locked / Editable-badges op de activiteitkiezer (24-uurs edit-venster met aftelling, "Bewerken (post-window)" voor HoD/Admin).
- Autosave-indicator + het lichtgewicht `POST /wizard-drafts/{slug}` REST-endpoint voor snel typing-debounce.
- Mobiel-vs-desktop responsive splitsing voor de beoordeelstap (één-speler-tegelijk op mobiel vs volledige verticale lijst op desktop, met swipe-gestures).
- Resume-banner ("Je bent hier 2 dagen geleden begonnen — doorgaan of opnieuw beginnen?") bij entry.
