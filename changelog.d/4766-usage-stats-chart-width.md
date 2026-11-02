# Usage statistics: the chart no longer pushes a phone screen sideways (#4766)

On a 320px phone, the usage statistics page could scroll sideways for a moment after loading. The daily active users chart kept the width it was drawn at until it redrew itself. The chart is now held to the width of its panel at all times. It still fills the panel on every screen size, and at 320px its dates and values stay readable. CSS only.
