<!-- audience: user -->

# Wedstrijduitvoering — het live scherm op wedstrijddag

Het wedstrijduitvoeringsscherm is het mobielgerichte scherm dat een
assistent-trainer langs de lijn tijdens een wedstrijd gebruikt. Je opent
het vanaf de detailpagina van een wedstrijdactiviteit zodra de wedstrijd is
voorbereid (zie *Wedstrijdvoorbereiding*). Het houdt de stand, de
speelklok en het volgen per speler op één plek bij.

Het kruimelpad boven het scherm verwijst terug naar de bovenliggende
activiteit: **Dashboard / Activiteiten / {activiteit} / Wedstrijduitvoering**.
Tik op de activiteitskruimel om terug te keren naar de detailpagina van de
wedstrijdactiviteit.

## Bewerken is een bewuste keuze

Het scherm opent **alleen-lezen**. De stand, doelpunten en wissels worden
getoond maar zijn niet bewerkbaar totdat je op **Bewerken** tikt in de
kop. Bewerken wordt alleen aangeboden zolang de wedstrijd nog wijzigingen
accepteert — tijdens het spel, in de rust en in de nabesprekingsperiode.
Zodra een wedstrijd is **afgesloten**, verdwijnt de knop Bewerken en
blijven de live-knoppen vergrendeld: een afgesloten wedstrijd is de
vastlegging van wat de spelers werkelijk deden en mag niet stilzwijgend
worden herschreven. (De server dwingt dezelfde vergrendeling af, dus een
afgesloten wedstrijd weigert wijzigingen aan stand, doelpunten en wissels,
ongeacht het scherm.)

## Opstelling — het verticale veld

Bovenaan het scherm, onder de stand en de speelklok, toont een verticaal
veld de **basiself van de eerste helft per positie**. Elke speler staat op
de plek waar zijn opstellingsslot uit de wedstrijdvoorbereiding naar
verwijst, op basis van de gekozen formatie (4-3-3, 4-2-3-1, 4-4-2 en de
andere ondersteunde formaties).

- Een gevulde plek toont het rugnummer van de speler (of het positielabel
  als er geen nummer is ingesteld) en een korte naam. De korte naam is de
  **voornaam plus de eerste letter van de achternaam** (bijv. "Daan P."),
  zoals een trainer een speler langs de lijn noemt; een speler met een naam
  van één woord wordt ongewijzigd getoond.
- Een lege plek — een slot zonder speler in de voorbereiding — toont een
  gestippelde markering met het positielabel.

Het veld wordt netjes weergegeven op een telefoon van 360px breed en
schaalt mee op grotere telefoons en tablets. De posities komen
rechtstreeks uit de opstelling van de voorbereiding, dus een positie
aanpassen in de voorbereiding werkt het veld hier bij.

## Live verloop — het gebeurtenissenlog

Onder het veld toont het **Live verloop** de doelpunten en wissels van de
wedstrijd in chronologische volgorde. Elke regel toont:

- de **helft en minuut** waarop de gebeurtenis plaatsvond (bijv. `H1 23'`);
- een **typelabel** met een icoon en tekst — een bal voor een doelpunt, een
  wisselpijl voor een wissel (het label combineert altijd kleur met een
  icoon en tekst, zodat het leesbaar blijft voor kleurenblinde gebruikers);
- voor doelpunten een **tussenstand** met de stand na dat doelpunt;
- de betrokken speler — de maker bij een doelpunt, of "{in} in voor {uit}"
  bij een wissel.

Het log wordt opgebouwd uit dezelfde doelpunt- en wisselgebeurtenissen die
het live scherm al vastlegt terwijl je ze tijdens de wedstrijd aantikt (en
uit late doelpunten of wissels die je tijdens de nabesprekingsperiode
toevoegt). Rode en gele kaarten worden niet bijgehouden en verschijnen dus
niet in het verloop.

## Geregistreerde minuten corrigeren

De **geregistreerde minuten** per speler worden automatisch berekend uit
het wissellog terwijl de wedstrijd live is en in de nabesprekingsperiode.
Zodra de wedstrijd is **afgesloten**, draait er geen automatische
herberekening meer — daarom toont een afgesloten wedstrijd onder
*Geregistreerde minuten* de actie **Geregistreerde minuten corrigeren**.

Tik erop om per speler een numeriek veld te tonen, corrigeer een getal dat
verkeerd is vastgelegd en klik op **Minuten opslaan** (of **Annuleren** om
weg te gaan zonder iets te wijzigen). Dit is alleen een correctie op de
aanwezigheidsvastlegging — het heropent de vergrendelde wedstrijd niet, dus
de stand, doelpunten en wissels blijven vergrendeld. De gecorrigeerde
waarde stroomt rechtstreeks door naar de minutenrapporten. Vóór het
afsluiten pas je in plaats daarvan het wissellog aan, dan herberekenen de
minuten correct.

Het corrigeren van minuten vereist de capability `tt_edit_activities`,
dezelfde rechten die ook de rest van het wedstrijduitvoeringsscherm
afschermen.

## Waar de gegevens vandaan komen

Beide onderdelen lezen uit de gegevens die de wedstrijd al vastlegt — de
opstelling uit de voorbereiding voor de posities en de doelpunt- en
wissellogboeken voor het verloop. Er hoeft niets nieuws te worden ingevoerd
om ze te vullen.

Dezelfde gegevens zijn beschikbaar via de REST-API voor integraties en de
toekomstige webapp:

- `GET /wp-json/talenttrack/v1/match-execution/{activity_id}/event-feed`
  — het samengevoegde, chronologische doelpunt- en wisselverloop met
  tussenstand.
- `GET /wp-json/talenttrack/v1/match-execution/{activity_id}/pitch-lineup`
  — de basiself van de eerste helft met positiecoördinaten.

Beide vereisen de capability `tt_edit_activities`, dezelfde rechten die ook
het wedstrijduitvoeringsscherm zelf afschermen.
