<!-- audience: user -->

# Spond-agenda-integratie

Draait je club de trainingsplanning + wedstrijden al in **Spond**? Dan haalt TalentTrack elk event automatisch binnen. Geen sessies meer in beide systemen kloppen.

De integratie is **read-only**: Spond blijft de bron voor de planning en RSVP's; TalentTrack blijft de bron voor evaluaties, doelen, aanwezigheid en al het andere.

## Hoe het werkt (vanaf v3.69.0)

Spond heeft nooit een iCal-feed gepubliceerd — de URL-flow die oudere TalentTrack-versies aannamen, bestaat dus niet. Vanaf v3.69.0 gebruikt de integratie dezelfde interne JSON-API die de officiële Spond-app gebruikt. In de praktijk: je logt één keer in met een echt Spond-account en TalentTrack gebruikt dat account om events op te halen voor de groepen waar dat account lid van is.

## Instellen

Je hebt **één** Spond-account nodig dat lid is van elke ploeg die je wilt synchroniseren. De meeste clubs hebben hier al een toegewijd trainer-/manager-account voor. Tweestapsverificatie wordt **niet** ondersteund in v1 — schakel die op dit account uit, of gebruik een apart account zonder 2FA.

1. Ga naar **Configuratie → Spond** in wp-admin.
2. Vul het Spond-e-mailadres + wachtwoord in en klik **Credentials opslaan**. Het wachtwoord wordt versleuteld bewaard; bij rotatie van WordPress' `AUTH_KEY`-salt wordt het ongeldig en moet je het opnieuw invoeren.
3. Klik **Verbinding testen** om te bevestigen dat Spond de login accepteert. Een groene melding betekent klaar.
4. Open per TalentTrack-ploeg het team-formulier (of gebruik de nieuwe-team-wizard) en kies de bijbehorende **Spond-groep** uit de dropdown. De dropdown wordt live gevuld met de groepen waar je account lid van is.

Klaar. Binnen een uur verschijnt elk Spond-event voor elke gekoppelde groep als TalentTrack-activiteit. In het overzicht herken je ze aan de **Spond**-bron-pil.

## Wat wordt gesynchroniseerd

- **Datum / tijd / locatie / titel** — Spond wint. Past een coach één van die velden aan op een Spond-geïmporteerde activiteit, dan overschrijft de volgende sync het. De Spond-pil is de waarschuwing.
- **Activiteitstype** — TalentTrack's keyword-classificator kiest training, wedstrijd, toernooi of vergadering op basis van de titel. Past een coach het type later aan, dan blijft die wijziging bewaard.
- **Aanwezigheid, evaluaties, gekoppelde doelen** — alleen TalentTrack. Nooit overschreven.

Verdwijnt een event uit Spond (verwijderd, geannuleerd), dan wordt de bijbehorende TalentTrack-activiteit **soft-gearchiveerd** — nooit verwijderd — zodat eventuele evaluaties bewaard blijven. Komt het Spond-event later weer terug, dan wordt de activiteit gedearchiveerd.

Het sync-window is **30 dagen terug + 180 dagen vooruit** rollend, dus historische events buiten dat venster worden niet bij elke tick opnieuw geïmporteerd.

## Sync-schema

- **Automatische uurlijkse sync** via WP-Cron.
- **Nu vernieuwen**-knop op het team-formulier én op het Spond-overzicht voor een directe sync.
- **WP-CLI**: `wp tt spond sync` (alle teams) of `wp tt spond sync --team=<id>`.

Laatste-sync-status verschijnt in de tabel op **Configuratie → Spond** — groen bij ok, rood met de reden bij een mislukking.

## Privacy + beveiliging

- **E-mail + wachtwoord** staan in de TalentTrack-configuratietabel, scoped op je club, met het wachtwoord versleuteld via dezelfde envelope die VAPID-push-keys ook gebruikt (`CredentialEncryption`).
- **Spond's login-token** wordt ~12 uur gecached. Bij verloop (of intrekking door Spond) logt de volgende sync transparant opnieuw in.
- **Credentials komen nooit voor in een phone-home-payload** — het v1-payload-schema sluit Spond-credentials en groep-ID's expliciet uit.
- **Loskoppelen**: klik op **Loskoppelen** op de Spond-pagina. Bestaande geïmporteerde activiteiten blijven staan; toekomstige sync's pauzeren. Per-team groep-selecties blijven bewaard, zodat heropnieuw verbinden naadloos doorgaat.

## Wat (nog) niet wordt ondersteund

- **Tweezijdige sync** — wijzigingen in TalentTrack lopen niet terug naar Spond.
- **Tweestapsverificatie** op het Spond-account.
- **Per-coach Spond-accounts** — één account per club.
- **Inbound webhooks** — Spond publiceert die niet; de uurlijkse cron is het model.

## Overstappen vanaf de iCal-flow (vóór v3.69.0)

Heb je eerder iCal-URL's in het team-formulier geplakt? Dan worden die automatisch geleegd door migratie 0052. Verbind opnieuw door op **Configuratie → Spond** je Spond-e-mailadres + wachtwoord in te voeren en per ploeg de groep te kiezen. Bestaande geïmporteerde activiteiten blijven staan en gaan weer updaten zodra een groep is gekoppeld.
