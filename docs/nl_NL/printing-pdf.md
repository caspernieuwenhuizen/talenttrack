# Afdrukken & PDF-export

TalentTrack kan nette, print-geoptimaliseerde weergaven genereren voor spelerrapporten — handig voor oudergesprekken, uitdelen tijdens evaluaties of het archiveren van papieren trails.

## Printknoppen

Pagina's die afdrukken ondersteunen, hebben rechtsboven een printicoon:

- **Speler rate cards** — volledige rate card met FIFA-kaart, radar, trends
- **Evaluatiedetailweergave** — één evaluatie met al zijn categorieën en notities

## Wat er gebeurt bij afdrukken

Door op de printknop te klikken ga je naar een URL als `?tt_print=<id>`, die:

1. Wordt onderschept door de PrintRouter (vroeg in de request-pipeline van WordPress)
2. Een standalone HTML-pagina rendert zonder beheerderskader, zonder thema en zonder zijbalk
3. Logo van de academie, huisstijlkleuren, nette typografie gebruikt
4. Automatisch het printvenster van de browser opent via JavaScript

## Exporteren naar PDF

Gebruik **Opslaan als PDF** in het printvenster van je browser. Chrome, Safari, Firefox en Edge ondersteunen dat native — geen PDF-bibliotheek aan serverzijde nodig.

De printweergave opent in een nieuw venster met knoppen Afdrukken, PDF downloaden en **Venster sluiten**. Sluit het venster als je klaar bent — je oorspronkelijke TalentTrack-tab blijft op z'n plek.

Stel de paginagrootte in op A4 (of Letter in de VS). Zet marges op "Standaard" of "Normaal". Liggend werkt beter voor rate cards (meer horizontale ruimte voor radar + trendgrafieken naast elkaar).

## Stijl

De printlayout is puur CSS; wat je ziet is wat er wordt afgedrukt. Kleuren en logo komen uit je [Configuratie](?page=tt-docs&topic=configuration-branding). Als je PDF er bleek uitziet, controleer dan of je primaire kleur en logo ingesteld zijn.
