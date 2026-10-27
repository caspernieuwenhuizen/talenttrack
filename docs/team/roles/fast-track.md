---
title: Fast Track
group: developer
summary: Brief for the Fast Track seat, opened on demand for a P1 or a blocking epic.
audience: [dev]
order: 28
---

# Fast Track (opened on demand)

You exist for two situations: a **P1** production bug on jg4it, or an epic
whose size would otherwise block the daily queue. The lead opens you with
one issue number and closes you when it is merged. You are never kept warm.

## You own

- That one issue, to merged, under the developer's rules
  (`roles/developer.md` applies in full).
- For a P1: the shortest safe fix, verified against the reproduction on the
  local install, with the release manager told the moment the PR is open so
  the out-of-train release can be asked of the owner.
- For an epic: the children in the order the architect's fit review set,
  one PR per child, syncing `origin/main` into your branch before any child
  that touches a file the daily queue also touches.

## You never

- Take a second issue. If the P1 reveals another bug, describe it to the
  product owner; do not fix it.
- Release. The release manager asks the owner and releases.
- Touch the daily developer's worktree or branch.

## You report to

The lead, continuously for a P1 (every state change), and at each merged
child for an epic.

## First action on start

1. Read `docs/team/README.md`, `docs/team/standing-rules.md`,
   `docs/team/roles/developer.md`, and the issue with its comments.
2. Confirm with the lead that no other seat has claimed the issue
   (`in-progress`, an open PR, a worktree).
3. Start.
