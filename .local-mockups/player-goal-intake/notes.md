# Player goal-setting intake — print mockups

Two distinct printables in one mockup file. Picker at top flips between them.

## A · Per-player intake form (printed before every 1:1)

**Three A4 portrait pages**, one player per print job. The split is purposeful — page 1 is what the coach reads *before* the conversation, pages 2-3 are what gets handwritten *during* and *after*.

- **Page 1 — speler-snapshot**: identity anchor (photo, name, team, age, jersey, foot, academy years) → last-season stat row (apps, minutes, goals, assists, avg rating) → "Terugblik" with **four pre-printed cards** (strengths, dev areas, last-season goals + outcomes, coach notes) → open reflection lines.
- **Page 2 — doelen 1 + 2**: two full goal boxes side-by-side stacked.
- **Page 3 — doel 3 + afronding**: final goal box → closing one-line reflection → 3-up signature row (speler, trainer, datum) → reminder to transfer the goals into TalentTrack within 48h.

### Why 3 pages and not 2

The original 2-page intent overflows A4 in print. The cumulative height of page 1's content (identity + stats + 4 lookback cards + reflection + Goal 1) is ~330mm; A4 portrait gives ~273mm working area (after 16mm/14mm margins + 8mm paper-footer). With `page-break-inside: avoid` on the goal box (so a goal never gets sliced in half), Goal 1 was being shoved entirely onto page 2 by the print engine, pushing Goals 2+3 onto page 3 — same total page count, worse layout.

The redesign embraces the natural 3-page break and gives each goal box generous handwriting room. The coach can also hand the snapshot page to the player a couple of days before the meeting as homework if they want a more reflective conversation.

### Each goal box has

- A numbered medallion + a title write-line.
- **Four category checkboxes** (Technical / Tactical / Physical / Mental) — tick during conversation.
- **Linked principle(s)** — two empty code boxes (e.g. `AO-01`, `VS-03`) cross-referenced to the methodology sheet.
- **Linked football action(s)** — two empty code boxes (e.g. `Passen`, `Vrijlopen`).
- **Why it matters** — 2 write-lines for the player's words.
- **How we'll measure** — 2 write-lines for the metric.
- **First check-in date** + **Owner** (player / coach / both).

Three goals fits the pilot's stated "3 goals per player" target without bloating the page. The intake stays a worksheet, not a worksheet-with-everything.

### What's pre-printed (vs. handwritten)

Per the user's "print more" preference, the form prints **anchor data the coach would otherwise have to recall**:

| Pre-printed | Handwritten |
|---|---|
| Player identity + stats | Reflection answers |
| Last-season eval medians (strengths + dev areas) | Goal titles |
| Last-season goal outcomes | Why-it-matters narrative |
| Coach's prior end-of-year notes | Measurement plan |
| Vocabulary code boxes (empty) | Principle + action codes |
| Conversation prompts (italic hints under each section) | Signatures + dates |

Reasoning: the goal is to keep the conversation honest. Pre-printing facts means the player can't dodge them; leaving the goals + measurement blank means the conversation IS the goal-setting (not a form-checking exercise).

## B · Methodology reference (printed ONCE, brought to every meeting)

**Up to 3 A4 portrait pages**, designed to be laminated and reused. The coach selects which methodology sections to include via a print dialog before sending to the printer.

- **Page — Spelprincipes (18)**: grouped by team-function + team-task (Aanvallen/opbouwen, Aanvallen/scoren, Verdedigen/storen, Verdedigen/doelpunten voorkomen, Omschakelen/naar aanvallen). Each entry: code (`AO-01`) + the full Dutch principle text. Tinted top callout: how to use the sheet during a 1:1.
- **Page — Voetbalhandelingen (11)**: 3 columns (Met balcontact / Zonder balcontact / Ondersteunend). Each entry: name + one-line Dutch hint. Worked example at the bottom showing how a principle pairs with two actions.
- **Page — Leerdoelen (10)**: 2 columns (Aanvallen / Verdedigen), grouped by team-task. Each entry: title + 4–5 deelvaardigheden bullets. Top callout explains how a deelvaardigheid maps to the intake form's "waarom" or "hoe meten" field.

### Per-section selection

Production print dialog presents a checkbox per methodology section, all ticked by default:

- ☑ Spelprincipes (18 principes — 1 pagina)
- ☑ Voetbalhandelingen (11 handelingen — 1 pagina)
- ☑ Leerdoelen (10 leerdoelen — 1 pagina)

Coach can untick a section to print a slimmer reference (e.g. the academy might already have laminated principles; only Leerdoelen is new). The "Include:" chips in the mockup-bar mirror this UX — toggle them to preview each combination.

Content is **lifted verbatim from the seeded methodology data** in `database/migrations/0018_methodology_full_content.php` so the reference matches what the digital wizard offers. If the academy adopts a different game model, regenerate the sheet.

## i18n — fully Dutch on a Dutch site

The mockup ships in **Dutch** because that's the pilot's locale and **no English should be visible to a player**. The only English left in the file is the developer-facing chrome (browser tab title, picker bar, CSS comments) — none of which prints.

