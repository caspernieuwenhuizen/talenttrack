<!-- audience: admin -->

# Configuratie — Algemeen

**Dashboard → Configuratie → Algemeen** (`?config_sub=general`)

Academiebrede basisinstellingen die bepalen hoe datums en de kalender in heel TalentTrack worden weergegeven. De instellingen staan per club in `tt_config`, zodat een toekomstige multi-tenant-installatie de keuze van elke academie apart houdt. Opslaan gaat via `POST /wp-json/talenttrack/v1/config`, net als de andere inline-configuratieformulieren; de pagina is alleen voor beheerders / clubbeheerders (`tt_edit_settings`).

## Instellingen

| Instelling | Wat het doet |
| --- | --- |
| **Datumnotatie** | Hoe datums worden geschreven — Systeemstandaard (de WordPress-datumnotatie-optie), `31-12-2026`, `31/12/2026`, `31.12.2026`, `12/31/2026`, ISO `2026-12-31` of lang `31 December 2026`. Het formulier toont een live voorbeeld van de datum van vandaag terwijl je kiest. |
| **Eerste dag van de week** | Maandag (standaard) of Zondag — de dag waarop het weekraster van de **teamplanner** begint. |
| **Tijdzone** | Academiebrede standaardtijdzone (de standaard WordPress-tijdzonelijst). |
| **Locale** | Standaardtaal voor datum- en getalnotatie. Alleen geïnstalleerde talen worden getoond. |

## Hoe de datumnotatie wordt toegepast

Datumnotatie loopt via één helper, `TT\Shared\Dates\TTDate`, zodat de keuze van de academie op één plek wordt nageleefd in plaats van bij elke aanroep opnieuw te worden bepaald. De preset **Systeemstandaard** reproduceert exact de WordPress-datumnotatie, zodat een installatie die de instelling nooit aanraakt ongewijzigd blijft.

De datumnotatie wordt in de hele frontend toegepast overal waar een **volledige datum** wordt getoond — spelersprofielen, evaluaties, activiteiten, doelen, PDP-ondertekeningen, rapporten, scoutingbezoeken en de audit-stempels "aangemaakt / bijgewerkt". **Compacte kalenderlabels** (de `ma 31` / `31 dec`-dagcellen van de teamplanner en de afgekorte `31 dec '26`-kerngegevensdatums) houden bewust hun compacte notatie — de preset bepaalt volledige datums, niet ruimtebeperkte labels. De **teamplanner** respecteert ook de eerste dag van de week.

## Zie ook

- [Configuratie en branding](configuration-branding.md)
- [Modules](modules.md)
