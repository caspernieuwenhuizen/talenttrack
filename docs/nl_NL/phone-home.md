<!-- audience: admin -->

# Phone-home telemetrie

> Documentatie van de #0065 Admin Center phone-home-client opgeleverd in v3.65.0. TalentTrack-installs sturen dagelijks plus bij drie trigger-events een korte payload naar de mothership-site die de operator (Casper) draait. De ontvanger is de aparte `talenttrack-admin-center`-plugin (eigen repo, eigen release-cadans). Deze pagina legt uit wat je install verstuurt, waarom, en welke privacy-grens de code afdwingt.

## Wat het doet

Eén keer per 24 uur, plus direct na activeren, deactiveren en elke versiebump van de plugin, stuurt je TalentTrack-install een kleine JSON-payload over HTTPS naar `https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`. De payload bevat operationele cijfers — aantallen en vormen — nooit individuele spelersgegevens.

Foutscenario's verlopen stil: een netwerkfout of 5xx van de mothership wordt op de volgende tick opnieuw geprobeerd. Een aanhoudende 4xx (die zou betekenen dat het schema is gaan afwijken) logt één keer per 24 uur, zodat de operator het opmerkt.

## Waarom

Zonder zicht op versies, foutpatronen en gebruikssignalen over de hele vloot kan de operator (Casper) een betalende klant niet zinvol ondersteunen. Phone-home is het kanaal dat vertelt dat je install bestaat en functioneert. Het bestaat in dienst van *jouw* support-relatie.

Dit is conventie voor B2B-software in dit prijssegment. Klanten verwachten van Salesforce, Notion, Linear of Stripe geen "telemetrie uit"-knop. TalentTrack wijkt daar niet van af. **Waar TalentTrack wél bewust van afwijkt is transparantie** — het payload-schema is vastgezet, deze pagina documenteert veld voor veld wat er verstuurd wordt, en een geautomatiseerde controle in CI laat de build falen als er ooit een verboden veld bijkomt.

Er is geen `wp-config`-opt-out, geen knop in de UI en geen omgevingsvariabele. Operationele telemetrie hoort bij het gebruik van TalentTrack.

## Wire-protocol

JSON over HTTPS, ondertekend met HMAC-SHA256.

- Endpoint: `POST https://www.mediamaniacs.nl/wp-json/ttac/v1/ingest`
- Header: `X-TTAC-Signature: sha256=<hex>`
- Geheim-afleiding (v1): `hash('sha256', install_id . '|' . site_url)` — beide waarden staan in de payload zelf, dus de ontvanger leidt het geheim opnieuw af uit wat binnenkomt. License-key-afgeleid geheim is uitgesteld naar een latere billing-oversight-sub-spec.
- Body: canonieke JSON (sleutels recursief gesorteerd, geen whitespace, UTF-8, slashes niet ge-escaped) zodat beide kanten op exact dezelfde bytestream uitkomen om te tekenen.
- Triggers: `daily` / `activated` / `deactivated` / `version_changed`.
- Cadans: dagelijkse wp-cron-tick plus de drie events hierboven.

## Wat zit er in de payload — elk veld

