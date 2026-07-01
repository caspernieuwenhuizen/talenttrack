# Help buttons now hide when the Documentation module is disabled (#2186)

Bump: patch

The contextual **Help** buttons (on goals, wizards, and anywhere else that
uses the shared help-drawer trigger) now render only when the **Documentation**
module is enabled under Configuration → Modules. Disabling the module removes
the buttons everywhere, matching the promise that a disabled module leaves no
dangling entry points. The gate reads the same module-state registry the
Modules admin page writes — no hardcoded check — and never fatals if the
disabled module class isn't loaded.
