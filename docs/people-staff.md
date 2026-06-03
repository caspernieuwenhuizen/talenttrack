<!-- audience: admin -->

# People (staff)

The **People** page is your roster of non-player humans: head coaches, assistant coaches, physios, goalkeeper coaches, team managers, board members.

## Why People is separate from WP users

A **person** is a real-world role at the club. A **WordPress user** is a login account. They are related but not the same:

- A head coach might be a person AND a WP user (so they can log in and evaluate).
- A physio might be a person WITHOUT a WP user (they never log in).
- A WP admin might be a user WITHOUT a person record (they only maintain the system).

Link them when both exist. The link powers things like "coach X can see team Y" and "who coaches this team".

## Functional roles

Each person can have one or more functional roles like **Head coach**, **Assistant coach**, **Physio**. These map to authorization roles via the [Access control](?page=tt-docs&topic=access-control) page — so granting someone the Head coach functional role can automatically grant them the capabilities needed.

## Assigning people to teams

From the **Teams** edit page, add a person with a functional role. The person can be on multiple teams (an assistant coach who assists two age groups, for example).

## Archiving

When staff leave, archive them. Archived people disappear from team assignment dropdowns but historical records stay intact.

## Permanent delete (clean-up)

To remove a person permanently and clean every reference to them in one go, open the **People** admin page (`wp-admin → TalentTrack → People`), select the rows you want to delete, choose **Delete permanently** from the bulk-action dropdown, and click **Apply**.

Before anything is written, an **impact preview dialog** shows you exactly what is about to happen, per selected person:

- **Removed**: team assignments, functional-role scope grants, staff-development entries, certifications, staff evaluations, staff goals, mentorship pairings, and pending invitations.
- **Cleared (parent row stays)**: "granted by" attribution on scope grants made by this person, accepted invitations targeting this person (the historical record stays; the target reference is nulled), and player records that listed this person as parent contact.

If you confirm, a single database transaction runs the cleanup; if any step fails, the whole batch rolls back — partial deletes are impossible.

For batches of 3+ persons OR any person with 5+ affected references, a second step asks you to type **DELETE** to confirm — protection against accidental clicks on large clean-ups.

**WordPress user accounts are NOT touched.** If a deleted person also had a WP login, the account remains and can still sign in. Delete it separately via the WordPress **Users** admin if you want them locked out.
