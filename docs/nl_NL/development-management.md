<!-- audience: admin, dev -->

# Ontwikkelingsbeheer

De Ontwikkeling-tegelgroep zet "dat zouden we moeten oplossen" om in een echt `ideas/NNNN-…md`-bestand in de talenttrack GitHub-repo, zonder dat iemand het dashboard verlaat.

Iedereen behalve spelers en ouders kan een idee indienen. De hoofdontwikkelaar (administrator) beoordeelt inzendingen en wijst ze af met een notitie of klikt op **Goedkeuren & promoten** — de plugin reserveert de volgende vrije `#NNNN`, commit het bestand rechtstreeks naar `main` via de GitHub REST API en schrijft de commit-URL terug op de stagingrij.

## De flow van begin tot eind

1. **Indienen.** Coach / Hoofd Opleidingen / Club Admin / Scout / Staff / Observer / admin opent de **Idee indienen**-tegel. Titel + vrije tekst + een type (`feat` / `bug` / `epic` / `needs-triage`). Status begint op **Ingediend**.
2. **Verfijnen.** Admin / Hoofd Opleidingen / Club Admin opent het **Ontwikkelbord** (kanban). Ze bewerken titel / body / slug, kiezen het type, koppelen optioneel een speler, team of ontwikkelspoor, en verplaatsen de kaart naar **Verfijnen** of **Klaar voor goedkeuring**.
3. **Goedkeuren.** Administrator opent de **Goedkeuringswachtrij**. Twee acties per kaart: **Goedkeuren & promoten** (commit naar GitHub) of **Afwijzen met notitie** (stuurt een e-mail naar de auteur).
4. **Promoten.** Bij goedkeuring lijst de plugin `ideas/`, `specs/` en `specs/shipped/` op GitHub op, reserveert de volgende `NNNN` en doet een `PUT` op `ideas/NNNN-<type>-<slug>.md`. De commit-URL wordt op de rij opgeslagen en getoond op het verfijnscherm.
5. **Volgen.** Eenmaal opgeleverd: zet de kaart op **In uitvoering** en daarna **Klaar**. Als het idee aan een speler was gekoppeld, spawnt de overgang naar **In uitvoering** automatisch een doel in de Doelen-module gekoppeld aan die speler.

De auteur ziet altijd een vriendelijke status: *In beoordeling*, *Geaccepteerd* of *Niet geaccepteerd*. Interne toestanden zoals *Promoten…* en *Promotie mislukt* lekken nooit naar niet-admins.

## Rechten

| Capability | Standaard toegekend aan |
| --- | --- |
| `tt_submit_idea` | Administrator + elke TalentTrack-rol behalve Speler en Ouder |
| `tt_refine_idea` | Administrator + Hoofd Opleidingen + Club Admin |
| `tt_view_dev_board` | Administrator + Hoofd Opleidingen + Club Admin |
| `tt_promote_idea` | Alleen administrator |

Spelers + ouders zien de tegel "Idee indienen" helemaal niet — indienen is een tool voor staff en het adminteam. Alleen de hoofdontwikkelaar (admin) kan een rij promoveren naar een echt GitHub-bestand; afwijzen vereist dezelfde cap.

## GitHub-configuratie

De promoter praat met de GitHub REST API met een fine-grained Personal Access Token. De token moet in `wp-config.php` staan zodat hij nooit in de database belandt (en daar in back-ups, staging-clones en migratie-exports).

```php
define('TT_GITHUB_TOKEN',       'github_pat_...');             // verplicht
define('TT_IDEAS_REPO',         'caspernieuwenhuizen/talenttrack'); // optionele override
define('TT_IDEAS_BASE_BRANCH',  'main');                       // optionele override
```

Token-eisen:

- **Repository-toegang:** alleen de talenttrack-repo.
- **Permissies:** `Contents: Read & write`. Verder niets.

Zolang `TT_GITHUB_TOKEN` niet is gedefinieerd, is de knop **Goedkeuren & promoten** op de Goedkeuringswachtrij uitgeschakeld met een tooltip en een banner. Indienen en verfijnen blijven werken; alleen de GitHub-commitstap is geblokkeerd.

## Ontwikkelsporen

Sporen zijn een admin-beheerde lijst (bijv. *Snelheid*, *Spelinzicht*) waaraan ideeën optioneel kunnen worden gekoppeld. De tegel **Ontwikkelsporen** toont een per-spoor geordende lijst van elk idee op dat spoor met een statuspil — handig als spelersontwikkelings-roadmap voor ideeën die verder gaan dan een enkele bugfix.

Sporen worden op dezelfde pagina aangemaakt en verwijderd; bij verwijderen worden de gekoppelde ideeën losgekoppeld (ze worden niet verwijderd).

## Wat als promotie mislukt

Netwerk-glitch, rate limit of ingetrokken token — de rij gaat naar **Promotie mislukt** met de fout opgeslagen. De Goedkeuringswachtrij toont deze in een aparte sectie met een **Promotie opnieuw proberen**-knop.

ID-toewijzingsrace: als er een aparte commit op `main` landt tussen het "lijst folders"-aanroepen en het `PUT`-commando van de plugin, retourneert de `PUT` een 422. De promoter probeert het automatisch nog één keer met de net opgehaalde max + 1; een tweede botsing op rij markeert de rij als mislukt en toont de fout.

## Zie ook

- [Toegangsbeheer](access-control.md) — voor de vier `tt_…_idea`-capabilities.
- [Doelen](goals.md) — **In uitvoering** spawnt automatisch een doel als een idee aan een speler is gekoppeld.
