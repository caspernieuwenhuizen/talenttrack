<!-- audience: admin, developer -->

# Authoring the methodology library (frontend)

Academy editors can author methodology content directly from the frontend — no wp-admin needed. This surface is the counterpart to the read-only [Methodology](methodology.md) library.

## How to find it

Open the **Methodology** tile in the **Reference** group. If you have the editing capability you'll see a **Manage methodology** button in the header; it opens the authoring surface. You can also go straight there with `?tt_view=methodology&mode=manage`.

Only users with the `tt_edit_methodology` capability (academy admin, head of development, administrators) can reach the manage surface. Everyone else sees a "not authorized" notice.

The surface always offers a **View published methodology** link back to the read view, plus the standard breadcrumb trail.

## Entity tabs

The manage surface is tabbed by methodology entity, mirroring the read view. Each tab is a self-contained authoring page — a list of that entity's records with a "+ New …" button, edit and delete row actions, and a flat create/edit form.

**Principles**, **Vision** and **Framework primer** are available. Formations, set-pieces and the remaining entities are added in later releases; each appears as its own tab as it ships.

Two of these tabs — **Vision** and **Framework primer** — are *single-record* editors rather than lists: each club has exactly one vision and one framework primer, so the tab opens straight onto its edit form (no list, no "+ New", no delete).
**Principles** and **Formations** are available now. Set-pieces, visions, the framework primer and the other entities are added in later releases; each appears as its own tab as it ships.
**Principles** and **Set pieces** are available today. Formations, visions, the framework primer and the other entities are added in later releases; each appears as its own tab as it ships.
**Principles** and **Football actions** (voetbalhandelingen) are available. Formations, set-pieces, visions, the framework primer and the other entities are added in later releases; each appears as its own tab as it ships.

## Editing a principle

A principle carries:

- **Code** — the short reference like `AO-01`.
- **Team-function** and **team-task** — the framework categories the principle belongs to.
- **Title**, **Explanation** and **Team-level guidance** — each with side-by-side **Dutch (NL)** and **English (EN)** inputs.
- **Per-line guidance** — a Dutch and English note for forwards, midfielders, defenders and the goalkeeper.

Fill in Dutch first; English is optional and falls back to Dutch when a viewer's language is English but no English text was supplied. Save and Cancel sit together at the bottom of the form — Cancel returns you to the list (or to wherever you came from).

Deleting a principle removes it permanently and asks for confirmation first.

## Editing the club vision

The **Vision** tab edits your club's single vision record. It carries:

- **Formation** and **Style of play** — picked from the methodology's formation list and the fixed style vocabulary.
- **Way of playing** and **Notes** — each with side-by-side **Dutch (NL)** and **English (EN)** text.
- **Important traits** — a Dutch and English list, one trait per line.

The first save creates your club's vision; later saves update it. The shipped sample vision is read-only and is never touched here. What you save appears on the read view's **Visie** tab.

## Editing the framework primer

The **Framework primer** (Raamwerk) tab edits your club's single framework primer — the introductory text that frames the methodology and each of its themes:

- **Title** and **Tagline** — single-line, NL/EN.
- **Inleiding**, plus a **toelichting** (intro) for each theme: **Voetbalmodel**, **Voetbalhandelingen**, **Vier fasen**, **Leerdoelen** and **Factoren van invloed**.
- **Reflectie** and **De toekomst** — closing sections.

Every section has side-by-side Dutch and English text. The first save creates the primer; later saves update it. The primer is the parent of the phases, learning goals and influence factors authored on their own tabs. What you save appears on the read view's **Raamwerk** tab.

## Editing a phase

The **Fasen** tab lists the phases that hang off your framework primer — the four attacking and four defending phases that describe how your club moves through the game. A phase carries:

- **Side** — attacking, defending or transition.
- **Phase number** — 1 to 4.
- **Title** and **Goal** — each with side-by-side **Dutch (NL)** and **English (EN)** inputs.

