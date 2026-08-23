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

Tijdens het spel zijn de bewerkingsknoppen al zichtbaar — een speler
wisselen is de kern van het scherm langs de lijn, dus je hoeft niet eerst
op Bewerken te tikken. In de **nabesprekingsperiode** opent het scherm
alleen-lezen om per ongeluk tikken te voorkomen: de stand, doelpunten en
wissels worden getoond maar zijn niet bewerkbaar totdat je op **Bewerken**
tikt in de kop.

## Na de wedstrijd — elk gegeven aanpassen

Zodra de wedstrijd eindigt, komt hij in **nabespreking**. Dit is de
volledige controle-en-bewerkstatus: met Bewerken aan kun je **elk gemeten
gegeven** aanpassen — de **stand** bijstellen, een **wissel toevoegen of
ongedaan maken**, een **doelpunt toevoegen of ongedaan maken** en de
**minuten** corrigeren (door het wissellog te herstellen, of via de
panelen *Laat doelpunt / wissel toevoegen* voor gebeurtenissen die je live
vergat aan te tikken). Een wissel corrigeren herberekent de minuten, dus de
geregistreerde minuten die de rapporten lezen blijven in lijn met wat je
wijzigt.

Als je klaar bent, tik je op **Wedstrijd afsluiten** om te vergrendelen.
Een afgesloten wedstrijd is de vastlegging van wat de spelers werkelijk
deden, dus de live-knoppen blijven vergrendeld en de knop Bewerken
verdwijnt. (De server dwingt dezelfde vergrendeling af, dus een afgesloten
wedstrijd weigert wijzigingen aan stand, doelpunten en wissels, ongeacht
het scherm.)

Op het scherm na de wedstrijd staat ook **Wedstrijdanalyse schrijven** — het
moment waarop de wedstrijd eindigt is het moment waarop je hem nog scherp
voor ogen hebt. Zie *Wedstrijdanalyse*.

### Een afgesloten wedstrijd heropenen

Afsluiten is een bewuste vergrendeling, maar nooit een doodlopende weg. Een
afgesloten wedstrijd toont de actie **Heropenen voor correcties**. Als je
erop tikt (er wordt om bevestiging gevraagd) keert de wedstrijd terug naar
*nabespreking* zodat je elk gegeven kunt corrigeren — stand, wissels,
doelpunten of minuten — en daarna weer kunt afsluiten. Elke heropening
wordt vastgelegd in het auditlog, en heropenen herberekent de minuten zodat
de rapporten kloppend blijven.

Zowel afsluiten als heropenen vereist de capability `tt_edit_activities`,
dezelfde rechten die ook de rest van het wedstrijduitvoeringsscherm
afschermen.

## Gevolgde spelers

Elke speler die je in het wedstrijdplan hebt gemarkeerd — met een
specifiek doel of een aandachtspunt — verschijnt in het onderdeel
**Gevolgde spelers** met een live-teller. Tik op **+ actie** telkens
wanneer die speler doet waar je op let (een loopactie in de diepte, een
gewonnen duel, een schot op doel — wat de notitie ook zegt); houd
ingedrukt om de laatst getelde actie te verwijderen.

Deze tellingen zijn **ontwikkelacties, geen doelpunten**. Ze worden als
eigen gebeurtenissen met tijdstip vastgelegd en veranderen de stand nooit.
Doelpunten die de stand bepalen leg je apart vast (de scoreknoppen en de
lijst *Wedstrijddoelpunten* in de review). Door die twee gescheiden te
houden kan een ontwikkeltelling de uitslag niet per ongeluk beïnvloeden.

## Wie is eigenaar van de minuten

Gespeelde minuten worden **automatisch afgeleid** uit de basisopstelling en
het wissellog — je hoeft ze nooit in te typen. Omdat ze worden afgeleid,
corrigeer je een verkeerde waarde normaal gesproken door de wissel te
corrigeren die hem veroorzaakte.

Als een echte correctie niet via het wissellog uit te drukken is — een
speler die met een blessure van het veld ging zonder dat er een wissel is
gelogd, bijvoorbeeld — kun je in het reviewscherm via **Geregistreerde
minuten → Corrigeren** een expliciete overschrijving per speler instellen.
Een overschrijving wint van de afgeleide waarde en overleeft elke latere
herberekening; maak het veld leeg om terug te vallen op de afgeleide
waarde. Dit is de enige plek waar minuten met de hand worden gezet voor een
wedstrijd die via dit scherm is gespeeld — daarom stapt het gewone
minutenveld op het aanwezigheidsscherm opzij en verwijst het je hierheen.

Wedstrijden die je nooit via wedstrijduitvoering speelt, blijven
ongewijzigd: er is geen uitvoering die de minuten bezit, dus registreer je
ze op de gebruikelijke manier bij de aanwezigheid van de activiteit.

