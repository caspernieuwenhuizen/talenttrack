<!-- audience: admin, developer -->

# De methodologie-bibliotheek beheren (frontend)

Academiebeheerders kunnen methodologie-inhoud rechtstreeks vanuit de frontend beheren — zonder wp-admin. Deze pagina is de tegenhanger van de alleen-lezen [Methodologie](methodology.md)-bibliotheek.

## Hoe je het vindt

Open de tegel **Methodologie** in de groep **Naslag**. Heb je het bewerkrecht, dan zie je in de kop de knop **Methodologie beheren**; die opent de beheeromgeving. Je kunt er ook direct naartoe via `?tt_view=methodology&mode=manage`.

Alleen gebruikers met het recht `tt_edit_methodology` (academiebeheerder, hoofd opleiding, beheerders) komen bij de beheeromgeving. Iedereen anders krijgt een melding dat de toegang ontbreekt.

De omgeving biedt altijd een link **Gepubliceerde methodologie bekijken** terug naar de leesweergave, plus het gebruikelijke kruimelspoor.

## Tabbladen per onderdeel

De beheeromgeving heeft een tabblad per methodologie-onderdeel, net als de leesweergave. Elk tabblad is een op zichzelf staande beheerpagina — een lijst met records, een knop "+ Nieuw …", bewerk- en verwijderacties per rij, en een plat aanmaak-/bewerkformulier.

**Spelprincipes**, **Visie** en **Raamwerk** zijn beschikbaar. Formaties, spelhervattingen en de overige onderdelen volgen in latere releases; elk verschijnt als eigen tabblad zodra het wordt opgeleverd.

Twee van deze tabbladen — **Visie** en **Raamwerk** — zijn *één-record*-formulieren in plaats van lijsten: elke club heeft precies één visie en één raamwerk-introductie, dus het tabblad opent meteen op het bewerkformulier (geen lijst, geen "+ Nieuw", geen verwijderen).
**Spelprincipes** en **Formaties** zijn nu beschikbaar. Spelhervattingen, visies, de raamwerk-introductie en de overige onderdelen volgen in latere releases; elk verschijnt als eigen tabblad zodra het wordt opgeleverd.
**Spelprincipes** en **Spelhervattingen** zijn nu beschikbaar. Formaties, visies, de raamwerk-introductie en de overige onderdelen volgen in latere releases; elk verschijnt als eigen tabblad zodra het wordt opgeleverd.
**Spelprincipes** en **Voetbalhandelingen** zijn beschikbaar. Formaties, spelhervattingen, visies, de raamwerk-introductie en de overige onderdelen volgen in latere releases; elk verschijnt als eigen tabblad zodra het wordt opgeleverd.

## Een principe bewerken

Een principe bevat:

- **Code** — de korte verwijzing zoals `AO-01`.
- **Teamfunctie** en **teamtaak** — de raamwerkcategorieën waartoe het principe hoort.
- **Titel**, **Toelichting** en **Team-richtlijn** — elk met naast elkaar een **Nederlands (NL)**- en **Engels (EN)**-invoer.
- **Richtlijn per linie** — een Nederlandse en Engelse notitie voor aanvallers, middenvelders, verdedigers en de keeper.

Vul eerst het Nederlands in; Engels is optioneel en valt terug op het Nederlands wanneer de taal van een lezer Engels is maar er geen Engelse tekst is opgegeven. Opslaan en Annuleren staan samen onderaan het formulier — Annuleren brengt je terug naar de lijst (of naar waar je vandaan kwam).

Een principe verwijderen is definitief en vraagt eerst om bevestiging.

## De clubvisie bewerken

Het tabblad **Visie** bewerkt de ene visierecord van je club. Die bevat:

- **Formatie** en **Speelstijl** — gekozen uit de formatielijst van de methodologie en de vaste stijlwoordenschat.
- **Speelwijze** en **Notities** — elk met naast elkaar **Nederlandse (NL)**- en **Engelse (EN)**-tekst.
- **Belangrijke eigenschappen** — een Nederlandse en Engelse lijst, één eigenschap per regel.

De eerste keer opslaan maakt de visie van je club aan; latere keren werken die bij. De meegeleverde voorbeeldvisie is alleen-lezen en wordt hier nooit aangeraakt. Wat je opslaat verschijnt op het tabblad **Visie** van de leesweergave.

## De raamwerk-introductie bewerken

Het tabblad **Raamwerk** bewerkt de ene raamwerk-introductie van je club — de inleidende tekst die de methodologie en elk van de thema's kadert:

- **Titel** en **Slogan** — één regel, NL/EN.
- **Inleiding**, plus een **toelichting** per thema: **Voetbalmodel**, **Voetbalhandelingen**, **Vier fasen**, **Leerdoelen** en **Factoren van invloed**.
- **Reflectie** en **De toekomst** — afsluitende secties.

Elke sectie heeft naast elkaar Nederlandse en Engelse tekst. De eerste keer opslaan maakt de introductie aan; latere keren werken die bij. De introductie is de ouder van de fasen, leerdoelen en factoren van invloed die op hun eigen tabbladen worden beheerd. Wat je opslaat verschijnt op het tabblad **Raamwerk** van de leesweergave.
## Een formatie bewerken

