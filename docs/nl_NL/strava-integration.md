<!-- audience: user, admin -->

# Strava-koppeling

Koppel het persoonlijke Strava-account van een speler zodat zijn trainingen —
hardlopen, fietsen, conditiewerk buiten de academietrainingen — als gedateerde
items op **de eigen tijdlijn van die speler** verschijnen. Het beantwoordt de
vraag *"welke training doet deze speler buiten onze sessies, en hoe ontwikkelt
zijn belasting zich?"*

Dit is **het koppelen van een account**, niet "inloggen met Strava". Spelers
blijven gewoon ingelogd in TalentTrack; Strava koppelen is een eenmalige
autorisatie boven op de bestaande sessie. Strava is hier nooit een
identiteitsprovider, dus een speler die geen Strava-account kan hebben, wordt
nooit buitengesloten van TalentTrack.

---

## Wat wordt gesynchroniseerd — en wat niet

Per activiteit geïmporteerd:

- afstand
- bewegingstijd en totale tijd
- tempo / gemiddelde snelheid
- hoogtemeters
- type activiteit, naam en starttijd

**Er worden nooit hartslag- of andere biometrische gegevens opgevraagd,
opgeslagen of getoond.**

Dat is bewust. Strava blokkeert hartslaggegevens voor sporters onder de 16 (een
eis vanuit de Europese gegevensbescherming), en de meeste academiespelers zijn
jonger dan 16. In plaats van een functie die stilletjes faalt voor de
kerngroep, is de koppeling beperkt tot afstand / duur / tempo / hoogtemeters —
zo werkt het voor elke speler identiek, ongeacht leeftijd. Geïmporteerde
activiteiten staan op een aparte lijst per speler, los van teamsessies.

---

## Een Strava-account koppelen

Het paneel "Koppelen met Strava" staat op het profiel van de speler (een eigen
pagina via `?tt_view=strava`, en een tabblad **Strava** op de spelerdetailpagina).

1. Vink het **toestemmingsvinkje** aan — akkoord met het delen van de
   activiteitsgegevens van de speler (afstand, duur, tempo, hoogtemeters) met de
   academie. De koppelknop blijft uitgeschakeld totdat het is aangevinkt.
2. Klik op **Koppelen met Strava**. Je wordt naar het toestemmingsscherm van
   Strava gestuurd.
3. Geef daar akkoord; Strava brengt je terug naar het spelerprofiel met een
   bevestiging. Activiteiten verschijnen binnen enkele minuten nadat ze zijn
   vastgelegd.

### Toestemming — wie akkoord geeft, en een vastgelegde kanttekening

Toestemming wordt vastgelegd op het **eigen profiel van de speler** via het
vinkje en wordt **vastgelegd in het auditlog**. De afdwinging gebeurt
server-side: de autorisatiestap is niet bereikbaar zonder vastgelegde
toestemming, dus het vinkje is de affordance, niet de enige bewaking.

> **Vastgelegde kanttekening (28-06-2026).** Toestemming vastleggen op het eigen
> profiel van de speler is de eenvoudigere flow die voor de pilot is gekozen.
> Voor minderjarigen is dit een zwakkere positie qua ouderlijke toestemming dan
> het vastleggen van toestemming op de ouderweergave van het kind. Deze afweging
> is door de producteigenaar geaccepteerd; heroverweeg dit als een juridische
> toetsing toestemming aan de kant van de voogd vereist, waarna de affordance
> naar de ouderweergave verhuist.

Het gaat om minderjarigen: geïmporteerde gegevens zijn alleen zichtbaar voor
rollen die de speler al mogen bekijken, nooit over academies, leeftijdsgroepen
of niet-geautoriseerde rollen heen.

---

## Ontkoppelen

Een speler (of een coach met bewerkrechten) kan via het paneel **Ontkoppelen**:
dit trekt de toestemming bij Strava in en wist de opgeslagen tokens. Trekt de
sporter de toegang in aan de **Strava**-kant, dan krijgt TalentTrack daar bericht
van en gebeurt hetzelfde automatisch.

In beide gevallen worden de eerder geïmporteerde activiteiten **gearchiveerd**
(zacht verwijderd, niet hard gewist), zodat er na een ontkoppeling niets in een
geautoriseerde staat achterblijft.

---

## Instellen door de beheerder

Beide eenmalige stappen staan op de console **Strava-koppeling**, te bereiken
via **Configuratie → Koppelingen → Strava-koppeling** (of rechtstreeks via
`?tt_view=strava-admin`):

1. **Registreer de Strava-appgegevens.** Maak een API-applicatie aan in je
   Strava-account en plak de **Client ID** en het **Client secret** in het
   onderdeel *App-gegevens*. Het secret wordt versleuteld opgeslagen, is alleen
   schrijfbaar en wordt nooit meer getoond — laat het veld leeg om de opgeslagen
   waarde te behouden. Stel het *Authorization Callback Domain* van de Strava-app
   in op deze site en de redirect op de callback-URL die op de pagina staat.
