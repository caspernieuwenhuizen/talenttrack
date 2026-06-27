# PDP conversation breadcrumb returns to the player's file (#2038)

Bump: patch

Opening a conversation from a PDP file and then using the breadcrumb back
affordance now returns to that player's PDP file, not the whole PDP list.
The conversation page previously reused the file-detail breadcrumb chain,
whose only clickable step was the PDP list. It now renders its own chain —
"PDP → PDP file detail → Conversation" — with the file-detail crumb as the
back-to-file step. Navigation only; no data or query changes.