Phases hang off the framework primer, so author (save) the primer on the **Raamwerk** tab first — until then the Fasen tab points you there. Save and Cancel sit together at the bottom of the form. Deleting a phase removes it permanently after a confirmation.

## Editing a learning goal

The **Leerdoelen** tab lists your learning goals — coachable focus areas within attacking or defending. A learning goal carries:

- **Slug** — the short reference like `positiespel-verbeteren`.
- **Side** — attacking, defending or transition.
- **Linked team-task** — optional; ties the goal to a teamtaak so the read view can group it.
- **Title** — side-by-side Dutch and English.
- **Bullets** — a Dutch and an English observable-checklist, one bullet per line in each textarea.
- **Sort order** — controls the order within a side.

Learning goals hang off the framework primer; author it first. Save and Cancel sit together at the bottom. Deleting a learning goal removes it permanently after a confirmation.

## Editing an influence factor

The **Factoren van invloed** tab lists the factors that shape a player's development. An influence factor carries:

- **Slug** — the short reference like `spelers`.
- **Sort order** — its position in the list.
- **Title** and **Description** — each with side-by-side Dutch and English inputs.
- **Sub-factors (JSON)** — optional array of sub-cards. Each entry needs a `slug` plus `title` and `description` in both languages: `[{"slug":"motivatie","title":{"nl":"Motivatie","en":"Motivation"},"description":{"nl":"…","en":"…"}}]`. Leave it blank for none; malformed JSON is discarded on save.

Influence factors hang off the framework primer; author it first. Save and Cancel sit together at the bottom. Deleting a factor removes it permanently after a confirmation.

## Editing a formation

The **Formaties** tab lists your formations. Each formation carries:

- **Slug** — the short reference like `1-4-3-3`.
- **Name** and **Description** — each with side-by-side **Dutch (NL)** and **English (EN)** inputs.
- **Diagram data (JSON)** — optional. Normalized 0–100 position coordinates for the pitch diagram (`{"positions":{"1":{"x":50,"y":92,"label":"K"}}}`). Leave it blank to use the default layout.

Save and Cancel sit together at the bottom — Cancel returns you to the formation list (or to wherever you came from). Deleting a formation removes it and all its position cards permanently, after a confirmation.

## Editing formation positions

Each formation has up to eleven **position cards** — one per jersey slot. From the formation list, use the **Positions** action to open a formation's positions, then **+ New position** to add one. A position carries:

- **Jersey number** — 1–11.
- **Short name** and **Long name** — side-by-side Dutch and English inputs (e.g. "Vleugelverdediger" / "Wing-back").
- **Attacking tasks** and **Defending tasks** — Dutch and English textareas, **one task per line**. Blank lines are dropped.

Positions belong to their formation; deleting a formation deletes its positions too.

## Shipped vs club-authored

Shipped principles, formations and positions curated by TalentTrack are **read-only** here — they show a "Shipped" badge and no edit or delete action, so you can't accidentally break the reference content. Club-authored records are fully editable and deletable.
## Editing a set piece

A set piece carries:

- **Slug** — the short reference like `corner-attacking-far-post`.
- **Kind** — corner, free kick (direct), free kick (cross), penalty or throw-in.
- **Side** — attacking, defending or transition.
- **Title** — with side-by-side **Dutch (NL)** and **English (EN)** inputs.
- **Bullets** — a Dutch and an English coaching-point list, one bullet per line in each textarea.
- **Diagram overlay (JSON)** — optional raw JSON describing marker positions on the pitch diagram. Leave it blank if you have none; malformed JSON is discarded on save.

Fill in Dutch first; English is optional and falls back to Dutch when a viewer's language is English but no English text was supplied. Save and Cancel sit together at the bottom of the form — Cancel returns you to the list (or to wherever you came from). Deleting a set piece removes it permanently and asks for confirmation first. Saved set pieces are reflected in the read view's **Set pieces** tab.
## Editing a football action

A football action (voetbalhandeling) carries:

