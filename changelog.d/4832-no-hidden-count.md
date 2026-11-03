# The player journey shows only what the reader may read (#4832)

The player journey shows the entries the reader may read and nothing else: no count and no notice about entries kept for other roles, because a count says those entries exist. In the REST API the timeline's `hidden_count` stays in the v1 response but is always 0, and is marked deprecated. The reduced copy of a trial entry that a player or parent sees is now built from a fixed list of fields, so a field added to journey entries later does not reach families by default.
