# My Journey event labels no longer leak English (#1818)

The player journey timeline now shows event-type labels (Position changed,
Trial ended, Injury started, …) and the filter chips in the active
language. On Dutch installs they render in Dutch instead of English: the
view resolves each label through the lookup translator, and a migration
seeds the Dutch journey labels into the translation store.
