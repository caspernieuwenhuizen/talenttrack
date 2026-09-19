# No-guardian-contact alert no longer skips players whose parent invitation was never sent (#3657)

The "Player with no guardian contact" alert used to leave a player out as soon as a parent invitation existed for them, even one that was created and never mailed. When such an invitation expired, none of the invitation alerts reported the player either, so a family nobody had asked stayed invisible. Only an invitation that was actually sent now hands the player over to "Parent invited but never activated".
