# Link goals to a PDP conversation — the "combine" (#1853)

Bump: minor

Goals and the PDP cycle are now genuinely linked, not just co-located. On the development-talk form, a coach ticks **Goals discussed in this talk** from the player's active goals; on *My PDP*, each conversation card shows a **Goals discussed** list so the player's self-review reflects on the goals that were actually covered. Built on the existing `tt_goal_links` table (a new `pdp_conversation` link type — no schema migration; the methodology-link sync is scoped so it can't clobber the conversation links), with repository methods + REST handling on the conversation PATCH (coach-only, and the goal set is validated to belong to the player). Phase 5 of the development-hub epic (#1846); supersedes the POP linkage in #1717. Turning an agreed action into a brand-new goal is a planned follow-up — this slice is the read/link connective tissue.
