<!-- audience: user, admin -->

# Speler rate cards

Een rate card is een blik-in-één-oogopslag op de huidige stand en recente ontwikkeling van een speler: een FIFA-achtige kaart, kerncijfers (meest recente, rollend gemiddelde, all-time), een radarchart over de hoofdcategorieën en een trendlijn over de tijd.

## Waar vind je het

**Beheer**: `TalentTrack → Speler rate cards`. Speler-kiezer bovenaan; kies een speler en zie zijn/haar kaart met optionele filters op datumbereik en evaluatietype.

**Frontend (v3.0.0+)**: de tegel **Rate cards** op de tegellandingspagina. Tik erop voor dezelfde functionaliteit in een mobile-first layout. Iedereen met `tt_view_reports` heeft hier toegang toe — Waarnemer, Coach, Scout, Clubbeheerder, Hoofd opleiding.

## Wat er te zien is

- **FIFA-achtige kaart** — positiekleur, overall-beoordeling, belangrijkste attribuutwaarden, naam, team, foto
- **Kerncijfers** — Meest recente beoordeling (enkele laatste eval), Rollend (gemiddelde van laatste 5 evals), All-time gemiddelde, aantal evaluaties
- **Radarchart** — hoofdcategorieën als spinnenweb, laat de vorm van het spelersprofiel zien
- **Trendlijn** — rollende gemiddelden in de tijd uitgezet; lange vlakke stukken versus dalen en klims vertellen in één oogopslag ontwikkelingsverhalen

## Filters

Zowel op beheer- als op frontend-zijde:

- **Datum van / tot** — beperk tot evaluaties in een tijdvenster
- **Evaluatietype** — bijv. alleen Wedstrijden, alleen Trainingen, of beide

Filters werken consistent op alle vier de panelen.

## Frontend versus beheer

De frontend-versie gebruikt dezelfde rendering-klasse intern — geen functionaliteitsverschil. Het verschil is de omkadering: het beheer heeft het standaard WP-admin kader met tabs en broodkruimels, de frontend heeft de header van de tegellanding met een Terug-knop. Beide tonen dezelfde content onder de speler-kiezer.

## Rol Waarnemer

De belangrijkste reden dat deze tegel op de frontend bestaat. Alleen-lezen Waarnemers (bestuursleden, assistent-coaches in opleiding, externe beoordelaars) kunnen nu de rate card van elke speler bekijken zonder beheerderstoegang nodig te hebben. Hun rol geeft leesrechten over de hele plugin; deze tegel is hun dagelijkse ingang.
