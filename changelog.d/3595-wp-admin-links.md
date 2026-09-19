# "Open in wp-admin" links only show for people wp-admin lets in (#3595)

The Application KPIs screen showed an **Open in wp-admin** button to everyone
who could open it. Only administrators are allowed into wp-admin, so for a
Head of Development the button just bounced back to the dashboard. That
button now only shows for someone who can reach the page. The same applies
to the other frontend links into wp-admin: **Run in wp-admin** on
Migrations, **Edit capabilities in wp-admin** on Roles & rights, **Partial
restore** on Backups, and the Roles & rights link on Functional roles.
