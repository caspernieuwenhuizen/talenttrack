<!-- audience: user -->

# Spond-agenda-integratie

Als je club de trainings- en wedstrijdkalender al in **Spond** bijhoudt, kan TalentTrack alle events automatisch ophalen. Geen dubbele invoer meer.

De integratie is **alleen-lezen**: Spond blijft de bron van waarheid voor de planning en RSVP's; TalentTrack blijft de bron voor evaluaties, doelen, aanwezigheid en al het andere.

## Instellen (per team)

1. Open in Spond de teaminstellingen en kopieer de **iCal-feed-URL** (Spond → Groepsinstellingen → Agenda → "Abonneren op agenda" → URL kopiëren).
2. Open in TalentTrack de teampagina (beheer of frontend).
3. Plak de URL in het nieuwe veld **Spond iCal-URL** en sla op.

Klaar. Binnen een uur verschijnen alle Spond-events voor dat team als TalentTrack-activiteiten. In het overzicht herken je ze aan de **Spond**-bron-pill zodat coaches weten waar ze vandaan komen.

## Wat gesynchroniseerd wordt

- **Datum / tijd / locatie / titel** — Spond wint. Verandert een coach een van deze velden in TalentTrack op een Spond-activiteit, dan overschrijft de eerstvolgende sync die wijziging weer. De "Spond"-pill is daar de waarschuwing voor.
- **Activiteittype** — de TalentTrack-classifier kiest training / wedstrijd / toernooi / bespreking op basis van de titel. Als een coach het type later aanpast, blijft die aanpassing staan.
- **Aanwezigheid, evaluaties, gekoppelde doelen** — alleen TalentTrack. Wordt nooit overschreven.

Verdwijnt een event uit Spond (verwijderd, afgelast), dan wordt de bijbehorende TalentTrack-activiteit **zacht gearchiveerd** — nooit verwijderd — zodat eventuele evaluaties bewaard blijven. Komt het Spond-event later terug, dan komt de activiteit weer uit de archief.

## Synchronisatieschema

- **Automatisch elk uur** via WP-Cron.
- **Nu vernieuwen**-knop op de teampagina voor een directe sync.
- **WP-CLI**: `wp tt spond sync` (alle teams) of `wp tt spond sync --team=<id>`.

Onder het URL-veld zie je de status van de laatste synchronisatie — groen bij succes, rood met reden bij een fout.

## Privacy

De iCal-URL is een bearer-credential — wie hem heeft, kan de teamkalender lezen. TalentTrack slaat hem **versleuteld** op, ontsleutelt alleen op synchronisatiemoment en logt de URL nooit. Wil je toegang intrekken? Genereer een nieuwe URL in Spond en plak die hier.

## Wat (nog) niet werkt

- **Tweezijdige synchronisatie** — wijzigingen in TalentTrack gaan niet terug naar Spond.
- **RSVP's / aanwezigheid** uit Spond — de iCal-feed bevat ze niet.
- **Spond-chat / berichten** — buiten de scope.
- **Meerdere URL's per team** of **OAuth** — één URL per team is het credential.

Deze beperkingen komen uit Spond's iCal-export; de partner-API-integratie die ze zou opheffen volgt in een toekomstige v2.
