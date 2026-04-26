<!-- audience: admin -->

# Custom fields

Need to track something TalentTrack doesn't ship with? Add a custom field.

## What you can add

Custom fields are additional data fields attached to core entities:

- Players (e.g. "Shirt size", "Emergency contact", "Parent email")
- Teams (e.g. "Home colour", "Kit supplier")
- Evaluations (e.g. "Pitch condition", "Weather")

Each field has a type: text, number, date, select (with options), or textarea.

## Creating a field

**Configuration → Custom Fields → Add new**

1. Pick the entity (Player / Team / Evaluation).
2. Label the field and give it a slug (auto-generated from the label, adjust if needed).
3. Pick the type.
4. If `select`, enter the options one per line.
5. Mark as required if needed.
6. Save.

The field automatically appears on that entity's edit form in the appropriate position.

## Display order

Custom fields are ordered by their `display_order` value within each entity. Currently editable only on the edit form; drag-reorder for custom fields is on the backlog.

## Export

Custom field values travel with the entity in CSV exports (when available) and are queryable via SQL.

## Archiving

Archived custom fields stop appearing on forms but existing values on historical entities are preserved.
