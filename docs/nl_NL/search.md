---
title: Zoeken
group: basics
summary: Het zoekveld in de bovenbalk en de resultatenpagina erachter, om vanaf elk scherm een speler, team, activiteit, persoon, instelling of helponderwerp te vinden, en voor ouders en spelers hun eigen records.
audience: [user, admin, player, parent]
views: [search]
order: 22
---

# Zoeken

Vind een speler, team, activiteit, persoon, instelling of helponderwerp vanaf
elk scherm, zonder eerst terug te gaan naar het dashboard.

## Het zoekveld

Het veld staat in de bovenbalk, in beide navigatie-indelingen, op elk scherm.
Typ erin en de resultaten verschijnen eronder, gegroepeerd:

1. Spelers
2. Teams
3. Activiteiten
4. Evaluaties, doelen en berichten (voor ouders en spelers)
5. Mensen
6. Instellingen
7. Onderdelen
8. Help

Elke groep toont de beste vier spelers of drie van de rest. Zijn er meer, dan
opent een link *Alle* onderaan de groep de volledige lijst op de
resultatenpagina. De letters die je typte staan vet.

- **Een speler** toont de foto, of de initialen als er geen foto is, en het
  team. Naast de naam staan maximaal twee snelle acties, zoals *Nieuwe
  evaluatie* of *Doelen*. Je ziet alleen een actie die je mag gebruiken.
- **Een instelling** toont het onderdeel van de configuratie waar hij staat.
- **Een activiteit** toont de datum en het team.

Een leeg veld toont wat je het laatst opende: de spelers, teams en activiteiten
waarvan je de pagina bezocht, nieuwste eerst. Een record dat je niet meer mag
openen verdwijnt vanzelf van die lijst. Zolang je nog niets hebt geopend, toont
het lege veld de onderdelen die je kunt bereiken.

### Met het toetsenbord

| Toets | Wat het doet |
| --- | --- |
| **Ctrl+K** (**⌘K** op een Mac) | Spring vanaf elke plek naar het veld |
| **↓** / **↑** | Loop door de resultaten |
| **Enter** | Open het gekozen resultaat; zonder keuze opent de resultatenpagina |
| **Escape** | Sluit de lijst; nog eens drukken maakt het veld leeg |

### Op een telefoon

In de bovenbalk staat een zoekknop in plaats van het veld. Tik erop en het
zoeken opent over het hele scherm, met het veld klaar om te typen. De
sluitknop, Escape en de terugknop van je telefoon sluiten het, en je bent terug
waar je was.

## Wat je kunt typen

Accenten en hoofdletters maken niet uit: *jose* vindt José. Elk woord dat je
typt moet kloppen, dus *den b* vindt Daan den Besten. Een paar verkortingen
worden herkend, en het veld zegt dat boven de resultaten:

- **#7** vindt de spelers met rugnummer 7;
- **O14** vindt de O14-teams en hun spelers (**MO14** alleen de meidenteams);
- **12-10** vindt de activiteiten op 12 oktober van dit seizoen;
- een weekdag zoals **zaterdag** of **saturday** vindt de activiteiten op
  afgelopen en komende zaterdag.

## De resultatenpagina

Druk op Enter in het veld, of volg een link *Alle*, om alle resultaten te
openen.

- **Soort**: een rij knoppen, één per soort resultaat, elk met het aantal. Tik
  op een knop om alleen die soort te zien; *Alle* toont weer alles. Op een
  telefoon schuift de rij opzij.
- **Team**: alleen de records van één team. De lijst bevat de teams die je kunt
  zien.
- **Seizoen**: alleen de activiteiten van één seizoen.
- Resultaten staan met twintig per pagina, met *Vorige* en *Volgende* eronder.

Het adres van de pagina bevat wat je zocht en elk filter, dus je kunt hem als
bladwijzer bewaren of naar een collega sturen. Die ziet alleen wat hij zelf mag
openen.

Komt er niets uit, dan stelt de pagina voor wat je anders kunt proberen.

## Wat je kunt vinden

Alleen wat je al mag openen. Zoeken verbreedt niet wat je mag bereiken; het
maakt bereiken sneller. Een record dat je niet mag openen wordt niet getoond en
niet geteld, dus een zoekopdracht ernaar leest precies als een zoekopdracht naar
een naam die niet bestaat.

## Voor ouders

Een trainer appt je dat een training "nu in TalentTrack staat". Zo vind je hem:
hetzelfde zoekveld en dezelfde resultatenpagina.

Je doorzoekt vier soorten records, van je eigen kinderen en van niemand anders:

- **Activiteiten**: trainingen, wedstrijden en andere sessies waar je kind bij
  is, ook de sessies die nog komen.
- **Evaluaties**: wat een trainer over je kind schreef. Het soort evaluatie
  wordt in je eigen taal gevonden, dus *wedstrijdevaluatie* vindt een
  wedstrijdevaluatie.
- **Doelen**: waar je kind aan werkt.
- **Berichten**: wat de academie je stuurde.

Typ een naam of een titel (*training*, *Ajax*), of een datum: *3 nov*,
*3 november*, *2026-11-03* en *12-10* werken allemaal. Een datum vindt de
activiteiten op die dag, de evaluaties van die dag, de doelen die dan
afgerond moeten zijn en de berichten die toen verstuurd zijn. Twee tekens is
de kortste zoekopdracht die iets vindt.

Met meer dan één kind doorzoek je ze allemaal tegelijk. Bij een evaluatie of
een doel staat over welk kind het gaat, en elk resultaat opent bij het juiste
kind. Een onderdeel dat je kind privé houdt, wordt niet
doorzocht.

Het zoeken komt nooit bij een ander gezin, en vertelt je nooit dat een record
van een ander gezin bestaat: een zoekopdracht die bij het kind van iemand
anders zou passen, geeft precies hetzelfde terug als een zoekopdracht naar een
verzonnen naam. Ouders krijgen geen snelle acties en geen instellingen.

Is er nog geen kind aan je account gekoppeld, dan zegt de pagina dat: vraag de
academie om je te koppelen.

**Alles zien wat eraan komt.** De activiteitenpagina van je kind opent op wat
al geweest is. Met **Toon komende** bovenaan de lijst zie je alles wat nog
komt, eerstvolgende eerst. Het blok **Binnenkort** boven de lijst toont altijd
de eerstvolgende paar.

## Voor spelers

Je vindt:

- **jezelf**, en opent dan je eigen ontwikkelingspagina;
- **je teamgenoten**: de spelers die je pagina *Mijn team* toont, op naam,
  en opent dan hun teamgenotenkaart;
- **de activiteiten van je eigen team**;
- **je eigen evaluaties en doelen**.

Je vindt nooit de evaluaties of doelen van een andere speler, geen notities en
geen instellingen, en je krijgt geen snelle acties.

## Voor ontwikkelaars

Het veld en de pagina lezen dezelfde REST-routes: `GET /talenttrack/v1/search`
met `page` voor de gegroepeerde en gepagineerde resultaten,
`GET /talenttrack/v1/me/recent` voor de lijst van laatst geopend, en
`POST /talenttrack/v1/me/recent` om een geopend record vast te leggen. Het
openen van een spelers-, team- of activiteitpagina legt dat op de server vast,
zodat elke frontend dezelfde lijst vult. `GET /talenttrack/v1/me/search` blijft
bestaan voor wie hem al gebruikt: hij antwoordt in zijn oude vorm (`children`,
`results`, `total`), opgebouwd uit dezelfde zoekmachine. Het oude
helponderwerp over het zoeken voor ouders opent dit onderwerp. Zie
[REST API](rest-api.md).
