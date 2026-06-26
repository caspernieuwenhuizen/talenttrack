<#
.SYNOPSIS
  Local mirror of the gating CI checks (.github/workflows/*). Run before you
  push so GitHub Actions never surprises you.

.DESCRIPTION
  Runs the checks that can run on a dev machine. Each gate reports PASS / FAIL /
  SKIP (skipped when the tool it needs isn't installed). Exits non-zero if any
  runnable gate failed, so it doubles as a pre-push hook.

  By default it scans only files changed vs origin/main (fast — matches what you
  just edited). Use -All to sweep the whole tree the way CI does.

  Prefers host tools (php, composer, msgfmt). Where a tool is missing but
  wp-env is up, it falls back to running inside the container.

.PARAMETER All
  Lint/scan the entire tree instead of just changed files.

.PARAMETER E2E
  Also run the Playwright E2E suite (slow; needs wp-env running).

.PARAMETER Mo
  Recompile languages/*.mo from *.po so local Dutch matches what ships.

.EXAMPLE
  ./tools/dev-check.ps1            # fast: changed files only
  ./tools/dev-check.ps1 -All       # full sweep like CI
  ./tools/dev-check.ps1 -Mo        # refresh .mo after editing a .po
#>
[CmdletBinding()]
param(
    [switch]$All,
    [switch]$E2E,
    [switch]$Mo
)

$ErrorActionPreference = 'Continue'
$repo = Split-Path -Parent $PSScriptRoot
Set-Location $repo

$results = [System.Collections.Generic.List[object]]::new()
function Record($name, $status, $detail = '') {
    $results.Add([pscustomobject]@{ Gate = $name; Status = $status; Detail = $detail })
    $color = switch ($status) { 'PASS' { 'Green' } 'FAIL' { 'Red' } 'SKIP' { 'DarkGray' } default { 'Gray' } }
    Write-Host ("  [{0,-4}] {1}" -f $status, $name) -ForegroundColor $color
    if ($detail) { Write-Host "         $detail" -ForegroundColor DarkGray }
}
function Have($cmd) { [bool](Get-Command $cmd -ErrorAction SilentlyContinue) }

# Resolve a PHP executable: prefer one on PATH, fall back to XAMPP's bundled PHP
# so the check works even before PATH changes reach the current shell.
$Php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $Php -and (Test-Path 'C:\xampp\php\php.exe')) { $Php = 'C:\xampp\php\php.exe' }
function HavePhp { [bool]$Php }

# Resolve the set of PHP files to lint.
function Get-PhpTargets {
    if ($All) {
        return Get-ChildItem -Recurse -Path 'src' -Filter '*.php' | Select-Object -ExpandProperty FullName
    }
    $changed = git diff --name-only --diff-filter=ACM origin/main...HEAD -- 'src/**/*.php' 2>$null
    $working = git diff --name-only --diff-filter=ACM -- 'src/**/*.php' 2>$null
    $staged  = git diff --name-only --cached --diff-filter=ACM -- 'src/**/*.php' 2>$null
    @($changed) + @($working) + @($staged) | Where-Object { $_ -and (Test-Path $_) } | Sort-Object -Unique
}

Write-Host "`nTalentTrack dev-check  ($(if($All){'full tree'}else{'changed files'}))`n" -ForegroundColor Cyan

# ---- Gate 1: PHP syntax lint (release.yml) ----------------------------------
$phpTargets = Get-PhpTargets
if (-not (HavePhp)) {
    Record 'PHP syntax lint' 'SKIP' 'no PHP found (runs in CI; or: install PHP 8.1+)'
} elseif (-not $phpTargets) {
    Record 'PHP syntax lint' 'PASS' 'no changed PHP files'
} else {
    $bad = @()
    foreach ($f in $phpTargets) {
        $out = & $Php -l $f 2>&1
        if ($LASTEXITCODE -ne 0) { $bad += "$f`n$out" }
    }
    if ($bad) { Record 'PHP syntax lint' 'FAIL' ($bad -join "`n") }
    else { Record 'PHP syntax lint' 'PASS' "$($phpTargets.Count) file(s)" }
}

