---
title: Standing rules and approvals
group: developer
summary: What the delivery team may do without asking, the three approvals that are only ever given by the owner in the responsible seat's own session, and how a standing rule is granted or withdrawn.
audience: [dev]
order: 21
---

# Standing rules and approvals

A standing rule is permission the owner gives once, written here, so a seat
that restarts knows it without being told. Anything not listed here is
asked. A seat that is unsure asks; the cost of a question is one message,
the cost of a wrong assumption on a live install is a pilot's trust.

## Granted (2026-10-26)

| Rule | Who uses it | What it covers | What it does not cover |
| --- | --- | --- | --- |
| Daily drain to merged | Developer | Taking the oldest `ready-for-dev` issue, building it in a worktree, opening the PR, merging it when every check-run has completed without failure (`CLAUDE.md` §7, drain semantics) | Releasing; touching an issue that is not `ready-for-dev`; editing the main checkout while the queue runs |
| Daily Academy HQ run | QA | Running the persona simulation against the local install and triaging findings into `from-hq` issues | Running the HQ against sto or jg4it |
| Verification walks | QA | Driving the local install, sto and jg4it headlessly as demo or read-only accounts to check a release | Any write on sto or jg4it beyond what the walked feature itself does as that persona |
| Shaping and queueing bugs | Product owner | Marking a bug `ready-for-dev` when its cause is located (file and line), the fix is single-path and acceptance criteria are listed (`CLAUDE.md` §7) | Queueing an enhancement, anything with options, or anything needing a wizard exemption; those go to the decision inbox |
| Filing issues and board cards | Every seat | Filing what it finds, searching the queue first so parallel seats do not duplicate | Closing someone else's issue without a merged `Closes #N` |
| Reading the live installs | Every seat | `GET` calls with the read-only credentials the owner provided | Any `POST`, `PUT`, `PATCH`, `DELETE` |

## Approvals that never leave the owner's hands

These are given by the owner, in the responsible seat's own session, and
never relayed by the lead or anyone else. A seat that receives one second
hand treats it as not given.

| Approval | Asked by | How it is asked | Why it is never relayed |
| --- | --- | --- | --- |
| **Every release** | Release manager | In the release manager's session, with the batch, the environments it will reach, the risk the release manager sees and QA's verification result. The release manager releases on the owner's yes and on nothing else. | A release reaches academies. A relayed yes cannot be checked against what was actually asked. |
| **Any write to a live install** | Whoever would make it | The permission rule prompts the owner for every `curl` write to a TalentTrack REST address on sto or jg4it. The seat shows the preview first. | A live academy sees the change immediately. |
| **Disclosure of a security finding** | Architect | Findings stay in a private GHSA draft; the owner decides when and how anything becomes public. | The repository is public; a public issue is a disclosure. |

### Releases: the standing yes

The owner approves every release until they are confident in the quality.
There is no fixed bar. The quality signals in the charter are what the owner
judges it on, and the lead keeps them in every weekly report. When the owner
feels ready they grant a standing yes for **patch** releases in the release
manager's session, and this file is updated to say so. The owner withdraws it
the same way. Minor and major releases stay on the owner's yes regardless.

## How a rule changes

A new standing rule, or a change to one, is a PR to this file, opened by the
lead after the owner has said it in the lead's or the responsible seat's
session, with the date and the owner's words quoted in the PR body. The rule
is in force when the PR is merged, not when it was spoken.
