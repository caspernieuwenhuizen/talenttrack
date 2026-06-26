<!-- audience: admin -->

# Auditlogboek

**Dashboard → Configuratie → Auditlogboek** (`?tt_view=audit-log`)

Een alleen-lezen, gepagineerde weergave van het auditspoor van de academie — elke instellingswijziging en toegang tot gevoelige gegevens die `AuditService` vastlegt in `tt_audit_log`. Het beantwoordt *wie heeft wat wanneer gewijzigd*, zodat een beheerder een configuratiewijziging of een inzage in een gevoelig record kan herleiden tot de gebruiker, het tijdstip en het IP-adres erachter.

De frontend-weergave is de canonieke ingang (#1918); het oudere wp-admin-tabblad (`?page=tt-config&tab=audit`) blijft beschikbaar als terugvaloptie voor gevorderden, maar Configuratie stuurt je daar niet langer naartoe.

## Toegang

De tegel en de weergave zijn afgeschermd door de capability `tt_view_audit_log` — toegekend aan beheerders / clubbeheerders via de autorisatiematrix, nooit via een rolnaamcontrole. Een gebruiker zonder de capability ziet geen Auditlogboek-tegel in Configuratie, en een directe bezoek aan `?tt_view=audit-log` levert een toestemmingsmelding op. Elke query is club-gebonden (`club_id`), zodat een toekomstige multi-tenant-installatie het spoor van de ene academie nooit aan een andere lekt.

## Wat het toont

Het tabblad **Alle items** toont het spoor nieuwste-eerst, 50 rijen per pagina:

| Kolom | Wat het toont |
| --- | --- |
| **Wanneer** | Het tijdstip waarop het item is vastgelegd. |
| **Gebruiker** | De actor — weergavenaam, of `#id` als de naam niet beschikbaar is, of *(systeem)* voor items zonder gebruiker (cron, migraties). |
| **Actie** | De vastgelegde actiesleutel (bijvoorbeeld `config.update`, `lookup.needs_review`, `login_fail`). |
| **Entiteit** | Het entiteitstype waarop de actie betrekking had, plus de `#id` indien van toepassing. |
| **IP** | Het bron-IP-adres dat op dat moment is vastgelegd. |
| **Payload** | De JSON-details die bij het item zijn vastgelegd (oude/nieuwe waarden, context). |

### Filters

Boven de lijst beperkt een filterformulier het spoor. Alle filters zijn optioneel en combineerbaar:

- **Actie** en **Entiteit** — keuzelijsten opgebouwd uit de werkelijk aanwezige waarden in het spoor.
- **Gebruiker #** — numeriek (`inputmode="numeric"`); het WordPress-gebruikers-ID van de actor.
- **Van** / **Tot** — een datumbereik (`type="date"`).

**Filteren** past de selectie toe; **Wissen** stelt deze opnieuw in. Paginering gebruikt een `apage`-queryparameter zodat deze nooit botst met de gereserveerde `paged` van WordPress.

### Mislukte aanmeldingen

Een tweede tabblad, **Mislukte aanmeldingen**, aggregeert `login_fail`-items over de laatste 7 en 30 dagen: een dagelijkse uitsplitsing, de top-10 geprobeerde gebruikersnamen en de top-10 bron-IP-adressen. Er is geen automatische blokkering — de weergave bestaat om het volume zichtbaar te maken zodat de beheerder kan ingrijpen wanneer een ongebruikelijk patroon opduikt.

## REST

Dezelfde gegevens zijn alleen-lezen beschikbaar via `GET /wp-json/talenttrack/v1/audit-log`, afgeschermd door dezelfde capability `tt_view_audit_log` en club-gebonden in de `WHERE`-clausule. Het accepteert `action`, `entity_type`, `entity_id`, `user_id`, `date_from`, `date_to`, `page` en `per_page`, en geeft gepagineerde rijen terug met de headers `X-WP-Total` / `X-WP-TotalPages`. Een toekomstige SaaS-frontend kan hetzelfde spoor weergeven zonder de query opnieuw op te bouwen. Er is geen schrijf-endpoint — het auditspoor is alleen-toevoegen en wordt uitsluitend door `AuditService` geschreven.

## Zie ook

- [Configuratie — Algemeen](configuration-general.md)
- [Configuratie — Lookups](configuration-lookups.md) (het canonieke-taalcontrolehulpmiddel schrijft `lookup.needs_review`-items hierheen)
- [Toegangsbeheer](access-control.md)
