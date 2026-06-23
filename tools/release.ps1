<#
.SYNOPSIS
  Batched release for TalentTrack — one version bump for a drained batch.

.DESCRIPTION
  Consolidates changelog.d/*.md snippets into CHANGES.md + readme.txt,
  bumps both version lines in talenttrack.php (the `Version:` plugin
  header and the TT_VERSION constant) and the readme.txt `Stable tag`,
  then deletes the consumed snippets.

  Version is auto-determined unless you pass one:
    - current version is read from talenttrack.php;
    - each snippet may declare `Bump: patch|minor|major` (default patch);
    - the highest bump across the batch wins (SemVer §9: patch resets
      nothing, minor resets patch to 0, major resets minor+patch to 0).
  Pass -Version to override the computed value.

  It does NOT compile .mo and does NOT create a git tag. Pushing the
  resulting version bump to `main` triggers
  .github/workflows/auto-release.yml, which recompiles .mo from .po and
  publishes the GitHub release. CI owns .mo and tagging.

  Run from anywhere; paths resolve relative to the repo root (this
  script lives in tools/).

.PARAMETER Version
  Override the auto-computed semver, e.g. 4.46.0. Optional.

.PARAMETER Commit
  Also stage + commit the result (no push). Without it, changes are left
  in the working tree for review.

.EXAMPLE
  pwsh tools/release.ps1                 # auto-detect next version
  pwsh tools/release.ps1 -Commit         # auto-detect + commit
  pwsh tools/release.ps1 4.46.0          # force a specific version
#>
param(
    [Parameter(Position = 0)][string]$Version = '',
    [switch]$Commit
)

$ErrorActionPreference = 'Stop'

if ($Version -ne '' -and $Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "Version override must be semver MAJOR.MINOR.PATCH, e.g. 4.46.0 (got '$Version')."
}

$root    = Split-Path -Parent $PSScriptRoot   # tools/ -> repo root
$plugin  = Join-Path $root 'talenttrack.php'
$readme  = Join-Path $root 'readme.txt'
$changes = Join-Path $root 'CHANGES.md'
$snipDir = Join-Path $root 'changelog.d'

foreach ($f in @($plugin, $readme, $changes)) {
    if (-not (Test-Path $f)) { throw "Expected file not found: $f" }
}

function Read-Text($p) { [System.IO.File]::ReadAllText($p) }
function Write-Text($p, $t) {
    $enc = New-Object System.Text.UTF8Encoding $false   # UTF-8, no BOM
    [System.IO.File]::WriteAllText($p, $t, $enc)
}

# --- collect + parse snippets ----------------------------------------------
$snips = @()
if (Test-Path $snipDir) {
    $snips = Get-ChildItem -Path $snipDir -Filter *.md |
        Where-Object { $_.Name -ne 'README.md' } | Sort-Object Name
}
if (-not $snips -or @($snips).Count -eq 0) {
    Write-Warning "No changelog.d/*.md snippets found — nothing to consolidate."
}

$items  = @()
$issues = @()

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

    # Bump marker: a `Bump: patch|minor|major` line anywhere in the body.
    $bump = 'patch'
    $bodyLines = @()
    for ($i = $ti + 1; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match '^\s*Bump:\s*(patch|minor|major)\s*$') {
            $bump = $Matches[1].ToLower()
        } else {
            $bodyLines += $lines[$i]
        }
    }
    $body = ($bodyLines -join "`n").Trim()
    if ($body -eq '') { $body = $titleLine }

    if ($titleLine -match '#(\d+)') { $issues += $Matches[1] }

    $items += [pscustomobject]@{ Title = $titleLine; Body = $body; Bump = $bump }
}

# --- determine the version -------------------------------------------------
$pluginText = Read-Text $plugin
if ($pluginText -notmatch '(?m)^\s*\*\s*Version:\s*(\d+)\.(\d+)\.(\d+)') {
    throw "Could not read the current Version: from talenttrack.php."
}
$curMajor = [int]$Matches[1]; $curMinor = [int]$Matches[2]; $curPatch = [int]$Matches[3]
$current  = "$curMajor.$curMinor.$curPatch"

$auto = $false
if ($Version -eq '') {
    $auto = $true
    $rank  = @{ 'patch' = 0; 'minor' = 1; 'major' = 2 }
    $level = 'patch'
    foreach ($it in $items) { if ($rank[$it.Bump] -gt $rank[$level]) { $level = $it.Bump } }

    switch ($level) {
        'major' { $Version = "$($curMajor + 1).0.0" }
        'minor' { $Version = "$curMajor.$($curMinor + 1).0" }
        default { $Version = "$curMajor.$curMinor.$($curPatch + 1)" }
    }
    Write-Host "Auto-detected bump: $level  ($current -> $Version)  from $(@($items).Count) snippet(s)." -ForegroundColor Cyan
}

# --- build changelog blocks ------------------------------------------------
$changesBlocks = @()
$readmeBlocks  = @()
foreach ($it in $items) {
    $changesBlocks += "# TalentTrack v$Version — $($it.Title)`n`n$($it.Body)"
    $oneLine        = ($it.Body -replace "`r?`n", ' ').Trim()
    $readmeBlocks  += "= $Version — $($it.Title) $oneLine ="
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
$p = $pluginText
$p = [regex]::Replace($p, '(?m)^(\s*\*\s*Version:\s*)\d+\.\d+\.\d+', "`${1}$Version")
$p = [regex]::Replace($p, "(define\(\s*'TT_VERSION'\s*,\s*')\d+\.\d+\.\d+(')", "`${1}$Version`${2}")
Write-Text $plugin $p

# --- consume snippets ------------------------------------------------------
foreach ($s in $snips) { Remove-Item $s.FullName -Force }

$issueRefs = (@($issues) | Sort-Object -Unique | ForEach-Object { "#$_" }) -join ' '

Write-Host ""
Write-Host "Release prepared: v$Version$(if($auto){' (auto)'}else{' (override)'})" -ForegroundColor Green
Write-Host "  talenttrack.php  Version: + TT_VERSION bumped ($current -> $Version)"
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
