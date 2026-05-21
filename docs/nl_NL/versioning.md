# Versies — hoe lees ik het versienummer van de plug-in?

**Doelgroep:** academiebeheerders die het wp-admin "Plug-in-updates"-paneel bekijken.

TalentTrack volgt vanaf versie 4.0.0 [Semantic Versioning](https://semver.org).

Een versie leest als `MAJOR.MINOR.PATCH`. Bijvoorbeeld **4.1.3**:

| Deel | Betekenis |
|---|---|
| `4` (major) | Een nieuwe majorversie betekent een wijziging waarbij je bij de upgrade iets moet doen — een hernoeming van een databasekolom, een wijziging in het REST-API-contract, een wijziging in de rechtenmatrix. We benoemen dit expliciet in de upgrade-notities. |
| `1` (minor) | Er is een nieuwe functie opgeleverd (bijvoorbeeld een nieuwe dashboardwidget, een nieuwe export, een nieuwe wizardstap). De upgrade is veilig; de nieuwe functie verschijnt naast de bestaande. |
| `3` (patch) | Bugfixes en kleine verbeteringen binnen dezelfde minor. Altijd veilig om bij te werken. |

## Wat betekent de v4.0.0-reset voor mij?

Operationeel niets. De reset was puur cosmetisch — versies `3.110.x` waren naar een betekenisloze plek afgedreven; v4.0.0 trekt de lijn opnieuw. Er zijn geen gegevens gewijzigd, geen instelling vereist je aandacht, er draait geen migratie. De plug-in-update verloopt automatisch via je normale WordPress-update-flow.

## De changelog lezen

Elke release voegt een paragraaf toe aan `CHANGES.md` (ook zichtbaar vanaf de WordPress-plug-ins-pagina → "Details bekijken") die uitlegt wat is veranderd, waarom, en hoe je het kunt testen als je wilt verifiëren.

Bij twijfel is het **majornummer** het signaal: als het verhoogt, kijk dan naar de upgrade-notities. Als alleen minor of patch verhogen, kun je in je normale tempo bijwerken.
