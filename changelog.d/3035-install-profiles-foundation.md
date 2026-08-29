# Install profiles: Basics and Full academy (#3035)

Bump: minor

An install can now be described by a named profile instead of fifty separate module decisions. Two ship: **Basics**, which keeps the development loop — players, teams, people, evaluations, goals, activities, measurements, the journey and the reports that read them back — and switches off match day, training plans, the knowledge library, the integrations and the developer surfaces; and **Full academy**, which is everything the plugin ships and is what an install gets when no profile is chosen.

Two choices inside Basics look like mistakes and are not. Analytics stays on, because the reports and the dashboard figures read the analytics engine directly and only the separate explorer surface is switched off. Communication stays on, because that is what invitations and account mail travel over; only its two cost-bearing extras go.

A profile is an association rather than a copy: the install remembers which one it is on and works out afresh how far it has drifted, so switching something back into line clears the drift immediately. Applying a profile never deletes data — a module it switches off keeps every row it owns — and it never overrules the plan an install is on: anything above the entitlement is reported as skipped, with the reason, instead of being switched on and failing later.

This ship is the mechanism only. Nothing on screen changes yet: the Modules-page strip, the preview-and-confirm screen and the Setup-wizard step follow.
