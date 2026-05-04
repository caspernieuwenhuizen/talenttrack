<!-- audience: user -->

# Demodata — Excel-werkboek

De demodatagenerator bij **Tools → TalentTrack Demo** heeft drie bronnen:

- **Alleen procedureel** — kies een preset (Tiny / Small / Medium / Large) en laat de generator alles doen. Snel en geloofwaardig, maar team- en spelersnamen zijn willekeurig.
- **Excel-upload** — vul offline een werkboek, upload het, de importer maakt precies wat erin staat. Er wordt niets procedureel gegenereerd. Ideaal voor demo's waar de eigen teamnamen en verhalen van de prospect ertoe doen.
- **Hybride: upload + procedurele aanvulling** — Excel-tabbladen winnen; de procedurele generator vult elk leeg tabblad aan. Het beste van beide — herkenbare teamnamen uit Excel, plus een volledig seizoen aan evaluaties / activiteiten / doelen uit de generator.

## Werkboekstructuur

Klik in de bronstap op **Template downloaden (.xlsx)** voor een vers werkboek met alle 15 tabbladen, gegroepeerd op tabkleur:

- **Master** (groen) — Teams, People, Players, Trial_Cases.
- **Transactioneel** (blauw) — Sessions, Session_Attendance, Evaluations, Evaluation_Ratings, Goals, Player_Journey.
- **Configuratie** (paars) — Eval_Categories, Category_Weights, Generation_Settings.
- **Referentie** (grijs) — _Lookups.

Elk entiteit-tabblad heeft een `auto_key`-kolom met een live formule die een stabiele tekstsleutel berekent zodra je begint te typen. Verwijzingen tussen tabbladen gebruiken die sleutels: bijv. `Players.team_key` verwijst naar `Teams.auto_key`.

## Wat v1.5 importeert

De Master- en Transactioneel-tabbladen worden letterlijk geïmporteerd. Referentie-tabbladen (Eval_Categories, Category_Weights, _Lookups) zijn alleen ter documentatie in v1.5 — beheer die via de bestaande Configuratie-schermen. Generation_Settings wordt gelezen voor datumhints in hybride modus.

## Validatie

De importer weigert bij:

- Een verplichte kolom die ontbreekt op een gevuld tabblad.
- Een verplicht veld dat leeg is op een rij.
- Een verwijzing (`team_key`, `player_key`, `evaluation_key`, `session_key`) die nergens in het bovenliggende tabblad voorkomt.

Fouten komen als lijst terug — los ze op in het werkboek, upload opnieuw.

## Opnieuw importeren

Opnieuw uploaden voegt rijen toe (geen rij-niveau upsert). Om alles te vervangen: gebruik eerst **Wipe demo data** en upload daarna.

## Upload-fouten oplossen (v3.89.2)

De upload-route is gehard tegen de "lijkt op een hosting-fout"-faalmodus. Als er iets misgaat krijg je een rode TalentTrack-melding met de werkelijke oorzaak, in plaats van de generieke 500 van de hostingprovider.

| Symptoom | Wat het betekent | Oplossing |
| - | - | - |
| **"De upload overschrijdt de POST-limiet van de server (post_max_size = 8M). Vraag je hoster om die op te hogen…"** | Het werkboek plus de overige formuliervelden samen zijn groter dan `post_max_size`; PHP heeft het verzoek al voor de plugin afgewezen. | Vraag je hoster om `post_max_size` (en `upload_max_filesize`) op te hogen; typische waardes zijn 32M–128M. Of splits het werkboek. |
| **"De upload overschrijdt upload_max_filesize op de server (8M). Vraag je hoster om die op te hogen…"** | Alleen het bestand zelf is al groter dan `upload_max_filesize`. | Idem. |
| **"De upload is halverwege onderbroken. Probeer opnieuw op een stabiele verbinding."** | Het netwerk hapte. | Probeer opnieuw. |
| **"Kon het werkboek niet lezen: …"** | PhpSpreadsheet kreeg het bestand niet open (kapot zip, half gedownload, wachtwoordbeveiligd, …). | Sla het werkboek opnieuw op in Excel / Calc en probeer opnieuw. |
| **"Excel-import is gecrasht: …. Bekijk de TalentTrack-log voor details."** | Er kwam een fatal langs de binnenste catch (zelden — meestal OOM met een te lage `memory_limit`, zelfs na de raise van de plugin). | Vraag je hoster om `memory_limit` op ≥128M te zetten, of splits het werkboek. De TalentTrack-log bevat de error-klasse + het bericht. |

De daadwerkelijke serverlimieten op jouw installatie staan onder het bestandsveld, zodat je het werkboek vóór de upload op maat kunt brengen.
