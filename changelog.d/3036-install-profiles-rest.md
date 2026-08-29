# Install profiles over REST (#3036)

Install profiles are now readable and applicable through the API as well as from PHP: `GET /profiles` lists what ships and which one this install is on, `GET /profiles/{slug}` returns that profile with the full list of what applying it would change, and `POST /profiles/{slug}/apply` applies it, honouring a list of rows the caller chose to hold back.

The preview is a plain read of the same route the apply uses, so a front end that is not WordPress gets exactly the answer the plugin's own screens will get. Every route is gated on the capability that already governs the Modules page, and a request for a profile that does not exist comes back as a missing resource rather than a bad request.
