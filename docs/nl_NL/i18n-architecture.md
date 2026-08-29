<!-- audience: dev -->

# i18n-architectuur — twee kanalen, één regel

TalentTrack draait vertaling via **twee aparte kanalen**, elk met een andere categorie strings. De splitsing is bewust: het kostenmodel en de tooling per kanaal zijn verschillend, dus mengen produceert het slechtste van beide werelden. Deze pagina legt uit welk kanaal wat bezit, waarom, en hoe je een nieuwe vertaling toevoegt.

## TL;DR

| Kanaal | Bezit | Opslag | Bewerk-pad | Taal toevoegen |
|---|---|---|---|---|
| **`.po` / gettext** | UI-strings — `__('Save')`, knoplabels, validatiefouten, koppen | `languages/talenttrack-<locale>.po` → `.mo` | Loco / Poedit / `xgettext` | Lever `<locale>.po` skeleton (#0010) |
| **`tt_translations` tabel** | Data-row strings — lookup-labels, eval-categorie-namen, rol-labels, functionele-rol-labels | `tt_translations(club_id, entity_type, entity_id, field, locale, value)` | Per-entity Vertalingen-formulier, seed-review Excel | Toevoegen aan `I18nModule::REGISTERED_LOCALES` |

De harde regel: **een string hoort exact bij één kanaal**. Als je een `__()`-aanroep toevoegt voor een waarde die in een database-kolom leeft, stop — dat is data, route die via `TranslationsRepository::translate()`.

## Waarom de splitsing?

UI-strings en data-row-strings hebben fundamenteel verschillende leespatronen en bewerkings-eisen.

### UI-strings blijven in `.po`

Vijf technische redenen die elke "moeten we UI-strings in de DB zetten?"-discussie overleven:

1. **Performance.** gettext mmapt `.mo` één keer per request. `__('Save')` resolven is een hash-lookup tegen geheugen, nul query-kosten. Data-row resolution kost één gecachete SELECT per `(entity_type, entity_id)`. UI-strings renderen duizenden keren per pagina; cache-misses daar zouden ertoe doen.
2. **Meervouden.** `_n('1 player', '%d players', $n)` — gettext begrijpt taalspecifieke meervoudsregels (Pools 4 vormen, Russisch 3, Arabisch 6). Het `tt_translations`-schema niet.
3. **Context-disambiguatie.** `_x('Open', 'verb', 'talenttrack')` vs `_x('Open', 'adjective', 'talenttrack')` — `.po` heeft `msgctxt` voor dit; de data-tabel niet.
4. **Statische analyse.** `xgettext` loopt over `__()`-call sites, bouwt de `.pot`, vangt ontbrekende vertalingen automatisch op. Data-row strings zijn dynamisch en kunnen niet statisch geëxtraheerd worden.
5. **Plugin / hook-integraties.** WPML, Polylang, Loco haken aan op de `gettext`-filter. UI-strings in DB-tabellen omzeilen dat ecosysteem.

### Data-row strings verhuizen naar `tt_translations`

Zes redenen waarom UI-string-tooling de verkeerde fit voor data is:

1. **Operator-geschreven content.** Een coach voegt een eigen lookup-rij "Linker spits" toe — er is geen `.po`-kanaal voor. Vóór #0090 verscheen zo'n rij voor altijd onvertaald.
2. **Per-club rebranding (toekomst).** Volgens spec Decision Q11 follow-up wil een club "Players" mogelijk hernoemen naar "Pupils". Dat is per-tenant data, geen globale string.
3. **Bewerkbaar vanuit de UI.** Operators verwachten de Nederlandse vertaling van "Goalkeeper" inline aan te passen, niet via een `.po`-rondgang + plugin-update.
4. **Bulk-review-pad.** De seed-review Excel (#0089 / Phase 5) is een natuurlijke fit voor 200 lookup-labels in 5 talen tegelijk bewerken. `.po` is een één-taal-per-keer-tool.
5. **Zelfde data, andere routes.** Dezelfde lookup-rij moet in elk kanaal verschijnen dat een SaaS-frontend kan consumeren — REST, mobiele app, publieke widget. Een doorzoekbare tabel past daar beter bij dan `.mo`-bestanden.
6. **Cache-coherent invalidation.** Een vertaal-rij opslaan bumpt een per-`(entity_type, entity_id)` versie-teller; gecachete vertalingen verlopen direct. `.mo` vereist een server-reload — prima bij ship-time, lastig bij runtime.

## Hoe het data-row kanaal werkt

### Schema

```sql
CREATE TABLE tt_translations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    club_id         INT UNSIGNED NOT NULL DEFAULT 1,
    entity_type     VARCHAR(32) NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    field           VARCHAR(32) NOT NULL,
    locale          VARCHAR(10) NOT NULL,
    value           TEXT NOT NULL,
    updated_by      BIGINT UNSIGNED DEFAULT NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_lookup (club_id, entity_type, entity_id, field, locale),
    KEY idx_lookup (club_id, entity_type, entity_id),
    KEY idx_locale (locale)
);
```

`entity_type` is `VARCHAR(32)` (geen ENUM) — een nieuwe vertaalbare entiteit toevoegen vereist nul schema-migratie. De `TranslatableFieldRegistry` handhaaft de allowlist in software.

### Registry

`Modules\I18n\TranslatableFieldRegistry` declareert welke `(entity_type, field)`-paren vertaalbaar zijn. De registry wordt geraadpleegd door:

- `TranslationsRepository::translate()` — weigert niet-geregistreerde velden (defensief tegen typo's bij call sites).
- De seed-review Excel — genereert `<field>_<locale>` kolommen via registry × `REGISTERED_LOCALES`.
- De per-entity admin Vertalingen-formulieren — renderen één rij per geregistreerd veld.

Een nieuwe vertaalbare entiteit registreren vanuit de `boot()` van je module:

```php
TranslatableFieldRegistry::register( 'my_entity', [ 'label', 'description' ] );
```

De vier momenteel geregistreerde entiteiten (#0090 Phases 2-4):

| Entiteit | Velden |
|---|---|
| `lookup` | `name`, `description` |
| `eval_category` | `label` |
| `role` | `label` |
| `functional_role` | `label` |

### Resolver

`TranslationsRepository::translate( $entity_type, $entity_id, $field, $locale, $fallback )` retourneert de vertaling van de rij als die bestaat, anders de canonical-kolom-waarde (`$fallback`).

**Locale fallback-keten:** `$locale → 'en_US' → $fallback`. Geeft nooit een lege string. De canonical-kolom op de bron-tabel is het onverzettelijke vangnet.

**Cache:** 60 seconden `wp_cache` met versie-keys (kopieert het #0078 Phase 5 `CustomWidgetCache`-patroon). Opslaan bumpt de per-rij versieteller; cached entries verlopen direct. O(1) invalidatie.

**Tenancy:** elke lees + schrijf scoped op `CurrentClub::id()`.

### Per-entity helpers

Elke vertaalbare entiteit heeft een ergonomische wrapper die de resolver consumeert:

- `LookupTranslator::name( $row )` / `LookupTranslator::description( $row )`
- `EvalCategoriesRepository::displayLabel( $raw, ?int $entity_id )`
- `RolesPage::roleLabel( $key, ?int $entity_id )` + `FunctionalRolesPage::roleLabel( $key, ?int $entity_id )`
- `LabelTranslator::authRoleLabel( $key, ?int $entity_id )` + `LabelTranslator::functionalRoleLabel( $key, ?int $entity_id )`

Geef het entity_id mee zodra je het hebt. String-only callers blijven werken via de gettext-fallback.

**Uitzondering — `LookupTranslator` heeft geen gettext-stap (#3082).** `name()` en `description()` lossen op via `tt_translations` en retourneren daarna de canonieke `tt_lookups`-kolom. Een runtime-waarde aan `__()` geven vraagt de catalogus om welke msgid dan ook die op die string matcht, in de betekenis waarin die geschreven is: de `foot_option`-rij `Left` werd op Nederlandse installaties *Vertrokken*, omdat de enige `msgid "Left"` bij de vertrekkolom van media-retentie hoorde (#3031). De stille variant van dezelfde fout is hoofdlettergebruik en woordsoort — een bijvoeglijk naamwoord of een kleingeschreven middenzinswoord dat in een labelvak belandt.

De afweging: een niet-geseede lookup rendert zijn canonieke Engels. Zichtbaar onvertaald Engels wordt gemeld; een echt Nederlands woord met de verkeerde betekenis niet. Vaste labels horen in `LookupTranslationSeeds` en komen via een migratie in de database — de enige plek waar een label vanuit de broncode te beoordelen is. Migratie `0247` repareert rijen die de backfill van de ingetrokken fallback (migratie `0086`) al verkeerd had weggeschreven — die overschrijft alleen een waarde die teken voor teken is wat een kale `__()` retourneert én niet de vaste seed is, zodat het eigen label van een academie onaangeroerd blijft.

### De seed-map is gekoppeld aan `tt_lookups.name` — en meldt niet wanneer dat misgaat (#3117)

`LookupTranslationSeeds::map()` heeft als sleutel `lookup_type => canonieke naam => locale => label`, en die canonieke naam moet exact zijn wat er in `tt_lookups.name` staat. Migratie `0248` (net als `0151` daarvoor) loopt over de map en doet per locale een `INSERT IGNORE`, dus **een sleutel die geen rij raakt is een no-op zonder foutmelding.**

Die faalwijze heeft lang doorgelopen. 68 entries raakten niets, en `0151` seedde helemaal niets voor 13 van de 20 types die het claimt te dekken, omdat de vocabulaires eronder hernoemd waren: `journey_event_type` ging naar snake_case-sleutels, `activity_type` van `Match` naar `game`, `competition_type` werd in migratie `0027` in zijn geheel hernoemd naar `game_subtype`, `behaviour_rating_label` en `potential_band` werden door `0153` herschreven, en `eval_category` verliet `tt_lookups` volledig voor `tt_eval_categories` in `0008`.

`LookupSeedMapCoverageTest` bewaakt nu beide richtingen op een vers gemigreerde database:

1. elke map-entry lost op naar een bestaande rij;
2. elke bestaande rij binnen een gecureerd type heeft een entry, tenzij die in `LookupTranslationSeeds::LOCALE_INVARIANT_ROWS` staat (UEFA-graden en dergelijke);
3. een gecureerd type zonder rijen moet in `UNSEEDED_VOCABULARIES` staan met een reden — want "ingetrokken vocabulaire, entries weg" en "gedeclareerde vocabulaire die niemand seedt, de ontbrekende rijen zijn de bug" zien er vanuit de map identiek uit en vragen om een mens.

Regel 2 is geen beleefdheid. `LookupTranslator` en migratie `0247` behandelen de vaste seed allebei als de bekend-goede waarde, en dat moet per type kloppen in plaats van gemiddeld: een half gecureerd type is erger dan een niet-gecureerd type, omdat de ontbrekende helft gedekt lijkt.

Een `lookup_type` die niet in de map staat is bewust ongecureerd (posities, blessuretypes, de VCT-vocabulaires) en wordt door de test genegeerd. Een type opnemen ís de belofte.

### Een taal toevoegen

Eén regel:

```php
// src/Modules/I18n/I18nModule.php
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];
```

Elke consumer (resolver, admin-formulier, seed-review Excel) pakt de nieuwe taal automatisch op. Geen schema-wijziging. Geen migratie. Geen data-backfill.

De daadwerkelijke `.po`-uitrol voor UI-strings is een aparte ship — dat is #0010-territorium. Deze module opent alleen het data-row-kanaal.

## Wanneer je niet zeker weet bij welk kanaal een string hoort

Vraag: *is deze string opgeslagen in een database-kolom waarvan een operator de waarde mogelijk bewerkt?*

- **Ja** → `tt_translations`. Registreer de entity + field, route reads via de helper van de entiteit.
- **Nee** → `.po`. Wrap met `__()`, laat de `.po`-toolchain hem oppikken.

Randgevallen:

- **Status-keys** (`'active'`, `'open'`, `'completed'`) — keys, geen labels. Render-time vertaald via `LabelTranslator`. Opgeslagen als enum-achtige strings; het menselijke label mapt via gettext.
- **Migratie-geseede Engelse waardes** — die zaten historisch in `.po` (gettext-oplosbaar) EN nu in `tt_translations` (Phase 6 backfill via gettext). Reads kiezen `tt_translations` eerst. Waar de gettext-backfill onvolledig was (de eval-categorie-vocabulaire had maar een paar `.po`-entries, dus de meeste labels lekten Engels op nl_NL), seedt een vervolgmigratie de gezaghebbende Nederlandse labels rechtstreeks in `tt_translations`, gekoppeld via de stabiele `category_key` en met overslaan van door de academie hernoemde rijen (migratie `0169`, #1733).
- **Computed strings** — `sprintf( __('Hello, %s'), $name )` — het format-string hoort in `.po`, de variabele is data en blijft raw.

## Zie ook

- `docs/i18n-audit-2026-05.md` — pre-#0090 audit die deze architectuur triggerde.
- `specs/shipped/0090-epic-data-row-i18n.md` — de architectuur-beslissingen in detail (12 Qs vergrendeld, Phase-plan, definition of done).
- #0010 spec — de FR/DE/ES `.po`-uitrol voor UI-strings (apart epic).
- #0025 spec — auto-translate engines (DeepL / OpenAI) die `tt_translations` voor nieuwe locales kunnen bulk-vullen.
