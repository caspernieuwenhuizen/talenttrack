<!-- audience: admin -->

# Player accounts

The **Player accounts** view (`?tt_view=player-accounts`) is where an
academy admin connects a player to a login on the site. It is the primary
way to give a player (or, through them, their data) an account;
invitations remain the secondary, self-service path.

Reach it from the dashboard tile **Player accounts**, or from the
**Player accounts** button on the Players list.

## What you see

A list of every player in your academy, each row showing:

- The player's name and photo (the row anchor) plus team and age group.
- An **account status**:
  - **No account** — nobody is linked yet.
  - **Invited (pending)** — an invitation has been sent but not accepted.
  - **Linked** — a WordPress account is connected (the account name is shown).

Filter by status, or search by player name, with the controls above the list.

## Linking and unlinking

- **Link an existing user.** On a *No account* (or *Invited*) row, pick an
  account from the **Choose account** dropdown and press **Link**. The
  dropdown only lists accounts that aren't already connected to another
  player or to a staff/parent record, so you can't double-book one login.
  Linking also grants that account the player role.
- **Invite instead.** Use **Generate invite / Share invite** on the same
  row to send the player (or their guardian) a self-service sign-up link.
- **Create a new account directly.** On the **Parent accounts** view, the
  *Create a new parent account* panel provisions a brand-new account
  (name + email), links it to the chosen player, and emails the person a
  **"set your password"** link — you never see or set a password. For the
  rare case where there's no usable email, tick *No usable email* to set a
  temporary password instead (share it securely). Every direct-create is
  audit-logged. Inviting remains the low-friction default; direct-create is
  the admin-convenience path.
- **Unlink.** On a *Linked* row, press **Unlink** and confirm. The player
  record stays; only the connection is removed. The player role is removed
  from the account **only** if that account isn't also linked to another
  player or to a staff/parent record — so unlinking a coach who once played
  doesn't strip their coach access.

## Why one account, one player

A login is connected to **at most one** player. The system enforces this so
a parent, player, or coach opening "their" record can never land on the
wrong child's data. If you try to link an account that's already in use,
the view tells you so rather than silently moving the link.

## Who can use it

Academy and club admins (the capability that also governs creating and
deleting player records). Coaches and scouts don't see the view or the
tile.
