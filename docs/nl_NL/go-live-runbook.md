<!-- audience: admin -->

# Go-live-runbook

Pre-launch-checklist om een TalentTrack-installatie met een echte academie in productie te nemen. Werk hem in de week vóór go-live van boven naar beneden door; elk punt is binnen een minuut of twee te verifiëren. Punten gemarkeerd met **(blocker)** moeten groen zijn voordat de eerste echte gebruiker inlogt.

## 1. Licentiestatus (blocker)

De pilotselectie overschrijdt de gratis tier (1 team / 25 spelers) op dag één van de gegevensinvoer, dus de licentiestatus moet vóór de rosterimport zijn vastgezet:

- **Niet-commerciële installatie (de standaard):** controleer `define( 'TT_COMMERCIAL_MODE', false );` in `talenttrack.php`. Met commerciële modus uit is elke functie ontgrendeld, gelden er geen gebruikslimieten en wordt proefperiode-verloop genegeerd — niets te doen behalve controleren dat niemand hem heeft omgezet.
- **Commerciële installatie:** staat `TT_COMMERCIAL_MODE` op `true`, zet dan de tier vast zodat limieten de onboarding niet kunnen onderbreken — wijs het betaalde plan toe in Freemius, of stel de developer-override in (Account-pagina → developer-tier-override, opgeslagen met wie-en-wanneer). Verifieer eerst op een staging-kopie door een tweede team en een 26e speler aan te maken.

Leg in beide gevallen vast welk mechanisme actief is in je operationele notities. Het symptoom van een fout hier is een harde "limiet bereikt"-fout midden in de rosterimport.

## 2. Back-ups (blocker)

De ingebouwde Backup-module van TalentTrack exporteert volgens schema de eigen tabellen van de plug-in — dat is **geen** volledige site-back-up. Vóór go-live:

- **Volledige site-back-up op hostniveau** geconfigureerd: bestanden + database, dagelijks, retentie ≥ 14 dagen, opgeslagen **buiten de webserver**. Spelersfoto's staan in `wp-content/uploads/` en WordPress-gebruikers in `wp_users` — geen van beide valt onder de tabelexport van de plug-in.
- **Back-upschema van de plug-in** ingeschakeld (Configuratie → Back-ups): dit is het snelle, selectieve herstelpad bij invoerfouten, plus het automatische-snapshot-vangnet vóór bulkverwijderingen.
- **Herstel één keer getest**: zet de laatste host-back-up terug op een staging-omgeving en log in. Een back-up die nooit is teruggezet is hoop, geen plan.

## 3. Schema & migraties (blocker)

- wp-admin toont **geen** TalentTrack-schemabanner (geel = in afwachting, rood = een migratie is mislukt; zie [Migraties & updates](migrations.md)).
- De Migraties-beheerpagina toont nul openstaande migraties en de meest recente runs zonder fouten.

## 4. Integraties

- **Spond** (indien gebruikt): Configuratie → Spond toont een geslaagde synchronisatie binnen het afgelopen uur, en de gesynchroniseerde activiteiten kloppen op een teamplanner. Let op: de sync gebruikt Sponds niet-officiële API — stopt het bijwerken van schema's midden in het seizoen, kijk dan eerst op die pagina.
- **E-mail**: stuur een testuitnodiging naar een mailbox die je beheert; bevestig dat hij aankomt (afleverbaarheid en SPF/DKIM zijn host-zaken, maar ze falen vaker in de go-live-week dan ooit).

## 5. Accounts & toegang

- Beheerdersaccounts gebruiken sterke wachtwoorden; MFA aan waar beschikbaar.
- Elke coach heeft een account met de juiste rol, gekoppeld aan de juiste teams — steekproef: één coach ziet het eigen roster en niet de medische gegevens van een andere leeftijdsgroep.
- De persona-dashboardpagina bestaat en is bereikbaar (normaal aangemaakt door de Setup-wizard).

## 6. Supportplan voor dag één

- Wie appen coaches als er iets stuk gaat in de eerste week, en wie escaleert naar de ontwikkelaar/host?
- Plan de eerste rosterimport en de eerste echte training een dag uit elkaar, zodat dataproblemen opduiken vóór gebruik langs het veld.

## Na go-live

Draai de eerste plug-in-update op een rustige avond en controleer direct daarna de schemabanner (zie §3). Houd de retentie van de host-back-up minimaal de eerste maand op 14+ dagen.
