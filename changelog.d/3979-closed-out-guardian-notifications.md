# A closed-out child's family is no longer sent messages about them (#3979)

The parent e-mail step of the notification chain and the Comms recipient
resolver decided who a child's guardians were from the raw parent link, so the
family of a released, archived or binned child could still be sent messages
about them. Both now ask the same guardian resolver the dashboard and goal
threads use, and it names nobody once the child is closed out. The older
fallbacks — the legacy parent column and the guardian e-mail and phone fields —
follow the same rule. A child on trial is not closed out, so the trial welcome
still reaches their family.
