# Messages held for quiet hours are now sent in the morning (#3646)

A non-urgent message sent between 21:00 and 07:00 was logged as "Held until
morning" and then never sent. It is now held in a short-lived queue and sent
in the first hour after the window ends. The same log row changes to the
final outcome and shows as a second attempt. Opt-outs and switched-off
templates are checked again at that moment, so a family that opted out
overnight is not written to. A held message that has not gone out within 24
hours is marked failed ("Not sent after quiet hours") instead of waiting
forever. Scheduled reports now ignore quiet hours, because a held message
never keeps a file overnight. Any other message with a file attached that
falls inside the window is logged as failed straight away.
