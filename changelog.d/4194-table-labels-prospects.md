# Tables on a phone say what each value is; prospects lose two card frames (#4194)

Bump: patch

Below 480px, tables that stack into one card per row now show each column's name in front of its value, so a "20" or a "3–1" on a phone reads as *Minutes 20* or *Result 3–1*. The labels come from the table's own column headers, so every list that stacks this way gets them, including rows added after the page loads. A cell with no matching header keeps the thin divider it had. The prospects list on a phone is now one level of cards (one per prospect) instead of a card inside a bordered box inside a card; from 768px it keeps its card look.
