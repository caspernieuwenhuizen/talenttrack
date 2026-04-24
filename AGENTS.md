# Running Claude Code on TalentTrack — work instruction

A practical how-to for driving Claude Code with limited parallelism, written for a non-technical lead developer. The goal is quality first, parallel only when it's genuinely safe.

Keep this doc in the repo (e.g. at the root alongside `DEVOPS.md`). It's reference, not process ceremony.

---

## Part 1 — The default setup (one agent, one task, simple and safe)

This is how 80% of work should happen. You don't need more than this most of the time.

### One-time setup (a few minutes, only once)

Before the first session:

1. **Install Claude Code** — follow the install instructions at `docs.claude.com`. You'll end up with a `claude` command you can run in a terminal.
2. **Log in once** — `claude` will prompt for authentication the first time you run it.
3. **Pick a terminal app** — on macOS the built-in Terminal is fine; iTerm2 is nicer if you do this a lot. On Windows, Windows Terminal.
4. **Open the repo folder in that terminal** — `cd` into the TalentTrack folder.

That's it. You don't need to learn git commands, branch commands, or anything else up front — Claude Code handles those for you when you ask.

### The basic daily flow

1. **Decide what to work on.** Open `SEQUENCE.md` (the backlog sequencing doc in the repo root) and pick the next item.
2. **Open a terminal in the repo folder** and run `claude`.
3. **Tell Claude Code what to do.** Point at the specific idea file. Example prompts that work well:
   - *"Read `ideas/0015-bug-frontend-my-profile-undefined-method.md` and implement the fix. Follow the conventions in `DEVOPS.md`. Open a PR when done."*
   - *"Shape `ideas/0019-epic-frontend-first-admin-migration.md` into specs. Start with Sprint 1 only. Ask me whatever you need to clarify before writing code."*
   - *"Let's work on the next item from `SEQUENCE.md`. What do you think we should do?"*
4. **Answer its questions.** Claude Code will ask clarifying questions for bigger items. Answer in plain language — you don't need to be technical. "Yes" and "use your judgement, keep it simple" are legitimate answers when something isn't clear to you.
5. **When it says it's done**, ask to see what it did. *"Show me a summary of the changes."* or *"Open the PR on GitHub for me."*
6. **Review on GitHub.** You don't need to understand every line of code. Look for: does the PR title match what you asked? Does the description explain what was changed and why? Any obvious red flags in the file list (e.g., it modified files you didn't expect)? If anything looks off, ask Claude Code to explain or adjust.
7. **Merge.** *"Merge the PR and delete the branch."*

### What to say when you're stuck

These phrases all work:

- *"I don't understand what you just said. Can you explain it in simpler terms?"*
- *"Show me which files you changed and why."*
- *"Is this safe? Could this break anything?"*
- *"Don't merge yet — let me look at it first."*
- *"I made a mistake — can you undo the last thing?"*
- *"Stop, I want to think about this."*

Claude Code is not offended by any of these. It's the right way to stay in control.

### What to always do

- **One idea file at a time.** Don't bundle "fix #0015 and also #0008" in one session. You'll lose track.
- **Ask before merging anything big.** For a one-line typo fix, fine. For anything touching multiple files or a whole module, pause and review.
- **Let Claude Code run tests and linters itself** when they exist. *"Before opening the PR, run the test suite and make sure nothing's broken."*
- **Commit your work in progress.** If a session is getting long and you want to stop, say: *"Commit what you have so we can continue tomorrow."*

### What to never do

- **Never run a destructive command you don't understand.** If Claude Code proposes `rm -rf` something or `git reset --hard`, pause and ask *"what does this do, and is there a safer way?"*
- **Never approve a change that touches things you didn't ask about.** If you asked for a bug fix and the PR also deletes a module, stop.
- **Never work on two things in the same session.** One task, one PR. Start a new session for the next thing.

---

## Part 2 — When to consider a second agent

### The honest answer: rarely.

Most of the time, one agent working sequentially on one task is fine. The bottleneck in development isn't typing speed — it's decisions, review, and integration. Adding a second agent only helps if it's saving *your* time, not theirs.

A second agent is worth considering when **both** of these are true:

