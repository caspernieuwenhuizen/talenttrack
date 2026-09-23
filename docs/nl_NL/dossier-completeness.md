---
title: Compleetheid dossiers
group: basics
summary: Per team: bij wie het contact van de verzorger, het ouderaccount of de fototoestemming nog ontbreekt.
audience: [admin, user]
views: [dossier-completeness]
module: TT\Modules\Players\PlayersModule
capability: tt_view_players
order: 22
---

# Compleetheid dossiers

Elk spelersdossier heeft een deel dat niet over voetbal gaat: een volwassene
die de club kan bereiken, een ouder die het dossier kan meelezen, en een
vastgelegd antwoord op de vraag of de club deze speler mag fotograferen.
**Compleetheid dossiers** toont dat deel van een hele selectie op één scherm.

De pagina bestaat omdat het kantoor die vraag tot nu toe speler voor speler
moest beantwoorden. De spelerslijst toont naam, voorkeursbeen en rugnummer;
wilde je de stand van zestien dossiers weten, dan opende je zestien
dossiers.

## Openen

Open de tegel **Compleetheid dossiers** en kies een team. Je ziet één kaart
per controle, met hoeveel spelers compleet zijn en wie er niet compleet is.
Elke naam is een link naar dat spelersdossier, en de terugkoppeling brengt je
direct terug naar de lijst waar je mee bezig was.

Je ziet de teams waarvan je de spelers al mag lezen. Een beheerder ziet elk
team, een trainer zijn eigen teams. Er is bewust geen clubbrede versie — zie
*Wat deze pagina niet toont*.

## De zes controles

| Controle | Compleet wanneer |
| --- | --- |
| **Naam verzorger** | Er staat een naam van een verzorger op het dossier. |
| **E-mailadres verzorger** | Er staat een e-mailadres van een verzorger op het dossier. |
| **Telefoonnummer verzorger** | Er staat een telefoonnummer van een verzorger op het dossier. |
| **Ouderaccount gekoppeld** | Er hangt een ouderaccount aan de speler. |
| **Foto- en videotoestemming vastgelegd** | De toestemming is vastgelegd. De regel noemt ook de datum waarop dat gebeurde. |
| **Beeld in dossier zonder toestemming** | Óf de toestemming is vastgelegd, óf er is geen beeld waarvoor toestemming nodig is. |

**Een gekoppeld ouderaccount en de velden van de verzorger zijn twee
verschillende dingen, en ze worden apart gerapporteerd.** Een account is
hoe een ouder het dossier van zijn kind meeleest; de naam, het e-mailadres
en het telefoonnummer zijn hoe iemand van de club op zaterdagochtend een
gezin belt. Een speler kan prima het een hebben en het ander niet, en een
rapport dat die twee op één hoop gooit, meldt een compleet dossier terwijl
er nog steeds niemand te bellen is.

## Gezinnen die we kunnen bereiken

Boven de zes controles staat één regel met hoeveel gezinnen van deze selectie
de club überhaupt kan bereiken: **"3 van 21 gezinnen bereikbaar"**. Een gezin
geldt als bereikbaar zodra de speler een e-mailadres van de verzorger, een
telefoonnummer van de verzorger **of** een gekoppeld ouderaccount heeft — elk
van de drie is een route naar iemand.

Die regel staat er omdat de twee feiten erboven leken tegen te spreken. Een
selectie zonder e-mailadressen van verzorgers en met drie gekoppelde
ouderaccounts laat op de ene kaart "0 van 21" zien en op de andere "3 van
21", en beide zijn waar. Deze regel zegt wat ze samen betekenen, zodat
niemand het op een telefoon hoeft uit te rekenen.

De regel vervangt de controles niet en gaat dat ook nooit doen. Bereikbaar
betekent "er is een manier om dit gezin te bereiken", niet "dit dossier is
compleet" — een speler van wie de ouder een account heeft maar van wie het
telefoonnummer leeg is, is per e-mail bereikbaar en nog steeds niemand die je
op zaterdagochtend kunt bellen. Daarom blijven de kaarten eronder apart.

Dezelfde telling voor de hele academie staat onder *Het overzicht voor Hoofd
Opleiding en beheerders* in het onderwerp Meldingen.

**"Beeld in dossier zonder toestemming" telt items én spelers.** Eén speler
met negen foto's is een ander gesprek dan negen spelers met er één, en dat
is precies waar je tussen kiest als je de telefoon pakt. Een foto die aan
elf kinderen hangt, telt voor elk van hen mee: het zijn elf gesprekken.

## Wat deze pagina niet toont

**Nooit de naam, het e-mailadres of het telefoonnummer van een verzorger.**
De pagina zegt dát een veld leeg is, niet wát erin staat als het gevuld is.
Die gegevens staan op het dossier van de speler zelf, achter de rechten van
dat dossier, speler voor speler. Deze pagina is een checklist, en een
checklist die de contactgegevens van elk gezin afdrukt, is een export met
een vriendelijker kopje.

Om dezelfde reden is er geen clubbrede versie. Een selectie is de eenheid
waarin deze vraag werkelijk gesteld wordt.

## Toestemming wordt vastgelegd, nooit afgedwongen

Niets op deze pagina verbergt, vervaagt of blokkeert beeld, en de melding
hieronder evenmin. Een trainer die een foto niet kan zien, kan niet
beoordelen of hij hem mag gebruiken, en een dossier waaruit beeld stilletjes
verdwijnt oogt kapot in plaats van zorgvuldig. Het antwoord van de academie
op ontbrekende toestemming is het gezin ernaar vragen — deze pagina laat
zien wie je moet vragen.

## De melding

**Beeld in dossier zonder toestemming** is ook een melding. Ze verschijnt
voor een actieve speler met minstens één foto of video in het dossier en
geen vastgelegde toestemming, en gaat naar de hoofdtrainer van het team en
naar wie spelers mag bewerken. Ze verdwijnt zodra de toestemming is
vastgelegd of het laatste item is gearchiveerd. Net als de pagina vraagt ze
een mens om actie — aan wat iemand kan zien verandert ze niets.

Ze staat naast **Speler zonder contact thuis**, een andere vraag: die zegt
dat de club over deze speler helemaal niemand kan bereiken. Een speler kan
een prima bereikbare ouder hebben en toch geen vastgelegde toestemming.

## Voor een front end buiten WordPress

Hetzelfde antwoord staat op
`GET /wp-json/talenttrack/v1/teams/{team_id}/dossier-completeness`. Dat
geeft `player_count`, `family_reachable` en één blok per controle met `total`, `complete`,
`counts` en een `needs`-lijst met de namen — dezelfde vorm als
`GET /teams/{team_id}/measurement-coverage`. Het endpoint vereist een
spelersleesrecht, globaal of op dat team, en bevat evenmin
contactgegevens.

De academiebrede telling staat op
`GET /wp-json/talenttrack/v1/alerts/family-reachability` en antwoordt alleen
met totalen en teamnamen — daar wordt geen speler genoemd, en juist daarom is
er nog steeds geen clubbrede dossierroute.