- **Slug** — the short machine reference like `aannemen`.
- **Category** — one of *Met balcontact* (with ball), *Zonder balcontact* (without ball) or *Ondersteunend* (support).
- **Name** and **Description** — each with side-by-side **Dutch (NL)** and **English (EN)** inputs.

Fill in Dutch first; English is optional and falls back to Dutch when a viewer's language is English but no English text was supplied. Save and Cancel sit together at the bottom of the form — Cancel returns you to the list (or to wherever you came from).

Deleting a football action removes it permanently and asks for confirmation first. An action that a goal still links to (via its linked action) **cannot** be deleted — you'll get a notice telling you how many goals reference it. Unlink those goals first, then delete.

## Shipped vs club-authored

Shipped content curated by TalentTrack (principles, set pieces and the other entities) is **read-only** here — it shows a "Shipped" badge and no edit or delete action, so you can't accidentally break the reference content. Club-authored records are fully editable and deletable.

## REST API

Everything the manage surface does is also available over REST, so a future non-WordPress front end gets the same behaviour:

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles` | List principles (club-scoped). |
| `POST` | `/wp-json/talenttrack/v1/methodology/principles` | Create a club-authored principle. |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | One principle, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Edit a club-authored principle. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Delete a club-authored principle. |
| `GET` | `/wp-json/talenttrack/v1/methodology/vision` | The active club vision. |
| `GET` | `/wp-json/talenttrack/v1/methodology/vision/{id}` | One vision, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/vision/{id}` | Edit the club vision. |
| `GET` | `/wp-json/talenttrack/v1/methodology/framework-primer` | The active club framework primer. |
| `GET` | `/wp-json/talenttrack/v1/methodology/framework-primer/{id}` | One primer, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/framework-primer/{id}` | Edit the club framework primer. |
| `GET` | `/wp-json/talenttrack/v1/methodology/phases` | List the active primer's phases. |
| `POST` | `/wp-json/talenttrack/v1/methodology/phases` | Create a club-authored phase on the active primer. |
| `GET` | `/wp-json/talenttrack/v1/methodology/phases/{id}` | One phase, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/phases/{id}` | Edit a club-authored phase. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/phases/{id}` | Delete a club-authored phase. |
| `GET` | `/wp-json/talenttrack/v1/methodology/learning-goals` | List the active primer's learning goals (filter by `side`). |
| `POST` | `/wp-json/talenttrack/v1/methodology/learning-goals` | Create a club-authored learning goal. |
| `GET` | `/wp-json/talenttrack/v1/methodology/learning-goals/{id}` | One learning goal, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/learning-goals/{id}` | Edit a club-authored learning goal. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/learning-goals/{id}` | Delete a club-authored learning goal. |
| `GET` | `/wp-json/talenttrack/v1/methodology/influence-factors` | List the active primer's influence factors. |
| `POST` | `/wp-json/talenttrack/v1/methodology/influence-factors` | Create a club-authored influence factor. |
| `GET` | `/wp-json/talenttrack/v1/methodology/influence-factors/{id}` | One influence factor, with Dutch + English values and sub-factors. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/influence-factors/{id}` | Edit a club-authored influence factor. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/influence-factors/{id}` | Delete a club-authored influence factor. |

The framework primer's children — **phases**, **learning goals** and **influence factors** — are scoped to the active club-authored primer: `POST` needs a primer to exist (else `409`), and `GET` list returns an empty set until one does. Phase `goal`, learning-goal `title` and influence-factor `title` / `description` use the `{ "nl": …, "en": … }` shape; learning-goal `bullets` uses `{ "nl": ["…"], "en": ["…"] }`; the influence-factor `sub_factors` field is an array of `{ slug, title:{nl,en}, description:{nl,en} }` cards.
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces` | List set pieces (club-scoped; filter by `kind`, `side`, `source`). |
| `POST` | `/wp-json/talenttrack/v1/methodology/set-pieces` | Create a club-authored set piece. |
| `GET` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | One set piece, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Edit a club-authored set piece. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/set-pieces/{id}` | Delete a club-authored set piece. |

Formations (and their nested position cards) expose the same CRUD:

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations` | List formations (club-scoped). |
| `POST` | `/wp-json/talenttrack/v1/methodology/formations` | Create a club-authored formation. |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | One formation, with its positions. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | Edit a club-authored formation. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/formations/{id}` | Delete a club-authored formation (and its positions). |
| `GET` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions` | List a formation's positions. |
| `POST` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions` | Create a position on the formation. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions/{pid}` | Edit a position. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/formations/{id}/positions/{pid}` | Delete a position. |

Every route requires the `tt_edit_methodology` capability and is scoped to the current club. Multilingual string fields (`title`, `explanation`, `team_guidance`, `name`, `description`, `short_name`, `long_name`) accept and return an `{ "nl": "…", "en": "…" }` shape; array fields (`attacking_tasks`, `defending_tasks`) use `{ "nl": ["…"], "en": ["…"] }`. Editing or deleting a shipped record returns `409`.
Every route requires the `tt_edit_methodology` capability and is scoped to the current club. Multilingual string fields (principle `title`, `explanation`, `team_guidance`, `line_guidance`; set-piece `title`) accept and return an `{ "nl": "…", "en": "…" }` shape. The set-piece `bullets` field takes `{ "nl": ["…"], "en": ["…"] }`, and `diagram_overlay` is a free-form JSON object.
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions` | List football actions (club-scoped). |
| `POST` | `/wp-json/talenttrack/v1/methodology/football-actions` | Create a club-authored football action. |
| `GET` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | One football action, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Edit a club-authored football action. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/football-actions/{id}` | Delete a club-authored football action (blocked with `409` while a goal links to it). |

Every route requires the `tt_edit_methodology` capability and is scoped to the current club. Principle multilingual fields (`title`, `explanation`, `team_guidance`, `line_guidance`) and football-action fields (`name`, `description`) accept and return an `{ "nl": "…", "en": "…" }` shape.

The **vision** and **framework primer** are single records per club, so they expose read + update only — no `POST` create, no `DELETE`. Their multilingual fields (vision: `way_of_playing`, `notes`, `important_traits`; primer: `title`, `tagline`, `intro`, the per-theme `*_intro`, `reflection`, `future`) accept and return the same `{ "nl": …, "en": … }` shape; `important_traits` is a list of strings per language.

## For developers — adding an entity tab

The manage surface is built around an extensible tab registry, `TT\Modules\Methodology\Frontend\Manage\MethodologyManageRegistry`. A sibling entity registers its tab **without editing any shared switch statement** — typically from the module's `boot()`:

```php
MethodologyManageRegistry::register( [
    'key'    => 'formations',                     // the mtab slug
    'label'  => __( 'Formaties', 'talenttrack' ), // tab label
    'render' => [ FormationsManageTab::class, 'render' ], // callable( array $ctx ): void
    'handle' => [ FormationsManageTab::class, 'handle' ], // optional POST handler
    'order'  => 30,                               // sort position (lower = earlier)
] );
```

- `render( array $ctx )` receives `[ 'action' => 'list'|'new'|'edit', 'id' => int, 'flash' => string ]` and emits the tab body (list ⇄ form).
- `handle( array $post )` (optional) runs on form POST after the shared nonce is verified and returns `[ 'flash' => string, 'back_to_list' => bool ]`. Omit it for tabs that write purely over REST.
- Use `MethodologyManageView::tabUrl( $mtab, $args )` and `MethodologyManageView::cancelUrl( $mtab )` to build in-tab links and the Save/Cancel target.

The REST side has a matching base: extend `TT\Modules\Methodology\Rest\AbstractMethodologyRestController`, set `restBase()` (e.g. `methodology/formations`) and implement the five CRUD callbacks. The base wires the `tt_edit_methodology` permission callback, club scoping and the standard JSON envelope. `PrinciplesManageTab` and `PrinciplesRestController` are the reference implementations to copy.
