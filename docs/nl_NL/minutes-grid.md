---
title: Minutenraster
group: match-day
summary: Leg per wedstrijd vast hoeveel minuten elke speler heeft gespeeld, in één raster.
audience: [user]
views: [minutes-grid]
module: TT\Modules\Activities\ActivitiesModule
feature: minutes_grid
order: 30
---

# Minutenraster

Het **minutenraster** legt vast hoeveel minuten elke speler per wedstrijd
kreeg, over een hele periode — de desktop-tegenhanger in spreadsheetvorm van
het [aanwezigheidsraster](attendance-grid.md), beperkt tot wedstrijden.

Open het via de schakelaar **Aanwezigheid / Minuten** boven het raster, of
rechtstreeks vanuit **Activiteiten** (je hebt rechten nodig om activiteiten te
bewerken). Net als het aanwezigheidsraster is het gemaakt voor een desktop of
laptop.

## Wat je ziet

- **Rijen zijn je spelers** — de actieve selectie van het gekozen team.
- **Kolommen zijn wedstrijden** — de wedstrijden van het team in de gekozen
  periode, oudste links.
- **Elke bewerkbare cel is een minutenveld.** Typ de minuten die een speler in
  die wedstrijd kreeg en sla op. De kolom **Totaal** rechts telt de minuten
  van elke speler over de getoonde periode op.
- **Gearceerde cellen** betekenen dat de speler niet in de selectie van die
  wedstrijd zat, dus er zijn geen minuten vast te leggen. Voeg de speler eerst
  aan de wedstrijd toe (via aanwezigheid) als dat niet klopt.
- Een **"live"**-label op een kolom betekent dat die minuten uit een via het
  wedstrijdformulier gespeelde wedstrijd komen. Je mag hier toch een waarde
  corrigeren — je invoer wordt bewaard als correctie die een herberekening
  overleeft.

## Minuten vastleggen

1. Kies het **team** en de **periode** (een snelknop of een eigen datumbereik).
2. Typ de minuten in elke selectiecel. Laat een veld leeg om het te wissen.
3. Klik op **Opslaan**. De teller laat zien hoeveel wijzigingen klaarstaan;
   bewerkte velden krijgen een rand tot je opslaat. Met **Annuleren** verlaat
   je het scherm zonder op te slaan.

De minuten die je hier invoert zijn dezelfde cijfers die de minuten-audit en
het rapport Minuten per team gebruiken, zodat alles blijft kloppen.

## Uitschakelen

Een beheerder kan het raster verbergen via **Instellingen → Functies →
Minutenraster**. Als het uit staat, verdwijnt de rasterknop en kan de pagina
niet worden geopend. De per-wedstrijd minuten-editor blijft gewoon werken.
