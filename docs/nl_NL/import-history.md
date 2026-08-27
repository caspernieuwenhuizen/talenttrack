---
title: Importgeschiedenis
group: configuration
summary: Wat elke spreadsheet-import heeft binnengebracht, en hoe je een hele import weer ongedaan maakt.
audience: [admin]
module: TT\Modules\Import\ImportModule
views: [import-history]
order: 162
---

# Importgeschiedenis

Elke spreadsheet-import wordt vastgelegd: uit welk bestand hij kwam, wanneer hij liep, wie hem uitvoerde, en hoeveel ploegen, spelers en stafleden hij heeft aangemaakt. **Configuratie → Importgeschiedenis** toont ze, de nieuwste bovenaan.

Een import is een startpunt, geen definitieve keuze. Het verkeerde bestand uploaden, of het juiste bestand met een kolom op de verkeerde plek, is een normale beginnersfout — en de oplossing hoort niet te zijn dat je tweehonderd spelers met de hand verwijdert.

## Een import ongedaan maken

**Deze import ongedaan maken** verwijdert precies de records die die import heeft aangemaakt. De rest blijft onaangeroerd: andere imports, met de hand ingevoerde records en demodata vallen allemaal buiten bereik van de ongedaanmaking.

Voordat het gebeurt zie je wat er weggaat — "3 ploegen, 24 spelers" — en moet je bevestigen.

### Als er sinds de import aan records is gewerkt

Zijn sommige geïmporteerde records sinds hun komst bewerkt, dan zegt de bevestiging dat, en hoeveel het er zijn. Die bewerkingen gaan mee met de records; de ongedaanmaking probeert ze niet te behouden.

Dat is een waarschuwing en geen blokkade, want de meest voorkomende reden om ongedaan te maken is dat het hele bestand verkeerd was — dat hoor je niet met de hand te moeten uitpluizen omdat iemand toevallig één record heeft geopend. Lees het getal wel voordat je bevestigt: is het groot, dan is de import corrigeren mogelijk sneller dan hem ongedaan maken.

De telling gaat alleen over records die bijhouden wanneer ze voor het laatst zijn gewijzigd, dus lees hem als "minstens zoveel" en niet als een exact aantal.

## Twee keer ongedaan maken

Een import ongedaan maken die dat al is, doet niets en zegt dat ook. De regel blijft in de geschiedenis staan, met de import als al ongedaan gemaakt, zodat er nog steeds vastligt dat het bestand ooit is binnengehaald.

## Wat er niet onder valt

Ongedaan maken verwijdert records; het herstelt niet wat een import heeft overschreven. Heeft een import een bestaand record bijgewerkt in plaats van aangemaakt, dan zet ongedaan maken de oude waarden niet terug.
