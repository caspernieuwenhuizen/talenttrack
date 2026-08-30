# Potential rating can now be switched off, like behaviour rating (#3243)

Bump: minor

Behaviour rating has been switchable since v3.x; potential never was. An academy
that does not work in potential bands was still shown a **Set potential** button
on every player profile and a potential form on the capture screen, with no way
to stop it.

**Modules & features** now has a **Potential rating** switch alongside Behaviour
rating. Turning it off hides the profile affordance and the potential half of
the capture screen, and stops the API accepting new bands.

**What you already recorded stays.** The band on a player's profile and the
potential history behind it remain exactly as they were, and reappear in the
forms if you switch it back on. Off means stop asking, not hide the record.

**The reminder follows the switch.** The *Potential not revisited* alert goes
quiet when the feature is off, so you do not also have to find the alert screen
to stop being nagged about work you have deliberately stopped doing.

Switching capture off does **not** by itself remove potential from the
traffic-light status — that stays a separate choice on the player-status
methodology screen, because an academy may stop recording new bands while still
wanting the last one to count. The documentation now sets out all three switches
and what each one does.
