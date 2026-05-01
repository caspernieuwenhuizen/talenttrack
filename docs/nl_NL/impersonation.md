<!-- audience: admin -->

# Impersonatie

> Documentatie voor de gebruiker-impersonatie-functie opgeleverd in v3.72.0 als onderdeel van de #0071-autorisatiematrix-completeness-epic.

Native admin-naar-gebruiker impersonatie laat een Academy Admin (de WordPress-`administrator` of iemand met de `tt_club_admin`-rol) tijdelijk overschakelen naar de sessie van een andere gebruiker, zien wat die gebruiker ziet, en weer terugschakelen. Twee echte problemen die dit oplost:

1. **Testen**: "hoe ziet het ouder-dashboard eruit voor iemand wiens kind in U10 zit?"
2. **Support**: "de gebruiker meldt een bug; laat mij zien wat ze zien."

Vandaag zijn de alternatieven schermdelen of een sok-poppen-account opzetten met de exacte rol- en team-toewijzing van de echte gebruiker — beide traag en foutgevoelig.

## Wie mag impersoneren

De capability `tt_impersonate_users` is standaard toegekend aan:

- de WordPress-`administrator`-rol (altijd — supergebruikers houden emergency access)
- de `tt_club_admin`-rol (Academy Admin in matrix-termen)

**Geen enkele andere persona heeft deze capability.** Specifiek: Head of Development krijgt **geen** impersonatie-rechten — zelfs na de #0071-versmalling van HoD tot een ontwikkelingsgerichte persona, want impersonatie onthult alles over een gebruiker, inclusief content die expliciet voor HoD verborgen is door de matrix (configuratie-data waar ze geen edit-rechten meer op hebben, etc.). Wil een club impersonatie aan een niet-admin-rol delegeren, dan kan dat via de matrix (de cap is matrix-gebrugd), maar standaard is het alleen-admin.

## Hoe het werkt

Twee fasen met expliciete terugkeer:

1. **Start.** Vanaf de People-admin-pagina (of een ander oppervlak met een gebruikerslijst) klik op "Switch to this user" naast de doelrij. Bevestig in de modal. De pagina herlaadt als de doelgebruiker — het dashboard rendert exact zoals zij het zien.
2. **Actief.** Een fel-gele niet-sluitbare banner zit boven elke pagina: *"Impersoneert Anna de Vries. Elke actie wordt gelogd."* — met een "Terugschakelen"-knop.
3. **Einde.** Klik "Terugschakelen" in de banner. De sessie wordt hersteld naar de oorspronkelijke admin. (Of sluit de browser — een dagelijkse cleanup-cron sluit weeskinderen na 24 uur.)

Onder de motorkap: een gesigneerde `tt_impersonator_id`-cookie draagt de werkelijke admin-ID; `wp_set_auth_cookie` wisselt de WordPress-sessie naar de identiteit van de doelgebruiker. Beide overgangen schrijven naar `tt_impersonation_log`.

## Wat wordt gelogd

Elke impersonatie-sessie schrijft een rij naar `tt_impersonation_log`:

- **actor_user_id** — de admin
- **target_user_id** — wie ze impersoneerden
- **club_id** — handhaaft de tenant-grens
- **started_at** / **ended_at** — UTC-timestamps
- **end_reason** — `manual` (klikte Terugschakelen) / `expired` (de dagelijkse cron sloot een wees) / `forced` / `session_ended`
- **actor_ip** / **actor_user_agent** — voor forensisch onderzoek
- **reason** — optionele door admin opgegeven notitie ("ticket #1247"); standaard leeg

De log staat los van `tt_authorization_changelog` omdat die verschillende domeinen registreren (matrix-config-bewerkingen vs. authenticatie-events) en het samenvoegen queries onduidelijk zou maken. Zowel Academy Admin als Head of Development kunnen de log lezen; alleen Academy Admin kan rijen verwijderen.

## Verdediging in lagen

De service weigert met een distincte error-code:

| Error-code | Reden |
|------------|-------|
| `forbidden` | Actor heeft geen `tt_impersonate_users`. |
| `target_not_found` | Doelgebruiker bestaat niet. |
| `admin_target_forbidden` | Doel heeft ook `tt_impersonate_users` — admin-op-admin is verboden. |
| `self_impersonation` | Actor en doel zijn dezelfde gebruiker. |
| `already_impersonating` | De actor zit al in een impersonatie-sessie. Stapelen is verboden. |

In multi-tenant-installs (post-v1) vereist cross-club-impersonatie een expliciete `tt_super_admin`-cap die standaard niet wordt toegekend.

## Wat je tijdens een sessie wel en niet kunt

**Je kunt** alles wat de doelgebruiker kan — hun dashboard lezen, hun spelerkaarten bekijken, links klikken, door de site navigeren.

**Je kunt geen** destructieve admin-acties triggeren tijdens een impersonatie-sessie. Specifiek geblokkeerd: matrix Apply, role grants, role revokes, backup-restores, demo-data-resets, alle `tt_delete_*`-admin-handlers en bulk-imports. De reden: een admin die het ouder-perspectief debugt mag niet per ongeluk vanuit die sessie destructieve acties triggeren. Schakel terug om destructieve acties uit te voeren.

E-mail- en push-notificaties die door de doelgebruiker's acties getriggerd zouden zijn worden ook onderdrukt — je wilt geen echte notificatie laten afgaan vanwege een admin's debugging.

## Aanbevelingen

- **Geef altijd een reden-notitie** (bv. ticketnummer) bij start, zodat het auditlog doorzoekbaar is.
- **Schakel zo snel mogelijk terug** — laat sessies niet open. De 24-uur-cron sluit uiteindelijk weeskinderen, maar auditability is beter wanneer je expliciet Terugschakelt.
- **Impersoneer niet zonder duidelijke reden.** Elke sessie wordt gelogd met je IP en user-agent; dit is een permanent auditspoor.

## Het auditlog raadplegen

Het log is opvraagbaar via de REST-API op `GET /wp-json/talenttrack/v1/impersonation/log` (cap-gated op de `impersonation_log`-matrix-entiteit — Academy Admin RCD, Head of Development R). Een wp-admin-oppervlak voor het log is gepland maar niet in v1; tot dat verschijnt, query het REST-endpoint rechtstreeks met de juiste filters.

## Buiten scope

- Een wp-admin-auditoppervlak voorbij REST (gepland voor follow-up).
- Cross-club-impersonatie in multi-tenant-installs (gegate op een `tt_super_admin`-cap die standaard niet wordt toegekend).
- Automatische her-authenticatie voor 2FA-installs — `wp_set_auth_cookie` slaat de 2FA-uitdaging vandaag over; een `define( 'TT_IMPERSONATION_REQUIRES_2FA_REVERIFICATION', true )`-constante is gereserveerd voor clubs die het nodig hebben.
