<!-- audience: user, admin -->

# Goals as a conversation

Each player development goal carries a chat-style conversation thread. Coaches, players, and linked parents can post short messages, ask questions, and reflect on progress — the dialogue stays attached to the goal record instead of leaking into WhatsApp or email.

This page covers what you can post, who sees what, how notifications work, and the rules for editing and deleting messages.

## What you'll see

Open any goal (*My goals*, *Goals* on the coach surface, or admin-side `?tt_view=goals&id=...`) and scroll past the form fields. The **Conversation** section shows the thread:

- **Your own messages** appear right-aligned in a coloured bubble.
- **Other people's messages** appear left-aligned with the author name, time, and (when relevant) a small "Coaches only" pill.
- **System messages** (goal created, status changed) appear centered and italicized so it's clear they came from the system, not a person.
- A "New messages" highlight stays on messages posted since you last visited so you can scan to them.

The compose box sits at the bottom: write, send, your message appears at the end of the thread.

## Who can read what

Goals are seen by:

- The **coach** who owns the goal (or any coach assigned to the player's team).
- The **player** whose goal it is.
- **Linked parents** — matched on the player's guardian email.
- **Admins / Head of Development** — read-only by default but can post too.

Coaches and admins can mark a message as **Coaches only** by ticking the *Coaches only* checkbox before sending. Coaches-only messages stay invisible to players and parents (and don't trigger their email notifications).

## Notifications

Every public message sends an email to every other thread participant — except the author, who's never notified about their own message. Coaches-only messages email coaches and admins only.

Email subject: *New message on Marcus's goal: "Improve first-touch under pressure"*. The body shows the author, a short preview, and a link back to the goal.

When push notifications land (planned via `#0042`), participants with an active push subscription get push instead of email; everyone else still gets email.

Admins can disable notification fan-out entirely by setting `threads.notify_on_post=0` in `tt_config`.

## Editing and deleting

- You can **edit** your own message for **5 minutes** after posting it. After that, the edit window closes — soft-delete is the only option.
- You can **soft-delete** your own message at any time. The bubble stays in the thread but the body is replaced with "Message deleted." Admins can soft-delete any message; the original body is preserved in the audit log so it's recoverable if needed.
- System messages are immutable.

## System messages

Goal-status changes write a system message into the thread automatically:

- "Goal created: Improve first-touch under pressure."
- "Status changed to: In Progress."
- "Status changed to: Completed."

So even without anyone typing, the thread tells the story of how the goal moved.

## Polling and live updates

The thread polls for new messages every 30 seconds while you have the page open. When you switch tabs or background the page, polling pauses; when you come back, it resumes and catches up. There's no live websocket / SSE in v1 — goal-pace conversations don't need one.

## Audit log

Every post / edit / delete writes a row to the audit log (`thread_message_posted`, `thread_message_edited`, `thread_message_deleted`). Deleted messages keep their original body in the audit log payload so admins can recover what was said.

## REST API

The thread primitive is exposed at:

```
GET    /wp-json/talenttrack/v1/threads/{type}/{id}                read messages, mark as read
POST   /wp-json/talenttrack/v1/threads/{type}/{id}/messages       post message
PUT    /wp-json/talenttrack/v1/threads/{type}/{id}/messages/{m}   edit (5-min window, author only)
DELETE /wp-json/talenttrack/v1/threads/{type}/{id}/messages/{m}   soft-delete
POST   /wp-json/talenttrack/v1/threads/{type}/{id}/read           explicit read marker
```

v1 only registers `goal` as a thread type. Future epics (#0017 trial cases, #0014 scout reports, #0044 PDP conversations) will register their own.

## What's not in v1

- **File or image attachments.** Plain text only for now.
- **Reactions / emoji.** A "thanks!" comment is enough.
- **@-mentions** with autocomplete. Notifications already fan out to all participants.
- **Live websocket updates.** 30-second polling.
- **Editing past 5 minutes.** Soft-delete + repost is the workflow.
- **Per-message hard delete.** GDPR erasure goes through the existing retention path.
