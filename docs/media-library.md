---
title: Media library
group: development
summary: Photos and video attached to players, teams and activities — where files are stored, who can see them, and how to switch the whole thing off.
audience: [admin, dev]
views: [media-retention]
module: TT\Modules\Media\MediaModule
feature: media
capability: tt_view_media
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

A media item is attached to one or more records — a player, a team, or a training or match activity. One photo taken at a training can be attached
to that activity **and** to each player in the frame, so a single upload appears on every record it belongs to instead of being uploaded four times.

## Where you see it

A player's photos and video live on a **Media** tab on their profile, beside Evaluations and Injuries. The tab appears only for people whose
permissions reach that player's media, so a coach without access to a squad never sees it at all.

Media is ordered by **when it was taken**, newest first — not by when it was uploaded. That is what makes the tab read as part of the player's
story rather than as a folder: emptying a camera roll in November does not push August's training above it.

Tap a photo to see it full size, or a video to play it. Arrow keys move between items and Escape closes the viewer. Videos only start loading when
you actually play one, so opening the tab on a phone does not spend your data on footage you have not asked for.

**Remove** deletes permanently — the file itself, not just the entry. It is only offered to people who may edit that player's media.

### Teams and trainings

A team has a **Media** section on its page, below the roster and fixtures — squad photos, tournament days, end-of-season moments. It shows only
what is attached to the team itself; media of an individual player stays on their profile, so browsing a team does not scroll through every
player's file. Like the other sections on that page, you can switch it off for your own view.

A training or match has its own **Media** section, and that is where tagging happens.

### Tagging the players in a photo

On a training or match, each photo offers **Tag players**, listing that team's squad. Tick the players who are in the picture and it appears on
their profiles too — one upload, however many records it belongs to. No Save button: each tick is stored as you make it, and reverts if it cannot
be.

Untagging one player removes it from that player only. The photo stays on the training and on everyone else you tagged.

This is also what makes the shared-visibility point earlier concrete: a photo tagged to three players is visible to all three families.

## Adding media

Use **Add media** from a player, team or training. The wizard has four steps:

1. **Who for** — prefilled when you started from a record, so there is nothing to pick.
2. **Files** — choose photos or video, or paste a link to video hosted elsewhere. On a phone the camera is one tap away.
3. **Details** — a title, an optional description, and the date it was taken.
4. **Confirm** — what will be saved, and where it will appear.

**Uploads are saved as soon as they finish**, before you reach the last step. That is deliberate: it means a dropped connection or a closed tab
never loses a file you have already waited for. If you leave halfway through, the photos are on the record already — just without a title, which
you can add later from the record itself.

The date in step 3 decides where the media sits on the player's timeline, so it should be the day of the training or match rather than the day you
uploaded it. When a photo carries its own date, that date is filled in for you.

Each file's progress is shown while it uploads, and you can cancel one that is taking too long without losing the others.

### Video links

Paste the web address of a video and TalentTrack works out where it is hosted. Veo, Hudl, YouTube and Vimeo are recognised; for YouTube and Vimeo
the title and a thumbnail are fetched automatically. Anything else is saved as a plain link with a title you type — TalentTrack never contacts an
address it does not recognise.

### Long galleries load in pages

A record with a lot of media shows the 24 most recent items and a **Show more** button underneath. Each press adds the next 24, oldest continuing
downwards, until there is nothing left to load and the button disappears.

This is deliberately a button rather than loading more as you scroll: the oldest photo stays reachable, the browser's back button keeps working, and
there is nothing small to hit with a thumb.

The number on the Media tab counts everything held for that player, not what is currently on screen — so a player showing 24 tiles and a badge
reading 31 has seven more waiting behind the button.

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

### Media addresses expire

The address a photo or video loads from is tied to your login session and stops working after about a day. This is deliberate: it means an address
copied out of a page — pasted into a chat, caught in a server log, carried in a referrer header — cannot be used by anyone else to open the file.
Someone who follows such a link without being logged in to TalentTrack is refused.

The practical effect is small but worth recognising: a gallery left open in a browser tab overnight will show broken images the next morning.
Reloading the page fixes it.

### Location data is removed

A photo or video taken on a phone usually records where it was taken. At training, that is the location of a pitch full of children, and it
travels inside the file.

**Photos.** TalentTrack reads the date the photo was taken — so it lands in the right place on the player's timeline — and then removes all
embedded information, including location, before storing it. The stored file contains the picture and nothing else.

**Video.** TalentTrack finds the parts of the video file where phones record coordinates and blanks them before storing it. The picture and sound
are never touched and the file is not re-encoded, so nothing about the footage changes.

After you upload a video, the upload list tells you what happened:

- *Location data was removed from this video.* — coordinates were found and are gone.
- Nothing said — the file carried no location data to begin with.
- A warning that the file **carries metadata TalentTrack could not read** — the file is stored, but something in it could not be understood, and
  it may still say where it was filmed. Remove it before uploading, or use the video-link option instead.

That last case is rare, and it is deliberately shown rather than hidden. TalentTrack will not tell you a file is clean when it cannot be sure.

If you would rather no footage sat on the server at all, use the video-link option and keep it with your video provider.

### Upload size

The largest file you can upload is set by your web server, not by TalentTrack. Many hosts default to somewhere between 8MB and 64MB, which is
smaller than a minute of phone video. The upload screen shows your server's actual limit. If it is too small, ask your host to raise
`upload_max_filesize` and `post_max_size`, or use video links instead.