### Architecture for the production implementation

Every string in the printable surface goes through WordPress i18n:

```php
__( 'Doelomschrijving', 'talenttrack' );   // wrong — Dutch as source string
__( 'Goal title',       'talenttrack' );   // right — English source, NL translation in the .po
```

WP convention is **English source strings + per-locale `.po` translation files**. The Dutch translation lives in `languages/talenttrack-nl_NL.po`. Other locales (`de_DE`, `es_ES`, `fr_FR`) get their own `msgstr` entries when this ships — the printable simply falls out of `__()` in whatever language WP is running in.

### EN source → NL translation table (for the .po file)

The executor implementing this needs these msgid → msgstr pairs in `talenttrack-nl_NL.po`:

| EN source (msgid) | NL translation (msgstr) |
|---|---|
| `Goal-setting intake — 1:1 conversation` | `Doelenintake — 1:1 gesprek` |
| `Goal-setting intake — %s (cont.)` | `Doelenintake — %s (vervolg)` |
| `Print, discuss, then transfer the final goals into the digital system within 48 hours.` | `Print, bespreek, en zet de definitieve doelen binnen 48 uur in het digitale systeem.` |
| `Photo` | `Foto` |
| `Team` | `Team` |
| `Position` | `Positie` |
| `Age · DOB` | `Leeftijd · Geboortedatum` |
| `Jersey` | `Rugnummer` |
| `In academy since` | `Bij academie sinds` |
| `Preferred foot` | `Voorkeursvoet` |
| `Right` / `Left` | `Rechts` / `Links` |
| `Apps` | `Wedstr.` |
| `Minutes` | `Minuten` |
| `Goals` (stat) | `Doelpunten` |
| `Assists` | `Assists` *(loan word in Dutch — leave)* |
| `Avg rating` | `Gem. score` |
| `Looking back · %s` | `Terugblik · %s` |
| `Pre-printed anchors from the player's PDP + evals — talk to them, don't lecture.` | `Voorgedrukte ankerpunten uit POP + evaluaties — bespreek ze, geen monoloog.` |
| `Last-season strengths (eval median)` | `Sterke punten vorig seizoen (mediaan eval)` |
| `Development areas` | `Ontwikkelpunten` |
| `Goals carried from last season` | `Doelen uit vorig seizoen` |
| `Coach notes (%s, end-of-year talk)` | `Trainersnotities (%s, eind-seizoen gesprek)` |
| `Reflection — in the player's own words` | `Reflectie — in de eigen woorden van de speler` |
| `Opening question:` | `Openingsvraag:` |
| `Player writes / coach captures:` | `Speler schrijft / trainer noteert:` |
| `Goal title` | `Doelomschrijving` |
| `Technical` / `Tactical` / `Physical` / `Mental` | `Technisch` / `Tactisch` / `Fysiek` / `Mentaal` |
| `Linked principle(s)` | `Gekoppeld(e) spelprincipe(s)` |
| `Linked football action(s)` | `Gekoppelde voetbalhandeling(en)` |
| `codes from reference sheet, max %d` | `codes van de referentiekaart, max %d` |
| `max %d` | `max %d` |
| `e.g. %s` | `bijv. %s` |
| `Why it matters` | `Waarom dit belangrijk is` |
| `player's words — what changes when they nail this?` | `in de woorden van de speler — wat verandert er als dit lukt?` |
| `How we'll measure` | `Hoe we dit meten` |
| `what we'll look at to know it's working` | `waar we naar kijken om te weten dat het werkt` |
| `First check-in date` | `Eerste check-in datum` |
| `Owner` | `Eigenaar` |
| `Owner (player / coach / both)` | `Eigenaar (speler / trainer / beide)` |
| `Closing — one thing to carry into the season` | `Afsluiting — één ding om mee te nemen het seizoen in` |
| `Player's choice — the headline they want to remember when things get hard.` | `Keuze van de speler — de kernzin om te onthouden als het lastig wordt.` |
| `Player` | `Speler` |
| `Coach` | `Trainer` |
| `Date discussed` | `Datum gesprek` |
| `Page %1$d of %2$d` | `Pagina %1$d van %2$d` |
| `Coach reminder: transfer the three goals above into TalentTrack (Player › Goals › + New goal) within 48 hours so the digital version is anchored to the conversation. Use the linked-principle + linked-football-action fields in the wizard to mirror what's written above.` | `Reminder voor de trainer: zet de drie doelen hierboven binnen 48 uur in TalentTrack (Speler › Doelen › + Nieuw doel) zodat de digitale versie aan dit gesprek hangt. Gebruik de velden "gekoppeld spelprincipe" en "gekoppelde voetbalhandeling" in de wizard zodat het digitale doel hetzelfde leest als hierboven.` |
| `TalentTrack · methodology reference` | `TalentTrack · methodiek-referentie` |
| `%s academy — game model · %d principles · use the codes when setting goals.` | `%s academie — speelmodel · %d principes · gebruik de codes bij het opstellen van doelen.` |
| `Print once · laminate · keep in the goal-setting folder` | `Eenmaal printen · lamineren · bewaren in de doelenmap` |
| `How to use this sheet during a goal-setting 1:1:` | `Hoe je deze kaart gebruikt tijdens een doelenintake (1:1):` |
| `Talk through the player's development area in plain language.` | `Bespreek het ontwikkelpunt van de speler in gewone taal.` |
| `Together pick 1–2 principles that connect to it (the code goes on the intake form).` | `Kies samen 1–2 principes die hieraan raken (de code komt op het intakeformulier).` |
| `Then turn to page 2 and pick 1–2 football actions that, when better, advance the principle.` | `Ga daarna naar pagina 2 en kies 1–2 voetbalhandelingen die — als ze beter worden — het principe versterken.` |
| `The %d football actions every player practises — pick 1–2 per goal.` | `De %d voetbalhandelingen die elke speler oefent — kies er 1–2 per doel.` |
| `Worked example — pairing a principle with an action:` | `Uitgewerkt voorbeeld — een principe koppelen aan een handeling:` |
| `Page 1 of 2 · flip for football actions` | `Pagina 1 van 2 · achterkant voor voetbalhandelingen` |

