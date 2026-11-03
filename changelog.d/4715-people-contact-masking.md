# People lists show "Hidden" for a guardian's contact (#4715)

A guardian's e-mail and phone on the people list, a person's page and the wp-admin people list now follow the same rule as the people REST routes: the guardian themselves and staff of one of their children see them; any other reader of people, such as the read-only observer, sees "Hidden" in their place. The list's rows carry the e-mail cell as `email_cell_html`, either the link or the "Hidden" marker, so the screen can say what is hidden without receiving it. Staff see what they saw before.
