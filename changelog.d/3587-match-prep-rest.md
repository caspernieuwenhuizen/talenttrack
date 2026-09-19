# REST: match preparation can be read back, and unknown fields are refused (#3587)

The match-prep endpoint dropped any field it didn't recognise and still
answered with a success. There was also no way to read a saved prep back.
Now:

- **`GET match-prep/{activity_id}`** returns the saved prep: squad (`availability`), line-up per half, formation, goals, per-player goals and roles.
- **`PUT` declares its fields and answers with the saved prep.**
- **`PUT` refuses unknown fields.** A field it doesn't accept is refused with `unknown_field`, naming the field, before anything is saved.

The prep screen and the API now read a prep through the same code.
