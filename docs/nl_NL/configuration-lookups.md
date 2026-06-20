<!-- audience: admin -->

# Configuratie — Lookups

**Doelgroep:** academy-administrator.

De tegel Lookups op de configuratiepagina is de enige plek om elke dropdown-vocabulaire die het dashboard rendert te beheren — activiteitstypes, posities, leeftijdsgroepen, doelstatussen, evaluatietypes, gedragsbeoordelingen, potentieelbanden, enzovoort. Bewerkingen hier worden direct toegepast op elk formulier, elke lijst en elk rapport dat het betreffende vocabulaire leest.

## De vier surfaces

1. **Configuratie → Lookups** — landingspagina. Eén tegel per lookup-categorie, **gegroepeerd in domeinsecties** (Activiteiten & aanwezigheid, Spelers & teams, Evaluaties & ontwikkeling, Doelen, Scouting & stages, Toernooien & wedstrijd, Staf & mensen, Rapporten & workflow, Geavanceerd / intern). Elke tegel heeft een icoon, een korte beschrijving en klikt door naar de per-categorie editor. Een sectie zonder zichtbare tegels toont geen kop. *(v4.26.11 — voorheen één plat raster van ~32 kaarten.)*
2. **Per-categorie editor** — de list-first weergave van de waarden van één lookup-categorie (bijv. Activiteitstypes). Standaardweergave: een overzichtelijke lijst van waarden met een knop `+ Waarde toevoegen` bovenaan.
3. **Nieuwe waarde toevoegen** — opent vanaf de `+ Waarde toevoegen` knop. Leeg formulier met het veld Interne sleutel bovenaan + een vertalingsraster met 5 locales eronder.
4. **Waarde bewerken** — opent door op een rij in de lijst te tikken. Zelfde vorm als Toevoegen maar gevuld met de data en vertalingen van de rij. Het veld Interne sleutel is alleen-lezen op bestaande rijen.

## Lijstweergave

Elke rij toont van links naar rechts:

- **Sleepgrip** (⋮⋮) — sleep om opnieuw te ordenen. De nieuwe sorteervolgorde wordt direct opgeslagen via het bestaande reorder-endpoint.
- **Kleurvlakje** — als de categorie een `show_color` vlag heeft (Activiteitstypes, Doelstatussen, enz.).
- **Label + interne sleutel** — het operator-zichtbare label komt uit `tt_translations` (een Nederlandse site toont dus het Nederlandse label); de interne sleutel staat in monospace ernaast als stabiele database-identifier.
- **Vertaalstatus-stippen** — één stip per ondersteunde locale. Groen gevuld = er bestaat een vertaling voor die locale; oranje waarschuwing = ontbreekt. Site-locale staat eerst; `en_US` als tweede; overige locales volgen in stabiele volgorde. De dekkings­controle kijkt alleen naar het Label — een Beschrijving is optioneel en bepaalt de stip niet.
- **Sorteervolgorde** — het gehele getal waarop de rij gesorteerd wordt binnen de categorie.
- **Verwijderknop** — verborgen op vergrendelde rijen (rijen waar workflow-regels van afhankelijk zijn). Vergrendelde rijen zijn nog steeds aanklikbaar voor de bewerkweergave; de verwijderknop wordt gewoon niet getoond.

Een knop `+ Waarde toevoegen` bovenaan de lijst opent de Toevoegen-weergave.

## Bewerkweergave

Tik op een rij om de Bewerkweergave te openen. Het formulier heeft twee kaarten:

### Kaart 1 — de kolommen van de rij

- **Interne sleutel** — uitgeschakeld op bestaande rijen. Dit is de stabiele database-identifier (bijv. `match`, `training`). Om deze te wijzigen is een code-migratie nodig zodat elke verwijzing atomair wordt bijgewerkt.
- **Sorteervolgorde** — geheel getal.
- **Pillkleur** — kleurkiezer, als de categorie `show_color` heeft.
- **Beschrijving (canoniek, optioneel)** — de Engelse beschrijving die wordt getoond als er geen per-locale vertaling is, als de categorie `show_desc` heeft.