2. **Maak het webhookabonnement aan.** De knop **Aanmaken / opnieuw verifiëren**
   in het onderdeel *Webhookabonnement* registreert het ene academiebrede
   push-abonnement bij Strava, dat het direct valideert met een
   challenge-handshake. **Abonnement verwijderen** haalt het weg.

   Strava staat slechts **één abonnement per applicatie** toe. De knop mag
   gerust meerdere keren worden ingedrukt: bestaat er al een abonnement bij
   Strava (van een eerdere installatie, of waarvan deze installatie het id is
   kwijtgeraakt), dan neemt de console het over in plaats van een foutmelding
   te geven. De getoonde status wordt bij elke paginalading afgestemd op de
   werkelijke staat bij Strava, dus een abonnement dat aan Strava-zijde is
   verwijderd, verdwijnt hier automatisch.

De tabel **Gekoppelde spelers** op dezelfde console toont elke speler die een
Strava-account is gaan koppelen — status (gekoppeld, wacht op toestemming,
ingetrokken, ontkoppeld), aantal geïmporteerde activiteiten, laatste activiteit
en laatste synchronisatie — zodat een beheerder in één oogopslag ziet wiens
training binnenkomt.

Elke actie is ook beschikbaar via de REST API voor een niet-WordPress-frontend:
`POST /wp-json/talenttrack/v1/strava/app`, `GET/POST/DELETE
.../strava/webhook/subscription` en `GET .../strava/connections`. Het client
secret en de tokens per speler worden nooit door een endpoint teruggegeven.

### Wie de console kan bereiken

De console is **matrix-afgeschermd**, niet gekoppeld aan de WordPress-rol
*Beheerder*: bekijken volgt `tt_view_strava` (de *lees*-activiteit van de
entiteit `strava_integration`) en het wijzigen van gegevens of de webhook volgt
`tt_edit_strava_credentials` (de *wijzig*-activiteit). Standaard hebben
academiebeheerders en hoofden opleiding deze rechten; stel ze per persona in via
de autorisatiematrix.

De OAuth-**callback** (`GET .../strava/callback`) en de **webhook**
(`GET/POST .../strava/webhook`) zijn noodzakelijkerwijs openbare routes —
Strava roept ze rechtstreeks aan. Ze authenticeren zichzelf (de callback
verifieert een ondertekende `state`; de webhook-handshake verifieert een token
per installatie), nooit via een WordPress-sessie.

---

## Hoe het werkt (architectuur)

- **OAuth-koppeling.** De koppelknop maakt een autorisatie-URL met een
  ondertekende, in tijd beperkte `state` die de koppelende speler bindt (CSRF +
  identiteitsbinding). De openbare callback verifieert die `state`, wisselt de
  code server-side in voor tokens en slaat ze op.
- **Tokens per speler, versleuteld.** De access- en refresh-tokens van elke
  koppeling worden versleuteld opgeslagen, één rij per speler. Access-tokens
  verlopen na zes uur; het refresh-token roteert bij elke vernieuwing, en het
  geroteerde token wordt atomair samen met het nieuwe access-token opgeslagen,
  zodat een speler nooit wordt buitengesloten door een halve schrijfactie.
- **Tokenvernieuwing** draait op de hartslag van de workflow-engine (het ene
  scheduler-knooppunt), plus op aanvraag vlak voor een synchronisatie. Een door
  Strava geweigerde toegang zet de koppeling op "ingetrokken" zodat de
  interface om opnieuw koppelen kan vragen.
- **Webhooksynchronisatie, geen polling.** Strava staat precies één
  push-abonnement per applicatie toe, dat alle geautoriseerde sporters dekt.
  Aanmaken / wijzigen / verwijderen van activiteiten en het intrekken van
  toestemming komen binnen als pushes; TalentTrack haalt de volledige activiteit
  op met het token van de speler en werkt die bij. Elke speler pollen zou de
  ratelimieten van Strava overschrijden — webhooks zijn het bedoelde mechanisme.

---

## REST API

Alle endpoints staan onder `talenttrack/v1`; zie [`docs/rest-api.md`](../rest-api.md)
voor de volledige lijst en afscherming. Kort: per speler `connect` /
`disconnect` / `status` / `activities`, de openbare `callback` en `webhook`, en
de beheerdersroutes `app`, `webhook/subscription` en `connections` (de
console-lijst). De beheerdersroutes zijn matrix-afgeschermd op `tt_view_strava`
(lezen) / `tt_edit_strava_credentials` (schrijven). Tokens per speler en het
client secret worden nooit in een respons teruggegeven.
