---
title: Wizard nieuwe evaluatie
group: performance
summary: De activiteit-eerst route om een hele selectie te beoordelen.
audience: [user, admin]
module: TT\Modules\Evaluations\EvaluationsModule
order: 15
---

# Nieuwe Evaluatie wizard

> Operator + coach referentie voor de activity-first nieuwe-evaluatie wizard opgeleverd in v3.75.0 (#0072).

## Wat het doet

Eén wizard met een **expliciete keuze vooraf**. De eerste stap vraagt
*wat* je evalueert, met twee grote knoppen:

- **Een activiteit evalueren** — *"Ik ben net klaar met de training met U14, laat me de spelers beoordelen die er waren."* Kies een recente rateable activiteit, de wizard toont aanwezige + te-laat-spelers, je legt aanwezigheid vast, beoordeelt optioneel, en één Submit legt alles vast.
- **1 speler evalueren** — *"Ik zag iets in een toernooi dat ik wil vastleggen."* Kies een speler, vul datum + context + ratings in (en een optionele gedragsbeoordeling), één Submit maakt één evaluatie zonder activiteit-koppeling.

Er is geen verborgen automatische keuze meer. Jij kiest het pad;
**Vorige** op de volgende stap brengt je terug naar de twee knoppen, dus
wisselen is één tik.

Elke "activiteit"-deur in de app landt nu in dezelfde flow: de
dashboard-hero **Aanwezigheid registreren**, de **Activiteit voltooien**-
knoppen en de knop *Een activiteit evalueren* van deze wizard bereiken
allemaal hetzelfde `mode=activity`-pad. (De oude `mark-attendance`-link
werkt nog steeds — die verwijst naar deze wizard met het activiteitpad.)

## Pad 1 — Een activiteit evalueren (de dagelijkse flow)

`Activiteit kiezen → Aanwezigheid → Nu beoordelen? → Spelers beoordelen → Controleren`

### Stap 1 · Activiteit kiezen

Toont rateable activiteiten van de laatste 90 dagen, op ploegen waaraan je via Functionele Rollen bent toegewezen (of alle ploegen als je HoD / Academy Admin bent), waar het activiteitstype als **rateable** is gemarkeerd in de lookups-admin (standaard: ja; standaard uit voor clinics, methodology-lectures en team-meetings).

Klik een activiteit aan en **Doorgaan**. Is de lijst leeg, dan zegt de stap dat — hij springt nooit stilletjes naar het spelerpad; ga **Terug** en kies *1 speler evalueren* om een speler zonder activiteit te beoordelen. (Deze stap wordt overgeslagen als een deur al een activiteit vooraf koos, bijv. de dashboard-hero of een Activiteit-voltooien-knop.)

### Stap 2 · Aanwezigheid

Stilletjes overgeslagen als de aanwezigheid al is geregistreerd. Anders: vink de status aan voor elke speler (aanwezig / te laat / afwezig / verontschuldigd). Standaard is **aanwezig**. Deze stap schrijft echte aanwezigheidsrijen, dus de activiteit zelf weerspiegelt ze daarna.

Voor het veelvoorkomende "iedereen was er"-geval is er bovenaan een sneltoets — **Iedereen was er - doorgaan** zet de hele selectie op aanwezig en gaat in één tik direct door naar beoordelen. Markeer eerst eventuele afwezigen op de kaarten hieronder als dat nodig is, en gebruik hem dan (of de gewone **Volgende**).

Alleen **aanwezig** + **te laat** spelers stromen door naar de beoordeelstap. Afwezig en verontschuldigd worden vastgelegd voor rapporten maar overgeslagen bij beoordelen.

### Stap 3 · Nu beoordelen?

De aanwezigheid is nu opgeslagen, dus de wizard vraagt of je de aanwezige spelers meteen wilt beoordelen. **Beoordeel de aanwezige spelers** gaat door naar de beoordeelstap. **Sla beoordelen over — ik doe het later** stopt hier (de activiteit blijft beschikbaar om later te beoordelen). **Sla beoordelen over — geen beoordeling nodig** stopt en sluit de activiteit voor beoordeling (omkeerbaar vanaf het activiteitdetail). Beide varianten markeren de activiteit als **voltooid**.

### Stap 4 · Spelers beoordelen

Voor elke aanwezige/te-late speler krijg je een rij per **snelbeoordeel-categorie** (standaard Technisch / Tactisch / Fysiek / Mentaal — clubs kunnen individuele categorieën aan/uit zetten via Configuratie → Evaluatiecategorieën). Typ een getal 1-5 (of wat je rating-schaal-max ook is).

Elke speler heeft een **Overslaan**-checkbox als je echt niet wilt beoordelen dit ronde — overslaan schrijft geen evaluatie-rij, maar de speler verschijnt nog steeds in aanwezigheid.

Voeg per-speler notities inline toe. Het deep-rate-panel voor een enkele speler is een follow-up — voor v1 zijn de snelbeoordeel-rij + het notities-tekstveld het oppervlak.

**Een speler vinden in een grote selectie:** boven de lijst filtert een **zoekvak** de spelers op naam terwijl je typt, en een schakelaar **Alleen nog niet beoordeeld** verbergt iedereen die je al beoordeeld of overgeslagen hebt, zodat je in één oogopslag ziet wie er nog over is. De schakelaar werkt op dezelfde live per-speler-status als de regel *"X van N spelers beoordeeld"*, dus een speler verdwijnt uit de nog-niet-beoordeeld-weergave zodra je hem beoordeelt. Beide zijn directe filters op je apparaat — ze veranderen nooit wat er wordt verstuurd.

**Standaard bij training:** wanneer de activiteit een trainingssessie is, wordt de categorie **Mentaal** als eerste getoond en alvast uitgeklapt (met de gedetailleerde subcategorieën zichtbaar). Dit is alleen een weergavestandaard — je kunt nog steeds elke andere categorie beoordelen en je bent nooit verplicht een Mentaal-score in te vullen om te kunnen opslaan.

Het activiteitpad gebruikt **snelbeoordelen** — alleen de hoofdcategorieën. Gedragsbeoordelingen zitten in het diepe pad *1 speler evalueren*, niet hier.

### Stap 5 · Controleren

Toont hoeveel evaluaties er gemaakt zullen worden. Is er een aanwezige speler ongerated en niet overgeslagen, dan krijg je bovenaan een zachte waarschuwing: *"X spelers waren aanwezig maar niet beoordeeld. Toch versturen, of terug?"* Beide knoppen beschikbaar.

Klik **Versturen**. De wizard schrijft één `tt_evaluations`-rij per beoordeelde speler met `activity_id` ingesteld, plus de per-categorie ratings, en markeert de activiteit als **voltooid**.

## Pad 2 — 1 speler evalueren (ad-hoc, diep)

`Speler kiezen → Diep beoordelen → Gedrag → Controleren`

### Stap 1 · Speler kiezen

Ploeg-gerichte speler-dropdown. Kies een ploeg en selecteer vervolgens de speler uit de lijst — typen is niet nodig. Coach je precies één ploeg, dan is die al voorgeselecteerd, zodat de spelerslijst meteen gevuld is wanneer de stap opent. Head of Development / Academy Admin kan via de ploegfilter (of "Alle teams") spelers uit de hele academie bereiken.

### Stap 2 · Hybride deep-rate

Datumkiezer (standaard vandaag), Type-dropdown (gestuurd door de `eval_type`-lookup), vrije-tekst-context (max 500 tekens), dan de rating-velden.

Elke hoofdcategorie is een **inklapbaar blok, standaard ingeklapt**. De samenvattingsregel toont de categorienaam, een alleen-lezen sterren-spiegel en het gemiddelde-woord, zodat je in één oogopslag ziet wat al beoordeeld is zonder iets uit te klappen. Tik op een categorie om die uit te klappen: beoordeel de categorie rechtstreeks, of beoordeel de subvaardigheden — het beoordelen van subvaardigheden zet de categorie op het afgeronde gemiddelde van de niet-nul subs, en de samenvatting volgt live. Inklappen behoudt elke waarde.

Zet je het Type op **Training**, dan springt de categorie **Mentaal** naar boven in de lijst en klapt automatisch open. Kies je een ander type, dan keert Mentaal terug naar de normale positie. Het blijft een standaard — een Mentaal-score is niet verplicht om op te slaan.

### Stap 3 · Gedrag (optioneel)

Gedrag wordt los van prestatie bijgehouden. Deze optionele ronde legt gedrag vast, geen voetbal: geef de speler een gedragsbeoordeling en een optionele regel notitie, of laat het leeg en tik op **Volgende**. De stap wordt volledig overgeslagen als je het gedragsbeoordelings-recht niet hebt. Dit is de enige plek waar gedrag wordt vastgelegd — het snelle activiteitpad vraagt er niet naar.

### Stap 4 · Controleren + Versturen

Eén evaluatie-rij. Versturen maakt één `tt_evaluations`-rij met `activity_id = NULL`, plus een gedragsrij als je die hebt ingevuld.

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

## Autosave (v3.78.0)

Elke wizard-stap slaat nu automatisch op. Terwijl je typt of een veld wijzigt wacht de wizard ~800ms en POST't dan stilletjes je input naar `POST /wp-json/talenttrack/v1/wizards/{slug}/draft`, die de patch in je `tt_wizard_drafts`-rij merget. Een kleine statustekst naast de actie-knoppen toont de toestand — "Autosave klaar" → "Opslaan…" → "Opgeslagen · 14:32".

Tijdens autosave draait er geen validatie; dat is bewust. Half-getypte input is het hele punt. Validatie draait wel bij **Volgende** via het normale submit-pad van de stap. Valt het netwerk weg, dan toont de tekst "Opslaan mislukt" en de volgende typeburst probeert automatisch opnieuw.

## Resume-banner (v3.78.0)

Wanneer je een wizard heropent met een concept ouder dan ~10 minuten (het cross-session-signaal), verschijnt bovenaan een banner met *"Je bent 2 uur geleden begonnen. Doorgaan waar je gebleven was, of opnieuw beginnen?"* Klik **Doorgaan** om verder te gaan, of **Opnieuw beginnen** om het concept te wissen en vers te starten. Same-session refreshes (binnen 10 minuten) slaan de banner over omdat er niets te hervatten valt.

## Per-speler voortgang bij verzenden (v3.78.0)

Review-stap Verzenden POST't nu één rij per evaluatie naar `POST /wp-json/talenttrack/v1/wizards/new-evaluation/insert-row`, met een voortgangsbalk en "Evaluatie 3 van 12 wegschrijven…"-status. Dezelfde DB-rijen als voorheen; het enige verschil is zichtbare feedback tijdens een batch van 12 spelers. Browsers zonder JS vallen terug op het v3.75.0 PHP-only one-shot submit.

## Wat staat nog op de roadmap

Deze polish-items staan in de wachtrij als follow-ups:

- Locked / Editable-badges op de activiteitkiezer (24-uurs edit-venster met aftelling, "Bewerken (post-window)" voor HoD/Admin).
- Mobiel-vs-desktop responsive splitsing voor de beoordeelstap (één-speler-tegelijk op mobiel vs volledige verticale lijst op desktop, met swipe-gestures).
