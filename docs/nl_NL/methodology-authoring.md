<!-- audience: admin, developer -->

# De methodologie-bibliotheek beheren (frontend)

Academiebeheerders kunnen methodologie-inhoud rechtstreeks vanuit de frontend beheren — zonder wp-admin. Deze pagina is de tegenhanger van de alleen-lezen [Methodologie](methodology.md)-bibliotheek.

## Hoe je het vindt

Open de tegel **Methodologie** in de groep **Naslag**. Heb je het bewerkrecht, dan zie je in de kop de knop **Methodologie beheren**; die opent de beheeromgeving. Je kunt er ook direct naartoe via `?tt_view=methodology&mode=manage`.

Alleen gebruikers met het recht `tt_edit_methodology` (academiebeheerder, hoofd opleiding, beheerders) komen bij de beheeromgeving. Iedereen anders krijgt een melding dat de toegang ontbreekt.

De omgeving biedt altijd een link **Gepubliceerde methodologie bekijken** terug naar de leesweergave, plus het gebruikelijke kruimelspoor.

## Tabbladen per onderdeel

De beheeromgeving heeft een tabblad per methodologie-onderdeel, net als de leesweergave. Elk tabblad is een op zichzelf staande beheerpagina — een lijst met records, een knop "+ Nieuw …", bewerk- en verwijderacties per rij, en een plat aanmaak-/bewerkformulier.

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
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces` | Spelhervattingen tonen (per club; filter op `kind`, `side`, `source`). |
| `POST` | `/wp-json/talenttrack/v1/methodology/set-pieces` | Een clubeigen spelhervatting aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Eén spelhervatting, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Een clubeigen spelhervatting bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Een clubeigen spelhervatting verwijderen. |

Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige tekstvelden (principe `title`, `explanation`, `team_guidance`, `line_guidance`; spelhervatting `title`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`. Het veld `bullets` van een spelhervatting neemt `{ "nl": ["…"], "en": ["…"] }`, en `diagram_overlay` is een vrij JSON-object.
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions` | Voetbalhandelingen tonen (per club). |
| `POST` | `/wp-json/talenttrack/v1/methodology/football-actions` | Een clubeigen voetbalhandeling aanmaken. |
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Eén voetbalhandeling, met Nederlandse + Engelse waarden. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Een clubeigen voetbalhandeling bewerken. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Een clubeigen voetbalhandeling verwijderen (geweigerd met `409` zolang een doel eraan gekoppeld is). |

Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige velden voor principes (`title`, `explanation`, `team_guidance`, `line_guidance`) en voor voetbalhandelingen (`name`, `description`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`.

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
