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
