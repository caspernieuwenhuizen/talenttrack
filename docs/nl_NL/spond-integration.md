---
title: Spond-integratie
group: configuration
summary: Alleen-lezen Spond → TalentTrack iCal-sync per team.
audience: [user]
views: [spond, spond-monitor, team-spond]
module: TT\Modules\Spond\SpondModule
capability: tt_view_spond
order: 170
---

# Spond-agenda-integratie

Draait je club de trainingsplanning + wedstrijden al in **Spond**? Dan haalt TalentTrack elk event automatisch binnen. Geen sessies meer in beide systemen kloppen.

De integratie is **read-only**: Spond blijft de bron voor de planning en RSVP's; TalentTrack blijft de bron voor evaluaties, doelen, aanwezigheid en al het andere.

## Hoe het werkt (vanaf v3.69.0)

Spond heeft nooit een iCal-feed gepubliceerd — de URL-flow die oudere TalentTrack-versies aannamen, bestaat dus niet. Vanaf v3.69.0 gebruikt de integratie dezelfde interne JSON-API die de officiële Spond-app gebruikt. In de praktijk: je logt één keer in met een echt Spond-account en TalentTrack gebruikt dat account om events op te halen voor de groepen waar dat account lid van is.

## Instellen

Je hebt **één** Spond-account nodig dat lid is van elke ploeg die je wilt synchroniseren. De meeste clubs hebben hier al een toegewijd trainer-/manager-account voor. Tweestapsverificatie wordt **niet** ondersteund in v1 — schakel die op dit account uit, of gebruik een apart account zonder 2FA.

