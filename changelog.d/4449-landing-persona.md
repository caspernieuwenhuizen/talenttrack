# Shell navigation shows your role before you ever use the switcher (#4449)

The app shell's navigation shows your name and role at its foot. The role
used to appear only once you had picked one in the role switcher, so a head
coach who never switched saw a name with nothing under it. It now shows the
role your landing page is built for: the one you picked, or otherwise your
first role. The landing page, the coach's week, the tile grid and the
navigation now all read that role from one place, `PersonaResolver::landingPersona()`,
instead of three copies of the same fallback.
