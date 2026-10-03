# Media consent leaves the players list (#4196)

Bump: patch

The players list no longer has a Media consent column or a Media consent filter, on desktop or on a phone. Consent is read on the player's profile, on the identity card and above the Media tab. For a whole squad, use the Dossier completeness page and the *Pictures on file with no consent* alert. A saved view that used the Media consent filter still opens, with that filter left out. `GET /players` is unchanged: it still returns the consent fields and still accepts `filter[media_consent]`.
