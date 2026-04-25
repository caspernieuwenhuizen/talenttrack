# Licentie en account

TalentTrack draait op drie tiers — **Free**, **Standard** (€399/jr) en **Pro** (€699/jr). De license- en accountmodule die in v3.17.0 is gereleased legt de basis; de daadwerkelijke facturatie start zodra de Freemius-gegevens in een release zitten.

## Wat zit er in v3.17.0

- Een **`Configuratie → Account`**-pagina in wp-admin (of `TalentTrack → Account` direct onder het dashboard) die de huidige tier, trial-/overgangsstatus en gebruik versus de Free-tier-limieten toont.
- De state machine **30-daagse Standard-trial → 14 dagen alleen-lezen → Free**. Eén klik op de Accountpagina start de trial.
- **Free-tier-limieten**: 1 team / 25 spelers / onbeperkt evaluaties. Bij het bereiken van de team- of spelerlimiet verschijnt een upgrade-melding in plaats van opslaan.
- **Drie kerngate's** ingebouwd:
  - Spelersvergelijking
  - Rate cards (volledige analyses)
  - CSV-bulkimport
- **Ontwikkelaarsoverride** voor demo-opnames en lokale tests — zie hieronder.
- De Freemius-SDK-adapter is **standaard slapend** en activeert pas wanneer `TT_FREEMIUS_PRODUCT_ID` en `TT_FREEMIUS_PUBLIC_KEY` zijn gedefinieerd in `wp-config.php`. Tot die tijd draait elke installatie Free (of trial / dev-override indien actief).

## Tiers (voorlopig)

| Functie | Free | Standard | Pro |
| - | - | - | - |
| Basis: spelers / teams / sessies / doelen / basisevaluaties | ✓ | ✓ | ✓ |
| Lokale + e-mail-back-ups | ✓ | ✓ | ✓ |
| Tot 1 team en 25 spelers | ✓ | onbeperkt | onbeperkt |
| Radar charts, spelersvergelijking, rate cards (volledig) | — | ✓ | ✓ |
| CSV-bulkimport | — | ✓ | ✓ |
| Functionele rollen | — | ✓ | ✓ |
| Gedeeltelijk terugzetten + 14-daagse undo | — | ✓ | ✓ |
| Multi-academie / federatie | — | — | ✓ |
| Foto-naar-sessie AI (#0016 wanneer geleverd) | — | — | ✓ |
| Proefspelersmodule (#0017) | — | — | ✓ |
| Scout-toegang (#0014 Sprint 5) | — | — | ✓ |
| S3 / Dropbox / GDrive-back-upbestemmingen | — | — | ✓ |

De matrix is **bewerkbaar via het Freemius-dashboard tijdens runtime** — de PHP-standaarden zijn een terugvaloptie; wat Casper in Freemius' plan-features instelt overschrijft ze op elke installatie zodra de SDK synct.

## Trial-flow

1. Een Free-gebruiker klikt op de Accountpagina op **Start 30-daagse Standard-trial**.
2. Standard-tier-functies worden 30 dagen ontgrendeld; resterende dagen staan op de Accountpagina.
3. Op dag 30 gaat de installatie in **alleen-lezen overgangstermijn**: bestaande gegevens blijven toegankelijk, gegate functies zijn verborgen, banner toont "Trial beëindigd — upgrade om nieuwe evaluaties te blijven toevoegen."
4. Op dag 44 valt de installatie hard terug naar Free. Gegevens blijven behouden.

Een trial kan slechts één keer worden gestart. Resetten kan alleen via de ontwikkelaarsoverride.

## Ontwikkelaarstier-override (alleen voor de eigenaar)

Voor demo's en lokale tests zonder zelf te betalen.

**Eenmalige setup op je demo- / dev-installatie**:

1. Genereer een bcrypt-hash van een wachtwoord dat je onthoudt. In een PHP-shell:
   ```php
   echo password_hash( 'jouw-wachtwoord-hier', PASSWORD_BCRYPT );
   ```
2. Voeg toe aan `wp-config.php`:
   ```php
   define( 'TT_DEV_OVERRIDE_SECRET', '$2y$10$....je-hash-hier....' );
   ```
3. Bezoek `wp-admin/admin.php?page=tt-dev-license` (geen menu-link — typ de URL).
4. Vul je wachtwoord in, kies een tier, klik op Activeren.

De override wordt opgeslagen als een 24-uurstransient. Een "🔓 DEV: Pro"-pill verschijnt in de wp-admin-bovenbalk zodat je niet vergeet dat hij aan staat. Bezoek de URL opnieuw om hem eerder te wissen.

**Klantinstallaties zien deze code nooit** — zonder de constante is de adminpagina een 404 en negeert `LicenseGate::tier()` de override.

## Accountconfiguratie

Drie constanten sturen de monetisatie aan (alle in `wp-config.php`, alle optioneel):

| Constante | Vereist voor | Effect |
| - | - | - |
| `TT_FREEMIUS_PRODUCT_ID` | Betaalde plannen + checkout | Activeert de SDK |
| `TT_FREEMIUS_PUBLIC_KEY` | Betaalde plannen + checkout | Authenticeert met Freemius |
| `TT_DEV_OVERRIDE_SECRET` | Dev-override | Schakelt de verborgen override-pagina in |

Zonder de eerste twee draait de plugin Free voor iedereen. Dat is het veilige standaardgedrag — Sprint 1 levert monetisatie slapend; Casper schakelt in zodra het Freemius-account klaar is.

## Verkoopanalyse

Gebruik voor v1 het **eigen Freemius-dashboard** op freemius.com — installaties, trials, conversies, MRR, churn, refunds, EU-btw-inning. Een aparte `talenttrack-ops`-plugin/-site voor rijkere custom analyses is de v2-optie zodra de leemtes in het Freemius-dashboard concreet zijn.
