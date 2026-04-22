# Teams & spelers

## Teams

Een **team** is een selectie binnen een specifieke leeftijdscategorie (bijv. "U13 Blauw", "U15 Rood"). Elk team heeft:

- Een naam en een optioneel label voor de leeftijdscategorie
- Een hoofdcoach (uit je **Personen**-register)
- Toegewezen spelers

Maak teams aan op de beheerpagina **Teams**. De leeftijdscategorie is belangrijk, omdat [categorie gewichten](?page=tt-docs&topic=eval-categories-weights) per leeftijdscategorie worden gedefinieerd.

## Spelers

Een **speler** is een individuele voetballer. Elke speler heeft:

- Voornaam en achternaam
- Positie(s), voorkeursvoet, rugnummer
- Lengte, gewicht, geboortedatum
- Optionele koppeling aan een WordPress-gebruikersaccount (zodat hij/zij kan inloggen)
- Eventuele aangepaste velden die je academie heeft geconfigureerd

Maak spelers aan op de beheerpagina **Spelers**. Gebruik de knop **+ Nieuwe toevoegen**.

## Speler koppelen aan WordPress-gebruiker

Als een speler een `wp_user_id` heeft, wordt die gebruiker na inloggen doorgestuurd naar zijn eigen dashboardweergave op de frontend-shortcode. Zonder deze koppeling bestaat de speler alleen als record dat je kunt evalueren.

## Archiveren versus verwijderen

Gearchiveerde spelers blijven in de database maar verdwijnen uit actieve lijsten (oude evaluaties blijven wel naar ze verwijzen). Permanent verwijderen werkt alleen als er geen evaluaties, doelen of sessies naar de speler verwijzen. Gebruik in de meeste gevallen **archiveren** — zie [Bulkacties](?page=tt-docs&topic=bulk-actions).
