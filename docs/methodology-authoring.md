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

## Shipped vs club-authored

Shipped principles curated by TalentTrack are **read-only** here — they show a "Shipped" badge and no edit or delete action, so you can't accidentally break the reference content. Club-authored principles are fully editable and deletable.

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

Every route requires the `tt_edit_methodology` capability and is scoped to the current club. Multilingual fields (`title`, `explanation`, `team_guidance`, `line_guidance`) accept and return an `{ "nl": "…", "en": "…" }` shape.

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
