# A migration archive imports back into the install it came from (#5041)

Importing a migration archive into the same install it was exported from no longer rolls back. A record imported as new now gets a fresh identifier when the one in the archive is already in use on this install. An identifier that is still free is kept, so a record moved to another install keeps its identity.
