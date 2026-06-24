<!-- audience: operator, developer -->
# Records verwijderen — archiveren, herstellen, definitief verwijderen

De meeste records in TalentTrack volgen een **archiveer-levenscyclus**: je
archiveert ze (zacht verwijderen) en kunt ze herstellen. Een aparte,
onomkeerbare **definitieve verwijdering** wordt alleen aangeboden op reeds
gearchiveerde rijen en vereist `tt_edit_settings`.

## Verwijderen met referentiële-integriteitscontrole (#1783)

Definitief verwijderen is **fail-closed**. Vóór het verwijderen wordt
gescand of andere records ernaar verwijzen, en vervolgens wordt:

- de eigen onderliggende data **mee-verwijderd** (een beoordeling
  verwijderen verwijdert ook de categoriescores; een doel verwijderen
  verwijdert de koppelingen en het gesprek),
- de verwijzing **leeggemaakt** bij rijen die het record overleven (een
  workflowtaak die een doel aanmaakte blijft bestaan, met de doelkoppeling
  leeggemaakt), of
- de verwijdering **geblokkeerd** wanneer een ander record er nog naar
  verwijst dat er geen eigendom van is. De verwijdering wordt geweigerd met
  een melding van wat er nog naar verwijst (bijv. *"Kan niet verwijderen:
  nog gekoppeld aan 18 spelers, 6 activiteiten. Archiveer of verwijder die
  eerst."*).

In het slechtste geval wordt een verwijdering **geweigerd** — een
definitieve verwijdering laat nooit stilzwijgend losgekoppelde rijen achter.

### Gedrag per entiteit nu

| Record | Bij definitief verwijderen |
| --- | --- |
| Speler, Persoon, POP-bestand | Volledige cascade (bestaande diensten). |
| Beoordeling | Verwijdert de scores + bewijslast-koppelingen mee. |
| Doel | Verwijdert koppelingen + gesprek mee; maakt doelkoppelingen leeg. |
| Toernooi | Verwijdert wedstrijden, selectie en opstellingen mee; maakt de toernooikoppeling van een activiteit leeg. |
| Proefdossier | Verwijdert stafkoppelingen, staf-input en verlengingen mee; maakt workflowtaak- / prospect-koppelingen leeg. |
| Team, Activiteit | **Blokkeert** zolang er nog records naar verwijzen (volledige cascades zijn een vervolg, #1784). |

Als een team of activiteit niet wil verwijderen, archiveer of verplaats dan
eerst de spelers / activiteiten en probeer het opnieuw.
