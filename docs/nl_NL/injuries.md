---
title: Blessures
group: performance
summary: Gedateerde blessures op de tijdlijn van een speler, van ontstaan tot terugkeer.
audience: [user]
views: [injuries]
module: TT\Modules\Journey\JourneyModule
order: 85
---

# Blessures

Een **blessure** is een gedateerde regel op de reis van een speler: wat er gebeurde, wanneer het begon en wanneer hij terug was. Ze staat naast stages, ondertekeningen en leeftijdsovergangen als een van de overgangen die de academie expliciet volgt — niet als een zin in een notitie die volgend seizoen niemand meer terugvindt.

TalentTrack legt de **ontwikkelimpact** van een blessure vast, niet de behandeling. Er zijn geen return-to-play-fasen, geen behandelplannen en geen medische bijlagen. Verwacht je fysio een medisch systeem, stel die verwachting dan vooraf bij: dit is er geen.

## Wie mag wat

| | Blessures zien | Vastleggen | Verwijderen |
| --- | --- | --- | --- |
| Hoofdtrainer | Eigen teams | Eigen teams | Nee |
| Assistent-trainer | Nee | Nee | Nee |
| Stafmedewerker met de functionele rol **Fysio** | De teams waarvan hij fysio is | De teams waarvan hij fysio is | Nee |
| Stafmedewerker met een andere functionele rol | Nee | Nee | Nee |
| Hoofd opleidingen | Elk team | Elk team | Ja |
| Academiebeheerder | Elk team | Elk team | Ja |
| Speler / ouder | De eigen, op de reis | Nee | Nee |

De assistent-trainer valt hier bewust buiten. Blessuregegevens zijn medische gegevens over minderjarigen, en de academie kiest ervoor dat minder mensen ze zien, niet meer. Een assistent-trainer markeert een speler nog steeds als **Blessure** bij de aanwezigheid van een sessie en als **Geblesseerd** in de beschikbaarheidsdrawer op wedstrijddag — zo regel je beschikbaarheid, en dat staat los van het blessuredossier.

**Blessures volgen de functionele rol, niet het type account.** Teammanager, materiaalman en fysio zijn alle drie hetzelfde type Staf-account, dus het account kan ze niet uit elkaar houden — de taak die iemand bij een elftal doet wel. Alleen de rol **Fysio** draagt het blessurelogboek, en die draagt het op de teams waarop de rol gehouden wordt en nergens anders. Een teammanager die ook de EHBO doet, krijgt daarom **Fysio** als tweede functionele rol op dat team, via **Personen → Functionele rollen**: iemand kan meerdere rollen op hetzelfde elftal hebben, en die staan gewoon naast elkaar. **Verwijderen** kan niemand in deze groep, welke rollen hij ook heeft. De toewijzing moet vandaag lopen wil de toegang gelden — loopt ze af, dan vervalt de toegang.

Eén uitzondering, en die is tijdelijk: een Staf-account zonder functionele rol op enig team houdt de blessuretoegang die het had vóór de functionele rollen bestonden, op de elftallen waaraan het gekoppeld is. Die persoon de rol geven die zijn werk beschrijft, is wat hem naar de tabel hierboven verplaatst. Zie [Toegangsbeheer](access-control.md) voor het volledige beeld.

## Een blessure vastleggen

1. Open de speler, ga naar het tabblad **Blessures** en kies **Blessure vastleggen**. De wizard kent de speler dan al, dus hij opent op **Wat er gebeurde**, met de naam van de speler bovenaan. (Of open de tegel **Blessures** en begin daar; die vraagt eerst om de speler.)
2. **Wat er gebeurde** — lichaamsdeel, type en ernst. Alle drie optioneel: langs het veld weet je "hamstring" veel eerder dan de graad, en een blessure met alleen een datum is meer waard dan een die niemand heeft vastgelegd.
3. **Wanneer** — de datum van de blessure is het enige verplichte veld. Vul een verwachte terugkeer in als je er een gevoel bij hebt; laat hem leeg als dat niet zo is.
4. Voeg een notitie toe. Schrijf die alsof het gezin meeleest, want bij een inzageverzoek is dat ook zo.

Opslaan zet automatisch een regel *Blessure ingetreden* op de reis van de speler.

## Een blessure bewerken

Een blessure die haastig langs het veld is vastgelegd, is vaak niet meer dan een datum en een notitie. Vul haar aan zodra je meer weet: kies **Bewerken** op het tabblad **Blessures** van de speler, of bij een regel in het overzicht **Blessures**. Je kunt het lichaamsdeel, het type, de ernst, de verwachte terugkeer en de notitie wijzigen. De datum van de blessure blijft zoals hij is vastgelegd.

De verwachte terugkeer kan niet vóór de datum van de blessure liggen. Een veld leegmaken wist het. **Annuleren** brengt je terug naar waar je vandaan kwam zonder iets op te slaan, en elke opgeslagen wijziging komt in het auditlogboek.

De knop **Bewerken** verschijnt alleen bij mensen die voor die speler blessures mogen vastleggen; voor de rest is de blessure alleen-lezen.

## De terugkeer vastleggen

Dit is de helft die er het meest toe doet, en de helft die vergeten wordt.

Op het tabblad **Blessures** van de speler heeft een lopende blessure een knop **Terugkeer vastleggen**. Vul de datum in waarop de speler weer meetrainde en sla op. Er gebeuren dan drie dingen:

- er komt een regel *Blessure hersteld* op de reis van de speler;
- de blessure telt niet meer als lopend, dus de speler valt uit het squad-overzicht;
- de duur van de afwezigheid wordt een echt getal in plaats van een open eind.

Zolang je dit niet doet blijft de blessure eeuwig openstaan, en loopt het overzicht langzaam vol met spelers die al maanden fit zijn.

