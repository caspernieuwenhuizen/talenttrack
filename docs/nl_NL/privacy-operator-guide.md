<!-- audience: admin -->

# Privacy — handleiding voor de academy admin

> Het draaiboek van de academy admin voor het omgaan met persoonsgegevens in TalentTrack — vooral met die van minderjarigen, want dat is het meeste wat TalentTrack opslaat. Geschreven voor de persoon die TalentTrack heeft geïnstalleerd en verantwoordelijk is voor de dagelijkse gegevensverwerking. Ben je coach, scout of staf? Dan is deze pagina niet voor jou — kijk dan op [Aan de slag](?page=tt-docs&topic=getting-started).

Deze gids beschrijft wat een academie onder de Europese privacywet (de AVG / GDPR) moet doen wanneer ze TalentTrack draait: wie informeer je, wat moet je ouders en spelers laten doen, hoe lang bewaar je data, wat doe je als iemand vraagt om export of verwijdering. De juridische beloftes die TalentTrack maakt — subverwerker-lijst, hostingregio, retentie-defaults, de DPA — staan op `talenttrack.app/privacy`. Deze pagina is de operator-handleiding hoe je het in de praktijk doet.

> **Disclaimer.** Deze gids beschrijft hoe TalentTrack is gebouwd en wat de gedocumenteerde controles doen. Geen juridisch advies. Jouw academie is de verwerkingsverantwoordelijke; raadpleeg je eigen Functionaris Gegevensbescherming of jurist voor advies dat past bij jouw jurisdictie en structuur.

## Het juridische kader in twee zinnen

Jouw academie is **verwerkingsverantwoordelijke** — jij bepaalt welke persoonsgegevens worden verzameld, waarom en voor hoe lang. MediaManiacs (het bedrijf dat TalentTrack levert) is je **verwerker** — wij houden de data namens jou bij, handelen alleen op jouw instructies, en hebben een Verwerkersovereenkomst (DPA) met jou ondertekend die deze verhouding documenteert. Beide rollen zijn AVG-begrippen; beide leggen specifieke verplichtingen op.

Het DPA-template staat op `talenttrack.app/privacy` om te downloaden. De meeste academies tekenen as-is. Heeft jouw jurist aanpassingen nodig? Neem contact op met MediaManiacs op het adres onderaan.

## Welke persoonsgegevens TalentTrack opslaat

Een volledige tabel van elke kolom in elke `tt_*`-tabel die persoonsgegevens bevat staat op `talenttrack.app/privacy`. Samenvatting:

