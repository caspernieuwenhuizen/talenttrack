# Activities crossing midnight keep their end time (#4513)

An activity that ends after midnight, such as a tournament evening from 23:00 to 00:30, no longer loses its end time on save. An end earlier than the start now counts as the next day when the span is six hours or less, and the duration in the stat strip and the match-length basis count it (23:00 to 00:30 is 90 minutes). A longer span, such as 10:00 to 09:00, is still treated as a typo and the end is dropped. Re-saving a late Spond activity without changes no longer locks its times against the Spond sync.
