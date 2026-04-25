<!-- type: feat -->

# Goals as a conversational thread — coach + player + parent dialogue

Origin: post-#0019 v3.12.0 idea capture. Today, a goal is a single record: title, description, status, due date. The data model treats it as a static checklist item. In reality, a goal is a *conversation* between coach, player, and (often) parent: the coach proposes a focus area, the player reflects on progress, the parent asks a clarifying question, the coach revises the target. Today none of that lives in the plugin — it happens via WhatsApp, email, or hallway chats, and the goal record stays a stale title.

This idea proposes turning a goal into a thread: comments + status changes + reflections, all attached to the goal record.

## Why this matters

- **Goals are central to a development plan** — without dialogue, they reduce to checklist items the coach maintains. With dialogue, they become a living development conversation owned across all three sides.
- **Audit trail value** — capturing "who said what when" against a goal record is enormously useful for HoD review, parent transparency, and trial-decision context.
- **Cross-feature win** — a thread primitive built for goals is the same primitive needed for #0017 (trial decisions), #0014 (scout reports), and any future review/comment surfaces.

## Working direction

**Defer to #0022 (Workflow & Tasks Engine).** This idea is a candidate first consumer of #0022's primitives once they exist — specifically, threaded comments on entity records. Building it before #0022 ships would mean either:

- Bespoke comment infrastructure that #0022 later replaces (rework cost), OR
- Comment infrastructure shoehorned into a generic shape that misses #0022's eventual design.

Better to wait until #0022 Phase 1 lands and then build #0028 on top of it as a clean consumer. This idea exists primarily to capture the requirement so it isn't forgotten.

## Open questions to resolve before shaping

1. **Notification rules.** When the coach comments, who gets pinged? Player by default, parent if linked, HoD if the goal is overdue? Configurable per-club?
2. **Visibility tiers.** Are all comments visible to all three roles, or are some "coach-only" / "coach + HoD only"? Trade-off: simpler model vs. coach freedom to take notes.
3. **Status changes inline.** Should "moved to In Progress" appear in the thread as a system message? Probably yes — the timeline value is enormous.
4. **Reflections from the player side.** Distinct format from comments? (Eg. "weekly reflection" prompts the player gets nudged into.) Or just normal comments tagged?
5. **Editing / deletion.** Soft-delete with audit log entry? Or immutable thread once posted?
6. **Mobile UX.** The thread surface is the most-used surface on a tablet/phone. Card layout, swipe-to-dismiss, etc. — needs design pass.

## Touches (when shaped, AFTER #0022 ships)

- New table: `tt_goal_comments` (or rather: `tt_thread_messages` if #0022 generalizes it, with goal as one entity_type among many).
- Goal detail view (#0014 territory or its own): renders thread + compose box.
- REST endpoints for posting + listing comments.
- Notification wiring via #0022's notification primitive.
- Documentation update.

## Sequence position (proposed)

Wait for #0022 Phase 1 to ship. Insert as one of the first consumers of #0022's thread/notification primitives, validating that the primitives are well-shaped. Don't shape this until #0022 has shipped enough of Phase 1 that the primitives are real.
