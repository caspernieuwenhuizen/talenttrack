<!-- audience: admin -->

# Beveiliging — handleiding voor de academy admin

> Het draaiboek van de academy admin om een TalentTrack-installatie veilig te houden. Geschreven voor de persoon die TalentTrack op zijn academie heeft geïnstalleerd en verantwoordelijk is voor wie waar bij kan. Ben je coach of speler? Dan is deze pagina niet voor jou — kijk dan op [Aan de slag](?page=tt-docs&topic=getting-started).

Deze gids beschrijft de beveiligingsconfiguratie die je op dag één moet inrichten en minimaal eens per jaar opnieuw bekijkt. Hij behandelt niet het onderliggende rechten-model — daarvoor is er [Toegangsbeheer](?page=tt-docs&topic=access-control). Hij behandelt niet back-ups — daarvoor is er [Back-ups](?page=tt-docs&topic=backups). Hij behandelt wat *jij* moet configureren, in welke volgorde, en wat je doet als er iets misgaat.

De publieke beveiligingsbeloftes van TalentTrack (waar data staat, encryptie-claims, melding-bij-incidenten, audit-cadans) staan op `talenttrack.app/security`.

## Vijf dingen om op dag één te doen

1. **Zet automatische updates voor WordPress core aan.** TalentTrack draait bovenop WordPress; beveiligingspatches op WP-niveau beschermen de hele installatie. Auto-updates voor WordPress core staan standaard aan — controleer dit in `wp-admin → Dashboard → Updates`. Heb je ze uitgezet? Zet ze weer aan.
2. **Beperk administrator-accounts tot mensen die ze écht nodig hebben.** WordPress-administrators omzeilen de TalentTrack-rechtenlaag. Elk admin-account is een sleutel tot alles. Hoe smaller je adminset, hoe kleiner de schade als één wachtwoord wordt gestolen. Twee admins is een redelijk minimum (één voor redundantie); tien is te veel voor één academie.
3. **Zorg dat elke admin een uniek sterk wachtwoord gebruikt.** Wachtwoord-hergebruik is de meest voorkomende inbraakroute: een dienst waar de admin zich los voor heeft aangemeld wordt gehackt, dezelfde combinatie wordt op TalentTrack geprobeerd, en bingo. Gebruik een wachtwoordmanager of dwing een wachtwoordsterkte-regel af via een WordPress-plugin.
4. **Loop de persona-rechtenmatrix door.** TalentTrack levert een verstandige standaard, maar elke academie is anders. Open `wp-admin → TalentTrack → Toegangsbeheer → Matrix` en scan de rijen op persona × entiteit-rechten die niet bij de werkelijke werkwijze van je academie passen. De matrix is het contract — wat daar is aangevinkt, is wat wordt gehandhaafd.
5. **Bookmark de audit-log.** `wp-admin → TalentTrack → Audit log` registreert gevoelige acties (impersonatie start/einde, rolwijzigingen, bulk-verwijderingen, licentie-tier-wijzigingen). Ook als je hem nooit dagelijks bekijkt, het is belangrijk dat je weet waar hij staat als er iets misgaat.

## MFA — multi-factor-authenticatie

