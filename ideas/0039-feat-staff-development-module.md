<!-- type: feat -->

# Staff development — personal goals + evaluations + certifications for coaches and other staff

Same primitives the players module uses (goals + evaluations), applied to the people behind the player surface: head coaches, assistant coaches, scouts, team managers, physios, club admins. The product builds a player-development pipeline already; the staff that runs the pipeline gets the same shape.

Decision locked during the 2026-04-27 review session: this is **personal-development-for-staff**, not setup-wizard-for-new-staff. The latter overlaps with the existing Setup Wizard (#0024).

## Why this is its own module

Today: TalentTrack tracks players in detail (goals, evaluations, attendance, methodology) and tracks staff only as `tt_people` rows with a name + email + functional-role assignment. There's no surface for "how is the head coach of U17 developing as a coach this season?". A club that takes coach development seriously can't use TalentTrack for that side of the work; they bolt on a spreadsheet.

The fix is a parallel surface to the player module:

- **Staff goals** — annual or quarterly goals per staff member ("Take an UEFA-B course", "Run two formal video-review sessions with U15 by Christmas", "Mentor the new assistant"). Same lifecycle as `tt_goals` (pending / in-progress / completed / archived).
- **Staff evaluations** — peer / self / HoD evaluations of staff. Same shape as `tt_evaluations` but scoped to a staff person rather than a player.
- **Certifications register** — UEFA-A, UEFA-B, first-aid, GDPR training, child-safeguarding course. Each row carries an issuer, an issued-on date, and an expires-on date. Renders an alert when something is within 90 days of expiry.
- **Personal development plan** — bullet-list narrative of where the coach is + where they're going. Replaces the spreadsheet.

## Personas + access

| Persona | Reads | Writes |
| - | - | - |
| Each staff member | Their own goals / evals / certifications / PDP | Their own goals + PDP |
| Head of Development | All staff | All staff |
| Functional Role: Mentor (new) | Their mentees only | Their mentees' goals + evals |
| Academy Admin | All | All |
| Coach (other) | None | None |

The new "Mentor" Functional Role is the interesting bit — it lets a senior coach or external advisor act on a specific person's record without becoming an academy_admin. Hooks into #0033's authorization matrix naturally.

## Why this isn't just `tt_goals` with `target_type = 'staff'`

Tempting shortcut. But:

- Staff goals have **certifications** baked in — not a player concept.
- Staff evaluations have **categories** that don't overlap with the player evaluation tree (player tree is "speed", "passing", "reading the game"; staff tree would be "communication with parents", "session planning", "tactical analysis").
- Staff PDPs have a **narrative** field that the player goals UI doesn't surface.
- The cap matrix differs (mentor row above) — overloading `tt_goals` would force the resolver to special-case the goal subject.

A separate `tt_staff_goals` + `tt_staff_evaluations` + `tt_staff_certifications` + `tt_staff_pdp` is cheaper than the special-cased shortcut.

## Open questions (for the shaping pass)

1. **Goal cadence — annual, quarterly, or both?** Player goals are quarterly via #0022. Staff goals could be annual (matches a season) with optional mid-season check-ins, OR quarterly (matches the existing workflow cadence). Recommend annual + a `?check_in=mid_season` URL param for the workflow integration.

2. **Evaluation reviewer — who evaluates whom?** Three flavors:
   - **Self-eval** (staff evaluates themselves once per season).
   - **Top-down** (HoD evaluates each staff member once per season).
   - **Peer** (assistant coach evaluates head coach, vice versa). Optional / opt-in.
   Recommend self + top-down for v1; peer deferred.

3. **Categories — share with players' tree or separate root?** Players have a deep evaluation category tree (#0008). Staff evaluations need their own categories. Recommend a separate `tt_eval_categories` parent (`is_staff_tree = 1`) so the existing tree UI works unchanged; categories are stored in the same table for consistency.

4. **Certifications — flat list, or one-row-per-cert-type lookup + assignment table?** A simple flat list (`tt_staff_certifications` with name + issuer + dates) is cheaper. A lookup-driven model (`tt_lookups[lookup_type=cert_type]` + `tt_staff_certifications` referencing it) lets admins say "everyone needs UEFA-B" centrally. Recommend the lookup-driven model.

5. **Workflow integration — which templates, if any?** `#0022` Phase 1 ships templates fan-out per active player. Staff equivalents:
   - Annual self-eval (every staff with a `tt_people.role_type` ≠ 'unknown' on Sept 1).
   - HoD review of every staff member, due 30 days after the staff's start-of-season anniversary.
   - Certification expiry warnings (90/60/30/0 days out).
   Recommend all three; small implementation cost, big admin value.

6. **Mentor functional role — automatic-grant or admin-grant?** Two options: any coach can be marked as a mentor for another staff member from the People page, OR the Functional Roles config seeds a "Mentor" role that admins can assign just like Head Coach / Assistant. Recommend the second — matches the existing FR pattern.

7. **PDP — single freeform text per person, or structured (strengths / development areas / actions)?** Recommend structured: three fields (`strengths`, `development_areas`, `actions_next_quarter`). Maps to how clubs already write PDPs in spreadsheets and gives the renderer something to show.

8. **First-load surface — where does it live?** Top-level tile under a new "Staff" group (parallel to "People")? Or absorbed into the existing People surface as additional tabs on the person edit form? Recommend new "Staff development" tile group: My PDP / My goals / My evaluations / My certifications for the staff persona, plus an academy-admin "Staff overview" rollup.

## Touches

New module: `src/Modules/StaffDevelopment/`
New tables: `tt_staff_goals`, `tt_staff_evaluations`, `tt_staff_certifications`, `tt_staff_pdp` (or one wide table — decide during shaping).
New Functional Role: `Mentor`.
Integrates with: `#0022` Workflow & tasks engine (template fan-out), `#0033` Authorization matrix (new persona scope: mentor → mentee), `tt_people` (existing staff records carry the FK).
Lookup additions: `cert_type` lookup if option 4 lands, `staff_eval_category` lookup roots if option 3 lands.

## Estimated effort (rough, pending shaping)

- v1 (goals + PDP + certifications, no evaluations): **~12-16h**.
- v1.5 add self + top-down evaluations: **+~10-12h**.
- v2 add workflow templates + mentor FR: **+~8-10h**.

Total: **~30-38h** if all of v1 + v1.5 + v2 ship; less if Casper picks just v1 first.

## Dependencies

None blocking. Lands cleanly on top of:
- `#0022` Phase 1 (workflow engine — templates ride on top).
- `#0033` (matrix gives us the mentor scope).
- Existing `tt_people` + `tt_functional_roles`.
