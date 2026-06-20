<!-- audience: admin -->

# Configuratie — Seizoenen

**Dashboard → Configuratie → Seizoenen** (`?tt_view=seasons`)

Beheer de seizoenen van de academie vanaf de frontend — aanmaken, bewerken, het huidige seizoen instellen en ongebruikte seizoenen verwijderen. Voorheen kon dit alleen in wp-admin; de frontend-beheerder neemt die omweg weg. Afgeschermd met `tt_edit_settings` (beheerder / academiebeheerder standaard).

Precies **één** seizoen is tegelijk het huidige. PDP-dossiers zijn gekoppeld aan een seizoen en de overdrachtstaak draait telkens wanneer je het huidige seizoen wijzigt.

## Wat je kunt doen

| Actie | Toelichting |
| --- | --- |
| **Aanmaken** | Naam + startdatum + einddatum. De einddatum moet na de startdatum liggen. Nieuwe seizoenen zijn niet huidig totdat je ze instelt. |
| **Bewerken** | Corrigeer de naam of de datums van een seizoen. De juiste manier om een fout te herstellen — verwijderen en opnieuw toevoegen is niet nodig. |
| **Huidig maken** | Promoveert één seizoen tot huidig en degradeert het vorige in dezelfde stap. Start de overdracht voor open PDP-dossiers. |
| **Verwijderen** | Alleen beschikbaar voor een seizoen dat **niet huidig** is en **geen gekoppelde records** heeft. |

## Waarom verwijderen beperkt is

Seizoenen worden door andere records gebruikt — PDP-dossiers en -blokken, stafontwikkelingsdoelen / -evaluaties en VCT-schema's. Een seizoen dat in gebruik is verwijderen zou die records ontkoppelen, dus dat wordt geblokkeerd:

- Het **huidige** seizoen kan niet worden verwijderd — stel eerst een ander seizoen in als huidig.
- Een seizoen **met gekoppelde records** kan niet worden verwijderd — de rij toont **In gebruik** in plaats van een Verwijderen-knop. Bewerk het in plaats daarvan.

Alleen een echt ongebruikt seizoen (bijvoorbeeld per ongeluk aangemaakt) kan worden verwijderd. Dezelfde beveiliging geldt op de REST-laag (`DELETE /wp-json/talenttrack/v1/seasons/{id}` geeft `409` met een reden), zodat een niet-WordPress-frontend dezelfde bescherming krijgt.

## REST

De beheerder is een dunne client over het seizoen-REST-contract:

- `GET /wp-json/talenttrack/v1/seasons` — lijst + huidige id (elke ingelogde gebruiker).
- `POST /seasons` — aanmaken. `PATCH /seasons/{id}` — bewerken. `PATCH /seasons/{id}/current` — huidig maken. `DELETE /seasons/{id}` — beperkte verwijdering. (Alle schrijfacties vereisen `tt_edit_settings`.)

## Zie ook

- [Configuratie — Algemeen](configuration-general.md)
- [Configuratie en branding](configuration-branding.md)
