# PDP files archive like everything else, instead of being deleted outright (#3300)

A PDP file's detail page carried a red **Permanently delete PDP** button — on a
live, in-progress development file for a minor, as the loudest element on a
page whose actual purpose is recording a verdict. It was the only record type
in the plugin that worked this way.

The button is gone. A PDP file now archives like a player, a team or a goal,
and permanent deletion lives where it does for everything else: in the recycle
bin, once the file is already out of the way, behind the recycle-bin
permission.

The underlying reason it was different: PDP had never been registered with the
recycle bin at all, so an archived file appeared nowhere, could not be
restored, and was never picked up by the retention cleanup. It is registered
now — listed under **PDP file**, and named by player and season so you can tell
whose file you are looking at before you act on it.

Permanently deleting a PDP file still removes its conversations, verdicts,
uploaded files and goal-evidence links.
