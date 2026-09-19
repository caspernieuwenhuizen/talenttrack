# Activities list: a planned squad no longer counts as recorded attendance (#3585)

The activities list counted the planned squad as if it were the register. A
match nobody had registered yet showed "16 recorded, 16 present", and the
attendance filter put it under complete. The counts and the filter now look
only at attendance that was actually recorded.