## Wie is er nu uit

De tegel **Blessures** opent het squad-overzicht: één regel per lopende blessure over de teams die je mag zien, met de speler, het team, het lichaamsdeel, de ernst, hoe lang hij eruit ligt en wanneer hij terug werd verwacht.

Een regel krijgt het label **Verlopen** wanneer de verwachte terugkeer voorbij is en er geen werkelijke terugkeer is vastgelegd. Dat is geen oordeel over de speler — het betekent dat de regel een beslissing nodig heeft: óf hij is terug en niemand heeft het gezegd, óf de verwachting is veranderd.

Een regel krijgt het label **Onvolledig** wanneer hij 14 dagen openstaat zonder verwachte terugkeer. Lichaamsdeel, soort en ernst mogen leeg blijven — een EHBO'er langs de lijn weet die vaak nog niet — maar tegen die tijd hoort iemand te zeggen wanneer de speler terug wordt verwacht. Een academie kan het aantal dagen aanpassen (configuratiesleutel `injury_incomplete_after_days`); het tabblad **Blessures** van de speler toont hetzelfde label.

## Plannen rond een verlopen of onvolledige blessure

Een verlopen of onvolledige blessure houdt de speler niet buiten de planning. De geplande aanwezigheid en de wedstrijdvoorbereiding tonen de speler als beschikbaar, met een neutraal label **Beschikbaarheid controleren** naast de naam, zodat de trainer bevestigt in plaats van gokt. Het label noemt geen oorzaak, dus het is veilig voor elke rol die de selectie plant, en het wordt nooit afgedrukt of opgeslagen in de wedstrijdvoorbereiding. Een blessure die nog binnen de verwachte terugkeer valt, of korter dan 14 dagen openstaat, blijft de speler als **Niet beschikbaar** tonen.

## De herstelherinnering

Wanneer een blessure om een beslissing vraagt — op de verwachte terugkeerdatum, of op de dag dat hij onvolledig wordt — krijgen de fysio en de hoofdtrainer van het team elk een taak **Controle herstel blessure**, met die dag als deadline. Een team zonder fysio stuurt hem alleen naar de hoofdtrainer, en alleen wie de blessures van de speler mag inzien, krijgt hem. Noch de taak, noch de e-mail bevat details over de blessure: de e-mail noemt de naam van de taak en de deadline, en de taak opent bij de speler.

Er is per speler één herinnering tegelijk. Wie de verwachte terugkeer verschuift, sluit de oude taak af en krijgt een nieuwe op de nieuwe datum; het vastleggen van de terugkeer of het archiveren van het dossier sluit hem ook af. Het dossier naar de prullenbak verplaatsen of definitief verwijderen sluit hem eveneens af, en wie een gearchiveerd dossier terugzet dat nog om een beslissing vraagt, krijgt de herinnering meteen terug. Iets anders bewerken laat de taak zoals hij is. Een speler die de academie heeft verlaten krijgt geen herinneringen: de speler archiveren of naar de prullenbak verplaatsen sluit de taak af, en wie de speler terugzet krijgt hem terug zolang een dossier nog om een beslissing vraagt.

De taak vraagt hoe het met de speler gaat. **Op schema** en **Onzeker** worden op de taak vastgelegd en veranderen niets aan het dossier. **Verlengen** zet een nieuwe verwachte terugkeer: de taak toont de blessures van de speler die nu om een beslissing vragen (is er maar één, dan staat die al gekozen), je kiest de blessure en vult de nieuwe datum in, en die wordt opgeslagen alsof je het dossier zelf hebt bewerkt, inclusief de regel in het auditlogboek. De nieuwe datum moet na vandaag liggen en mag niet vóór de blessuredatum of vóór de terugkeer liggen die het dossier al verwacht. **Verlengen** wordt alleen aangeboden aan wie de blessures van de speler mag bewerken; ieder ander ziet wie dat kan.

Filter op team en wissel tussen **Nu uit**, **Hersteld** en **Alles**.

Dezelfde lijst is voor andere apps beschikbaar via de API als `GET /wp-json/talenttrack/v1/player-injuries`, met dezelfde filters op team en status, per pagina. Daarvoor gelden dezelfde regels als voor deze pagina: je krijgt alleen de teams waarvan je hier de blessures mag zien, en elke keer dat de lijst wordt opgevraagd, komt dat in het auditlogboek.

**Blessure vastleggen** staat bovenaan de pagina, zodat je er een kunt vastleggen zonder eerst het dossier van een speler te openen — de wizard vraagt als eerste stap om het team en de speler. De knop verschijnt alleen bij rollen die een blessure mogen vastleggen; voor de rest is het overzicht alleen-lezen.

## Privacy

Blessures zijn medische gegevens over minderjarigen, dus gelden er twee dingen:

- Elke keer dat iemand de blessures van een speler leest — het tabblad, het overzicht of de API — komt dat in het auditlogboek met wie, wanneer en welke speler.
- Blessureregels op de reis hebben het zichtbaarheidsniveau **medisch**, dus een rol zonder medische inzage ziet de chronologie mét de melding dat er een regel verborgen is, in plaats van een stilzwijgend gat.

Een blessure verwijderen is voorbehouden aan het hoofd opleidingen en de academiebeheerder, en gearchiveerde regels gaan naar de prullenbak in plaats van te verdwijnen.

## Zie ook

- [Spelersreis](player-journey.md) — waar blessureregels in context verschijnen.
- [Activiteiten en aanwezigheid](activities.md) — de status **Blessure** per sessie, die over beschikbaarheid gaat en niet over het blessuredossier.
- [Toegangsbeheer](access-control.md) — functionele rollen, en wat elke rol geeft op het team waarop die gehouden wordt.
