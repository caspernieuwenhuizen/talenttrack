# Report Studio is closed to the read-only observer (#4695)

The read-only observer no longer reaches Report Studio:
- every Studio route answers it 403;
- its "Shared with me" list shows no Studio report and the reader's view opens none;
- a report cannot be published to the observer role.

Team managers, whether they hold the persona or the Manager role on a team, keep reading the reports published to them. Builders are unchanged. Previewing a single block is now part of building, so it needs the build right.

On update:
- team managers get their Studio read right, unless your academy already set that permission;
- any Studio permission on the observer is removed;
- any publication to the observer role is withdrawn. A report also published to other roles stays published to them.