1. **There's a clearly independent task** — one that touches different files, different modules, different entities than the current main work. From the backlog: a bug fix, a docs/copy pass, an unrelated feature.
2. **You can handle reviewing two PRs at once.** If you're going to let the second one sit unreviewed for three days, there's no parallelism benefit.

### When a second agent is **not** worth it

- **The tasks share files.** Merge conflicts eat more time than the parallel agent saves.
- **The tasks share schema.** Two agents adding migrations collide on migration numbering.
- **You're not sure yet whether the first task is going well.** Finish one thing before starting another.
- **You're tired.** Two parallel streams of decisions is more exhausting than one. This is a real cost.

### Good candidate pairings from SEQUENCE.md

Picking pairings where file overlap is genuinely zero:

- **#0019 work (any sprint)** + **#0012 Part A (remove AI fingerprints from docs)** — the first is code, the second is mostly markdown. No overlap.
- **#0019 Sprint 2 (sessions/goals frontend)** + **#0010 (multi-language translations)** — different surfaces entirely.
- **Any bug fix (Phase 0)** + **any Phase 0 other bug fix** — three tiny bugs in one day if you want to burst through Phase 0.

### Bad candidate pairings

- **Two different pieces of #0019.** The whole epic is tightly coupled — Sprint 2 uses Sprint 1's foundation. Sequential only.
- **#0014 and #0017 in parallel.** #0017 depends on #0014's renderer. Sequential.
- **Anything + a schema-touching task.** Schema changes should always be a solo affair.

---

## Part 3 — How to actually run two agents (safely)

Claude Code has native support for isolated parallel sessions via a feature called *git worktrees*. You don't need to understand what a worktree is — just treat it as "a separate folder where a second Claude Code can work without bumping into the first one."

### One-time setup for parallel work

Add this line to the repo's `.gitignore` (ask Claude Code to do this once):

```
.claude/worktrees/
```

That's the only prep needed. Claude Code handles everything else.

### Running two agents — the exact steps

**Terminal 1** — the main agent, on the primary task:

```
cd /path/to/talenttrack
claude
```

Then in that session: *"Work on `ideas/00XX-...md`. Read `DEVOPS.md` first."*

**Terminal 2** — the parallel agent, on the independent task:

```
cd /path/to/talenttrack
claude --worktree fix-0015
```

