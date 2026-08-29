---
title: Minuten + statistieken
group: match-day
summary: "Leg per wedstrijd minuten, doelpunten en assists per speler vast, in één raster."
audience: [user]
views: [minutes-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: minutes_grid
order: 30
---

# Minuten + statistieken

**Minuten + statistieken** legt vast hoeveel minuten elke speler per wedstrijd
kreeg én wat hij opleverde, over een hele periode — de desktop-tegenhanger in
spreadsheetvorm van het [aanwezigheidsraster](attendance-grid.md), beperkt tot
wedstrijden.

Open het via de schakelaar **Aanwezigheid / Minuten + stats** boven het
raster, of rechtstreeks vanuit **Activiteiten** (je hebt rechten nodig om
activiteiten te bewerken). Net als het aanwezigheidsraster is het gemaakt voor
een desktop of laptop.

## Wat je ziet

- **Rijen zijn je spelers** — de actieve selectie van het gekozen team.
- **Kolommen zijn wedstrijden** — de wedstrijden van het team in de gekozen
 periode, oudste links, elk opgesplitst in drie velden: **Min**, **G** en
 **A**.
- **Elke bewerkbare cel is een veld om in te typen.** Minuten, doelpunten,
 assists. Met Tab loop je Min → G → A → volgende wedstrijd, zoals in een
 spreadsheet. De **Totaal**-kolommen rechts tellen minuten, doelpunten en
 assists van elke speler over de getoonde periode op.
- **Gearceerde cellen** betekenen dat de speler niet in de selectie van die
 wedstrijd zat, dus er is niets vast te leggen. Voeg de speler eerst aan de
 wedstrijd toe (via aanwezigheid) als dat niet klopt.
- Een **"live"**-label op een kolom betekent dat die minuten uit een via het
 wedstrijdformulier gespeelde wedstrijd komen. Je mag hier toch een waarde
 corrigeren — je invoer wordt bewaard als correctie die een herberekening
 overleeft.

## Minuten, doelpunten en assists vastleggen

1. Kies het **team** en de **periode** (een snelknop of een eigen datumbereik).
2. Vul de velden in elke selectiecel. Laat een veld leeg om het te wissen.
3. Klik op **Opslaan**. De teller laat zien hoeveel wijzigingen klaarstaan;
 bewerkte velden krijgen een rand tot je opslaat. Met **Annuleren** verlaat
 je het scherm zonder op te slaan.

De minuten die je hier invoert zijn dezelfde cijfers die de minuten-audit en
het rapport Minuten per team gebruiken, zodat alles blijft kloppen.

### Doelpunten en assists zonder live wedstrijdformulier

Tot nu toe konden doelpunten en assists alleen worden vastgelegd door een
wedstrijd via het **live wedstrijdformulier** te spelen. Een club die de
administratie op zondagavond doet, had daardoor spelers met complete minuten
en permanent lege doelpunten — op hun profiel, in de rapportages, overal.

Je kunt ze nu gewoon intypen. Wat je vastlegt telt precies zo mee als een live
gelogd doelpunt: het bereikt het spelersdossier en elk rapport dat daarop
gebouwd is.

Twee dingen worden bewust **niet** vastgelegd als je een doelpunt achteraf
intypt:

- **Geen minuut.** Een live gelogd doelpunt weet dat het in de 34e minuut
 viel; een doelpunt dat je je op zondag herinnert niet, en het raster
 verzint er geen. Zo'n doelpunt verschijnt in de totalen van de speler, maar
 niet met een klok erbij op de wedstrijdtijdlijn.
- **Geen wijziging aan de uitslag.** De uitslag blijft precies zoals hij is
 vastgelegd. De uitslag is wat er gebeurd is; wie er scoorde is wat we
 daarvan weten, en een naam intypen hoort dat resultaat niet stilletjes te
 herschrijven.

**Een assist hangt aan een doelpunt.** Er een vastleggen telt geen extra
doelpunt bij het team — het benoemt wie een doelpunt voorbereidde dat er al
is. Is er geen doelpunt vrij om hem aan te hangen, dan legt het raster een
doelpunt **zonder maker** vast, de eerlijke versie van "iemand rondde zijn
pass af en ik weet niet meer wie".

**Een aantal naar beneden bijstellen** wist nooit historie: het doelpunt wordt
als teruggedraaid gemarkeerd in plaats van verwijderd, en getypte invoer wordt
eerder teruggedraaid dan live vastgelegde. Zo kan een correctie nooit iets
vernietigen dat destijds echt gezien is.

### Toegekend / stand

De onderste rij van het raster laat per wedstrijd zien hoeveel doelpunten een
maker op naam hebben tegenover de vastgelegde stand — `2/3` betekent dat één
doelpunt in die wedstrijd nog van niemand is.

Het is informatie, geen regel: niets houdt je tegen om op te slaan, en een
verschil is vaak gewoon waar. Het staat er zodat de uitslag en het
doelpuntenlogboek niet ongemerkt uit elkaar kunnen lopen.

### Minder kolommen tonen

Drie velden per wedstrijd is veel raster. Met de schakelaars **Tonen** boven
de tabel zet je de kolommen Doelpunten en Assists uit, waarmee het terugvalt
op een gewoon minutenraster. Die keuze is alleen van jou en wordt onthouden
als je de pagina de volgende keer opent.

## Uitschakelen

Een beheerder kan het raster verbergen via **Instellingen → Functies →
Minuten + statistieken**. Als het uit staat, verdwijnt de rasterknop en kan de
pagina niet worden geopend. De per-wedstrijd minuten-editor blijft gewoon
werken, en al vastgelegde doelpunten blijven op de spelersdossiers staan.
