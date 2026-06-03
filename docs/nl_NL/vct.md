<!-- audience: admin -->

# VCT — Voetbal Conditionele Training

VCT is de planner voor **leeftijdsveilige, voetbal-specifieke
conditionele trainingssessies voor JO10–JO14 jeugdteams**. De module
staat naast Activiteiten — VCT plant de training, Activiteiten houdt
aanwezigheid en nasleep bij.

Surface-overzicht:

| Surface | URL | Persona |
| --- | --- | --- |
| Nieuwe-VCT-sessie wizard | `?tt_view=wizard&slug=new-vct-session` | Trainer |
| Gepubliceerde sessie-coachview | `?tt_view=vct-session&id=N` | Trainer (sideline) |
| HoO oefeningenbibliotheek | `?tt_view=vct-library` | Hoofd Opleidingen |
| HoO VCT-configuratie | `?tt_view=vct-config` (sub-tabs: blokken / leeftijdsprofielen / schema's) | HoO |
| Configuratie-tegels | `?tt_view=configuration` → "VCT macro-blokken" / "VCT leeftijdsprofielen" | HoO |
| Team-detail VCT-standaardenpaneel | `?tt_view=teams&id=N` (inline onderaan) | HoO / Trainer |
| Speler-detail PHV-paneel | `?tt_view=players&id=N&tab=profile` | Trainer |

## Rechten

Drie matrix-only caps, geen role-baselines:

- **`tt_vct_plan`** — plannen / bewerken / publiceren van VCT-sessies binnen team-scope.
- **`tt_vct_admin_library`** — oefeningenbibliotheek + leeftijdsprofielen + macro-blokken bewerken.
- **`tt_vct_view_load`** — workload-aggregaten lezen.

Per `config/authorization_seed.php` hebben de vier persona's:

| Persona | tt_vct_plan | tt_vct_admin_library | tt_vct_view_load |
| --- | --- | --- | --- |
| `head_coach` | team | — | team |
| `assistant_coach` | team | — | — |
| `head_of_development` | global | global | global |
| `admin` | global | global | global |

## Wat is gelanceerd

De module is gelanceerd in Fase 1 (architectuur-eerst) en Fase 2 (UI):

**Fase 1 — schema + engine + REST** (gesloten via #905 child-issues):

- Schema-migratie 0122 — 10 nieuwe tabellen.
- Schema-migratie 0123 — `tt_player_phv_flags` voor de Peak Height Velocity vlag.
- Schema-migratie 0140 — breidt PHV-vlaggen uit met `reason_key` + `intensity_ceiling`.
- Seed-migraties 0124 (lookups + vertalingen voor nl_NL/fr/de/es) + 0125 (leeftijdsprofielen + sessie-templates + fase-profielen).
- Rules engine + 8 passes + repositories onder [src/Modules/Vct/](../../src/Modules/Vct/).
- REST endpoints onder `/wp-json/talenttrack/v1/vct/...`.
- Workflow task template `VctWorkloadAggregationTaskTemplate` voor nachtelijke aggregatie.

**Fase 2 — UI**:

| Surface | Child-issue | Slice |
| --- | --- | --- |
| VCT-9: nieuwe-vct-sessie wizard | #1084 | Eerste slice — begintijd-veld met team-standaarden-prefill |
| VCT-10: coach-view + A4-print | #1085 | Eerste slice — sideline PHV-uitsluitingsbanner |
| VCT-11: HoO bibliotheek-editor | #1086 | Inline edit + zoeken + intensiteitsband-rand |
| VCT-12: Configuratie-tegels | #1087 | macro-blokken + leeftijdsprofielen tegels op Configuratie |
| VCT-13: Team-paneel | #1088 | Inline weekdag-chips + begintijd + duur op team-detail |
| VCT-14: PHV-vlag-UI | #1089 | Per-speler Profiel-tabblad-paneel + oranje hero-pill |

## Wat (nog) niet is gelanceerd

- **VCT-8 — Oefeningencatalogus seed**. De 80-oefeningen per-club startercatalogus met diagrammen + coaching points × 5 locales. Contentintensief; gated op pilot-trainer review. Gevolgd via een follow-up issue.
- **Wizard stap 2 MD-context chip-bar visualisatie** — `PreviewStep` toont de auto-geresolveerde context al als header-chip; het kleurband-palet komt in een follow-up.
- **Bottom-sheet oefeningenpicker** op de wizard's blok-bouwer-stap — coach override per slot is een substantiële stap-3 UI-rebuild.
- **A4/A6 print mockup-fidelity polish** op de coach-view — `FrontendVctSessionPrintView` bestaat en print de sessie; mockup-polish komt in een follow-up.
- **Huidige-blok teal-rand highlight** + live timer op de coach-view — visuele polish, follow-up.

## Hoe de surfaces met elkaar praten

Een trainer plant een sessie via de wizard (#1084). De wizard leest:

- Het VCT-standaardenpaneel van het team (#1088) voor prefill van de basisstap.
- De oefeningenbibliotheek (#1086) voor slot-kandidaten (op leeftijd + MD + intensiteit gefilterd).
- De macro-blokken van het HoO (#1087) voor de per-week intensiteitsvermenigvuldiger.
- De leeftijdsprofielen van het HoO (#1087) voor het sessie-minuten-plafond + intensiteitsband-plafond.
- Per-speler PHV-vlaggen (#1089) zodat gevlagde spelers `growth_spurt_load_reduction_pct` toegepast krijgen via `WorkloadCapRule`.

De wizard publiceert een `tt_vct_sessions`-rij. De coach-view (#1085) leest die rij + zijn blokken. De PHV-banner op de coach-view (#1085) leest dezelfde `VctPhvFlagsRepository::activeForRoster()` die de WorkloadCapRule gebruikt, zodat sideline-display + engine synchroon blijven.

De Configuratie-tegels (#1087) linken naar de HoO VCT-configuratie sub-tabs (`?tt_view=vct-config&tab=blocks` / `&tab=age-profiles`), zodat de HoO één-tap-entry heeft vanuit het Configuratie-overzicht.

## Privacy

PHV (Fysiek / Health / Vitality) paneel + pill volgt CLAUDE.md §1 — staf (HoO / trainer / admin) ziet volledige reden + plafond + notities; andere ouders zien niets; AC-die-ook-ouder-is ziet eigen kind via parent-persona alleen. De redenpicker is een enum om lange medische vrije tekst te ontmoedigen.

## Referenties

- Gelanceerde spec: [`specs/shipped/0095-feat-vct-module.md`](../../specs/shipped/0095-feat-vct-module.md)
- Architectuur: [`docs/architecture.md`](../architecture.md)
- Authorization-matrix: [`docs/authorization-matrix.md`](../authorization-matrix.md)
- Configuratie-tegels: [`docs/nl_NL/configuration-vct.md`](configuration-vct.md)