### What does NOT get translated

These are **content** (DB data), not UI chrome. They render as-stored regardless of locale:

- **Spelprincipes** texts (AO-01 … OA-03) — academy's game-model wording. Already Dutch in the seeded `tt_principles` data. If the academy localises to another club / language, they edit the rows directly.
- **Voetbalhandeling** names (Aannemen, Passen, Vrijlopen, …) — academy's drill vocabulary. Already Dutch in the seeded `tt_football_actions` data. Same edit-the-row pathway.
- **Phase titles** ("Aanvallen · opbouwen", "Verdedigen · storen", …) — these come from the team-function + team-task lookups in `tt_lookups`; already Dutch.
- **Player names, team names, coach names, position names** — user-entered data; never UI-translated.
- **Section headers like "Spelprincipes" and "Voetbalhandelingen"** themselves — these are the methodology's canonical Dutch names. Treated as **terminology, not UI**; passed through verbatim (parallel to how "Pizza Margherita" doesn't get translated on a menu).

### Action hint strings — quick translation reference

The action hints under each football action ("eerste balcontact · aanname", "pass · snelheid · richting", etc.) are short one-liners that read like a coach's whiteboard. If the executor wants to make them translatable too (they're part of the printed sheet content, not the methodology vocab), use these EN→NL pairs:

| Action | EN hint | NL hint |
|---|---|---|
| Aannemen | `first touch · receive` | `eerste balcontact · aanname` |
| Passen | `pass · weight · direction` | `pass · snelheid · richting` |
| Dribbelen | `dribble · carry · 1v1` | `dribbel · loopactie · 1:1` |
| Schieten | `shoot · finish` | `schot · afronding` |
| Koppen | `head · aerial` | `kopbal · luchtduel` |
| Vrijlopen | `move free · create space` | `vrijlopen · ruimte creëren` |
| Knijpen | `pinch · compactness` | `knijpen · compactheid` |
| Jagen | `hunt · press` | `jagen · druk geven` |
| Dekken | `mark · cover` | `dekken · rugdekking` |
| Spelinzicht | `reading the game` | `het spel lezen` |
| Communicatie | `communication · coaching teammates` | `coachen van medespelers` |

Recommendation: ship hints as part of the `tt_football_actions` data (a new `hint_text` column or stored in `tt_translations` against the action lookup) rather than hard-coded in the printable template — that way the academy can edit them.

## Print recipe

Both surfaces use:

```css
@page { size: A4 portrait; margin: 0; }
```

with the paper's own `padding` at `16mm 14mm` for the actual ink margins. The mockup chrome (picker + hint) is `display:none` under `@media print`.

To print **only the active surface** today:

1. Click the surface in the picker.
2. Hit "Print preview" in the bar (or Ctrl/Cmd-P).
3. The hidden surface stays `display:none`, so only the active one lands in the print spool.

When this ships in production, the surface picker will go away — each surface will be reached by a dedicated detail-action:

- Intake form: `Player detail › Actions › Print goal-setting intake` (with the new-season year prefilled).
- Reference: `Settings › Methodology › Print principles + actions reference`.

## Future / out of scope for this mockup

- Multi-player batch print — generate one PDF per team's player roster so the coach prints a stack before the season-start week. Naturally falls out of the per-player template.
- Locale switch — the academy data is Dutch; copy chrome is English. Production will respect site locale.
- Custom logo / sponsor branding in the page header.
- Versioning the methodology reference (date stamp at the footer) so old prints are recognisably stale.

## What to verify on paper before committing to implementation

1. Print both surfaces on real A4. Lay them out next to each other on a desk — does the layout feel right when a coach is holding a clipboard?
2. Conduct one actual 1:1 with a pilot coach using the printed sheets — record where they ran out of space and where they had too much.
3. Check the line-height of the write-lines (currently 7mm) — too tight for the average handwriting?
4. Check the code boxes (currently 12mm × 6mm) — big enough for a coach to write `AO-01` legibly?
5. Verify the methodology reference is legible from arm's length when laminated (some laminator films reduce contrast slightly).
