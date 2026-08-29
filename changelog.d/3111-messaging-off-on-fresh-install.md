# A new academy starts with every message switched off (#3111)

Bump: minor

Installing TalentTrack for the first time no longer starts sending mail to
parents, players and staff before anybody decided that it should. A fresh
install now begins with every message type on **Configuration → Messages**
switched off, and the setup wizard is where an academy chooses what it
sends. The invitation email is unaffected — it is account plumbing and sits
outside the switch (#3110), so a new install can still onboard people.

**Existing academies are not affected.** Upgrading changes nothing: every
message that was being sent before is still being sent afterwards. The new
default is written once, at first activation, so it applies to
installations created from this release onward and never retroactively.

That also preserves the rule about later releases. This setting stores the
list of message types you have switched *off*, so a type shipped in a
future release is on nobody's off-list and lands enabled on every install
that already existed — while a fresh install seeds its own off-list from
the message types that exist at the moment it is activated.

The shipped template list moved to `Comms\Template\TemplateCatalog`, which
is readable without the plugin having booted. Activation runs long after
`init`, so seeding from the runtime registry would have written an empty
set — and failed silently, leaving a new install sending everything.
`TemplateSwitch::isEnabled()` is untouched.

The honest trade-off: an academy that skips the wizard step sends nothing
at all, including about a cancelled training. #3113 is what makes that step
hard to skip by accident.
