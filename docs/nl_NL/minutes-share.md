---
title: Minutenaandeel
group: analytics
summary: Welk percentage van de gespeelde minuten kreeg elke speler, afgezet tegen de norm van de academie.
audience: [user]
views: [standard-report]
module: TT\Modules\Reports\ReportsModule
order: 45
capability: tt_view_reports
---

# Minutenaandeel

Elk ander minutenrapport antwoordt in absolute getallen: deze speler heeft 350
minuten, die 620. **Minutenaandeel** beantwoordt de vraag die daarachter
schuilgaat — 350 minuten lijkt prima tot je weet dat het team er 700 speelde.

Open het via **Rapporten → Speeltijd → Team · Minutenaandeel**, kies een team,
en je krijgt één rij per speler: gespeelde minuten, beschikbare minuten, en het
aandeel daartussen, met een signaal bij iedereen onder de norm van de academie.

## Hoe de beschikbare minuten worden bepaald

Elke wedstrijd die het team in de periode **gespeeld** heeft, met de eigen duur
van die wedstrijd.

- **Gespeeld** betekent hier hetzelfde als in de andere minutenrapporten: de
 activiteit is voltooid, de datum is voorbij, of er zijn al minuten
 vastgelegd. Een wedstrijd die vanavond begint zit niet in de noemer, dus
 niemands aandeel zakt op de ochtend van een wedstrijd.
- **Eigen duur** betekent de helftduur maal twee — de waarde op de
 wedstrijdvoorbereiding als die is ingevuld, anders de standaard voor de
 leeftijdscategorie van het team, anders 35 minuten per helft. Een O9-team met
 helften van 30 minuten heeft over tien wedstrijden 600 beschikbaar, geen 700.

Tien voltooide wedstrijden van 70 minuten leveren dus **700 beschikbare
minuten** op, en een speler met 350 vastgelegde minuten staat op **50%**.

## De noemer krimpt niet mee met afwezigheid

Het aandeel is van elke minuut die het team speelde, of de speler nu
beschikbaar was of niet. Een speler die zes weken geblesseerd was, laat een
laag aandeel zien, en dat is het eerlijke getal — het blessuredossier legt uit
waarom.

Het alternatief — de noemer per speler stilletjes laten krimpen tot de
wedstrijden waarvoor hij beschikbaar was — zou juist het geval verbergen
waarvoor dit rapport bestaat: een speler die fit is, in de selectie zit en
niet aan spelen toekomt.

## De norm

Elke speler zou een minimumaandeel van de speeltijd moeten halen. De standaard
is **30%**, en een academie past dat aan onder **Configuratie →
Wedstrijdminuten**, naast de wedstrijdduur per leeftijdscategorie: die bepalen
de noemer, dit trekt de streep eroverheen.

Een speler onder de norm krijgt een **▼ onder de norm**-markering naast het
aandeel, nooit alleen kleur: deze rapporten worden afgedrukt, en een rode balk
die alleen rood is zegt niets in zwart-wit of voor een kleurenblinde lezer.

## De KPI-strip lezen

| Tegel | Wat het betekent |
| --- | --- |
| **Gespeelde wedstrijden** | Hoeveel wedstrijden de noemer voedden. Opent Team · Minutenverdeling, dat ze benoemt en aangeeft welke geen vastgelegde minuten hebben. |
| **Beschikbare minuten / speler** | De noemer waartegen elk aandeel op de pagina wordt gemeten. |
| **Mediaan aandeel** | Het midden van de selectie. Bewust de mediaan en niet het gemiddelde: één speler die elke minuut speelt trekt een gemiddelde boven een streep waar de rest van de selectie nergens in de buurt komt. |
| **Onder de norm** | Hoeveel spelers onder de norm zitten, goud gemarkeerd zodra dat er meer dan nul zijn. |

De rijen staan gesorteerd op **laagste aandeel eerst**. De spelers waar dit
rapport over gaat, horen niet onderaan een scroll.

## Als het rapport leeg is

- *Geen wedstrijden gespeeld in deze periode* — de noemer is nul, dus er valt
 geen aandeel te berekenen. Verruim de periode.
- *Er zijn wedstrijden gespeeld maar geen minuten vastgelegd* — de wedstrijden
 zijn er, de minuten niet. Leg ze vast vanuit de activiteit;
 **Minutenverdeling** laat zien welke wedstrijden ze missen.

## Via de API

`GET /teams/{id}/minutes-share` geeft de hele selectie;
`GET /teams/{id}/minutes-share/{player_id}` geeft de rij van één speler uit
hetzelfde antwoord. Beide accepteren `from` / `to` (`JJJJ-MM-DD`) en vallen
terug op de laatste twaalf maanden. Zie [de REST API-referentie](rest-api.md).
