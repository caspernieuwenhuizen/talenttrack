# Match prep on a plan without it shows one breadcrumb, not two (#4284)

When the club's plan did not include match prep and the match already had a
prep, the screen drew a short "Dashboard / Match prep" breadcrumb and back
pill above the upgrade panel, and then the full "Dashboard / Activities /
<match> / Match prep" chain and a second pill below it. It now draws the one
full chain, then the upgrade panel, then the read-only plan. Plans that
include match prep, and a match with no prep yet, are unchanged.
