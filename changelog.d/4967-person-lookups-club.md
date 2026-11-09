# Person lookups follow the current club (#4967)

The permission checks remember a user's person record and grants for the length of a request. Work that walks every club in one go, such as the scheduled tasks, now has those answers read again for each club, and again when it returns to the first one. Team assignments are also read only from the current club's records.
