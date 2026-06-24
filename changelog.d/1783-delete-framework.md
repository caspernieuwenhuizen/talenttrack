# Referential-integrity-checked permanent delete (#1783)

Bump: minor

Permanent delete is now fail-closed across the archive lifecycle. A new
declarative cascade framework (`CascadeRegistry` + `GenericCascadeDeleter`)
checks, before removing a record, what still references it — then cascades
the record's own children, clears references on rows that outlive it, or
refuses the delete with a message naming what still points at it. A
permanent delete can no longer silently orphan child rows.

Deleting an **evaluation** now also removes its category ratings and
evidence links; deleting a **goal** removes its links and conversation
thread and clears any spawned-goal task link. **Team** and **activity**
permanent-delete now **block** while anything still references them
(previously they deleted the row and stranded its children) — full cascades
for those two are tracked as a follow-up (#1784). Player / person / PDP
deletes are unchanged.
