"""Block Bash commands carrying a shell redirect.

Any '>' in a command string is classified as a file write by the permission
layer, which short-circuits the allowlist in .claude/settings.json and parks
the session on a prompt. In an unattended drain that stalls forever. See
CLAUDE.md section 7.

The character is what matters, not the intent: '2>&1' writes nothing and
still costs an approval, and a '>' inside a quoted grep pattern does too.

Escape hatch: put '# redirect-ok' in the command for a deliberate redirect.
That returns to the normal prompt rather than bypassing it.
"""

import json
import re
import sys


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except Exception:
        return 0

    command = (payload.get("tool_input") or {}).get("command") or ""

    if "# redirect-ok" in command or ">" not in command:
        return 0

    notes = []

    if re.search(r"\d?>\s*&\s*\d|2>\s*/dev/null|2>\s*\$null", command):
        notes.append(
            "stderr redirect: the Bash tool already captures and shows stderr, "
            "so delete it outright - it buys nothing and costs an approval"
        )

    if "<<" in command:
        notes.append(
            "heredoc: write the file with the Write tool, then pass it by path "
            "(gh issue comment --body-file <path>)"
        )
    elif re.search(r"(^|[\s;&|])(cat|echo|printf|tee)\b[^|;&]*>", command):
        notes.append(
            "shell file write: shell commands don't create files, the Write "
            "tool does"
        )

    if re.search(r"""(['"])[^'"]*>[^'"]*\1""", command):
        notes.append(
            "'>' inside a quoted argument: the classifier matches the "
            "character, not the intent - rephrase the pattern (e.g. use '.' "
            "or a character class) or split the command"
        )

    if not notes:
        notes.append(
            "redirect to a file: use the Write tool instead of a shell redirect"
        )

    reason = (
        "Blocked: this command contains '>', which the permission classifier "
        "reads as a file write. That bypasses the allowlist entirely, so every "
        "binary here being allowlisted does not help. Fix the command shape:\n"
        + "\n".join("  - " + n for n in notes)
        + "\n\nFor in-place edits across files use the Edit tool per file, or "
        "sed (already allowlisted). If the redirect is genuinely required, "
        "append '# redirect-ok' to run it with the usual prompt."
    )

    json.dump(
        {
            "hookSpecificOutput": {
                "hookEventName": "PreToolUse",
                "permissionDecision": "deny",
                "permissionDecisionReason": reason,
            }
        },
        sys.stdout,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
