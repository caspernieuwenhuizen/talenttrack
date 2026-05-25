---
description: Drain the ready-for-dev GitHub issue queue sequentially in isolated worktrees. Executor skill — strict standards adherence, skip-on-ambiguity, never pauses for input.
disable-model-invocation: true
allowed-tools: Bash(gh issue *) Bash(gh pr *) Bash(gh repo *) Bash(git *) Bash(php *) Bash(ls *) Bash(cat *) Read Write Edit Grep Glob
---

# Drain the ready-for-dev queue

Drain the ready-for-dev queue autonomously.

## Queue source

```
gh issue list --repo caspernieuwenhuizen/talenttrack \
  --label ready-for-dev --state open --sort created \
  --json number,title,body,labels
```

For each item, oldest-first:

1. **READ** the entire issue body. The acceptance criteria are the contract — no more, no less.

2. **VERIFY** before you act. Every file path, function, class, capability, table, or column the issue mentions: grep that it actually exists in the current code. If anything doesn't match — DO NOT GUESS. Comment the mismatch on the issue and skip to next.

3. **ISOLATE** in a worktree at `C:/Users/caspe/AppData/Local/Temp/tt-<issue>-<slug>`, branched from main. Never edit the main checkout while the queue runs.

4. **IMPLEMENT** exactly what the body specifies. Don't:
   - add features the spec doesn't request
   - refactor adjacent code "while you're here"
   - add comments explaining what well-named code already says
   - bundle multiple issues into one PR
   - invent file paths, function names, capability names, or DB columns

5. **PATTERN-MATCH, don't invent.** If the fix needs a UI pattern, CSS class, helper, or shared component: grep the codebase for an existing one and mirror it. If no pattern fits and the spec doesn't define one, comment "no existing pattern matched — needs design input" and skip.

6. **STANDARDS are binding.** CLAUDE.md sections 1-9 apply on every PR:
   - §1 Player-centric
   - §2 Mobile-first
   - §3 Wizard-first
   - §4 SaaS-ready
   - §5 Two-affordance nav (breadcrumbs + `tt_back` pill only)
   - §6 Save + Cancel via `FormSaveButton` on every record-mutating form
   - §7 Analyst/executor convention (you are the executor)
   - §9 Definition-of-done checklist — every applicable box ticked in PR body

   DEVOPS.md ship-along rules + tag/release flow apply.

7. **TESTS + LINTING must pass.** If a pre-commit hook fails: fix the root cause and create a NEW commit. Never `--no-verify`, never `--amend` a hook failure.

8. **VERSION bumping** follows SemVer per CLAUDE.md / #877:
   - **patch** (4.0.x) — bug fixes + small enhancements within the current minor
   - **minor** (4.x.0) — a feature epic lands
   - **major** (5.0.0) — operator-visible breaking change (DB / REST / cap matrix)

   Don't reflex-bump patch on a feature ship. Don't bump major casually.

9. **PR FORMAT**: title mirrors the issue. Body MUST contain `Closes #<num>` so the issue auto-closes on merge. Body MUST tick every applicable §9 DoD checkbox. No `Co-Authored-By` trailers, no robot footers, no AI fingerprints.

10. **MERGE** once CI is green, tag the release, clean up the worktree, move on.

## Ambiguity → comment + skip

Never pause the queue. Triggers:

- acceptance criteria unclear or self-contradictory
- file / function / class / cap / column referenced in body doesn't exist
- two reasonable implementations with different trade-offs
- schema change implied but no migration specced
- capability or auth choice not pinned down
- the issue depends on another issue that hasn't shipped yet

## Never, under any circumstance

- force-push, `git reset --hard`, `git checkout --`, `--amend`, `--no-verify`
- bypass the capability matrix or invent a new capability
- write a migration on the fly
- stop the queue waiting for user input — always skip + continue
- add a feature flag, fallback, or backwards-compat shim the spec didn't ask for

## End of run

Print one summary block:

```
SHIPPED: <issue#> <title> → <PR URL> → <version tag>
SKIPPED: <issue#> <title> → <reason, one sentence>
```

Stop. The user reviews the summary and addresses skips in a follow-up session.

## Optional argument

The user may pass an override like `/drain-queue ship #901 first`. Honor explicit per-run overrides; otherwise default to oldest-first.
