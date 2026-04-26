<!-- audience: user -->

# Evaluations

An **evaluation** is a coach's rated assessment of a player on a specific date, across the configured [evaluation categories](?page=tt-docs&topic=eval-categories-weights).

## Creating an evaluation

From the **Evaluations** admin page or the coach frontend dashboard:

1. Pick a player.
2. Pick an evaluation type (Training, Match, Tournament, etc.).
3. Pick a date.
4. For each main category, assign a rating on your configured scale (default 1–5).
5. Optionally drill into subcategories for more granular scoring.
6. Add free-text notes about what you saw.
7. If the evaluation type is configured as "Match", also fill in opponent, competition (dropdown of competition types configured under Configuration → Lookups), result, home/away, and minutes played.
8. Save. The form briefly confirms the save and you're returned to the dashboard tile grid — pick the Evaluations tile again if you want to add another.

## Rating a category

You can rate at either level:

- **Main category only** — e.g. "Technical: 4" — fine for quick assessments.
- **Subcategory breakdown** — e.g. "Technical → Passing: 3, First touch: 4" — the main category score becomes the weighted average of its subcategory scores.

Both work per evaluation. Mix freely.

## Viewing an evaluation

Click the evaluation row to see the full breakdown — categories and ratings rendered as a radar chart, notes in context, metadata (who coached, when).

## Archiving

Evaluations can be archived (hidden from lists but present for aggregate stats) or deleted. Aggregate queries on the Rate Card page automatically exclude archived evaluations.
