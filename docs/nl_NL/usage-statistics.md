# Gebruiksstatistieken

Via **Analyse → Gebruiksstatistieken** krijg je een beeld van hoe je club de plugin daadwerkelijk gebruikt.

## Wat er wordt bijgehouden

- **Logins** — elke WordPress-login telt, per gebruiker, per dag
- **Bezoeken aan beheerpagina's** — welke TalentTrack-beheerpagina's mensen bezoeken
- **Aangemaakte evaluaties** — geteld via de evaluatietabel, niet apart getrackt

Gebeurtenissen ouder dan **90 dagen worden automatisch verwijderd**. Er worden geen IP-adressen, user agents of referrer-URL's vastgelegd. Gebeurtenistracking is uitsluitend bedoeld voor club-interne inzichten.

## KPI-surfaces

Het dashboard toont:

- **Logins** — tellingen over 7 / 30 / 90 dagen
- **Actieve gebruikers** — unieke gebruikers met activiteit in 7 / 30 / 90 dagen
- **DAU-lijngrafiek** — laatste 90 dagen
- **Evaluaties per dag** — laatste 90 dagen
- **Actieve gebruikers per rol** — uitsplitsing beheerder / coach / speler / overig
- **Meest bezochte beheerpagina's** — welke TalentTrack-pagina's het vaakst worden bezocht
- **Inactieve gebruikers** — gebruikers die al 30+ dagen niet zijn ingelogd

## Drill-downs

Elke tegel, elk grafiekpunt en elke rij is klikbaar. Klik op "Logins (7 dagen)" om elk login-event te zien. Klik op een punt in de DAU-grafiek om te zien welke gebruikers die specifieke dag actief waren. Klik op een rolbalk voor alleen die gebruikers. Elke detailweergave heeft een broodkruimel met een Terug-knop naar het dashboard.

**Een specifieke dag kiezen:** naast elke grafiek (DAU en Evaluaties per dag) staat een knop **"Kies een dag…"**. Die opent de detailweergave met een datumkeuze die je kunt invullen of met ← / → knoppen dag voor dag kunt verschuiven — handig als de dag die je zoekt lastig te raken is op een grafiek met 90 staafjes.

## Rechten

Alleen voor beheerders (`tt_manage_settings`). Niet zichtbaar voor coaches.
