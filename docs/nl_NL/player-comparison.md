<!-- audience: user -->

# Spelervergelijking

Vergelijk tot 4 spelers naast elkaar. Over teams heen vergelijken is juist het doel — een U15 LB vergelijken met een U13 ST is valide voor scouting, transferbeslissingen en ontwikkelingsgesprekken.

## Waar vind je het

**Beheer**: `TalentTrack → Spelervergelijking`. Volledige versie met overlappende radarchart, overlappende trendchart en gedetailleerde uitsplitsing naar hoofdcategorie.

**Frontend (v3.0.0+)**: de tegel **Spelervergelijking** op de tegellandingspagina. Gestroomlijnde mobile-first versie met slot-kiezers, rij FIFA-kaarten, basisgegevens, kerncijfers en een uitsplitsingstabel op hoofdcategorie. Zonder de overlay-charts — daarvoor blijft de beheer-versie bestaan.

## Slot-kiezers

Vier slots (p1 / p2 / p3 / p4). Kies een willekeurige speler in een willekeurig slot; laat leeg voor minder dan 4. Labels in de kiezer tonen `Achternaam, Voornaam — Team (Leeftijdscategorie)` om verwarring te voorkomen — handig als twee U13-teams elk een "A. Kovač" in de selectie hebben.

## Filters werken op alle slots

Datum van, Datum tot, Evaluatietype — werken uniform op elke gekozen speler zodat de kerncijfers op dezelfde basis worden berekend. Verander een filter en alle 4 spelers worden opnieuw doorgerekend.

## Wat de frontend-versie toont

- **Rij FIFA-kaarten** — 4 kleine kaarten naast elkaar (horizontaal scrollen op telefoons)
- **Basisgegevens-tabel** — Team, Leeftijdscategorie, Posities, Voet, Rugnummer, Lengte — één kolom per speler
- **Kerncijfers** — Meest recente, Rollend (laatste 5), All-time, Aantal evaluaties — één kolom per speler
- **Gemiddelden per hoofdcategorie** — gemiddelde beoordeling per categorie, één kolom per speler

## Wat in beheer zit maar niet in de frontend

- **Radar-overlay** — alle 4 spelers getekend op dezelfde spin-grafiek voor een vormvergelijking
- **Trend-overlay** — alle 4 spelerstrendlijnen op dezelfde grafiek voor een trajectvergelijking

Deze gebruiken Chart.js met een aangepaste multi-dataset-configuratie. Toevoeging aan de frontend zou ~200 regels extra JS betekenen — bewust uitgesteld. Beheerders die ze willen gaan naar `TalentTrack → Spelervergelijking`.

## Melding bij gemengde leeftijdscategorieën

Als je spelers uit verschillende leeftijdscategorieën vergelijkt, toont de view een melding: overall-beoordelingen gebruiken per leeftijdscategorie categoriegewichten, waardoor de cijfers niet strikt appels met appels zijn. Ze geven de werkelijke beoordeling van elke speler weer zoals zijn/haar eigen coachstaf hem/haar ziet — dat is voor de meeste beslissingen nuttiger dan een genormaliseerde abstractie.

## Rol Waarnemer

Waarnemers hebben `tt_view_reports`, dus zij kunnen deze tegel gebruiken. Vergelijken over teams heen is precies waar de rol Waarnemer voor bedoeld is — een bestuurslid of externe beoordelaar die talent over de hele club beoordeelt, zonder iets te hoeven bewerken.
