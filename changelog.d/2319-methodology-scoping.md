# Methodology sets — content scoped to the active set (#2319)

Bump: patch

The methodology library, its repositories and the authoring REST endpoints now read and write within the active methodology set (epic #2316). A new ambient `MethodologyScope` — parallel to how club tenancy already works — makes every list read and every create resolve to the install's active set by default, so the read view shows one methodology at a time and new content is stamped into it. REST callers can scope to a specific set with an optional `methodology_id` query param. With a single set installed there is no visible change; it's the switch that lets two methodologies coexist without their content bleeding together.