# ---- Gate 2: PHPStan level 8 (advisory — CI runs it with `|| true`) ---------
if (-not (HavePhp)) {
    Record 'PHPStan (advisory)' 'SKIP' 'no PHP found'
} elseif (-not (Test-Path 'vendor/bin/phpstan')) {
    Record 'PHPStan (advisory)' 'SKIP' 'run `composer install` first'
} else {
    & $Php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
    # CI does not gate on PHPStan (it appends `|| true`), so neither do we.
    if ($LASTEXITCODE -eq 0) { Record 'PHPStan (advisory)' 'PASS' }
    else { Record 'PHPStan (advisory)' 'WARN' 'findings above — not a CI gate, but worth a look' }
}

# ---- Gate 3: PHP self-checks (release.yml lint job) -------------------------
$selfChecks = @('bin/qr-selfcheck.php', 'bin/admin-center-self-check.php')
foreach ($sc in $selfChecks) {
    if (-not (HavePhp)) { Record "self-check: $sc" 'SKIP' 'no PHP found'; continue }
    if (-not (Test-Path $sc)) { Record "self-check: $sc" 'SKIP' 'file missing'; continue }
    & $Php $sc | Out-Null
    if ($LASTEXITCODE -eq 0) { Record "self-check: $sc" 'PASS' }
    else { Record "self-check: $sc" 'FAIL' "php $sc exited $LASTEXITCODE" }
}

# ---- Gate 4: .po syntax (i18n-pr-check.yml / translations.yml) --------------
$poFiles = Get-ChildItem -Path 'languages' -Filter '*.po' -ErrorAction SilentlyContinue
if (-not $poFiles) {
    Record '.po syntax' 'SKIP' 'no .po files found'
} elseif (Have 'msgfmt') {
    $bad = @()
    foreach ($po in $poFiles) {
        $out = & msgfmt --check --statistics -o $env:TEMP\_devcheck.mo $po.FullName 2>&1
        if ($LASTEXITCODE -ne 0) { $bad += "$($po.Name)`n$out" }
    }
    if ($bad) { Record '.po syntax' 'FAIL' ($bad -join "`n") } else { Record '.po syntax' 'PASS' }
} else {
    Record '.po syntax' 'SKIP' 'gettext (msgfmt) not installed — runs in CI'
}

# ---- Optional: recompile .mo so local Dutch matches the release ZIP ---------
if ($Mo) {
    if (Have 'msgfmt') {
        foreach ($po in (Get-ChildItem 'languages' -Filter '*.po')) {
            & msgfmt -o ($po.FullName -replace '\.po$', '.mo') $po.FullName
        }
        Record '.mo recompiled (host msgfmt)' 'PASS'
    } else {
        # Container fallback — wp-cli ships make-mo, no host gettext needed.
        & npx wp-env run cli wp i18n make-mo languages languages 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { Record '.mo recompiled (wp-env)' 'PASS' }
        else { Record '.mo recompiled' 'FAIL' 'no msgfmt and wp-env make-mo failed — is wp-env up?' }
    }
}

# ---- Optional: Playwright E2E (e2e.yml) -------------------------------------
if ($E2E) {
    if (Test-Path 'node_modules/@playwright') {
        & npm run test:e2e
        if ($LASTEXITCODE -eq 0) { Record 'Playwright E2E' 'PASS' } else { Record 'Playwright E2E' 'FAIL' }
    } else {
        Record 'Playwright E2E' 'SKIP' 'run `npm install` first'
    }
}

# ---- Note the grep-based gates we deliberately do NOT reimplement -----------
Write-Host ""
Write-Host "  Not run locally (cheap, run in CI on your PR):" -ForegroundColor DarkGray
Write-Host "    legacy-sessions vocab gate, wizard-form-lint, lookup-translation-lint," -ForegroundColor DarkGray
Write-Host "    docs-audience markers, migration-lint, i18n hardcoded-English grep." -ForegroundColor DarkGray
Write-Host "    These only fail if you reintroduce a banned token — rare in normal work." -ForegroundColor DarkGray

# ---- Summary ----------------------------------------------------------------
$failed = $results | Where-Object Status -eq 'FAIL'
Write-Host ""
if ($failed) {
    Write-Host "dev-check FAILED — $($failed.Count) gate(s) red. Fix before pushing." -ForegroundColor Red
    exit 1
} else {
    Write-Host "dev-check OK — every runnable gate passed." -ForegroundColor Green
    exit 0
}

