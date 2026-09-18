---
title: Overnemen van een account
group: configuration
summary: Bekijk de app als een andere gebruiker om te zien wat die persoon ziet.
audience: [admin]
order: 132
---

# Impersonatie

> Documentatie bij het overnemen van een gebruikersaccount.

Native admin-naar-gebruiker impersonatie laat een Academy Admin (de WordPress-`administrator` of iemand met de `tt_club_admin`-rol) tijdelijk overschakelen naar de sessie van een andere gebruiker, zien wat die gebruiker ziet, en weer terugschakelen. Twee echte problemen die dit oplost:

1. **Testen**: "hoe ziet het ouder-dashboard eruit voor iemand wiens kind in U10 zit?"
2. **Support**: "de gebruiker meldt een bug; laat mij zien wat ze zien."

Vandaag zijn de alternatieven schermdelen of een sok-poppen-account opzetten met de exacte rol- en team-toewijzing van de echte gebruiker — beide traag en foutgevoelig.

## Wie mag impersoneren

De capability `tt_impersonate_users` is standaard toegekend aan:

- de WordPress-`administrator`-rol (altijd — supergebruikers houden emergency access)
- de `tt_club_admin`-rol (Academy Admin in matrix-termen)

**Geen enkele andere persona heeft deze capability.** Specifiek: Head of Development krijgt **geen** impersonatie-rechten — zelfs na de versmalling van HoD tot een ontwikkelingsgerichte persona, want impersonatie onthult alles over een gebruiker, inclusief content die expliciet voor HoD verborgen is door de matrix (configuratie-data waar ze geen edit-rechten meer op hebben, etc.). Wil een club impersonatie aan een niet-admin-rol delegeren, dan kan dat via de matrix (de cap is matrix-gebrugd), maar standaard is het alleen-admin.

## Supporttoegang, en wat je daarvan ziet

De supportmedewerkers van TalentTrack zijn niet jouw staf, en zij komen onder
andere voorwaarden bij jouw gegevens.

Een **supportmachtiging** is toestemming voor beperkte tijd, voor één met naam
genoemde medewerker en één met naam genoemd probleem. Zolang een machtiging
loopt, ziet iedereen bij jouw club die ingelogd is een oranje balk — in de app
én in wp-admin — met de naam van de medewerker, de reden, en het lokale tijdstip
waarop de toegang afloopt. **Die balk is niet weg te klikken.** Dat is precies
de bedoeling: een machtiging die je niet ziet is niet te onderscheiden van een
achterdeur, en dit product bevat gegevens van kinderen.

Drie dingen gelden hoe dan ook:

- **Elke machtiging loopt vanzelf af.** Het eindtijdstip wordt op je eigen
  installatie afgedwongen, dus een machtiging sluit op tijd, ook als je site
  ons een week lang niet kan bereiken.
- **Intrekken werkt bij de eerstvolgende controle.** Je installatie vervangt de
  machtigingen die ze heeft door wat de laatste controle meldt, dus een
  ingetrokken machtiging is simpelweg weg.
- **Wat support deed staat op je eigen installatie.** Bij het starten van een
  sessie wordt vastgelegd onder welke machtiging dat gebeurde, zodat "wie had
  toegang, en wat deed die" te beantwoorden is uit je eigen logboek en niet
  alleen uit het onze.

Je eigen beheerders merken hier niets van. Een machtiging stelt alleen een
extra eis aan supportaccounts; ze kan nooit tussen jouw club en je eigen
gegevens in gaan staan.

Supporttoegang vraagt **geen goedkeuring vooraf** van jou. Dat was een bewuste
keuze, en die is alleen verdedigbaar dankzij de drie eigenschappen hierboven —
en vooral doordat je het altijd ziet gebeuren.

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


## Wat je tijdens een sessie wel en niet kunt

**Je kunt** alles wat de doelgebruiker kan — hun dashboard lezen, hun spelerkaarten bekijken, links klikken, door de site navigeren.

**Je kunt geen** destructieve admin-acties triggeren tijdens een impersonatie-sessie. Specifiek geblokkeerd: matrix Apply, role grants, role revokes, backup-restores, demo-data-resets, alle `tt_delete_*`-admin-handlers en bulk-imports. De reden: een admin die het ouder-perspectief debugt mag niet per ongeluk vanuit die sessie destructieve acties triggeren. Schakel terug om destructieve acties uit te voeren.

E-mail- en push-notificaties die door de doelgebruiker's acties getriggerd zouden zijn worden ook onderdrukt — je wilt geen echte notificatie laten afgaan vanwege een admin's debugging.

## Aanbevelingen

- **Geef altijd een reden-notitie** (bv. ticketnummer) bij start, zodat het auditlog doorzoekbaar is.
- **Schakel zo snel mogelijk terug** — laat sessies niet open. De 24-uur-cron sluit uiteindelijk weeskinderen, maar auditability is beter wanneer je expliciet Terugschakelt.
- **Impersoneer niet zonder duidelijke reden.** Elke sessie wordt gelogd met je IP en user-agent; dit is een permanent auditspoor.

## Het auditlog raadplegen

Elke sessie wordt weggeschreven in de tabel `tt_impersonation_log`: wie wie overnam, wanneer het begon en eindigde, het IP-adres en de user-agent, en de opgegeven reden.

Je leest het onder **Auditlog → Impersonatie**. Eén regel per sessie: wanneer die begon, wie hem startte, als wie diegene handelde, wanneer en hoe hij eindigde, de opgegeven reden en het adres waar vandaan. Een sessie die nog niet is beëindigd, staat er als **Nog open** — dan zit er op dit moment iemand in het account van een ander, en dat is iets anders dan een sessie die vorige week is afgesloten.

Het tabblad verschijnt alleen als je het mag lezen. Het is afgeschermd via de matrix-entiteit `impersonation_log` (Academiebeheerder RCD, Hoofd opleiding R), los van de rest van het auditlog — zien wie het dossier van een minderjarige heeft geopend is een engere vraag dan zien wie wat heeft bewerkt.

Dezelfde gegevens staan op `GET /wp-json/talenttrack/v1/impersonation/log`, te filteren op `actor_user_id`, `target_user_id`, `date_from`, `date_to` en `active_only`, voor wie ze liever in de eigen rapportage trekt.

## Beperkingen
- **Cross-club-impersonatie** is niet gebouwd. TalentTrack draait vandaag één academie per installatie, dus er is geen cross-club-situatie om af te schermen.
- **2FA wordt niet opnieuw gevraagd.** Een sessie starten logt je in als de doelgebruiker zonder tweede factor. Een `define( 'TT_IMPERSONATION_REQUIRES_2FA_REVERIFICATION', true )`-constante is gereserveerd voor clubs die die stap nodig hebben, maar is nog niet aangesloten.