## Een doelpunt of wissel ongedaan maken

Elk vastgelegd doelpunt en elke wissel in het **Live verloop** heeft een
inline **Ongedaan maken**-link zolang de wedstrijd nog wijzigingen
accepteert. Ongedaan maken werkt ook na het herladen van de pagina — het is
gekoppeld aan de opgeslagen gebeurtenis, niet aan een kortstondig
tikgeheugen — zodat een verkeerd getikt doelpunt of een foute wissel op elk
moment tot aan het afsluiten kan worden hersteld. Een net vastgelegde
wissel biedt ook een snelle **Ongedaan maken** in de bevestigingsmelding.

## De minuut van een wissel corrigeren

Coaches leggen een wissel vaak net te laat vast — het wisselmoment was 55'
maar je tikte het pas op 58' in. Met **Bewerken** aan toont elke wissel in
het **Live verloop** een stapper **Minuut corrigeren** (− / + en een
invoerveld). De gecorrigeerde minuut wordt opgeslagen en de
minutenberekening loopt opnieuw, zodat de vastgelegde minuten van **beide**
spelers meebewegen: de speler die eruit ging wint (of verliest) het
verschil, en de speler die erin kwam verliest (of wint) het. Je bewerkt de
minuten nooit rechtstreeks — je corrigeert het *tijdstip* van de
gebeurtenis en de minuten volgen. De gecorrigeerde minuut wordt op bereik
gecontroleerd, net als elke andere.

## Wedstrijdtijdlijn — wie speelde, wanneer

Na de wedstrijd toont het nabesprekingsscherm een **Wedstrijdtijdlijn**: één
balk per speler over de hele wedstrijd (0' tot einde). Een groen segment is
tijd op het veld; een gearceerd segment is tijd op de bank. Elke wissel staat
op de grens met de minuut — `▲` waar een speler erin kwam, `▼` waar er een
eruit ging — en een `⚽` markeert elk doelpunt van die speler. De gespeelde
minuten staan aan het eind van elke rij, uit hetzelfde vastgelegde getal dat
de rapporten gebruiken, zodat de tijdlijn nooit afwijkt van het minutenrapport.
Spelers zijn gegroepeerd in **Gestart — basiself** en **Gestart — bank**; een
wisselspeler die niet inviel toont `0' · niet gebruikt`. Dit is in één
oogopslag het antwoord op "wie startte op de bank, wanneer kwam die erin, voor
wie, en hoe lang speelde iedereen?"

## Doelpunten van de tegenstander

De stand bestaat uit **wedstrijddoelpunten** voor beide teams. Onze doelpunten
worden per speler vastgelegd (de actie bij gevolgde spelers, of een laat
doelpunt); die van de tegenstander worden als eigen gebeurtenissen met een
tijd vastgelegd. Op het nabesprekingsscherm toont het onderdeel
**Wedstrijddoelpunten** beide — onze doelpunten met de maker, die van de
tegenstander als "Doelpunt tegenstander" — en met **Bewerken** aan kun je een
doelpunt van de tegenstander toevoegen (helft + minuut), de minuut corrigeren
of het verwijderen. De uitstand volgt automatisch het aantal doelpunten van de
tegenstander. Wedstrijddoelpunten staan los van **Gevolgde spelers**, die
individuele ontwikkelacties tellen en nooit de stand raken.

## Minuut- en opstellingscontroles

Het scherm weigert een onmogelijke wissel — je kunt geen speler wisselen
die niet in het veld staat, of een speler inbrengen die al speelt — en een
doelpunt- of wisselminuut buiten de wedstrijdduur (plus een korte
blessuretijdmarge) wordt geweigerd in plaats van stilzwijgend afgekapt. Deze
controles draaien op de server, dus ze gelden voor elke client.

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
- `DELETE /wp-json/talenttrack/v1/match-execution/{activity_id}/substitution/{event_uuid}`
  — een vastgelegde wissel ongedaan maken (soft-delete; de minuten
  herberekenen).
- `POST /wp-json/talenttrack/v1/match-execution/{activity_id}/reopen`
  — een afgesloten wedstrijd heropenen voor correcties (terug naar
  *nabespreking*; vastgelegd in het auditlog).
- `PATCH /wp-json/talenttrack/v1/match-execution/{activity_id}/minutes`
  — een minutenoverschrijving per speler instellen (`{player_id, minutes}`)
  of wissen (`{player_id, minutes: null}`).
- `POST /wp-json/talenttrack/v1/match-execution/{activity_id}/tracked-event`
  en `DELETE .../tracked-event/{event_uuid}` — een gevolgde ontwikkelactie
  van een gemarkeerde speler vastleggen of ongedaan maken.

Alle vereisen de capability `tt_edit_activities`, dezelfde rechten die ook
het wedstrijduitvoeringsscherm zelf afschermen.