### Kaart 2 — vertalingen

Een raster met één rij per ondersteunde locale (`en_US`, `nl_NL`, `de_DE`, `es_ES`, `fr_FR` op een typische installatie). De site-locale wordt gemarkeerd in de merk-accentkleur. Elke rij heeft een Label-veld en — als de categorie `show_desc` heeft — een Beschrijving-veld.

- **Engels (`en_US`) is een eerste-rangs vertalingsslot**. De canonieke Engelse weergavewaarde leeft in `tt_translations`, niet in de `name`-kolom van de database. De `name`-kolom wordt nu beschouwd als de immuabele interne sleutel.
- **Vertaal vanuit Engels** knop (boven het raster) roept de geconfigureerde vertaalmachine aan en vult lege Label-velden voor met automatische vertalingen. Controleer en bewerk vóór het opslaan.

## Toevoegen-weergave

Zelfde vorm als Bewerken, maar:

- Interne sleutel is bewerkbaar (en verplicht). Kleine letters ASCII, geen spaties. Deze waarde kan later niet gewijzigd worden.
- Sorteervolgorde krijgt een standaardwaarde gebaseerd op de bestaande lijst.
- Alle vertalingsvelden starten leeg.

## Opslaan + Annuleren

Zowel de Toevoegen- als de Bewerkweergave hebben een Annuleren + Opslaan paar onderaan (CLAUDE.md §6 contract). Annuleren keert terug naar de lijstweergave van dezelfde categorie. Een `+ Terug naar lijst` ghost-knop links doet hetzelfde.

## Data-backfill (v4.11.0)

In v4.11.0 backfilt een eenmalige migratie (`0131_lookup_translation_seeds`) `tt_translations`-rijen voor elke bestaande `tt_lookups`-rij over de vijf ondersteunde locales:

- **en_US** — geseed vanuit de `name` (en `description` waar aanwezig) van de rij.
- **nl_NL / de_DE / es_ES / fr_FR** — geseed vanuit de meegeleverde `.po`-vertalingen als de locale een `msgstr` heeft voor die msgid; anders leeg gelaten zodat het beheerformulier het ontbrekende slot toont.

De migratie is idempotent; opnieuw uitvoeren heeft geen effect.

### Leeftijdscategorie-labels (v4.26.6)

Leeftijdscategorieën gebruiken de internationale **U**-notatie als canonieke interne sleutel (`U7…U23`, `Senior`). Op een Nederlandse site worden ze getoond met de **O**-conventie (Onder) — `O7…O23`, `Senioren` — opgelost via `tt_translations`, net als elk ander lookup-label. Migratie `0163_seed_age_group_dutch_labels` vult deze Nederlandse labels aan op bestaande installaties (INSERT IGNORE, dus eigen bewerkingen van een club via het bewerkscherm worden nooit overschreven); nieuwe installaties seeden ze uit `LookupTranslationSeeds`. Frans, Duits en Spaans houden de U-notatie aan, dus die krijgen geen eigen rij. Elk dashboardscherm rendert het leeftijdscategorie-label via `LookupTranslator`, nooit de ruwe `name`.

## Vergrendelde rijen

Sommige rijen zijn gemarkeerd met `is_locked = true` omdat workflow-regels ze op naam lezen. Vergrendelde rijen:

- Blijven aanklikbaar voor de Bewerkweergave.
- Tonen het hangslot-icoon naast het label.
- Verbergen de verwijderknop (de rij kan niet worden verwijderd zonder de workflow-regel te breken).

Het veld Interne sleutel op een vergrendelde rij is ook uitgeschakeld — dezelfde Q4-bescherming die geldt voor alle bestaande rijen.

## REST-oppervlak