Het tabblad **Formaties** toont je formaties. Elke formatie bevat:

- **Slug** — de korte verwijzing zoals `1-4-3-3`.
- **Naam** en **Beschrijving** — elk met naast elkaar een **Nederlands (NL)**- en **Engels (EN)**-invoer.
- **Diagramgegevens (JSON)** — optioneel. Genormaliseerde 0–100-coördinaten voor het opstellingsdiagram (`{"positions":{"1":{"x":50,"y":92,"label":"K"}}}`). Laat het leeg voor de standaardopstelling.

Opslaan en Annuleren staan samen onderaan — Annuleren brengt je terug naar de formatielijst (of naar waar je vandaan kwam). Een formatie verwijderen wist deze en al haar positiekaarten definitief, na een bevestiging.

## Formatieposities bewerken

Elke formatie heeft maximaal elf **positiekaarten** — één per rugnummer. Gebruik in de formatielijst de actie **Posities** om de posities van een formatie te openen, en daarna **+ Nieuwe positie** om er een toe te voegen. Een positie bevat:

- **Rugnummer** — 1–11.
- **Korte naam** en **Lange naam** — naast elkaar Nederlandse en Engelse invoer (bijv. "Vleugelverdediger" / "Wing-back").
- **Aanvallende taken** en **Verdedigende taken** — Nederlandse en Engelse tekstvakken, **één taak per regel**. Lege regels vervallen.

Posities horen bij hun formatie; een formatie verwijderen verwijdert ook haar posities.

## Meegeleverd vs. clubeigen

Meegeleverde principes, formaties en posities van TalentTrack zijn hier **alleen-lezen** — ze tonen een label "Meegeleverd" en hebben geen bewerk- of verwijderactie, zodat je de naslaginhoud niet per ongeluk kunt beschadigen. Clubeigen records zijn volledig te bewerken en te verwijderen.
## Een spelhervatting bewerken

Een spelhervatting bevat:

- **Slug** — de korte verwijzing zoals `corner-attacking-far-post`.
- **Soort** — corner, vrije trap (direct), vrije trap (voorzet), penalty of inworp.
- **Kant** — aanvallend, verdedigend of omschakelen.
- **Titel** — met naast elkaar een **Nederlands (NL)**- en **Engels (EN)**-invoer.
- **Punten** — een Nederlandse en een Engelse lijst met coachpunten, één punt per regel in elk tekstvak.
- **Diagram-overlay (JSON)** — optionele ruwe JSON die de markerposities op het veldschema beschrijft. Laat leeg als je die niet hebt; ongeldige JSON wordt bij het opslaan genegeerd.

Vul eerst het Nederlands in; Engels is optioneel en valt terug op het Nederlands wanneer de taal van een lezer Engels is maar er geen Engelse tekst is opgegeven. Opslaan en Annuleren staan samen onderaan het formulier — Annuleren brengt je terug naar de lijst (of naar waar je vandaan kwam). Een spelhervatting verwijderen is definitief en vraagt eerst om bevestiging. Opgeslagen spelhervattingen zijn zichtbaar in het tabblad **Spelhervattingen** van de leesweergave.
## Een voetbalhandeling bewerken

Een voetbalhandeling bevat:

- **Slug** — de korte machineverwijzing zoals `aannemen`.
- **Categorie** — een van *Met balcontact*, *Zonder balcontact* of *Ondersteunend*.
- **Naam** en **Omschrijving** — elk met naast elkaar een **Nederlands (NL)**- en **Engels (EN)**-invoer.

Vul eerst het Nederlands in; Engels is optioneel en valt terug op het Nederlands wanneer de taal van een lezer Engels is maar er geen Engelse tekst is opgegeven. Opslaan en Annuleren staan samen onderaan het formulier — Annuleren brengt je terug naar de lijst (of naar waar je vandaan kwam).

Een voetbalhandeling verwijderen is definitief en vraagt eerst om bevestiging. Een handeling waaraan een doel nog is gekoppeld (via de gekoppelde handeling) kun je **niet** verwijderen — je krijgt een melding met het aantal doelen dat ernaar verwijst. Ontkoppel die doelen eerst en verwijder daarna.

## Meegeleverd vs. clubeigen

Meegeleverde inhoud van TalentTrack (principes, spelhervattingen en de overige onderdelen) is hier **alleen-lezen** — die toont een label "Meegeleverd" en heeft geen bewerk- of verwijderactie, zodat je de naslaginhoud niet per ongeluk kunt beschadigen. Clubeigen records zijn volledig te bewerken en te verwijderen.

## REST-API

Alles wat de beheeromgeving doet, is ook via REST beschikbaar, zodat een toekomstige niet-WordPress-frontend hetzelfde gedrag krijgt:

