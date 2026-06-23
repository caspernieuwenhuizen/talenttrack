<#
.SYNOPSIS
  Batched release for TalentTrack — one version bump for a drained batch.

.DESCRIPTION
  Consolidates changelog.d/*.md snippets into CHANGES.md + readme.txt,
  bumps both version lines in talenttrack.php (the `Version:` plugin
  header and the TT_VERSION constant) and the readme.txt `Stable tag`,
  then deletes the consumed snippets.

  It does NOT compile .mo and does NOT create a git tag. Pushing the
  resulting version bump to `main` triggers
  .github/workflows/auto-release.yml, which recompiles .mo from .po and
  publishes the GitHub release. CI owns .mo and tagging.

  Run from anywhere; paths resolve relative to the repo root (this
  script lives in tools/).

.PARAMETER Version
  New semver, e.g. 4.46.0.

.PARAMETER Commit
  Also stage + commit the result (no push). Without it, changes are left
  in the working tree for review.

.EXAMPLE
  pwsh tools/release.ps1 4.46.0
  pwsh tools/release.ps1 4.46.0 -Commit
#>
param(
    [Parameter(Mandatory = $true)][string]$Version,
    [switch]$Commit
)

$ErrorActionPreference = 'Stop'

if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Version must be semver MAJOR.MINOR.PATCH, e.g. 4.46.0 (got '$Version')."
}

$root     = Split-Path -Parent $PSScriptRoot   # tools/ -> repo root
$plugin   = Join-Path $root 'talenttrack.php'
$readme   = Join-Path $root 'readme.txt'
$changes  = Join-Path $root 'CHANGES.md'
$snipDir  = Join-Path $root 'changelog.d'

foreach ($f in @($plugin, $readme, $changes)) {
    if (-not (Test-Path $f)) { throw "Expected file not found: $f" }
}

function Read-Text($p) { [System.IO.File]::ReadAllText($p) }
function Write-Text($p, $t) {
    $enc = New-Object System.Text.UTF8Encoding $false   # UTF-8, no BOM
    [System.IO.File]::WriteAllText($p, $t, $enc)
}

# --- collect snippets ------------------------------------------------------
$snips = @()
if (Test-Path $snipDir) {
    $snips = Get-ChildItem -Path $snipDir -Filter *.md |
        Where-Object { $_.Name -ne 'README.md' } | Sort-Object Name
}
if (-not $snips -or @($snips).Count -eq 0) {
    Write-Warning "No changelog.d/*.md snippets found — bumping version with no new changelog entries."
}

$changesBlocks = @()
$readmeBlocks  = @()
$issues        = @()

foreach ($s in $snips) {
    $raw   = (Read-Text $s.FullName).Trim()
    $lines = $raw -split "`r?`n"

    $ti = 0
    while ($ti -lt $lines.Count -and $lines[$ti].Trim() -eq '') { $ti++ }
    if ($ti -ge $lines.Count) {
        Write-Warning "Snippet $($s.Name) is empty — skipped."
        continue
    }
    $titleLine = ($lines[$ti] -replace '^\s*#\s*', '').Trim()

    $body = ''
    if (($ti + 1) -lt $lines.Count) {
        $body = ($lines[($ti + 1)..($lines.Count - 1)] -join "`n").Trim()
    }
    if ($body -eq '') { $body = $titleLine }

    if ($titleLine -match '#(\d+)') { $issues += $Matches[1] }

    $changesBlocks += "# TalentTrack v$Version — $titleLine`n`n$body"
    $oneLine        = ($body -replace "`r?`n", ' ').Trim()
    $readmeBlocks  += "= $Version — $titleLine $oneLine ="
}

# --- prepend to CHANGES.md -------------------------------------------------
if ($changesBlocks.Count -gt 0) {
    $changesNew = ($changesBlocks -join "`n`n") + "`n`n" + (Read-Text $changes)
    Write-Text $changes $changesNew
}

# --- insert into readme.txt changelog + bump Stable tag --------------------
$readmeContent = Read-Text $readme
$marker = '== Changelog =='
if ($readmeBlocks.Count -gt 0) {
    $parts = $readmeContent -split [regex]::Escape($marker), 2
    if ($parts.Count -ne 2) { throw "Could not find '$marker' in readme.txt." }
    $entries       = ($readmeBlocks -join "`n`n")
    $readmeContent = $parts[0] + $marker + "`n`n" + $entries + "`n`n" + ($parts[1].TrimStart("`r", "`n"))
}
$readmeContent = [regex]::Replace($readmeContent, '(?m)^(Stable tag:\s*)\d+\.\d+\.\d+', "`${1}$Version")
Write-Text $readme $readmeContent

# --- bump talenttrack.php (both lines) -------------------------------------
$p = Read-Text $plugin
$p = [regex]::Replace($p, '(?m)^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+', "`${1}$Version")
$p = [regex]::Replace($p, "(define\(\s*'TT_VERSION'\s*,\s*')\d+\.\d+\.\d+(')", "`${1}$Version`${2}")
Write-Text $plugin $p

# --- consume snippets ------------------------------------------------------
foreach ($s in $snips) { Remove-Item $s.FullName -Force }

$issueRefs = (@($issues) | Sort-Object -Unique | ForEach-Object { "#$_" }) -join ' '

Write-Host ""
Write-Host "Release prepared: v$Version" -ForegroundColor Green
Write-Host "  talenttrack.php  Version: + TT_VERSION bumped"
Write-Host "  readme.txt       Stable tag bumped, $($readmeBlocks.Count) changelog entr$(if($readmeBlocks.Count -eq 1){'y'}else{'ies'}) added"
Write-Host "  CHANGES.md       $($changesBlocks.Count) block(s) prepended"
Write-Host "  changelog.d      $((@($snips)).Count) snippet(s) consumed"
if ($issueRefs) { Write-Host "  issues           $issueRefs" }

if ($Commit) {
    git -C $root add -A -- talenttrack.php readme.txt CHANGES.md changelog.d
    $msg = "chore(release): v$Version"
    if ($issueRefs) { $msg += " — batch $issueRefs" }
    git -C $root commit -m $msg
    Write-Host ""
    Write-Host "Committed. Next: push to main — auto-release.yml builds the .mo, ZIP, and GitHub release." -ForegroundColor Cyan
} else {
    Write-Host ""
    Write-Host "Changes left unstaged. Review, then commit + push to main to trigger the release." -ForegroundColor Cyan
}
Write-Host "Do NOT compile .mo or create a tag by hand — CI owns both."
Write-Host "If this batch is referenced in SEQUENCE.md, update it manually."
