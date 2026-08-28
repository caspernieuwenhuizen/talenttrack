---
title: Evaluation categories & weights
group: performance
summary: Main categories, subcategories, and how per-age-group weighting works.
audience: [admin]
views: [eval-categories, eval-category-weights]
module: TT\Modules\Evaluations\EvaluationsModule
capability: tt_view_category_weights
order: 20
---

# Evaluation categories & weights

## Main categories

The four seeded categories are **Technical**, **Tactical**, **Physical**, **Mental**. These are the default buckets your coaches rate players against.

You can add more, rename, reorder, or deactivate them on the **Evaluation Categories** page. Each category has a display order and an active/inactive flag.

## Subcategories

Each main category can have subcategories. Example: Technical → Passing, First touch, Shooting, Dribbling.

When a coach records an evaluation, they can rate the main category directly OR drill into subcategories for a granular assessment. If they rate subcategories, the main-category score becomes the weighted average.

## Category weights per age group

Open **Evaluation categories** and choose **Per-age-group weights**.

The weights screen lets you specify how much each main category counts toward a player's overall rating, **per age group**. For example:

- U9: Technical 40%, Tactical 20%, Physical 20%, Mental 20%
- U17: Technical 25%, Tactical 30%, Physical 25%, Mental 20%

Rationale: younger players are evaluated more on technique and less on tactical reading; older players shift toward tactical and physical maturity.

Weights must sum to 100% per age group. The total is shown as you type and Save stays unavailable until it reaches 100, so a set that does not add up cannot be stored.

An age group you have never configured shows **Equal weights** and counts every category the same. That is a working state, not a missing one — nothing is broken if you never set a single weight. **Reset to equal** puts a configured age group back to it, and the difference between the two is preserved: "equal because that is the default" and "equal because somebody chose it" stay distinguishable.

The system computes each player's overall rating using the weights matching their team's age group.

## Consequences of changing weights

Weights affect the **overall rating** shown on rate cards and the headline cards. Individual category scores are unaffected. Historical evaluations aren't re-rated on disk — overall is computed on read.
