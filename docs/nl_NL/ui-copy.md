<!-- audience: dev, admin -->

# Interfaceteksten — knoppen en labels

Woorden zijn onderdeel van de interface, geen versiering eroverheen. Deze pagina beschrijft de afspraak die elk knoplabel in TalentTrack volgt. Ze bestaat omdat die afspraak er niet was: 392 verschillende knoplabels waren afgedreven naar vier aanmaakwerkwoorden, drie schrijfwijzen en 54 spellingen van *Opslaan* voordat #2614 ze weer bij elkaar bracht.

Lees dit voordat je een knop toevoegt, er een hernoemt, of een PR beoordeelt die dat doet.

## 1. Altijd zinshoofdletters

Alleen het eerste woord en eigennamen krijgen een hoofdletter.

```
Doel toevoegen          niet  Doel Toevoegen
Volgorde opslaan        niet  Volgorde Opslaan
Aangepaste CSS openen   niet  Aangepaste CSS Openen
```

Eigennamen en afkortingen houden hun hoofdletters: `Strava koppelen`, `Exporteren naar Excel`, `JSON downloaden`.

**Zet de schrijfwijze nooit in CSS.** `text-transform: uppercase` op een knopklasse betekent dat het label dat een vertaler schrijft niet het label is dat een gebruiker leest, en zo'n regel raakt de ene tag wel en de andere niet — dat was #2615, waar `<a class="tt-btn">` als `ANNULEREN` verscheen naast een `<button>` met `Opslaan` in dezelfde rij. De schrijfwijze hoort in het label.

## 2. Werkwoord + object, en laat het object weg als de context het al geeft

Een knop in een sectie met de kop *Beoordelingsschaal* hoeft niet `Beoordelingsschaal opslaan` te heten. Er staat **Opslaan**.

```
Opslaan               in een sectie die al "Beoordelingsschaal" heet
Gesprek opslaan       op een scherm waar ook "Oordeel opslaan" staat
```

Benoem het object alleen wanneer twee acties met hetzelfde werkwoord op één scherm staan zonder dat er iets tussen zit. Staan er twee kale `Opslaan`-knoppen zonder koppen, dan is de oplossing koppen toevoegen — niet de labels verlengen.

Dezelfde toets geldt voor `Openen`, `Afdrukken`, `Exporteren` en `Downloaden`.

## 3. Twee woorden, ongeveer 18 tekens

Langer heeft een reden nodig, opgeschreven op de plek zelf:

```php
// long label: de twee effecten zijn echt gescheiden en het tweede is niet vanzelfsprekend.
__( 'Record decision and generate letter', 'talenttrack' )
```

Een label van meer dan 18 tekens breekt af op een actierij van 360px breed, en daar staan de meeste.

## 4. Eén werkwoord per actie

De vaste set:

| Werkwoord | Waarvoor |
| --- | --- |
| `Toevoegen` | Elk record aanmaken — een doel, een seizoen, een test, een toewijzing |
| `Opslaan` | Een bewerking vastleggen |
| `Annuleren` | Een formulier verlaten zonder op te slaan |
| `Bewerken` | Een record openen om te wijzigen |
| `Archiveren` | Zacht verwijderen, de veilige standaard |
| `Verwijderen` | Alleen onomkeerbaar verwijderen |
| `Openen` | Naar een ander scherm gaan |
| `Afdrukken` | Het afdrukvenster openen |
| `Exporteren` / `Downloaden` | Een bestand maken |

**Vervallen:** `Nieuw`, `+ Nieuw`, `+ Toevoegen`, `Verwijder` (als synoniem van archiveren), `Bewaren`. `Aanmaken` is voorbehouden aan account- en aanmeldknoppen.

## 5. Geen tekens vooraf, geen leestekens achteraan

```
Speler toevoegen    niet  + Speler toevoegen
Filters opslaan     niet  Huidige filters opslaan…
Afdrukken           niet  Afdrukken / Opslaan als pdf
```

De `+` verdubbelt iets wat de component al doet: `FrontendViewBase::pageActionsHtml()` tekent zijn eigen icoon, en op een telefoon klapt het label in tot dat icoon — dus een letterlijke `+` in de tekst is juist daar onzichtbaar waar hij zou moeten helpen.

Een ` / ` in een label betekent bijna altijd twee namen voor één actie; kies er één.

## 6. Schrijf het label in de taal van wie erop drukt

Benoem dingen zoals de gebruiker ze kent, niet zoals het systeem is gebouwd. Een trainer legt een **blessure** vast, geen `player_injuries`-rij; hij stelt een **rol** in, geen `functional_role_id`.

En het label moet vertaling overleven. Bouw er nooit een op uit losse stukken — geef een hele zin aan `__()` met `sprintf`-plaatshouders, zodat een vertaler de volledige tekst ziet en de volgorde kan aanpassen.

## 7. Bronteksten zijn Engels

De msgid is Engels, ook als de academie Nederlands is. Een Nederlandse brontekst is naar geen enkele andere taal te vertalen — het "origineel" is al gelokaliseerd — en verschijnt als Nederlands op een Engelse installatie. Betrap je jezelf op `__( 'Opslaan' )`, dan is de msgid `'Save'` en hoort het Nederlands in `languages/talenttrack-nl_NL.po`.

## Over de CI-controle

#2619 overwoog een diff-only lint voor deze regels, in de vorm van de inline-style-poort (#1389). Die is **niet gebouwd**, en de reden is het vastleggen waard: betrouwbaar bepalen wélke `__()`-aanroepen knoplabels zijn vraagt óf een regex over de gerenderde markup — het auditscript van #2614 deed dat en hield ongeveer 10% ruis van met `sprintf` opgebouwde markup — óf elk label door een helper `ButtonLabel::of()` leiden, en dat is een refactor over 122 bestanden.

Een poort die in 10% van de gevallen loos alarm slaat, leert mensen hem te negeren. Deze pagina plus de review is voorlopig de handhaving. Komt de wildgroei terug, dan is de helper-aanpak de te bouwen route, want die maakt de poort exact in plaats van bij benadering.

## Zie ook

- [i18n-architectuur](i18n-architecture.md) — waar labels leven en hoe ze de front-end bereiken.
- [Mobiele patronen](mobile-patterns.md) — de aanraakdoelen en de actierij van 360px waartegen deze grenzen zijn gezet.
