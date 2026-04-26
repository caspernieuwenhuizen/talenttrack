# Development management

The Development tile group turns "we should fix that" into a real `ideas/NNNN-…md` file in the talenttrack GitHub repo, without anyone leaving the dashboard.

Anyone except players and parents can submit. The lead developer (administrator) reviews submissions and either rejects with a note or **approves & promotes** — the plugin assigns the next free `#NNNN`, commits the file straight to `main` via the GitHub REST API, and writes the commit URL back onto the staging row.

## The flow, end to end

1. **Submit.** Coach / Head of Dev / Club Admin / Scout / Staff / Observer / admin opens the **Submit an idea** tile. Title + freeform body + a type (`feat` / `bug` / `epic` / `needs-triage`). Status starts as **Submitted**.
2. **Refine.** Admin / Head of Dev / Club Admin opens the **Development board** (kanban). They edit title / body / slug, set the type, optionally tag a player, team, or development track, and move the card to **Refining** or **Ready for approval**.
3. **Approve.** Administrator opens the **Approval queue**. Two actions per card: **Approve & promote** (commits to GitHub) or **Reject with note** (sends a note to the author by email).
4. **Promote.** On approve the plugin lists `ideas/`, `specs/`, and `specs/shipped/` on GitHub, allocates the next `NNNN`, and `PUT`s `ideas/NNNN-<type>-<slug>.md`. The commit URL is stored on the row and shown on the refine view.
5. **Track.** Once shipped, mark the card **In progress** then **Done**. If the idea was tagged to a player, the **In progress** transition automatically spawns a goal in the Goals module linked to that player.

The author always sees a friendly status: *In review*, *Accepted*, or *Not accepted*. Internal states like *Promoting…* and *Promotion failed* never leak to non-admins.

## Permissions

| Capability | Default grant |
| --- | --- |
| `tt_submit_idea` | Administrator + every TalentTrack role except Player and Parent |
| `tt_refine_idea` | Administrator + Head of Development + Club Admin |
| `tt_view_dev_board` | Administrator + Head of Development + Club Admin |
| `tt_promote_idea` | Administrator only |

Players + parents do not see the Submit-an-idea tile at all — submission is a tool for staff and the admin team. The lead developer (admin) is the only one who can promote a row to a real GitHub file; rejections require the same cap.

## GitHub configuration

The promoter talks to the GitHub REST API using a fine-grained Personal Access Token. The token must live in `wp-config.php` so it never leaks into the database (and from there into backups, staging clones, and migration exports).

```php
define('TT_GITHUB_TOKEN',       'github_pat_...');             // required
define('TT_IDEAS_REPO',         'caspernieuwenhuizen/talenttrack'); // optional override
define('TT_IDEAS_BASE_BRANCH',  'main');                       // optional override
```

Token requirements:

- **Repository access:** the talenttrack repo only.
- **Permissions:** `Contents: Read & write`. Nothing else.

Until `TT_GITHUB_TOKEN` is defined, the **Approve & promote** button on the Approval queue is disabled with a tooltip and a banner. Submitting and refining still work; only the GitHub commit step is gated.

## Development tracks

Tracks are an admin-curated list (e.g. *Speed*, *Game intelligence*) that ideas can optionally be tagged to. The **Development tracks** tile shows a per-track ordered list of every idea on that track with a status pill — useful as a player-development roadmap surface for ideas that go beyond a single bug fix.

Tracks are created and deleted from the same page; deleting a track detaches its ideas (it doesn't delete them).

## What happens when promotion fails

Network blip, rate limit, or revoked token — the row moves to **Promotion failed** with the error stored. The Approval queue surfaces these in a separate section with a **Retry promotion** button.

ID-allocation race: if a separate commit lands on `main` between the plugin's "list folders" call and its `PUT`, the `PUT` returns 422. The promoter automatically retries once with the freshly-fetched max + 1; a second collision in a row marks the row failed and surfaces the error.

## See also

- [Roles and permissions](access-control.md) — for the four `tt_…_idea` capabilities.
- [Goals](goals.md) — `In progress` automatically spawns a goal when an idea is tagged to a player.
