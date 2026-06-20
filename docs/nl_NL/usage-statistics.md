<!-- audience: admin -->

# Gebruiksstatistieken

Via **Analyse → Gebruiksstatistieken** krijg je een beeld van hoe je club de plugin daadwerkelijk gebruikt.

## Wat er wordt bijgehouden

- **Logins** — elke WordPress-login telt, per gebruiker, per dag
- **Frontend-weergaven** — welke dashboardschermen (`?tt_view=…`) mensen openen
- **Bezoeken aan beheerpagina's** — welke TalentTrack-beheerpagina's mensen bezoeken
- **Aangemaakte evaluaties** — geteld via de evaluatietabel, niet apart getrackt

Gebeurtenissen ouder dan **90 dagen worden automatisch verwijderd**. Er worden geen IP-adressen, user agents of referrer-URL's vastgelegd. Gebeurtenistracking is uitsluitend bedoeld voor club-interne inzichten.

## Applicatie-KPI's — gebruik, geen uitkomsten

De pagina **Applicatie-KPI's** beantwoordt *wordt de tool gebruikt, door wie, hoeveel en waarvoor* — betrokkenheidssignalen, geen voetbaluitkomsten. (Aanwezigheid %, doelvoltooiing en beoordelingen zijn *rapportinhoud* over spelerontwikkeling en staan in de **Rapporten**-launcher, niet hier.)

Hoofdtegels:

- **Actieve gebruikers** — unieke gebruikers met activiteit in de periode
- **Logins / gebruiker** — herbetrokkenheid: hoe vaak actieve gebruikers terugkomen
- **Kleefkracht (DAU/MAU)** — gemiddeld dagelijks-actief ÷ 30-dagen-actief; hoe routineus de tool is
- **Gem. sessie** & **Tijd online (gemeten)** — sessieduur en totale tijd, afgeleid uit de tussenpozen van events (bewust een *ondergrens* — één openstaande pagina is niet meetbaar)
- **Acties / gebruiker** — interacties (weergaven + logins + acties) per actieve gebruiker

Panelen:

- **Dagelijks actieve gebruikers** lijngrafiek
- **Actieve gebruikers per rol** — beheerder / coach / speler / overig
- **Meest gebruikte functies** — meest geopende frontend-weergaven + beheerpagina's
- **Inactieve gebruikers** — wie er in de periode niet heeft ingelogd (wie je een seintje geeft)

## Drill-downs

Elke tegel, elk grafiekpunt en elke rij is klikbaar. Klik op "Logins (7 dagen)" om elk login-event te zien. Klik op een punt in de DAU-grafiek om te zien welke gebruikers die specifieke dag actief waren. Klik op een rolbalk voor alleen die gebruikers. Elke detailweergave heeft een broodkruimel met een Terug-knop naar het dashboard.

**Een specifieke dag kiezen:** naast elke grafiek (DAU en Evaluaties per dag) staat een knop **"Kies een dag…"**. Die opent de detailweergave met een datumkeuze die je kunt invullen of met ← / → knoppen dag voor dag kunt verschuiven — handig als de dag die je zoekt lastig te raken is op een grafiek met 90 staafjes.

## Rechten

Alleen voor beheerders (`tt_manage_settings`). Niet zichtbaar voor coaches.