> **Status:** TalentTrack-eigen MFA-aanmelding is **vandaag beschikbaar** voor elke gebruiker (#0086 Workstream B Child 1 sprint 2, v3.101.1). Per-club afdwingen (MFA verplichten voor specifieke persona's) volgt in sprint 3 — tot die tijd is aanmelden vrijwillig per gebruiker.

Elke gebruiker kan zich nu zelf aanmelden via `wp-admin → TalentTrack → Account → MFA`:

1. Klik op **Begin aanmelding** om de wizard van 4 stappen te openen.
2. Stap 1 legt uit wat MFA is en welke authenticator-apps werken (Google Authenticator, Authy, 1Password, Microsoft Authenticator, elke RFC 6238 TOTP-app).
3. Stap 2 toont een QR-code die je authenticator-app scant, plus een handmatige terugvaloptie (Account / Uitgever / Secret) als scannen niet lukt.
4. Stap 3 vraagt om de eerste 6-cijferige code uit de app om te bevestigen dat alles goed staat. ±1-stap (90s) tolerantie voor klokafwijkingen.
5. Stap 4 toont 10 eenmalige reservecodes, één keer. Bewaar ze in een wachtwoordmanager of print ze — je krijgt ze niet opnieuw te zien. Elke code is goed voor één login (handig als de gebruiker zijn telefoon kwijt is). Vink het bevestigingsvakje aan om af te ronden.

Na aanmelding kan de gebruiker via dezelfde tab **reservecodes opnieuw genereren** (de oude set werkt direct niet meer) of **MFA uitzetten** (vereist bevestiging; verwijdert de secret + reservecodes; opnieuw aanmelden kan altijd).

**Wat doe je vandaag als academy admin?**:

- Meld je eigen admin-account als eerste aan. Loop de wizard van begin tot eind door, zodat je weet wat je staf gaat zien.
- Stuur elke administrator + Head of Development een mail of bericht met de vraag om binnen een week aan te melden. Sprint 3 maakt het verplicht; vrijwillig nu doen voorkomt straks gehaast werk.
- Voor staf zonder admin-rechten (coaches, scouts, teammanagers) is MFA aanbevolen maar nog niet verplicht. Sprint 3 voegt de per-persona-instelling toe waarmee je het per persona kunt afdwingen.

De TalentTrack-eigen MFA-route staat los van eventueel geïnstalleerde WordPress MFA-plugins (bijv. [Two Factor](https://wordpress.org/plugins/two-factor/), [Wordfence Login Security](https://wordpress.org/plugins/wordfence-login-security/)). De plugin-route blijft werken — maar zodra TalentTrack-eigen MFA-handhaving in sprint 3 landt, gebeurt per-persona-vereiste binnen TalentTrack en kunnen beide routes naast elkaar bestaan.

**Lockout-herstel (beheerder schakelt namens gebruiker uit)** — als een gebruiker zijn telefoon én zijn reservecodes kwijt is, is op dit moment de enige route dat de gebruiker een academy admin vraagt om de `tt_user_mfa`-rij rechtstreeks in de database te wissen, waarna de gebruiker zich opnieuw kan aanmelden. Sprint 3 levert de audit-gelogde "operator schakelt uit namens gebruiker"-flow die deze handmatige stap vervangt.

## De audit-log doornemen

`wp-admin → TalentTrack → Audit log` toont elke gevoelige actie. De standaardweergave is omgekeerd chronologisch. Filter op:

- **change_type** — het type actie. Let op `impersonation_start` (een admin is in iemand anders zijn sessie gestapt), `role_changed` (iemands persona is gewijzigd), `bulk_delete` (een grote sweep), `gdpr_*` (de toekomstige erasure / subject-access-flow zodra die landt).
- **user_id** — wie de actie heeft uitgevoerd. Vergelijk met je adminlijst.
- **target_id** — wie of wat het ging (een player_id, team_id, enz.).

Waar je in een gezonde installatie naar kijkt: de meeste rijen zijn routine — login-events, evaluatie-aanmaak, doel-opslag. Wat je moet doen pauzeren:

- Impersonatie-events die jij niet hebt gestart.
- Bulk-verwijderingen buiten kantooruren.
- Rolwijziging-events voor een admin-account dat jij niet hebt geautoriseerd.

Zie je iets dat niet klopt? Maak een screenshot, vergrendel het verdachte account (`wp-admin → Gebruikers → Bewerken → wachtwoord op iets willekeurigs zetten + alle rollen verwijderen`) en mail MediaManiacs op het adres onderaan.

## Impersonatie — de operator-lens, geen achterdeur

De Academy Admin kan in elke gebruikerssessie stappen via [Impersonatie](?page=tt-docs&topic=impersonation). Bedoeld voor legitieme support en testen — niet voor bespieden. Drie eigenschappen maken het veiliger dan een "geef me je wachtwoord"-omweg:

1. Elke start, einde en orphan-cleanup wordt naar `tt_impersonation_log` geschreven. Onzichtbaar impersonator-zijn kan niet.
2. Een felgele niet-wegklikbare banner staat bovenaan elke pagina tijdens impersonatie, zodat de operator nooit vergeet dat hij iemand anders is.
3. Cross-club impersonatie is in de service-laag geblokkeerd — zelfs een administrator op een multi-club-installatie (als die ooit landt) kan niet de data van een andere club in.

Vermoed je misbruik van impersonatie? De audit-log is de plek. Filter op `change_type IN ('impersonation_start', 'impersonation_end')` en zie wie wanneer in wiens sessie zat, en hoe lang.

## Vermoeden van een inbraak — wat te doen

Als je vermoedt dat een TalentTrack-account is gecompromitteerd:

1. **Vergrendel het account direct.** Bewerk de gebruiker in `wp-admin → Gebruikers`, zet het wachtwoord op een willekeurige string van 30 tekens, verwijder alle rollen. Inloggen kan nu niet meer.
2. **Maak een back-up.** `wp-admin → TalentTrack → Back-ups → Run backup now`. Bewaart de huidige staat zodat eventueel forensisch werk een ijkpunt heeft.
3. **Bekijk de audit-log voor recente activiteit van die gebruiker.** Pak de laatste 24-48 uur. Iets onverwacht? Bulkacties, rolwijzigingen, impersonatie-events?
4. **Neem contact op met MediaManiacs.** `casper@mediamaniacs.nl`. Vermeld de user-ID (niet de naam), wat je hebt gezien en bij benadering wanneer. Wij helpen je triëren en bepalen of het incident GDPR-melding-bij-de-toezichthouder vereist.
5. **Reset belendende accounts.** Had het gecompromitteerde account een breder bereik (bijvoorbeeld admin)? Ga er vanuit dat elk account dat die gebruiker kon zien ook is blootgesteld. Forceer een wachtwoordreset op elke admin en HoD.

GDPR-meldplicht: een persoonsgegevensinbreuk waarbij waarschijnlijk een risico voor de rechten en vrijheden van natuurlijke personen bestaat moet binnen 72 uur na detectie aan de toezichthouder worden gemeld. We helpen je bepalen of jouw incident die drempel haalt — meestal niet, maar de afweging moet bewust zijn, niet overgeslagen.

## Back-ups — je tweede beveiligingslaag

Een werkende back-up is het verschil tussen "we hebben een middag werk verloren" en "we hebben het seizoen verloren". Zie [Back-ups](?page=tt-docs&topic=backups) voor de volledige gids. Drie punten zijn het herhalen waard:

1. **Een geplande back-up is standaard geconfigureerd** — verifieer dat hij draait door `wp-admin → TalentTrack → Back-ups` te openen en het tijdstip "Laatst uitgevoerd" te bekijken.
2. **Off-site kopieën zijn cruciaal.** Een back-up die alleen op dezelfde WordPress-installatie staat, sterft mee als de installatie sterft. Kopieer back-up-bestanden minstens maandelijks naar je eigen externe opslag (Dropbox, OneDrive, Google Drive — alles wat geen onderdeel is van hetzelfde hosting-account).
3. **Een herstel dat je nooit hebt getest, is geen back-up.** Eens per jaar: zet de laatste back-up terug op een staging-installatie en klik rond. Als er iets stuk is, kom je daar nu achter, niet op de dag dat je hem nodig hebt.

## Jaarlijkse checklist

Eens per jaar, op een agenda-herinnering:

- [ ] Loop alle admin-accounts langs. Iedereen die de academie de laatste 12 maanden heeft verlaten, moet eraf.
- [ ] Loop alle Head of Development-accounts langs.
- [ ] Bevestig dat de audit-log nog wordt beschreven (kijk naar de datum van de meest recente rij).
- [ ] Loop de persona-matrix langs — is de structuur van de academie veranderd?
- [ ] Test een back-uprestore op een staging-installatie.
- [ ] Bevestig dat WordPress-core, TalentTrack en alle third-party-plugins up-to-date zijn.
- [ ] Wordt MFA via een third-party-plugin afgedwongen? Bevestig dat hij nog actief is voor elke admin.

## Contact

Voor elke beveiligingsvraag, vermoed incident of "klopt dit"-vraag: `casper@mediamaniacs.nl`. Beveiligingsvragen behandelen we als prioriteit — verwacht een reactie binnen één werkdag.

De beveiligingsbeloftes die TalentTrack publiekelijk maakt — waar data staat, encryptie at-rest en in-transit, audit-cadans, melding-bij-incidenten — staan op `talenttrack.app/security`. Die pagina is voor academie-directeuren en IT-teams die TalentTrack nog niet hebben geïnstalleerd; deze pagina is voor jou, na de installatie.