- **Spelers** (de meesten minderjarig): naam, geboortedatum, foto, contactgegevens, evaluatiehistorie, aanwezigheidshistorie, doelen, POP-records, journey-events, scoutrapporten, stagebeslissingen, notities (#0085), gedrag- en potentieel-evaluaties.
- **Ouders**: naam, e-mail, telefoon, link naar één of meer speler-records.
- **Staf** (coaches / scouts / HoD / managers / enz.): naam, e-mail, rol, scope-of-access in de matrix, login-activiteit.
- **Operationele metadata**: audit-log-entries, impersonatie-log, login-activiteit (standaard alleen datum), demodata-tags.

Wat TalentTrack *niet* opslaat: betalingsgegevens (de Freemius-integratie regelt dat op Freemius-servers), vrije-tekstvelden voorbij wat coaches in evaluatie- / doel- / notitie-bodies typen, browsing-historie buiten TalentTrack-pagina's, IP-adressen gekoppeld aan specifieke acties (de audit-log registreert user_id, niet IP).

## Drie dingen om op dag één te doen

1. **Inventariseer wie waar bij kan.** Elke TalentTrack-gebruiker heeft een WordPress-rol plus een TalentTrack-persona. De combinatie bepaalt wat ze zien. Open `wp-admin → TalentTrack → Toegangsbeheer → Compare users` en loop elk staf-account langs met de vraag *moet deze persoon zien wat dit account ziet?*. Bij "nee, smaller": fix de persona-toewijzing.
2. **Bepaal retentie-vensters per datatype.** AVG vereist dat persoonsgegevens *niet langer dan noodzakelijk* worden bewaard. Bepaal vandaag hoe lang "noodzakelijk" is per categorie — bijvoorbeeld: actieve speler-records bewaard zolang men speelt + 5 jaar na vertrek, dan archiveren; stage-beslisbrieven 7 jaar voor audit; demodata wekelijks gewist; audit-log 2 jaar. Documenteer dit als het retentie-beleid van je academie. TalentTrack levert defaults; het beleid is van jou.
3. **Vertel ouders wat je verzamelt.** Onder AVG moet je een privacyverklaring geven aan betrokkenen (de speler, de ouder voor minderjarigen) waarin staat welke data wordt verzameld en waarom. Het privacyverklaring-template op `talenttrack.app/privacy` is academie-klaar — fork het, vul je academienaam en contactgegevens in, distribueer (e-mail, inschrijfformulier, oudersportaal — wat past bij hoe je onboardt).

## Als een ouder of speler zijn data opvraagt — recht op inzage

Onder AVG kan een betrokkene jou vragen om *alle persoonsgegevens die je over hem houdt*, in een overdraagbaar formaat. Je moet binnen één maand reageren.

> **Status:** een formele "Subject Access Export"-feature komt in de Export-module (#0063 use case 10 — *Player GDPR export ZIP*). Tot die tijd geldt de handmatige procedure hieronder.

**Handmatige procedure (vandaag):**

1. Verifieer de identiteit van de aanvrager. Een ouder die de data van zijn kind opvraagt moet aantoonbaar de ouder zijn — meestal een snelle e-mailwisseling.
2. Loop het spelersprofiel door en kopieer elke sectie. Profiel, evaluaties, doelen, aanwezigheid, POP-records, stagedossiers, journey-events, notities (#0085 — staf-only, niet aan de speler of ouder getoond in de export — zie de AVG-noot hieronder), scoutrapporten.
3. Compileer in een PDF of ZIP. Het format is aan jou; AVG-eis is "gestructureerd, gangbaar gebruikt en machineleesbaar" — een schone PDF + JSON/CSV voor de gestructureerde delen voldoen beide.
4. Verstuur via een methode die past bij gevoelige data — versleutelde e-mail, eenmalige downloadlink, of in persoon met legitimatie.
5. Log de aanvraag en jouw reactie in het privacyregister van je academie.

**Zodra #0063 leeft:** de export wordt een enkele klik vanuit `wp-admin → TalentTrack → Spelers → [speler] → Export GDPR data`. De output-ZIP volgt de "data-portabiliteit"-bepaling van AVG, is ondertekend en getimestamped.

**Eén subtiliteit voor spelersnotities (#0085).** Spelersnotities zijn staf-only by design — coaches moeten openhartige observaties kunnen schrijven zonder dat ouders meekijken (*"Lucas was vanavond op de training stiller dan normaal, ouders zijn aan het scheiden"* is genuinely nuttig voor staf en schadelijk als de ouder het zou zien). Onder AVG heeft de ouder wél recht op de persoonsgegevens van zijn kind. Dat creëert spanning. De huidige aanpak: notitie-bodies opnemen in inzage-exports, tenzij de academie een gedocumenteerde gerechtvaardigd-belang-onderbouwing heeft om specifieke notities uit te sluiten (bijvoorbeeld safeguarding-flagged notities die naar een derde verwijzen). Overleg met je FG voordat je een verzoek beantwoordt waar notities in zitten.

## Als iemand vraagt vergeten te worden — recht op vergetelheid

Onder AVG kan een betrokkene vragen om verwijdering van zijn data ("recht op gegevenswissing" / "recht om vergeten te worden"). Reactie binnen één maand. Er zijn uitzonderingen — je mag weigeren als er een rechtmatige grond is om data te bewaren (bijv. wettelijke verplichting, verdediging tegen rechtsvordering) — maar de hoofdregel is "voldoen".

> **Status:** een formele wis-pijplijn (dry-run preview → 30-dagen grace → hard-delete in elke PII-tabel) is een toekomstige spec, op dit moment in shaping (afgesplitst van #0086 per de May 2026 retrospective; afhankelijk van de #0083 fact registry). De `PlayerDataMap` registry uit v3.95.0 (#0081 child 1) is het fundament. Tot de pijplijn er is geldt de handmatige procedure hieronder.

**Handmatige procedure (vandaag):**

1. Verifieer de identiteit van de aanvrager (zoals bij inzage).
2. Bepaal of wissing passend is. Is er een rechtmatige grond om de data te bewaren? Documenteer de afweging hoe dan ook.
3. Wissing passend? Archiveer de speler (`wp-admin → TalentTrack → Spelers → [speler] → Archiveren`). Dit is een soft-delete — de rij verdwijnt uit standaardlijsten maar de data staat nog in de database, ophaalbaar door een administrator. Soft-delete is onder AVG *geen* wissing — de data is er nog.
4. Voor échte wissing vandaag: neem contact op met MediaManiacs op het adres onderaan. De hard-delete loopt elke `tt_*`-tabel langs en is op dit moment een handmatige operator-procedure (omdat het verkeerd doen overal weeswezen oplevert). Wij doen het voor jou; we loggen de procedure; we bevestigen voltooiing schriftelijk.
5. Log de aanvraag en jouw reactie in het privacyregister van je academie.

**Zodra de wis-pijplijn leeft:** de procedure wordt een knop — `wp-admin → TalentTrack → Spelers → [speler] → Wissen`. Een dry-run-preview toont elke rij die wordt verwijderd. Een 30-dagen-grace laat je de beslissing terugdraaien. Na de grace wordt elke PII-rij hard-deleted; geaggregeerde analytics passen zich automatisch aan.

## Retentie-defaults en hoe je ze aanpast

TalentTrack levert verstandige defaults, maar de waardes zijn van jou om in te stellen. Waar ze leven:

| Data | Standaard-retentie | Waar te wijzigen |
|------|---------------------|------------------|
| Actieve speler-records | Onbepaald zolang `archived_at IS NULL` | Per-speler archiveren bij vertrek |
| Gearchiveerde speler-records | Onbepaald (tot wissing of handmatige cron) | Toekomstige wis-spec / vandaag per-speler wissing |
| Stage-beslissingen | Tot handmatig gearchiveerd | Stage-admin |
| Audit-log-entries | Onbepaald | Geen automatische opruiming vandaag; handmatig SQL indien nodig |
| Impersonatie-log | Onbepaald | Idem |
| Demodata | Op verzoek of via geplande "wipe demo"-run | `wp-admin → Tools → TalentTrack Demo Data` |
| Prospects (geen progressie) | 90 dagen na `created_at` | `wp_options.tt_prospect_retention_days_no_progress` (in `wp-config.php` als je een non-default wilt) |
| Prospects (terminale beslissing) | 30 dagen na archivering | `wp_options.tt_prospect_retention_days_terminal` |
| Login-events | Onbepaald | Idem audit-log |

De "onbepaald"-defaults zijn voor retentie-veiligheid — je wilt geen auto-purge die je verrast. Bepaal per categorie wat past en documenteer het. Zodra de toekomstige wis-spec leeft, worden de gedocumenteerde retentie-vensters afdwingbaar als automatisch beleid.

## Wanneer een speler toetreedt of vertrekt — de privacy-levenscyclus

**Toetreden:**
1. De ouder (voor minderjarigen) tekent de standaardregistratie van de academie, die verwijst naar de privacyverklaring van de academie (template op `talenttrack.app/privacy`).
2. Datapunt dat vanaf dat moment in TalentTrack staat: `tt_players`-rij + gekoppelde `tt_player_parents`-rij + consent-vlag gezet. De audit-log registreert het create-event.
3. Foto, contactgegevens, scoutingcontext — allemaal door staf ingevoerd, allemaal onderhevig aan retentie-beleid.

**Actief lidmaatschap:**
- Evaluaties, doelen, aanwezigheid, notities groeien volgens de matrix-rechten. De speler en de ouder zien hun eigen data via hun dashboards.
- Inzage- en wis-verzoeken volgen de procedures hierboven.

**Vertrekken:**
1. De speler wordt gearchiveerd (soft-delete). Active-list queries verbergen hem.
2. Retentie-beleid begint te lopen. Na de gedocumenteerde retentieperiode (bijv. 5 jaar) worden de speler-records hard-deleted via de wis-pijplijn (of handmatig vandaag).
3. Geaggregeerde analytics blijven bestaan — *N spelers in cohort U13 2021-2026, gemiddelde evaluatiescore X* — zonder de per-speler-rijen.

## Jaarlijkse privacy-checklist

- [ ] Loop elk actief staf-account in de matrix langs. Maak smaller waar passend.
- [ ] Bevestig dat de privacyverklaring aan ouders weerspiegelt wat de academie vandaag verzamelt (nieuwe modules / velden / integraties sinds vorig jaar?).
- [ ] Review je retentie-beleid tegenover de praktijk. Als je hebt afgesproken "5 jaar na vertrek" maar niemand wist ooit iets: documenteer het gat en beslis.
- [ ] Steekproef de audit-log op ongebruikelijke inzage-patronen.
- [ ] Bevestig dat het privacyregister van je academie up-to-date is (één rij per verzoek in de afgelopen 12 maanden).
- [ ] Lees deze gids en het publieke privacybeleid op `talenttrack.app/privacy` opnieuw voor updates.

## Contact

Voor privacyvragen, vermoede gegevensbescherming-issues of hulp bij een inzage- / wis-verzoek: `casper@mediamaniacs.nl`. We reageren binnen één werkdag en prioriteren alles wat een 72-uurs AVG-meldplicht-klok raakt.

De juridische beloftes die TalentTrack publiekelijk maakt — subverwerker-lijst, hostingregio, het DPA-template, het publieke privacybeleid — staan op `talenttrack.app/privacy`. Die pagina is de klantgerichte baseline. Deze pagina is de operator-handleiding.