The `--worktree fix-0015` flag tells Claude Code to create an isolated workspace named `fix-0015`. Use a short name that describes the task (e.g., `ai-fingerprints` for #0012 Part A, or `node20-bump` for #0008).

Then in that session: *"Work on `ideas/00YY-...md`. Read `DEVOPS.md` first."*

**That's the whole setup.** Both sessions can now run simultaneously without stepping on each other. Each works in its own folder, its own branch, and opens its own PR.

### The rules while both are running

1. **Don't switch prompts between sessions.** Terminal 1 is assigned to its task; Terminal 2 is assigned to its task. Don't paste the same question into both — you'll forget which is which.
2. **Label your terminal tabs.** Most terminals let you rename tabs. Name them after the task (`fix-0015` / `main`). Five seconds of effort, prevents a whole class of mistakes.
3. **Review in the order PRs arrive.** Don't try to hold both in your head. First one done, you review and merge; then the second.
4. **If one session asks a clarifying question, answer that one first before switching back.** A session waiting on input is blocked — don't leave it idle while you poke at the other one.
5. **Cap at two agents.** The docs suggest 3–5 is possible; for a non-technical driver, two is already plenty. Three is a job.

### When you're done with a parallel agent

In the second terminal, when the work is merged:

- *"The PR is merged. Clean up the worktree."*

Claude Code removes the workspace. Tidy.

---

## Part 4 — Things that are easy to get wrong, and how to avoid them

### 1. Not knowing what each agent is doing

**Symptom**: you finish a session, come back tomorrow, can't remember which agent was working on what.

**Prevention**: at the end of each session, say *"Before we stop, give me a one-line summary of what we did and what's next."* Keep those summaries in a notes file (paper or digital). Especially important with parallel agents.

### 2. Two agents editing the same file

**Symptom**: when you try to merge the second PR, git tells you there's a conflict. Claude Code can usually resolve simple ones, but it's annoying.

**Prevention**: before spawning the second agent, ask the first one: *"Which files will you need to edit for this task?"* Then when you start the second agent, check its task doesn't touch those files. This is especially important when both tasks touch the frontend or both touch PHP.

### 3. Schema changes in parallel

**Symptom**: both agents create a new migration file, both pick the next number (e.g. `0012_...`), second PR can't merge cleanly.

**Prevention**: never run two schema-changing tasks in parallel. Period. If the current main task involves a migration, second agents work on non-schema things (docs, copy, frontend, tests) only.

### 4. Review backlog piling up

**Symptom**: three PRs open, you haven't reviewed any of them, you can't remember what they were supposed to do.

**Prevention**: rule of thumb — no more open PRs than you've reviewed today. If you have two unreviewed PRs, do not open a third session. Review first.

### 5. Losing track because you got distracted

**Symptom**: you start two agents, step away for a meeting, come back two hours later, can't remember where each was.

**Prevention**: before any break longer than 15 minutes, in every active session, say *"Save a status update. Tell me where we are and what the next step is."* Claude Code will summarize. When you come back, read those before doing anything else.

### 6. The "just one more agent" trap

**Symptom**: things are going well with two agents, you think "let me add a third for a small task." An hour later you're stressed, confused, and have three half-finished branches.

**Prevention**: it's fine to want a third agent; do it *after* one of the first two finishes and merges. Three in flight simultaneously is where non-technical driving breaks down.

---

## Part 5 — The 90-second decision tree

Every time you sit down to work, run through this:

```
What's the current state?
  |
  +-- Something already in progress?
  |     +-- Yes → continue that session
  |     +-- No → go to next question
  |
  +-- Check SEQUENCE.md for next item
  |
  +-- Is it small and clear (a bug, a polish item)?
  |     +-- Yes → one agent, start working
  |     +-- No, it's big → shape it into specs first (one agent, spec only)
  |
  +-- Any zero-overlap item also waiting?
        +-- Yes AND I have time to review two PRs → consider a second agent
        +-- No / unsure → stick with one agent
```

When in doubt, one agent. The goal is shipped, high-quality work — not maximum parallelism.

---

## Part 6 — Phrases to paste into Claude Code

Copy-paste ready prompts for the most common situations. Adjust the `00XX` to the idea number you're working on.

**Start on an idea file:**
> Read `ideas/00XX-<filename>.md`. Work through it following the conventions in `DEVOPS.md`. Ask me questions before writing code if anything is ambiguous. Open a PR when the work is complete.

**Shape an epic into specs:**
> Shape `ideas/00XX-<filename>.md` into one or more specs under `specs/`. Do not implement yet. Ask clarifying questions for anything in the "Open questions" section before writing the spec.

**Check the sequence:**
> Read `SEQUENCE.md`. What should we work on next based on that document? Don't start yet — just tell me.

**Start a parallel independent task (in Terminal 2 with `claude --worktree <name>`):**
> Read `ideas/00XX-<filename>.md`. This is independent work happening in parallel to other work on the main branch. Follow `DEVOPS.md`. Do not touch any files outside the scope described in the idea file. Open a PR when done.

**Ask for a safety check before merging:**
> Before we merge, summarize: (1) what was changed, (2) which files were touched, (3) anything I should test manually, (4) anything risky or non-obvious in this change.

**Pause mid-session:**
> We're stopping for now. Commit what you have with a clear WIP commit message. Summarize the state of the work and what comes next, so I can pick it up tomorrow.

**Clean up after merge:**
> The PR was merged. Clean up the branch and the worktree if there is one.

**When you're unsure:**
> I'm not sure what to do here. Walk me through my options in non-technical terms, including the trade-offs.

---

## When to update this document

Update `SEQUENCE.md` when backlog priorities change. Update this work instruction (`AGENTS.md` or `CONTRIBUTING-claude.md`, call it what you prefer) when:

- You find a phrase or workflow that works well and you want to remember
- You find a trap you fell into, so future-you (or another human lead) avoids it
- Claude Code ships a feature that changes the ergonomics (worktree improvements, new flags, etc.)

Keep it pragmatic. This doc is for you, not a corporate wiki.
