<!-- audience: developer -->

# Mobiele patronen

Een kleine bibliotheek met CSS-componenten en JavaScript-helpers voor
oppervlakken die als `native` zijn geclassificeerd via
`MobileSurfaceRegistry::register($slug, MobileSurfaceRegistry::CLASS_NATIVE)`.
Bouwt voort op de mobile-first basis uit
#0056 (48 px tikbare oppervlakken, `inputmode`-attributen,
`:focus-visible`, `touch-action`, safe-area-insets) en de
mobile-first auteursregel uit `CLAUDE.md` §2.

De bibliotheek dekt de vier patronen die vandaag een echte
consument hebben. Nieuwe patronen worden toegevoegd wanneer een
oppervlak ze nodig heeft, niet speculatief.

- [Bottom-sheet modal](#bottom-sheet-modal)
- [Vaste CTA-balk](#vaste-cta-balk)
- [Segmented control](#segmented-control)
- [Lijstitem ter vervanging van tabelrijen](#lijstitem-ter-vervanging-van-tabelrijen)

## Laden

De bibliotheek bestaat uit twee bestanden:

- `assets/css/mobile-patterns.css`
- `assets/js/mobile-helpers.js`

Beide worden voorwaardelijk ingeladen door `DashboardShortcode::render()`,
alleen op routes met `mobile_class` = `native`. Oppervlakken met
`viewable` of `desktop_only` laden ze nooit. Geen buildstap nodig.

## Conventies bovenop #0056

- **Geen `<table>`-elementen op `native`-oppervlakken onder 480 px.**
  Gebruik `tt-mobile-list-item` in plaats daarvan.
- **Geen ad-hoc `position: fixed`-elementen.** De vaste onderbalk is
  de `tt-mobile-cta-bar`-component; bottom-sheets zijn
  `tt-mobile-bottom-sheet`. Andere fixed-positionering is verboden
  omdat ze slecht samenwerkt met de inschuivende URL-balk in iOS
  Safari.

## Bottom-sheet modal

Schuift omhoog vanaf de onderkant van de viewport, sleep om te sluiten,
maximaal 80% schermhoogte. Gebruik dit voor filters, bevestigingen,
secundaire acties, de spelerkiezer in de nieuwe-evaluatie-wizard.

```html
<div class="tt-mobile-bottom-sheet-backdrop"></div>
<div class="tt-mobile-bottom-sheet" role="dialog" aria-modal="true">
    <div class="tt-mobile-bottom-sheet-handle" aria-hidden="true"></div>
    <div class="tt-mobile-bottom-sheet-header">Kies een speler</div>
    <div class="tt-mobile-bottom-sheet-body">
        <!-- inhoud -->
    </div>
</div>
```

```js
var sheet = document.querySelector('.tt-mobile-bottom-sheet');
TT.Mobile.openBottomSheet(sheet);
// later:
TT.Mobile.closeBottomSheet(sheet);
```

De sleep-om-te-sluiten luistert op het `.tt-mobile-bottom-sheet-handle`
-element. Het sheet volgt de vinger. Loslaten met > 80 px translatie
(of flick-snelheid > 0,5 px/ms) sluit het sheet; anders schuift het
terug open. Klikken op de backdrop sluit ook. Respecteert
`prefers-reduced-motion`.

## Vaste CTA-balk

Sticky onderbalk met de primaire submit-knop. Blijft zichtbaar terwijl
de gebruiker door lange formulieren scrolt. Vervangt inline
submit-knoppen die anders van het scherm zouden lopen. De directe
consument is de Submit-knop op `RateActorsStep` in de nieuwe-evaluatie
-wizard.

```html
<form>
    <!-- … lange formuliervelden … -->
    <div class="tt-mobile-cta-bar">
        <button type="submit" class="tt-btn tt-btn-primary">Evaluaties opslaan</button>
    </div>
</form>
```

De component respecteert `env(safe-area-inset-bottom)` zodat de
iOS home-indicator vrijgehouden wordt. De minimumhoogte van de
knop is de 48 px-vloer uit v3.50.0; de fontgrootte is 16 px om
iOS auto-zoom op focus te voorkomen.

## Segmented control

Vervangt dropdowns bij 2–4 opties. iOS / Android-achtige segment
picker. Onderhuids verborgen radio-inputs zodat het meegestuurd wordt
in standaard form-payloads.

```html
<div class="tt-mobile-segmented-control" role="radiogroup" aria-label="Status">
    <input type="radio" id="seg-all" name="status" value="all" checked />
    <label for="seg-all">Alles</label>
    <input type="radio" id="seg-active" name="status" value="active" />
    <label for="seg-active">Actief</label>
    <input type="radio" id="seg-archived" name="status" value="archived" />
    <label for="seg-archived">Gearchiveerd</label>
</div>
```

Voor 5+ opties: gebruik een `<select>` — segmented controls worden
onleesbaar boven de vier segmenten.

## Lijstitem ter vervanging van tabelrijen

Kaartstijl, twee regels, met chevron-rechts om-naar-detail
-affordance. Gebruik het op elk `native`-oppervlak dat anders
`<table>`-rijen op telefoons zou tonen.

```html
<ul class="tt-mobile-list">
    <li>
        <a class="tt-mobile-list-item" href="/players?id=42">
            <div class="tt-mobile-list-item-leading">JD</div>
            <div class="tt-mobile-list-item-content">
                <div class="tt-mobile-list-item-primary">John Doe</div>
                <div class="tt-mobile-list-item-secondary">U16 · Spits</div>
            </div>
            <div class="tt-mobile-list-item-trailing">›</div>
        </a>
    </li>
    <!-- … -->
</ul>
```

De `*-leading`-slot is voor een avatar / icoon / initialen-cirkel; de
`*-trailing`-slot is voor de chevron / statusindicator. Beide
optioneel.

## Zie ook

- `docs/architecture-mobile-first.md` — de onderliggende conventies
  uit #0056.
- `CLAUDE.md` §2 — de altijd-actieve mobile-first auteursregel.
