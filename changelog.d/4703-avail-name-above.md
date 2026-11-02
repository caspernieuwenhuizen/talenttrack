# Match prep availability: the name stays above the status buttons at every width (#4703, #4704)

On a desktop the match prep availability step put the player's name beside the five status buttons. Inside the wizard frame that left each button about 67px, so short Dutch labels such as "Aanwezig" and "Geschorst" broke mid-word, and the "Check availability" marker ran under the first button. The name now sits above the buttons at every width, as it already did on a tablet, so each button gets the full row. A label now breaks only when a word cannot fit at all, without automatic hyphenation.
