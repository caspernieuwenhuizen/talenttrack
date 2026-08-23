# Photo capture will not send anything until you say where (#2695)

Bump: minor

Photo-to-plan capture used to have a working default endpoint. An install that
had merely switched the feature on was already able to send photographs taken at
a youth academy to a destination nobody had consciously chosen — and the DPIA
said the opposite, that EU residency was enforced and that leaving it took a
deliberate opt-out.

**The default is gone.** Two settings are now required in `wp-config.php`, and
until both are present the feature reports itself unconfigured and nothing is
sent:

```php
define( 'TT_VISION_ENDPOINT',    'https://…' );          // where requests go
define( 'TT_VISION_DATA_REGION', 'EU (Frankfurt)' );     // where that processes them
```

Switching the feature on without declaring a destination now answers plainly that
nothing was sent and what an administrator needs to set, rather than reporting
that the photo could not be read.

This cannot verify a declaration — no plugin can tell whether an endpoint really
processes data where its operator says it does. What it guarantees is that the
destination is always a choice somebody made, which is the thing a DPIA can
honestly record. The declared region belongs in the signed document.

Two related corrections: the extraction prompt now tells the model to keep player
names in the structured attendance field rather than in free-text notes, where
neither a subject-access export nor an erasure request could reach them; and the
`TT_VISION_BEDROCK_*` settings, which were documented but never read by any code,
have been removed so nobody configures them believing they do something.

**If you already use this feature, it will stop working until you add the two
settings.** That is deliberate.
