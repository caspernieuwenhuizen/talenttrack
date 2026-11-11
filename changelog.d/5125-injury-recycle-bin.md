# Injury reads honour the recycle bin, like every other entity (#5125)

Every read of a player's injuries now goes through the same archive rule as the other records: an injury in the recycle bin comes back from no list, count or lookup, while archived injuries still show where they do today. This is hardening; nothing visible changes, because no current screen moves an injury to the recycle bin.
