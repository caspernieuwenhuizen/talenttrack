<#
.SYNOPSIS
  Make the latest released `main` live in the local XAMPP WordPress.

.DESCRIPTION
  The local WordPress serves whatever the plugin junction points at - the
  repo-root checkout (LOCAL-DEV.md / memory local-wp-live-preview). So a
  freshly-released version on `main` is not live locally until the repo root
  is switched to `main`. This script does that switch, safely:

    - "clean" = no uncommitted changes to *tracked* files (untracked local
      files - mockups, LOCAL-DEV.md, these tools - are fine, they carry
      across a branch switch). Committed feature-branch work is also safe:
      switching to main leaves it on its branch, reachable again with
      `git checkout <branch>`.
    - If the tree has uncommitted tracked changes, it does NOTHING except
      print a notice - it never stashes or clobbers work. Switch by hand
      once you're ready.

  On a successful switch it pulls `main` --ff-only and bootstraps WordPress
  via wp-cli so pending DB migrations run (they run on WP bootstrap), then
  prints the now-live TT_VERSION.

  Doubles as the PostToolUse hook target: with -FromHook it reads the hook
  JSON on stdin and only proceeds when the Bash command was a push to main,
  so it's a cheap no-op after every other command.

.PARAMETER FromHook
  Read the Claude Code PostToolUse JSON on stdin and gate on "git push to main".

.EXAMPLE
  powershell -File tools/go-live-local.ps1          # switch + go live now

.NOTES
  The repo root is the script's own parent, so the defaults work from any
  clone. The WordPress paths default to a stock XAMPP layout; override them
  per machine with the TT_WP_PATH / TT_PHP / TT_WP_CLI environment variables
  or the matching parameters. A missing php / wp-cli only skips the WP
  bootstrap - the branch switch still happens.
#>
param(
    [switch]$FromHook,
    [string]$RepoRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$WpPath   = $(if ($env:TT_WP_PATH) { $env:TT_WP_PATH } else { 'C:\xampp\htdocs\wordpress' }),
    [string]$Php      = $(if ($env:TT_PHP)     { $env:TT_PHP }     else { 'C:\xampp\php\php.exe' }),
    [string]$WpCli    = $(if ($env:TT_WP_CLI)  { $env:TT_WP_CLI }  else { Join-Path $HOME 'bin\wp-cli.phar' })
)

# --- Hook gate: only act when the triggering command pushed to main --------
if ($FromHook) {
    $raw = [Console]::In.ReadToEnd()
    if (-not $raw) { exit 0 }
    try { $payload = $raw | ConvertFrom-Json } catch { exit 0 }
    $cmd = [string]$payload.tool_input.command
    if ($cmd -notmatch 'git\s+push') { exit 0 }
    if ($cmd -notmatch '(?i)main')   { exit 0 }
}

# Resolve git.exe by path first. PowerShell resolves command names
# function-before-application and case-insensitively, so a bare `& git` inside
# a function named `Git` calls the function again - infinite recursion ending in
# CallDepthOverflow. Binding the executable up front breaks that cycle.
$GitExe = (Get-Command git -CommandType Application -ErrorAction SilentlyContinue |
           Select-Object -First 1).Source
if (-not $GitExe) {
    Write-Host "go-live-local: git not found on PATH - nothing to do."
    exit 0
}

function Git { & $GitExe -C $RepoRoot @args }

if (-not (Test-Path (Join-Path $RepoRoot '.git'))) {
    Write-Host "go-live-local: repo root not found at $RepoRoot - nothing to do."
    exit 0
}

Git fetch origin main --quiet | Out-Null

$branch = (Git rev-parse --abbrev-ref HEAD).Trim()

$mainHeader = (Git show origin/main:talenttrack.php) -join "`n"
$mainVer = if ($mainHeader -match 'Version:\s*([0-9][0-9.]+)') { $Matches[1] } else { '?' }

# Uncommitted tracked changes? (untracked files do not block a branch switch)
$dirty = $false
Git diff --quiet;        if ($LASTEXITCODE -ne 0) { $dirty = $true }
Git diff --cached --quiet; if ($LASTEXITCODE -ne 0) { $dirty = $true }

if ($dirty) {
    Write-Host "go-live-local: SKIP - uncommitted changes on '$branch'."
    Write-Host "  Release v$mainVer is live on GitHub; local WordPress still on '$branch'."
    Write-Host "  Commit or stash, then run: powershell -File tools/go-live-local.ps1"
    exit 0
}

if ($branch -ne 'main') {
    Write-Host "go-live-local: switching repo root from $branch to main. Your $branch work is committed and safe; use git checkout $branch to return."
    Git checkout main --quiet
    if ($LASTEXITCODE -ne 0) { Write-Host "go-live-local: checkout main failed - aborting."; exit 0 }
}

Git pull --ff-only --quiet | Out-Null

$liveHeader = (Git show HEAD:talenttrack.php) -join "`n"
$liveVer = if ($liveHeader -match 'Version:\s*([0-9][0-9.]+)') { $Matches[1] } else { '?' }

# Bootstrap WordPress so migrations run; surface TT_VERSION / any fatal.
# The eval's OUTCOME decides what we claim: a failed bootstrap (DB down, fatal)
# still exits this block, so gating on Test-Path alone reported "migrations ran"
# when nothing had. Require exit 0 and a version-shaped payload before saying so.
if ((Test-Path $Php) -and (Test-Path $WpCli)) {
    $out = (& $Php $WpCli --path=$WpPath eval "echo defined('TT_VERSION') ? TT_VERSION : 'no-const';" 2>&1) -join ' '
    if ($LASTEXITCODE -ne 0 -or $out -notmatch '^\s*[0-9]') {
        Write-Host "go-live-local: repo root is on main (v$liveVer), but the WP bootstrap FAILED - migrations have NOT run."
        Write-Host "  Is XAMPP MySQL started? wp-cli said: $out"
    } else {
        Write-Host "go-live-local: local WordPress now on main - TT_VERSION=$out (migrations ran on bootstrap)."
    }
} else {
    Write-Host "go-live-local: local WordPress now on main (v$liveVer). Skipped WP bootstrap - php/wp-cli not at expected paths."
}
exit 0
