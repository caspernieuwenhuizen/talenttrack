<!-- audience: admin -->

# VCT-configuratie

Het Configuratie-overzicht heeft twee nieuwe tegels voor de Variable
Coaching Templates (VCT) module — **VCT macro-blokken** en **VCT
leeftijdsprofielen**. Beide tegels zitten achter de rechten-cap
`tt_vct_admin_library`, dus alleen het Hoofd Opleidingen (en
beheerders) ziet ze. Klik op een tegel om de bestaande VCT-configuratie
te openen (`?tt_view=vct-config`) op het bijbehorende sub-tabblad.

## Tegels in één oogopslag

| Tegel | Opent | Telregel | Waarom hier |
| --- | --- | --- | --- |
| **VCT macro-blokken** | `?tt_view=vct-config&tab=blocks` | "%d actief" — telt de operator-bewerkbare referentietemplates (`VctMacroBlocksRepository::listReferenceTemplates()`) | Bloktemplates die de VCT sessie-wizard gebruikt (warming-up, hoofddeel, cooldown, themablokken). Aanpassingen veranderen waarmee trainers in Stap 3 van de wizard-nieuwe-VCT-sessie starten. |
| **VCT leeftijdsprofielen** | `?tt_view=vct-config&tab=age-profiles` | "%d leeftijdsbanden" — telt de geseede per-leeftijd-enveloppen (`VctAgeProfilesRepository::listAll()`) | Per leeftijdsband (JO8 → JO19) het belastingsplafond, max intensiteit per MD-dag, max VCT sessieduur. Bepaalt de belastingscheck van de wizard én de `WorkloadCapRule` overal waar een VCT sessie wordt samengesteld of opgeslagen. |

De tegels hebben een groene **NIEUW**-pil + accentkleurige rand
(`.tt-cfg-tile--vct`), zodat hoofden opleidingen ze na de upgrade direct
zien. De styling volgt het ontwerp uit
`.local-mockups/vct-config-tiles/`.

## Wat valt onder deze slice

- Twee nieuwe tegels die in `FrontendConfigurationView::renderTileGrid()`
  inline gerenderd worden.
- Geen schema- of REST-wijzigingen — beide bestemmings-tabbladen
  shipten al met VCT-12 (#952). Dit issue dicht de
  vindbaarheidskloof.
- Rechten-gate: `tt_vct_admin_library` wordt één keer gecheckt in
  `renderVctTiles()`. Gebruikers zonder de cap zien de rest van het
  Configuratie-overzicht onveranderd.

## Wat niet in deze slice valt (open follow-ups)

- Telling van gearchiveerde templates op de macro-blokken-tegel
  (bijvoorbeeld "5 actief · 2 archief"). De mockup liet de archief-UX
  open; voor nu toont de tegel alleen het aantal actieve templates.
- Een waarschuwingsstatus op de leeftijdsprofielen-tegel als een band
  geen belastingsplafond heeft. Open vraag uit
  `.local-mockups/vct-config-tiles/notes.md`.

Komt er pilotfeedback op één van beide, dien dan een follow-up issue in
met verwijzing naar dit document.