| Methode | Route | Doel |
| --- | --- | --- |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles` | Principes tonen (per club). |
| `POST` | `/wp-json/talenttrack/v1/methodology/principles` | Een clubeigen principe aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Eén principe, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Een clubeigen principe bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Een clubeigen principe verwijderen. |
| `GET` | `/wp-json/talenttrack/v1/methodology/vision` | De actieve clubvisie. |
| `GET` | `/wp-json/talenttrack/v1/methodology/vision/{id}` | Eén visie, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/vision/{id}` | De clubvisie bewerken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/framework-primer` | De actieve raamwerk-introductie van de club. |
| `GET` | `/wp-json/talenttrack/v1/methodology/framework-primer/{id}` | Eén introductie, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/framework-primer/{id}` | De raamwerk-introductie bewerken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces` | Spelhervattingen tonen (per club; filter op `kind`, `side`, `source`). |
| `POST` | `/wp-json/talenttrack/v1/methodology/set-pieces` | Een clubeigen spelhervatting aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Eén spelhervatting, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Een clubeigen spelhervatting bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Een clubeigen spelhervatting verwijderen. |

Formaties (en hun geneste positiekaarten) bieden dezelfde CRUD:

| Methode | Route | Doel |
| --- | --- | --- |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations` | Formaties tonen (per club). |
| `POST` | `/wp-json/talenttrack/v1/methodology/formations` | Een clubeigen formatie aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | Eén formatie, met haar posities. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | Een clubeigen formatie bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | Een clubeigen formatie (en haar posities) verwijderen. |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions` | De posities van een formatie tonen. |
| `POST` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions` | Een positie op de formatie aanmaken. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions/{pid}` | Een positie bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions/{pid}` | Een positie verwijderen. |

Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige tekstvelden (`title`, `explanation`, `team_guidance`, `name`, `description`, `short_name`, `long_name`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`; lijstvelden (`attacking_tasks`, `defending_tasks`) gebruiken `{ "nl": ["…"], "en": ["…"] }`. Een meegeleverd record bewerken of verwijderen geeft `409`.
Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige tekstvelden (principe `title`, `explanation`, `team_guidance`, `line_guidance`; spelhervatting `title`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`. Het veld `bullets` van een spelhervatting neemt `{ "nl": ["…"], "en": ["…"] }`, en `diagram_overlay` is een vrij JSON-object.
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions` | Voetbalhandelingen tonen (per club). |
| `POST` | `/wp-json/talenttrack/v1/methodology/football-actions` | Een clubeigen voetbalhandeling aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Eén voetbalhandeling, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Een clubeigen voetbalhandeling bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Een clubeigen voetbalhandeling verwijderen (geweigerd met `409` zolang een doel eraan gekoppeld is). |

Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige velden voor principes (`title`, `explanation`, `team_guidance`, `line_guidance`) en voor voetbalhandelingen (`name`, `description`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`.

De **visie** en de **raamwerk-introductie** zijn één record per club, dus ze bieden alleen lezen + bijwerken — geen `POST` aanmaken, geen `DELETE`. Hun meertalige velden (visie: `way_of_playing`, `notes`, `important_traits`; introductie: `title`, `tagline`, `intro`, de `*_intro` per thema, `reflection`, `future`) accepteren en retourneren dezelfde `{ "nl": …, "en": … }`-vorm; `important_traits` is per taal een lijst met strings.

## Voor ontwikkelaars — een onderdeeltabblad toevoegen

De beheeromgeving is gebouwd rond een uitbreidbaar tabblad-register, `TT\Modules\Methodology\Frontend\Manage\MethodologyManageRegistry`. Een verwant onderdeel registreert zijn tabblad **zonder een gedeeld switch-statement te wijzigen** — meestal vanuit de `boot()` van de module:

```php
MethodologyManageRegistry::register( [
    'key'    => 'formations',                     // de mtab-slug
    'label'  => __( 'Formaties', 'talenttrack' ), // tabbladlabel
    'render' => [ FormationsManageTab::class, 'render' ], // callable( array $ctx ): void
    'handle' => [ FormationsManageTab::class, 'handle' ], // optionele POST-verwerker
    'order'  => 30,                               // sorteerpositie (lager = eerder)
] );
```

- `render( array $ctx )` krijgt `[ 'action' => 'list'|'new'|'edit', 'id' => int, 'flash' => string ]` en toont de tabbladinhoud (lijst ⇄ formulier).
- `handle( array $post )` (optioneel) draait bij een formulier-POST nadat de gedeelde nonce is gecontroleerd en retourneert `[ 'flash' => string, 'back_to_list' => bool ]`. Laat het weg voor tabbladen die puur via REST schrijven.
- Gebruik `MethodologyManageView::tabUrl( $mtab, $args )` en `MethodologyManageView::cancelUrl( $mtab )` voor links binnen het tabblad en het Opslaan/Annuleren-doel.

De REST-kant heeft een bijpassende basis: breid `TT\Modules\Methodology\Rest\AbstractMethodologyRestController` uit, stel `restBase()` in (bijv. `methodology/formations`) en implementeer de vijf CRUD-callbacks. De basis regelt de permission-callback `tt_edit_methodology`, de clubscope en het standaard JSON-envelope. `PrinciplesManageTab` en `PrinciplesRestController` zijn de referentie-implementaties om te kopiëren.