Elke actie in deze weergave gaat via `/wp-json/talenttrack/v1/lookups/{type}` (POST / PUT / DELETE) met de bestaande `tt_edit_settings` capability-poort. De weergave wordt server-side gerenderd; de JS-module stelt de netwerk-payload samen en herlaadt bij succes. Geen nieuwe REST-endpoints; het `/translations/preview` endpoint retourneert elke andere geïnstalleerde locale in één bulk-respons.

## Canoniek-taal contract (v4.12.0)

De vooruitgaande regel: **`tt_lookups.name` is de stabiele Engelse interne sleutel**. Het is nooit een vertaalde, voor de gebruiker zichtbare tekst. Voor de gebruiker zichtbare labels staan in `tt_translations` en worden weergegeven via `LookupTranslator::name()` (die alleen op `name` terugvalt wanneer er geen vertaling is geregistreerd).

Praktische gevolgen:

- Nieuwe rijen toegevoegd via het admin-grid: het Interne sleutel-veld is verplicht; typ een ASCII-tekst in kleine letters zonder spaties (bijv. `match`, `training`, `in_progress`).
- Bestaande rijen: het Interne sleutel-veld is alleen-lezen. Om het te wijzigen is een code-migratie nodig, zodat elke `WHERE name = ...` verwijzing in de codebase atomair wordt bijgewerkt.
- Dashboards lezen `tt_lookups.name` nooit rechtstreeks. Ze gaan via `LookupTranslator::name($row)`, die oplost via `tt_translations` voor de huidige locale, dan via het gettext-domein, en pas als laatste redmiddel de ruwe `name` retourneert.

## Drift-revisietool (v4.12.0)

Pilot-installaties van vóór v4.11.0 kunnen waarden in `tt_lookups.name` bevatten in gemengde talen (sommige in het Nederlands, sommige in het Engels, sommige in kleine letters) omdat eerdere admin-workflows toestonden dat operators alles in die kolom typten. v4.12.0 levert een eenmalige revisietool om de kolom te normaliseren zonder het dashboard te breken.

**Migratie 0132** loopt elke `tt_lookups`-rij door, vergelijkt `name` met de canonieke seed-map in `LookupCanonicalSeeds` en schrijft een `lookup.needs_review`-entry naar `tt_audit_log` voor elke afgedreven rij (met de huidige waarde, de voorgestelde canonieke waarde en de gedetecteerde brontaal). De migratie hernoemt nooit automatisch — elke geaccepteerde herschrijving is door de operator aangestuurd.

**De tool bereiken:** Configuratie -> tegel "Lookup canonical-language review" (verschijnt alleen wanneer er openstaande rijen zijn). De tegel-beschrijving toont het aantal.

**Per-rij acties:**

- **Accepteren** herschrijft `tt_lookups.name` naar de gekozen canonieke waarde (standaard: het migratie-voorstel; je kunt overschrijven met een andere waarde uit de canonieke lijst) EN behoudt de eerdere afgedreven waarde als een `tt_translations`-entry in de geselecteerde locale. Resultaat: de kolom is nu canoniek Engels; dashboards blijven het Nederlandse / Engelse / etc. label tonen waar je team aan gewend is.
- **Overslaan** laat de rij ongemoeid en legt de bewuste beslissing vast, zodat de wachtrij de rij niet meer toont. Gebruik Overslaan wanneer de afgedreven waarde aanvaardbaar is als canonieke waarde (bijv. een domeinterm die je academie heeft bedacht en die geen Engels equivalent heeft).

Beide acties zijn append-only schrijfacties op `tt_audit_log`; de oorspronkelijke `lookup.needs_review`-entry blijft bewaard voor traceerbaarheid.

**Waar te kijken voor follow-up:** de Geschiedenis-regel onderaan de tool toont totalen van toegepaste + overgeslagen resoluties. De tegel `Auditlog` in Configuratie filtert op `entity_type = lookup` voor volledige forensische details.
