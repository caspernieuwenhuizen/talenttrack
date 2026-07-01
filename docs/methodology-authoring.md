<!-- audience: admin, developer -->

# Authoring the methodology library (frontend)

Academy editors can author methodology content directly from the frontend — no wp-admin needed. This surface is the counterpart to the read-only [Methodology](methodology.md) library.

## How to find it

Open the **Methodology** tile in the **Reference** group. If you have the editing capability you'll see a **Manage methodology** button in the header; it opens the authoring surface. You can also go straight there with `?tt_view=methodology&mode=manage`.

Only users with the `tt_edit_methodology` capability (academy admin, head of development, administrators) can reach the manage surface. Everyone else sees a "not authorized" notice.

The surface always offers a **View published methodology** link back to the read view, plus the standard breadcrumb trail.

## Entity tabs

The manage surface is tabbed by methodology entity, mirroring the read view. Each tab is a self-contained authoring page — a list of that entity's records with a "+ New …" button, edit and delete row actions, and a flat create/edit form.

**Principles** and **Formations** are available now. Set-pieces, visions, the framework primer and the other entities are added in later releases; each appears as its own tab as it ships.

## Editing a principle

A principle carries:

- **Code** — the short reference like `AO-01`.
- **Team-function** and **team-task** — the framework categories the principle belongs to.
- **Title**, **Explanation** and **Team-level guidance** — each with side-by-side **Dutch (NL)** and **English (EN)** inputs.
- **Per-line guidance** — a Dutch and English note for forwards, midfielders, defenders and the goalkeeper.

Fill in Dutch first; English is optional and falls back to Dutch when a viewer's language is English but no English text was supplied. Save and Cancel sit together at the bottom of the form — Cancel returns you to the list (or to wherever you came from).

Deleting a principle removes it permanently and asks for confirmation first.

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

## REST API

Everything the manage surface does is also available over REST, so a future non-WordPress front end gets the same behaviour:

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles` | List principles (club-scoped). |
| `POST` | `/wp-json/talenttrack/v1/methodology/principles` | Create a club-authored principle. |
| `GET` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | One principle, with Dutch + English values. |
| `PUT` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Edit a club-authored principle. |
| `DELETE` | `/wp-json/talenttrack/v1/methodology/principles/{id}` | Delete a club-authored principle. |

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
