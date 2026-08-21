---
title: Media library
group: development
summary: Photos and video attached to players, teams and sessions — where files are stored, who can see them, and how to switch the whole thing off.
audience: [admin, dev]
order: 70
---

# Media library

A rating of 7 for one-v-one defending is a number. The twelve-second clip behind it is the evidence. The media library is where photographs and
video live so they sit on the player's record rather than in a coach's camera roll.

This page describes the **foundation**: where files are stored, who can reach them, and how an academy turns the feature off. The upload screens,
the media tab on a player and the demo content are being built on top of it and are documented as they land.

## What a media item is

Three kinds:

| Kind | What it is |
|---|---|
| Photo | An image uploaded to this academy — JPEG, PNG or WebP. |
| Video | A video file uploaded to this academy — MP4 or MOV. |
| Video link | A link to footage hosted elsewhere: Veo, Hudl, YouTube or Vimeo. Nothing is copied; the academy keeps a pointer to the match it already has online. |

A media item is attached to one or more records — a player, a team, or a training session or match. One photo taken at a session can be attached
to the session **and** to each player in the frame, so a single upload appears on every record it belongs to instead of being uploaded four times.

## Where the files are kept

Uploaded files are **not** put in the WordPress media library. A file in the WordPress media library has a public web address: anyone who knows or
guesses the address can open it, and that address cannot be withdrawn afterwards. For photographs of children that is not an acceptable default.

Instead, TalentTrack keeps media in a private folder of its own (`uploads/tt-media/`) with randomly-named files. There is no web address that
serves them. Every view of a photo or video goes through TalentTrack, which checks who is asking before it sends a single byte.

Two guards, and it is worth knowing which one is doing the work:

- The folder carries a rule blocking direct web access. **On Apache servers this works. On nginx servers it does nothing** — nginx does not read
  those rules.
- TalentTrack's own permission check runs on every request for a file, on every server.

The second guard is the real boundary. The first is a helpful extra where the server honours it.

### Photo location data is removed

A photo taken on a phone usually records where it was taken. For a photo taken at training, that is the location of a pitch full of children, and
it travels inside the image file.

TalentTrack reads the date the photo was taken — so it lands in the right place on the player's timeline — and then removes all embedded
information, including location, before storing it. The stored file contains the picture and nothing else.

**Video is the exception.** Removing embedded data from a video file needs tooling TalentTrack does not include, and phones do write location into
video. Uploaded video therefore keeps whatever its camera recorded. If that matters for your academy, use the video-link option and keep footage
with your video provider, or avoid uploading video shot on a phone at a venue you would rather not disclose.

### Upload size

The largest file you can upload is set by your web server, not by TalentTrack. Many hosts default to somewhere between 8MB and 64MB, which is
smaller than a minute of phone video. The upload screen shows your server's actual limit. If it is too small, ask your host to raise
`upload_max_filesize` and `post_max_size`, or use video links instead.

Uploaded video also uses real disk space, and nothing reclaims it automatically. An academy uploading match clips every week should keep an eye on
its hosting storage.

## Who can see a player's media

- **Staff** — coaches, scouts and administrators — see the media of the players they are responsible for, following the same permissions that
  govern the rest of a player's record.
- **The player**, and **the player's parent or guardian**, see that player's own media.
- Nobody else. Media never crosses between academies or into the hands of staff without access to that player.

### A photo can show more than one child

If a photo or clip is attached to three players, all three families can see it. That is deliberate: team sport is photographed in groups, and the
alternative — only ever showing a family a picture in which their child appears alone — would hide nearly every session photo from everyone.

**Make sure your consent wording matches this.** Families should be told, when they join, that photographs and video taken at the academy may show
their child alongside others and may be visible to those other families. This should not be something a parent discovers by seeing a photo they
did not expect.

## Deleting a player deletes their media

When a player is permanently deleted, their media attachments go with them. Any photo or video that was attached only to that player is deleted
outright — the database record and the file itself. Media that is also attached to a team or a session stays, because those records still point at
it.

This matters for a request to be forgotten: erasing a player erases their photographs, not just the row with their name in it.

## Turning it off

There are two switches, and they do different things.

**The module switch** (Modules → Media library) turns the feature off completely. An academy that does not want photographs of its players held in
the system at all uses this one. With the module off, none of the media functionality loads.

**The feature switch** (Features → Media library) hides the media screens while keeping the module and everything already stored. Use this to take
media out of daily use without discarding what has been collected.

Neither switch deletes anything. Turning either back on brings the existing media back exactly as it was.

## For developers

- Tables: `tt_media` (the item) and `tt_media_links` (what it is attached to). Both club-scoped; `tt_media` carries a `uuid`, which is the
  identity the REST surface exposes — sequential ids are not addressable from outside.
- Storage sits behind `MediaStorageInterface`. `LocalPrivateStorage` is the shipped implementation. `tt_media.storage_key` is **opaque**: it is not
  a path and not a URL, and only the adapter that wrote it may interpret it. Register another adapter through the `tt_media_storage_adapters`
  filter; existing rows keep being served by the adapter named in the row.
- `MediaIngestService` decides file type from the file's own bytes, never from its name, and refuses SVG outright.
- `MediaLinksRepository::unlink()` deletes the media and its file when it removes the last link. A media item attached to nothing is unreachable
  and is not kept.
