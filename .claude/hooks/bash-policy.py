"""Bash permission policy for this repo.

Two jobs, one chokepoint, evaluated in this order:

1. DENY any command carrying a shell redirect. Any '>' is classified as a
   file write by the permission layer, which short-circuits the allowlist
   and parks the session on a prompt. In an unattended drain that stalls
   forever. See CLAUDE.md section 7. Escape hatch: '# redirect-ok'.

2. ALLOW a command when every segment of it runs a binary that is already
   allowlisted in settings.json. The built-in prefix matcher only auto-
   approves shapes it can statically decompose: it does not split on
   newlines, and a leading VAR="..." assignment or a for/if construct
   makes the whole blob match no rule at all. So a three-line script of
   nothing but sed and grep prompts, even though Bash(sed:*) and
   Bash(grep:*) are both granted. This hook decomposes the command itself
   and returns "allow", which is evaluated before the classifier.

The allow branch grants NO binary that isn't already allowlisted - it only
makes the multi-line, chained, and looped forms of those same binaries
work. Interpreters and shells (python, node, perl, bash, npx, powershell)
are deliberately absent: auto-approving them is auto-approving arbitrary
code. Commands containing those, command substitution, process
substitution, or a heredoc fall through to the normal prompt.
"""

import json
import re
import shlex
import sys

SAFE_BINARIES = {
    # navigation / inspection
    "cd", "pwd", "ls", "cat", "head", "tail", "wc", "stat", "file", "nl",
    "basename", "dirname", "realpath", "readlink", "which", "date", "env",
    # text
    "echo", "printf", "grep", "egrep", "fgrep", "rg", "sed", "awk", "sort",
    "uniq", "cut", "tr", "diff", "jq", "find", "xargs", "column", "tee",
    # flow
    "sleep", "true", "false", "test", "[",
    # files the drain workflow touches
    "mkdir", "touch", "cp", "mv", "rm",
    # project tooling
    "git", "gh", "php", "composer", "npm", "wp",
    "msgfmt", "msgmerge", "msginit", "xgettext",
}

# Shell keywords that introduce structure rather than a command.
CONTROL = {
    "if", "elif", "then", "else", "fi", "while", "until", "do", "done",
    "case", "esac", "in", "select", "function", "time", "!", "{", "}",
    "(", ")",
}

ASSIGNMENT = re.compile(r"^[A-Za-z_][A-Za-z0-9_]*=")

# Shapes whose expansion can introduce a command we cannot see.
OPAQUE = ("$(", "`", "<(", ">(", "<<")


def split_segments(command):
    """Split on newlines and && || ; | & , honouring quotes.

    Returns None when the string has an unbalanced quote.
    """
    segments = []
    buf = []
    quote = None
    i = 0
    n = len(command)
    while i < n:
        ch = command[i]
        if quote:
            buf.append(ch)
            if ch == "\\" and quote == '"' and i + 1 < n:
                buf.append(command[i + 1])
                i += 2
                continue
            if ch == quote:
                quote = None
            i += 1
            continue
        if ch in "'\"":
            quote = ch
            buf.append(ch)
            i += 1
            continue
        if ch == "\\" and i + 1 < n:
            buf.append(ch)
            buf.append(command[i + 1])
            i += 2
            continue
        if command[i:i + 2] in ("&&", "||", ";;"):
            segments.append("".join(buf))
            buf = []
            i += 2
            continue
        if ch in ";|&\n":
            segments.append("".join(buf))
            buf = []
            i += 1
            continue
        buf.append(ch)
        i += 1
    if quote:
        return None
    segments.append("".join(buf))
    return [s.strip() for s in segments if s.strip()]


def binary_name(token):
    """Reduce a command token to a bare binary name.

    Handles quoted absolute paths ("C:/xampp/php/php.exe" -> php) and the
    ./tools/foo form.
    """
    token = token.strip().strip("'\"")
    token = re.split(r"[/\\]", token)[-1]
    if token.lower().endswith(".exe"):
        token = token[: -len(".exe")]
    return token


def segment_ok(segment):
    try:
        tokens = shlex.split(segment, posix=True)
    except ValueError:
        return False
    i = 0
    while i < len(tokens):
        token = tokens[i]
        if token == "for":
            # `for NAME in word word` runs nothing; the body is a separate
            # segment, split off at the ';' before `do`.
            return True
        if token in CONTROL or ASSIGNMENT.match(token):
            i += 1
            continue
        break
    if i >= len(tokens):
        # Pure assignment or pure structure - no command runs here.
        return True
    return binary_name(tokens[i]) in SAFE_BINARIES


def redirect_denial(command):
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

    return (
        "Blocked: this command contains '>', which the permission classifier "
        "reads as a file write. That bypasses the allowlist entirely, so every "
        "binary here being allowlisted does not help. Fix the command shape:\n"
        + "\n".join("  - " + n for n in notes)
        + "\n\nFor in-place edits across files use the Edit tool per file, or "
        "sed (already allowlisted). If the redirect is genuinely required, "
        "append '# redirect-ok' to run it with the usual prompt."
    )


HEREDOC_DENIAL = (
    "Blocked: heredocs are not used in this repo. Shell commands don't create "
    "files, the Write tool does - write the body to a file, then pass it by "
    "path (gh issue comment --body-file <path>). See CLAUDE.md section 7. If "
    "this is genuinely required, append '# redirect-ok' to run it with the "
    "usual prompt."
)


def decide(command):
    """Return (decision, reason), or (None, None) for the normal prompt flow."""
    if ">" in command or "<<" in command:
        # Never reach the allow branch with a redirect or heredoc in hand: the
        # segment splitter reads '> file' as an argument rather than a
        # separator, so it would ride along inside an otherwise-safe segment
        # and get auto-approved. With the escape hatch present, fall through
        # to the normal prompt - that is what the hatch promises, not a bypass.
        if "# redirect-ok" in command:
            return None, None
        if ">" in command:
            return "deny", redirect_denial(command)
        return "deny", HEREDOC_DENIAL

    if any(marker in command for marker in OPAQUE):
        return None, None

    segments = split_segments(command)
    if not segments:
        return None, None
    if not all(segment_ok(segment) for segment in segments):
        return None, None

    return "allow", (
        "Every segment runs a binary already allowlisted in settings.json; "
        "auto-approved by .claude/hooks/bash-policy.py so the multi-line and "
        "chained forms of those same binaries don't prompt."
    )


def main():
    try:
        payload = json.load(sys.stdin)
    except Exception:
        return 0

    command = (payload.get("tool_input") or {}).get("command") or ""
    if not command:
        return 0

    decision, reason = decide(command)
    if decision is None:
        return 0

    json.dump(
        {
            "hookSpecificOutput": {
                "hookEventName": "PreToolUse",
                "permissionDecision": decision,
                "permissionDecisionReason": reason,
            }
        },
        sys.stdout,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