Uploaded video also uses real disk space, and nothing reclaims it automatically. Once an academy has media stored, the total appears as **Media
stored** on the academy admin's system-health strip, so it is visible in the place you already check rather than behind a settings tab.

An academy uploading match clips every week should watch that number against whatever their hosting actually provides — TalentTrack has no way to
know the disk size it is running on.

## Recording photo and video consent

Each player record carries a **Photo & video consent** checkbox, on the player's edit form beside the photo. Ticking it stores the date and the name
of the staff member who recorded it, so the entry is evidence rather than an assertion. Clearing it removes both, because the provenance of a consent
that no longer stands would only mislead.

The player's profile shows the answer to staff — including when the answer is no, since a blank would read as "nobody asked".

**It records; it does not restrict.** Nothing about adding a photo checks this box. A coach can add media for a player with no consent on record, and
the academy will not be stopped from doing so. That is deliberate. The real control is the conversation and the form the family signed; a hard block
at the side of a pitch tends to be worked around by photographing on a personal phone instead, which leaves the child worse off than a recorded gap
does.

What the field is for is answering the question — *who may we photograph?* — before a matchday, and being able to show that the question was asked.

Withdrawal is recorded by clearing the box. It does not reach back and remove photographs already stored; if a family withdraws consent and wants
existing media removed, that is done from the player's Media tab.

## Who can see a player's media

- **Staff** — coaches, scouts and administrators — see the media of the players they are responsible for, following the same permissions that
 govern the rest of a player's record.
- **The player**, and **the player's parent or guardian**, see that player's own media.
- Nobody else. Media never crosses between academies or into the hands of staff without access to that player.

### A photo can show more than one child

If a photo or clip is attached to three players, all three families can see it. That is deliberate: team sport is photographed in groups, and the
alternative — only ever showing a family a picture in which their child appears alone — would hide nearly every training photo from everyone.

**Make sure your consent wording matches this.** Families should be told, when they join, that photographs and video taken at the academy may show
their child alongside others and may be visible to those other families. This should not be something a parent discovers by seeing a photo they
did not expect.

## How long media is kept

An academy asked *"how long do you keep photos of my child?"* needs an answer. TalentTrack's is: **for a set period after the player leaves**, and
then a person reviews it.

The period is set under **Configuration → Keep media after a player leaves**. It ships at **three years** and can be anything from one year to ten,
or **Keep indefinitely** if your academy would rather decide case by case.

Three things about it are worth being precise on, because they are what make it safe:

**The clock starts when the player leaves, not when the photo was taken.** A player who is still at the academy keeps their whole file, however
old. That longitudinal record — the same player at 12 and at 18 — is the point of the product, and a period measured from the photo's own date
would quietly delete the start of it.

**Nothing is ever deleted automatically.** When the period passes, the media appears under **Media retention** for someone to decide. That is why
shipping a default period is safe: it starts a review, not a deletion clock. An academy that upgrades finds a list waiting, not gaps in its records.

**Expiry applies to one player's link, not the whole photo.** A team photo showing a player who left is removed from *their* file; it stays on the
team, on the training it came from, and on the other players in it. Only when nothing is left pointing at a file is the file itself deleted.

### Reviewing

**Media retention** lists what is waiting, oldest departure first, with two choices per item:

- **Remove** — takes it off that player's file. If nothing else is attached to it, the file is deleted for good; the page tells you which happened.
- **Keep** — holds it, and asks why. A safeguarding matter, an open dispute, an appeal. Held items are listed separately with their reasons, because
 a retention policy with an invisible list of exceptions is not one anybody can check. You can put a held item back in the queue later.

Some rows are marked **estimated**. That means the player has no recorded leaving date — usually because they left before TalentTrack was recording
those — so the date their record last changed is used instead. It only affects when the item appears for review; nothing is decided on it.

## Deleting a player deletes their media

When a player is permanently deleted, their media attachments go with them. Any photo or video that was attached only to that player is deleted
outright — the database record and the file itself. Media that is also attached to a team or an activity stays, because those records still point at
it.

This matters for a request to be forgotten: erasing a player erases their photographs, not just the row with their name in it.

## What a subject-access request returns

When someone exercises their right to see everything the academy holds on a player, the export includes a `media.json` listing every photograph and
video held of them: what it is, when it was taken, what it was attached to, and who added it.

**The files themselves are not in the export.** A season of video runs to gigabytes, and an export too large to produce is no use to anybody. The
export says so plainly rather than staying silent, because a list with no explanation reads as though the academy holds nothing.

If the person wants the files, the academy sends them separately — the player's Media tab has everything, and each item can be opened and saved from
there.

Team and activity media never appear in an individual's export, even where the player was present. Those belong to the team or the session, not to
one child.

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
- The media root defaults to `uploads/tt-media/` and is filterable via `tt_media_storage_root`. Point it at a separate volume when video growth
 would otherwise threaten the disk WordPress itself runs on. A filtered path is used verbatim, so it must be absolute and writable.
- `MediaIngestService` decides file type from the file's own bytes, never from its name, and refuses SVG outright.
- `MediaLinksRepository::unlink()` deletes the media and its file when it removes the last link. A media item attached to nothing is unreachable
 and is not kept.
