# Threads API names its thread types and explains a wrong one (#3674)

The threads endpoints now declare their arguments, so an API client can see that a thread belongs to a goal, a player or a blueprint, and that a message needs a `body` and takes a `public` or `private_to_coach` visibility. Asking for a thread type that doesn't exist, such as `team` or `activity`, still answers 400, but the error now lists the valid types instead of a bare "invalid parameter". The thread routes are documented in the REST API reference.
