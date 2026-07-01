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

## Meegeleverd vs. clubeigen

Meegeleverde principes van TalentTrack zijn hier **alleen-lezen** — ze tonen een label "Meegeleverd" en hebben geen bewerk- of verwijderactie, zodat je de naslaginhoud niet per ongeluk kunt beschadigen. Clubeigen principes zijn volledig te bewerken en te verwijderen.

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

Elke route vereist het recht `tt_edit_methodology` en is beperkt tot de huidige club. Meertalige velden (`title`, `explanation`, `team_guidance`, `line_guidance`) accepteren en retourneren een vorm `{ "nl": "…", "en": "…" }`.

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
