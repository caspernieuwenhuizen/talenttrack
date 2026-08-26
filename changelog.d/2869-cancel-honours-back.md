# Cancel takes you back to where you came from (#2869)

Bump: patch

Pressing **Cancel** on a form sent you to a list rather than back to the record
you had opened the form from. Opening the attendance grid from an activity and
cancelling out of it dropped you on the activities list, leaving you to find that
activity again — on a phone at the side of a pitch, several taps from where you
were.

Cancel now returns you to wherever you opened the form from, whenever the plugin
knows. When it does not — you typed the address, or arrived from outside — it
still falls back to the sensible default: the record you were editing, or the
list you were adding to.

This is handled once, in the shared form component, so every form gets it,
including ones added later.