1. Ga naar **Configuratie → Spond-integratie** (de tegel opent de frontend-Spond-weergave op `?tt_view=spond` — sinds v4.57.0 / #1936 geen wp-admin-omweg meer).
2. Vul het Spond-e-mailadres + wachtwoord in en klik **Credentials opslaan**. Het wachtwoord wordt versleuteld bewaard; bij rotatie van WordPress' `AUTH_KEY`-salt wordt het ongeldig en moet je het opnieuw invoeren. Is er al een account verbonden, dan toont de pagina **Verbonden als &lt;e-mail&gt;** en blijft het wachtwoordveld leeg — laat het leeg om het opgeslagen wachtwoord te behouden, of typ een nieuw wachtwoord om het te wijzigen. Het opgeslagen wachtwoord wordt nooit teruggetoond.
3. Klik **Verbinding testen** om te bevestigen dat Spond de login accepteert. Een groene melding betekent klaar; een mislukte login toont de reden meteen.
4. Open per TalentTrack-ploeg het team-formulier (of gebruik de nieuwe-team-wizard) en kies de bijbehorende **Spond-groep** uit de dropdown. De dropdown wordt live gevuld met de groepen waar je account lid van is.

Klaar. Binnen een uur verschijnt elk Spond-event voor elke gekoppelde groep als TalentTrack-activiteit. In het overzicht herken je ze aan de **Spond**-bron-pil.

## Wat wordt gesynchroniseerd

- **Datum / tijd / locatie / titel** — Spond wint. Past een coach één van die velden aan op een Spond-geïmporteerde activiteit, dan overschrijft de volgende sync het. De Spond-pil is de waarschuwing. Spond-tijden worden van UTC naar de tijdzone van je site omgezet, zodat de geïmporteerde begintijd overeenkomt met het tijdstip waarop het event was gepland.
- **Locatie (naam + adres)** — heeft de locatie van een Spond-event zowel een naam als een straatadres, dan bewaart TalentTrack **beide** op één regel, gescheiden door een pipe: `Locatie | Adres`. Omdat locatie een "Spond wint"-veld is, wordt een aanpassing in TalentTrack bij de volgende sync overschreven.
- **Begin- / eindtijd** — overgenomen uit het Spond-event en opgeslagen bij elke geïmporteerde activiteit (voorheen werd alleen de datum bewaard).
- **Eindtijd wedstrijd (terugval)** — Spond-wedstrijdevents hebben vaak geen eindtijd, waardoor geïmporteerde **wedstrijden** voorheen een lege eindtijd hielden. Geeft Spond wel een begintijd maar geen eindtijd, dan krijgt een wedstrijd-type nu standaard een eindtijd van **aftrap + 105 minuten** (dezelfde standaard als de "+ Nieuwe activiteit"-wizard), afgekapt op het einde van de dag bij een zeer late aftrap. Een echte Spond-eindtijd wint altijd — de standaard vult alleen de lege waarde. Trainingen blijven ongewijzigd: een training zonder Spond-eindtijd blijft leeg.
- **Aftrap- & aanwezigheidstijd (wedstrijden)** — bij **wedstrijd**-types (wedstrijd, toernooi) wordt de Spond-begintijd de **aftraptijd** en wordt de Spond-verzameltijd (de "X minuten van tevoren aanwezig"-instelling van Spond) de **aanwezigheidstijd** ("Aanwezig"). Beide verschijnen op de week-PDF van de teamplanner.
- **Activiteitstype** — TalentTrack's keyword-classificator kiest training, wedstrijd, toernooi of vergadering op basis van de titel. Past een coach het type later aan, dan blijft die wijziging bewaard.
- **Notities** — bij de **eerste import** overgenomen uit de beschrijving van het Spond-event, daarna in beheer van TalentTrack. Daarna worden de notities van een coach nooit meer overschreven door een sync — en een latere wijziging van de beschrijving **in Spond** stroomt niet meer mee. (Zelfde model van "eenmalig instellen, daarna wint TalentTrack" als het activiteitstype.)
- **Aanwezigheid, evaluaties, gekoppelde doelen** — alleen TalentTrack. Nooit overschreven.

Verdwijnt een event uit Spond (verwijderd, geannuleerd), dan wordt de bijbehorende TalentTrack-activiteit **soft-gearchiveerd** — nooit verwijderd — zodat eventuele evaluaties bewaard blijven. Komt het Spond-event later weer terug, dan wordt de activiteit gedearchiveerd.

Het sync-window is **30 dagen terug + 180 dagen vooruit** rollend, dus historische events buiten dat venster worden niet bij elke tick opnieuw geïmporteerd.

## Sync-schema

- **Automatische uurlijkse sync** via WP-Cron. De Spond-weergave toont ongeveer hoelang het nog duurt tot de volgende automatische tick.
- **Nu vernieuwen**-knop op het team-formulier én per team op de Spond-weergave voor een directe sync.
- **WP-CLI**: `wp tt spond sync` (alle teams) of `wp tt spond sync --team=<id>`.

Laatste-sync-status verschijnt in de tabel op **Configuratie → Spond-integratie** — groen bij ok, rood met de reden bij een mislukking. Sinds v4.20.109 (#1368) tonen ook de dashboards van hoofd opleidingen en beheerder een waarschuwingsbanner wanneer de meest recente geslaagde sync ouder is dan 24 uur of de laatste sync van een gekoppeld team mislukte — zo valt een kapotte sync op waar iemand het daadwerkelijk ziet, niet alleen op deze beheerpagina.

## Privacy + beveiliging

- **E-mail + wachtwoord** staan in de TalentTrack-configuratietabel, scoped op je club, met het wachtwoord versleuteld via dezelfde envelope die VAPID-push-keys ook gebruikt (`CredentialEncryption`).
- **Spond's login-token** wordt ~12 uur gecached. Bij verloop (of intrekking door Spond) logt de volgende sync transparant opnieuw in.
- **Credentials komen nooit voor in een phone-home-payload** — het v1-payload-schema sluit Spond-credentials en groep-ID's expliciet uit.
- **Loskoppelen**: klik op **Loskoppelen** op de Spond-weergave. Bestaande geïmporteerde activiteiten blijven staan; toekomstige sync's pauzeren. Per-team groep-selecties blijven bewaard, zodat heropnieuw verbinden naadloos doorgaat.
- Credentials opslaan, de verbinding testen, loskoppelen en de API-endpoint-override lopen allemaal via de REST-API (`POST/DELETE /spond/credentials`, `POST /spond/test`, `POST /spond/base-url`), afgeschermd met de capability `tt_edit_spond_credentials`. De pagina bekijken en per team **Nu vernieuwen** zijn afgeschermd met `tt_edit_teams`.

## Hoofdtrainers koppelen hun eigen team (v4.x+)

Een hoofdtrainer heeft geen academie-beheerder meer nodig om Spond te koppelen voor het team dat hij leidt. Op de teampagina staat een actie **Spond-koppeling** die een paneel opent om het eigen Spond-e-mailadres + wachtwoord van het team op te slaan, de login te testen en (zodra een groep is gekoppeld) een synchronisatie te starten — dezelfde per-team-accountoverride die een beheerder op de clubbrede Spond-pagina kan instellen, nu bereikbaar voor de trainer voor zijn eigen team.

De toegang is beperkt tot het exacte team: de per-team-endpoints voor credentials, test en synchronisatie (`POST/DELETE /teams/{id}/spond/credentials`, `POST /teams/{id}/spond/test`, `POST /teams/{id}/spond/sync`) vereisen **wijzigingsrechten op `spond_integration` voor dat team** — een academie-beheerder heeft ze globaal, een hoofdtrainer voor zijn eigen team. De actie **Spond-koppeling** is verborgen voor wie die rechten niet heeft. (Dit sloot ook een eerder gat waarbij de per-team-credential-endpoints, die alleen op de any-team-capability `tt_edit_spond_credentials` waren afgeschermd, een schrijfactie van een hoofdtrainer op een ander team accepteerden.)

### Synchroniseren vanaf de activiteit zelf (v4.x+)

Een verouderde import valt op bij de activiteit, niet op deze instellingenpagina — daarom staat er bij elke vanuit Spond geïmporteerde activiteit een knop **Team synchroniseren vanuit Spond** in de paginakop, naast Bewerken en de rasters. Die roept hetzelfde endpoint `POST /teams/{id}/spond/sync` aan en is met dezelfde per-team-autoriteit afgeschermd: een academie-beheerder ziet hem bij de activiteit van elk team, een hoofdtrainer alleen bij die van zijn eigen team. Bij gearchiveerde activiteiten verschijnt hij niet.

Omdat Spond geen los event opnieuw kan ophalen, haalt de knop de **volledige** teamkalender opnieuw op; het label en de bevestiging zeggen daarom "team" in plaats van te suggereren dat alleen de activiteit voor je neus wordt ververst. Is het team minder dan een minuut geleden gesynchroniseerd, dan meldt de bevestiging dat, zodat wie twee keer klikt weet dat er niets mis is. Na een geslaagde synchronisatie herlaadt de pagina en toont de regel "Team laatst gesynchroniseerd vanuit Spond" het nieuwe tijdstip.

### De groep kiezen (v4.x+)

De trainer kiest de **Spond-groep** van het team nu in hetzelfde paneel, zodat de installatie op één plek klaar is in plaats van te stranden bij het teambewerkingsformulier (dat is afgeschermd met `tt_edit_teams`, een recht dat de meeste hoofdtrainers niet hebben).

De keuzelijst verschijnt **zodra de login werkt**. Groepen ophalen vereist een geauthenticeerde aanroep naar Spond, dus vóór een geslaagde login valt er niets te tonen — het paneel zegt dat en verwijst terug naar Opslaan + Testen in plaats van een lege keuzelijst te tonen. De lijst wordt vijf minuten per team gecached, zodat het paneel herhaaldelijk openen Spond niet opnieuw belast; het opslaan of wissen van de teamcredentials laat die cache meteen vervallen.

Is de gekozen groep **al aan een ander team gekoppeld**, dan waarschuwt het paneel en noemt het dat team — maar opslaan mag. Twee teams die één Spond-groep delen is legitiem (een gecombineerde agenda per leeftijdsgroep), dus dit is een signaal, geen blokkade. Beide teams importeren dan dezelfde events.

De endpoints zijn `GET /teams/{id}/spond/group` (de lijst, elk item gemarkeerd met het andere team dat hem gebruikt) en `POST /teams/{id}/spond/group` (opslaan; een lege `group_id` ontkoppelt). Beide zijn afgeschermd met dezelfde per-team-autoriteit als de credential-endpoints — **niet** met `tt_edit_teams`. Het beheerderspad op het teambewerkingsformulier blijft ongewijzigd.

## API-endpoint-override

Verhuist Spond ooit zijn API naar een nieuw adres, dan kan een beheerder TalentTrack omleiden zonder code-release: open de inklapbare sectie **API-endpoint** op de Spond-weergave, voer de nieuwe basis-URL in en sla op. Laat het leeg en sla op om terug te keren naar de meegeleverde standaard. Een verkeerde URL laat elke sync mislukken, dus wijzig dit alleen als Spond een nieuw endpoint aankondigt of om tegen een privé-mock te testen.

## Wat (nog) niet wordt ondersteund

- **Tweezijdige sync** — wijzigingen in TalentTrack lopen niet terug naar Spond.
- **Tweestapsverificatie** op het Spond-account.
- **Per-coach Spond-accounts** — één account per club.
- **Inbound webhooks** — Spond publiceert die niet; de uurlijkse cron is het model.

## Overstappen vanaf de iCal-flow (vóór v3.69.0)

Heb je eerder iCal-URL's in het team-formulier geplakt? Dan worden die automatisch geleegd door migratie 0052. Verbind opnieuw door op **Configuratie → Spond** je Spond-e-mailadres + wachtwoord in te voeren en per ploeg de groep te kiezen. Bestaande geïmporteerde activiteiten blijven staan en gaan weer updaten zodra een groep is gekoppeld.

## Integratiemonitor (proefrun)

Via de Spond-pagina opent **Integratiemonitor openen** een diagnostisch scherm
dat de Spond-API **live** ophaalt voor een gekozen team en toont wat er
binnenkomt — er wordt niets opgeslagen. Per gebeurtenis toont het het
geclassificeerde type, de datum, tijden en locatie, plus een **verschil**: of
een echte synchronisatie hem zou aanmaken (`NEW`), een bestaande activiteit zou
bijwerken (`UPDATE`, met precies welke velden zouden worden overschreven), of
een opgeslagen activiteit zou archiveren die niet meer in de feed staat. Gebruik
het om te verklaren waarom een afgedrukte activiteit afwijkt van wat je in Spond
hebt ingesteld — een verouderde cache, een gewijzigde gebeurtenis-UID of een veld
dat Spond beheert worden hier zichtbaar. Vereist dezelfde \`tt_edit_teams\`-
capaciteit als de synchronisatie.

## Accounts per team (het clublogin overschrijven)

Elk team op de Spond-pagina toont met welk account het synchroniseert —
standaard **Gebruikt clubaccount**, of **Eigen account: <e-mail>** bij een
override. Klap het **Account**-paneel van het team open om het een eigen
Spond-login te geven (e-mail + wachtwoord): dat teamaccount **overschrijft**
dan het clubaccount voor de synchronisaties van dat team. Laat de e-mail leeg
(of gebruik **Clubaccount gebruiken**) om de override te verwijderen en terug te
vallen op het clublogin. Wachtwoorden per team worden versleuteld bewaard en
elk team houdt zijn eigen token-cache. Vereist de Spond-inloggegevens-capaciteit.
