<!-- audience: developer -->

# Patroonbibliotheek voor mobiel

> Componenten gedeeld door surfaces die als `native` zijn geclassificeerd via `MobileSurfaceRegistry`. Bouwt voort op de mobile-first basis uit #0056 (48px-tikdoelen, `inputmode`, `:focus-visible`, safe-area-insets, `touch-action`). Ben je geen mobile-first surface aan het bouwen? Dan heb je niets uit deze gids nodig — de bestaande responsive CSS handelt `viewable`-surfaces ongewijzigd af.

De bibliotheek is bewust klein: vier componenten. We hebben speculatieve toevoegingen (`tt-mobile-stepper`, `tt-mobile-empty-state`, `tt-mobile-skeleton`) afgewezen toen er nog geen directe afnemer was. Voeg ze toe wanneer een surface ze nodig heeft.

CSS staat in [assets/css/mobile-patterns.css](../../assets/css/mobile-patterns.css). De drag-to-dismiss-helper voor de bottom sheet staat in [assets/js/mobile-helpers.js](../../assets/js/mobile-helpers.js). Beide laden voorwaardelijk — `DashboardShortcode::render()` enqueueert ze alleen als de geresolvede view als `native` classificeert of als de lege-view dashboard-landing rendert.

## Laden

Native-surfaces erven de bibliotheek automatisch — er valt niets te enqueuen vanuit de `render()`-methode van een view. De CSS gebruikt de bestaande TalentTrack-design-tokens (`--tt-bg`, `--tt-ink`, `--tt-line`, `--tt-accent`, `--tt-bg-soft`) met hardcoded fallbacks zodat een surface die de design-token-stylesheet niet enqueueert toch goed rendert.

## Conventies afgedwongen op `native`-surfaces

- **Geen `<table>` onder 480px.** Gebruik in plaats daarvan `tt-mobile-list-item`. Lint vangt `<table>`-markers op in templates die op `native`-routes renderen.
- **Geen ad-hoc `position: fixed`-elementen.** Bottom-CTA's mogen via `tt-mobile-cta-bar`; al het andere moet meescrollen.

## 1. `tt-mobile-bottom-sheet` — slide-up modaal

Filters, bevestigingen, secundaire acties, de uitgestelde wizard-spelerskiezer. Vervangt klassieke gecentreerde modals op mobiel.

```html
<div class="tt-mobile-bottom-sheet" id="filter-sheet" role="dialog" aria-modal="true" aria-labelledby="filter-sheet-title">
    <div class="tt-mobile-bottom-sheet-handle" aria-hidden="true"></div>
    <h2 id="filter-sheet-title" class="tt-mobile-bottom-sheet-title">Filteren</h2>
    <div class="tt-mobile-bottom-sheet-content">
        <!-- velden, lijst, etc. -->
    </div>
</div>
```

```js
const sheet = document.getElementById('filter-sheet');
window.TT.Mobile.open(sheet);
window.TT.Mobile.close(sheet);
```

Slide-up bij `is-open`, max 80% schermhoogte, drag-to-dismiss via de handle, tik op de backdrop sluit, Escape sluit. Auto-bind op elke `.tt-mobile-bottom-sheet` in de DOM bij laden en op elke sheet die later wordt geïnjecteerd. Respecteert `prefers-reduced-motion: reduce`.

## 2. `tt-mobile-cta-bar` — vaste actiebalk onderaan

Een primaire actieknop die zichtbaar moet blijven terwijl de gebruiker een lang formulier of een lange lijst scrollt. De referentie-afnemer is de Submit-knop van `RateActorsStep` in de nieuw-evaluatiewizard (uitgesteld polishpunt uit v3.78.0 — landt in #0084 Child 3).

```html
<form>
    <!-- formuliervelden -->
    <div class="tt-mobile-cta-bar-spacer" aria-hidden="true"></div>
</form>
<div class="tt-mobile-cta-bar">
    <button type="submit" form="…" class="tt-button-primary">Opslaan</button>
</div>
```

De `tt-mobile-cta-bar-spacer` is verplicht aan het einde van het scroll-gebied. Reserveert lege ruimte gelijk aan de hoogte van de balk + safe-area-inset, zodat het laatste formulierveld niet wordt afgedekt.

## 3. `tt-mobile-segmented-control` — 2-4 opties

Vervangt een `<select>` als er weinig opties zijn (2 tot 4) en de labels kort (≤12 tekens). iOS / Android-aanvoelende segmentkiezer.

```html
<div class="tt-mobile-segmented-control" role="tablist" aria-label="Periode">
    <button type="button" role="tab" aria-selected="true">Vandaag</button>
    <button type="button" role="tab" aria-selected="false">Week</button>
    <button type="button" role="tab" aria-selected="false">Seizoen</button>
</div>
```

Selectie wordt gecommuniceerd via `aria-selected="true"`. JS voor selectiewissel wordt niet meegeleverd (verschillende surfaces passen de wijziging anders toe — sommige verversen een lijst, andere wisselen een grafiek).

## 4. `tt-mobile-list-item` — vervanger voor tabelrijen

Vervangt tabelrijen op mobiel. Card-stijl, twee-regelige layout (primair + secundair), met chevron-rechts tap-to-detail.

```html
<ul class="tt-mobile-list" role="list">
    <li class="tt-mobile-list-item">
        <a href="?tt_view=players&id=42">
            <span class="tt-mobile-list-item-primary">Casper Nieuwenhuizen</span>
            <span class="tt-mobile-list-item-secondary">O13 · Laatst gezien 2026-05-04</span>
            <span class="tt-mobile-list-item-chevron" aria-hidden="true">›</span>
        </a>
    </li>
</ul>
```

Elk item is een tikbaar gebied dat de v3.50.0 (#0056) 48px-bodem haalt. De lijst is boven 720px automatisch verborgen — desktop-callers vallen terug op hun bestaande `<table>`-markup, naast de lijst gerenderd in dezelfde template; alleen één is per breakpoint zichtbaar.

## Reduced-motion

Alle animaties respecteren `prefers-reduced-motion: reduce`.

## Performance

- Volledige CSS ~3 KB minified, voorwaardelijk geladen.
- JS-helper ~2 KB minified, voorwaardelijk geladen, geen dependencies.
- De auto-binder gebruikt één document-level `MutationObserver` met begrensde werkverzet per mutatie.

## Gerelateerde docs

- [`docs/architecture-mobile-first.md`](../architecture-mobile-first.md) — de onderliggende mobile-first auteursregels uit #0056.
- [`docs/access-control.md`](access-control.md) — de per-club-instelling `force_mobile_for_user_agents` uit #0084 Child 1.