| Veld | Type | Betekenis |
|------|------|-----------|
| `protocol_version` | string (`"1.0"`) | Schemaversie. Nieuwe velden worden alleen toegevoegd. |
| `install_id` | UUID v4 | Eenmalig gegenereerd bij eerste lezing, opgeslagen in `wp_options:tt_install_id`. Heeft geen betekenis op zichzelf. |
| `trigger` | enum | `daily` / `activated` / `deactivated` / `version_changed`. |
| `sent_at` | ISO 8601 UTC | Wanneer deze payload is samengesteld. |
| `site_url` | URL | De `get_site_url()` van je install. |
| `contact_email` | e-mail | `wp_options:admin_email` — zodat de operator je kan bereiken. |
| `freemius_license_key_hash` | sha256-hex / `null` | SHA-256 van je Freemius-licentiesleutel, of `null` als geen Freemius-licentie aanwezig is. Het HMAC-geheim hangt NIET van dit veld af; het is alleen informatief. |
| `plugin_version` | string | TalentTrack-pluginversie, bv. `"3.65.0"`. |
| `wp_version` | string | WordPress-versie. |
| `php_version` | string | PHP-versie. |
| `db_version` | string | MySQL/MariaDB-versie. |
| `locale` | string | WP-locale, bv. `"nl_NL"`. |
| `timezone` | string | WP-tijdzone, bv. `"Europe/Amsterdam"`. |
| `club_count` | int | Altijd 1 in v1 (multi-tenant SaaS komt later). |
| `team_count` | int | Aantal `tt_teams`-rijen. |
| `player_count_active` | int | Spelers met `archived_at IS NULL`. |
| `player_count_archived` | int | Spelers met `archived_at IS NOT NULL`. |
| `staff_count` | int | Actieve personen in `tt_people`. |
| `dau_7d_avg` | float | Gemiddelde dagelijkse actieve gebruikers over de laatste 7 dagen, uit `tt_usage_events`. |
| `wau_count` | int | Unieke actieve gebruikers in de laatste 7 dagen. |
| `mau_count` | int | Unieke actieve gebruikers in de laatste 30 dagen. |
| `last_login_date` | datum / `null` | Datum van de meest recente `login`-event (alleen datum, geen tijd). |
| `error_counts_24h` | object | `{ "<error.class>": <aantal> }` — fout-klasse-namen uit `tt_audit_log` waarvan de action begint met `error.`. **Alleen namen, nooit message-bodies of stacktraces.** |
| `error_count_total_24h` | int | Som van bovenstaande. |
| `license_tier` | string / `null` | `pro` / `standard` / `free` / `null`. Null als Freemius niet is geconfigureerd. |
| `license_status` | string / `null` | `active` / `expired` / `trial` / `none` / `null`. |
| `license_renews_at` | datum / `null` | Verlengingsdatum indien bekend. |
| `module_status.spond` | object / `null` | `{ configured, last_sync_status, last_sync_at, events_synced_7d }`. Null als Spond niet is geïnstalleerd. |
| `module_status.comms` | object / `null` | `{ sends_7d }`. Null als Comms niet is geïnstalleerd (#0066). |
| `module_status.exports` | object / `null` | `{ runs_7d }`. Null als Export niet is geïnstalleerd (#0063). |
| `feature_flags_enabled` | array | Namen van TalentTrack-eigen feature flags die aanstaan. Beperkt vocabulaire; lekt geen custom-flags. |
| `custom_caps_in_use` | bool | `true` als een rol een custom (niet-TT, niet-WP-default) capability heeft. **Alleen boolean — cap-namen worden niet meegestuurd.** |

## Wat staat er NOOIT in de payload

De privacy-grens is in code vastgelegd. Onderstaande velden **mogen nooit** in de geserialiseerde payload voorkomen, en een CI-controle (`bin/admin-center-self-check.php`) laat de build falen als één van deze ooit alsnog wordt toegevoegd:

- Spelersnamen, leeftijden, foto's, evaluaties, doelen, aanwezigheid of welke per-speler-record dan ook.
- Coach- of stafnamen / e-mailadressen (alleen `contact_email` = `wp_options:admin_email`).
- Clubnaam (alleen `site_url`).
- Spond-credentials, login-tokens, group-IDs.
- Communicatie-inhoud (berichtenbody's, ontvangerslijsten).
- Export-inhoud (bestandsbody's, wat geëxporteerd is).
- Audit-log-rijen.
- Vrij-tekst-velden uit welke TalentTrack-tabel dan ook.
- IP-adressen (transport ziet ze; de payload draagt ze niet mee).
- Stacktraces of foutmeldingsteksten (alleen fout-klasse-namen — TalentTrack's eigen enum).

De mothership kan dit niet afdwingen — alleen de install kan weigeren te versturen. De CI-controle bewaakt de `PayloadBuilder`-source, dus een toekomstige wijziging kan geen van de bovenstaande velden lekken zonder een rode CI-job te activeren.

## Foutscenario's

- **Netwerkfout / DNS-fout / 5xx** — stil. Wordt op de volgende cron-tick opnieuw geprobeerd. Je install merkt er niets van.
- **Aanhoudende 4xx** — gelogd op warning-niveau, maximaal één keer per 24 uur (`admin_center.rejected`). Een 4xx betekent dat het payload-schema is gaan afwijken van wat de mothership verwacht, en daar wil de operator van weten.
- **Geen netwerk** — stil. Phone-home blokkeert geen enkele gebruikersflow.

## Buiten scope (voor nu)

- **Reverse-pull van mothership naar je install** — de mothership kan je install niet om extra data vragen. De dagelijkse roll-up is het enige kanaal.
- **Remote acties** — de mothership kan geen updates pushen, geen flags overschrijven en geen configuratie aanpassen op je install. Read-only in v1.
- **Opt-out** — geen kill-switch, geen constante, geen omgevingsvariabele.

## Zie ook

- [`docs/admin-center.md`](admin-center.md) — Admin Center-plugin overzicht (in een aparte repo).
- De mothership-spec in `talenttrack-admin-center/specs/0001-feat-foundation-monitoring.md` — beschrijft hoe de ontvanger handtekeningen verifieert en het dashboard rendert.
