<#
.SYNOPSIS
  Report content files shared between branches, before a parallel drain.

.DESCRIPTION
  For each branch, lists the files it changes vs the base (default `main`)
  and prints any file changed by more than one branch. "Independent"
  issues are only safe to run in parallel agents when their branches are
  file-disjoint — overlapping branches should go to the SAME agent
  sequentially (or be merged one-at-a-time with a rebase between).

  Exit code 0 = disjoint (safe to parallelise). Exit code 1 = overlap.

.PARAMETER Branches
  Two or more branch names (positional / remaining args).

.PARAMETER Base
  Base ref to diff against. Default: main.

.EXAMPLE
  pwsh tools/check-overlap.ps1 fix/1729-presence fix/1730-week-badge
  pwsh tools/check-overlap.ps1 fix/a fix/b fix/c -Base main
#>
param(
    [Parameter(Mandatory = $true, ValueFromRemainingArguments = $true)][string[]]$Branches,
    [string]$Base = 'main'
)

$ErrorActionPreference = 'Stop'

if (@($Branches).Count -lt 2) {
    throw "Pass at least two branch names: tools/check-overlap.ps1 <branchA> <branchB> [...]"
}

$map = @{}
foreach ($b in $Branches) {
    $files = git diff --name-only "$Base...$b"
    if ($LASTEXITCODE -ne 0) {
        throw "git diff failed for '$b' — does the branch exist and share history with '$Base'?"
    }
    $map[$b] = @($files | Where-Object { $_ -ne '' })
}

$counts = @{}
$owners = @{}
foreach ($b in $Branches) {
    foreach ($f in $map[$b]) {
        if (-not $counts.ContainsKey($f)) { $counts[$f] = 0; $owners[$f] = @() }
        $counts[$f]++
        $owners[$f] += $b
    }
}

$shared = $counts.Keys | Where-Object { $counts[$_] -gt 1 } | Sort-Object

if (@($shared).Count -eq 0) {
    Write-Host "OK - no shared files. Branches are file-disjoint; merge in any order." -ForegroundColor Green
    exit 0
}

Write-Host "SHARED FILES - serialise these (same agent) or merge one-at-a-time with a rebase between:" -ForegroundColor Yellow
foreach ($f in $shared) {
    Write-Host ("  {0}  <-  {1}" -f $f, ($owners[$f] -join ', '))
}
exit 1
