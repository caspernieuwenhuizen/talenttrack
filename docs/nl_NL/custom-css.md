<!-- audience: admin -->

# Aangepaste CSS

> Documentatie voor de Custom-CSS-module (#0064) die in v3.64.0 is opgeleverd. Hiermee laat een clubbeheerder TalentTrack er precies uitzien zoals hij wil, ongeacht het actieve WordPress-thema. Tegenhanger van de [Branding](configuration-branding.md)-pagina (#0023), die juist *deferring* naar het thema doet. De twee staan haaks op elkaar — op één surface kan slechts één van beide actief zijn.

## Wat het doet

Een clubbeheerder kan kleuren, lettertypes, hoeken, spacing en schaduwen aanpassen op het frontend dashboard, op de wp-admin TT-pagina's, of beide. Drie auteurspaden plus een vier-tab landingspagina, allemaal bereikbaar via `?tt_view=custom-css` (of via **Configuratie → Aangepaste CSS**):

- **Visuele instellingen** (Pad C) — kies kleuren, lettertypes, gewichten, hoekstraal, spacing-schaal en schaduwsterkte met dropdowns en pickers. Opslaan genereert een CSS-blok dat in dezelfde opslag belandt als de andere paden. Sinds v3.75.1 wordt elke wijziging direct getoond op de editorpagina zelf (live preview); opslaan persisteert de waarden zodat de rest van het dashboard ze bij de volgende paginalading oppikt.
- **CSS-editor** (Pad B) — schrijf zelf CSS. Het tekstveld gebruikt de WordPress-code-editor (CodeMirror) met syntaxiskleuren en regelnummers. Een knop "Open preview in nieuw tabblad" opent het dashboard zodat je het resultaat live ziet.
- **Upload + templates** (Pad A) — upload een `.css`-bestand of pas één van drie starttemplates toe (Fresh light / Classic football / Minimal — alle drie lichtgekleurd).
- **Geschiedenis** — de laatste 10 auto-saves plus benoemde presets. Klik **Terugzetten** om een eerdere save te herstellen (de revert wordt zelf weer een nieuwe rij in de geschiedenis, dus je kan altijd terug).

Een surface-schakelaar bovenaan wisselt tussen **Frontend dashboard** en **wp-admin pagina's**. Elke surface heeft zijn eigen aan/uit-knop en zijn eigen CSS.

## Hoe het veilig blijft

- **Scoped class isolation** — elke TalentTrack-pagina krijgt een `tt-root` body class. Eigen CSS-regels moeten met `.tt-root` voorafgaan zodat het thema er niet doorheen kan komen. De starttemplates en de visuele editor doen dat al; bij Pad B en Pad A vertrouwen we erop dat je de conventie volgt.
- **Block-list-sanitatie bij opslaan** — de saver weigert JavaScript-URLs (`url(javascript:…)`), `expression()`, `behavior:`, `-moz-binding`, externe `@import` en externe `@font-face`-URLs. Geweigerde payloads geven een inline foutmelding met een verwijzing naar de overtredende regel.
- **200 KB harde cap** — groter dan 200 KB en de save wordt geweigerd. Dat is ongeveer 10× het hele gebundelde `frontend-admin.css`-bestand, dus alleen een backstop tegen het per ongeluk plakken van een hele site-stylesheet.
- **Mobile-first-garantie** — de mobile-first stylesheet van de plugin laadt altijd eerst; eigen CSS er bovenop. Pad C heeft bewust geen layout-overschrijvingen (geen breakpoints, geen flex-direction). Bij Pad A en Pad B krijg je een documentatie-waarschuwing dat layout-eigenschappen overschrijven op eigen risico is.
- **Mutex met #0023 thema-overerving** — bij het aanzetten van Custom CSS op de Frontend-surface gaat de Theme-inheritance-knop automatisch uit. Op één pagina is altijd hooguit één van beide actief; de UI duwt je naar die grens.

## Veilige modus

Voeg `?tt_safe_css=1` aan een URL toe en TalentTrack slaat de eigen CSS over voor die pageview. Dat geeft je een herstelpad als een save de layout sloopt — open het dashboard met de safe-mode-URL, ga naar **Configuratie → Aangepaste CSS → Geschiedenis** en zet terug naar de laatste werkende snapshot.

## Visuele editor — referentie

Pad C koppelt formuliervelden aan `--tt-*` CSS custom properties op `.tt-root`. De volledige tabel:

| Veld | Token | Toelichting |
|------|-------|-------------|
| Primair | `--tt-primary` | Hoofdkleur voor knoppen en bovenste tegel. |
| Secundair | `--tt-secondary` | Accentkleur voor pillen en randen. |
| Accent | `--tt-accent` | Vaak gelijk aan primary in onze templates. |
| Primair — hover | `--tt-primary-hover` | Hover-kleur voor primaire knoppen + nav-links (sinds v3.73.0). |
| Secundair — hover | `--tt-secondary-hover` | Hover-kleur voor secundaire knoppen (sinds v3.73.0). |
| Accent — hover | `--tt-accent-hover` | Hover-kleur voor accent-knoppen + links (sinds v3.73.0). |
| Succes | `--tt-success` | "Aanwezig" + positieve trends. |
| Succes — subtiele achtergrond | `--tt-success-subtle` | Bleek-groene banner-achtergrond voor succes-meldingen (sinds v3.73.0). |
| Info | `--tt-info` | Neutrale chips + info-banners. |
| Info — subtiele achtergrond | `--tt-info-subtle` | Bleek-blauwe banner-achtergrond voor info-meldingen (sinds v3.73.0). |
| Waarschuwing | `--tt-warning` | Amber pil, "review nodig". |
| Waarschuwing — subtiele achtergrond | `--tt-warning-subtle` | Bleek-amber banner-achtergrond (sinds v3.73.0). |
| Gevaar | `--tt-danger` | Destructieve knoppen, "afwezig". |
| Gevaar — subtiele achtergrond | `--tt-danger-subtle` | Bleek-rode banner-achtergrond (sinds v3.73.0). |
| Focus-ring | `--tt-focus-ring` | Keyboard-focus-outline. |
| Achtergrond | `--tt-bg` | Pagina-achtergrond. |
| Kaart-oppervlak | `--tt-surface` | Vulling van tegels / panels / kaarten. |
| Tekst | `--tt-text` | Standaard tekstkleur. |
| Subtekst | `--tt-muted` | Hints, meta-regels. |
| Lijnen + randen | `--tt-line` | Veldranden, tabel-scheidingen. |
| Display-font | `--tt-font-display` | Koppen + naam op de FIFA-kaart. |
| Body-font | `--tt-font-body` | Al het andere. |
| Body-gewicht | `--tt-fw-body` | 300 / 400 / 500 / 600 / `normal` / `bold`. |
| Kop-gewicht | `--tt-fw-heading` | 500–800. |
| Hoekstraal — medium | `--tt-r-md` | Kaarten, invoervelden, knoppen. 0–32 px. |
| Hoekstraal — groot | `--tt-r-lg` | Hero-kaarten, modals. 0–40 px. |
| Spacing-schaal | `--tt-spacing-scale` | Vermenigvuldiger op `--tt-sp-*`. 0.6–1.6. |
| Kaartschaduw — klein | `--tt-shadow-sm` | Standaardschaduw op kaarten / panels / tegels (sinds v3.73.0). |
| Kaartschaduw — middel (hover) | `--tt-shadow-md` | Hover-schaduw op dezelfde oppervlakken (sinds v3.73.0). |
| Modal / lade-schaduw | `--tt-shadow-lg` | Schaduw op zwevende overlays (sinds v3.73.0). |
| Animatiesnelheid | `--tt-motion-duration` | Snel (120ms) / Standaard (180ms) / Langzaam (260ms) (sinds v3.73.0). |
| Animatiecurve | `--tt-motion-easing` | Standaard / Ease-in / Ease-out / Ease-in-out (sinds v3.73.0). |

Wat je niet instelt, valt terug op de standaardwaarden van TT.

Sinds v3.73.0 groepeert de editor de controls in acht inklapbare accordion-secties (Merkkleuren / Statuskleuren / Oppervlakken / Tekst / Typografie / Vorm + spacing / Schaduwen / Beweging) zodat het aantal velden beheersbaar blijft naarmate de catalogus groeit. Het oude enkele `Schaduwsterkte`-veld uit v3.64 is vervangen door drie expliciete schaduw-tokens; bestaande opslagen blijven correct renderen en worden bij het volgende opslaan genormaliseerd naar de nieuwe vorm.

## Start­templates

Drie lichtgekleurde startpunten. Elk vervangt de live CSS voor de actieve surface; gebruik **Geschiedenis → Terugzetten** als je terug wilt.

- **Fresh light** — zachte mint + teal met afgeronde hoeken en een lichte slagschaduw. Lees-prettig in daglicht; geschikt voor academies met een moderne stijl.
- **Classic football** — bosgroen + goud + crème — het traditionele academiewapen-palet. Strakkere hoeken en iets zwaardere kaartrand voor een clubshop-uitstraling.
- **Minimal** — neutraal grijs met één antraciet accent. Geen slagschaduwen, hoeken slechts licht afgerond. Werkt achter elk clubmerk zonder concurreren.

## Rechten

| Rechten-key | Toegewezen aan | Wat het toelaat |
|-------------|----------------|-----------------|
| `tt_admin_styling` | Administrator, Clubbeheerder | Custom CSS-pagina openen; opslaan, uploaden, templates toepassen, presets opslaan en terugzetten uit geschiedenis. |

Coaches, scouts en staf krijgen dit standaard niet. Een club die styling aan een "marketing-rol" wil delegeren kan de rechten-key toekennen aan een eigen rol via de Rechten-pagina.

## Opslag

De "live" payload zit in `tt_config`, met sleutels `custom_css.<surface>.css` / `.enabled` / `.version` / `.visual_settings` (waar `<surface>` `frontend` of `admin` is). De tabel `tt_custom_css_history` houdt de laatste 10 auto-saves plus benoemde presets bij. Beide zijn `club_id`-scoped per de SaaS-readiness-basis (#0052).

## Buiten scope

- Styling van de marketingsite — TalentTrack is alleen de plugin. De marketingsite van de club is de taak van het actieve thema.
- Per-pagina overrides — één CSS-payload per surface, niet één per pagina.
- JavaScript-injectie — alleen CSS. Geen `<script>`-tags, geen JS-uploads.
- Eigen HTML / template-overrides — buiten scope, altijd. De plugin bezit haar eigen templates.
- Per-team of per-coach styling — alleen op clubniveau.
