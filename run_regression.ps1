<#
.SYNOPSIS
    TinyPHP regression test runner.

.DESCRIPTION
    Runs every test/*.php file that declares `class Main` through
    `php tphp.php <entry.php> [<companions>...] --debug -o <out.exe>`
    using the built-in TCC compiler (default).

    Verifies that recent changes to tphp.php (#flag magic constant expansion
    for __EXT__/__INC__/__CMD__) and src/CodeGenerator.php (self-param
    double-prefix fix in generateMethodOverloads) did not break existing
    functionality.

    Pass criteria (matches tphp.php output markers):
      - exit code 0, AND
      - output contains [PASS] OR ([YES] appears AND no [NO]), AND
      - output contains no [FAIL] / [NO] markers.

    Multi-file tests are handled two ways:
      1. Explicit: entry file header `// @multi @with f1.php,f2.php,...`
         (paths relative to entry dir; may include subdirs).
      2. Implicit: if no @multi annotation, sibling .php files in the same
         directory carrying a general `// @skip` annotation (and no
         `class Main` of their own) are auto-included as companions.

    Skipped tests (infrastructure issues, NOT regressions):
      - test/ext/libevent_min.php        (known link failure — libevent not built)
      - test/platform/posix_test.php     (POSIX-only; fatal error on Windows)
      - test/platform/pcntl_test.php     (POSIX-only; fatal error on Windows)
      - Files with `@skip:Windows+TCC` (none currently, but supported)
      - On non-macOS+TCC: files with `@skip:macos+tcc` are RUN (not skipped)

.PARAMETER PhpExe
    PHP executable name/path. Default: php (resolves to php.exe on PATH).

.EXAMPLE
    PS> .\run_regression.ps1
    Runs all regression tests with default settings.

.EXAMPLE
    PS> .\run_regression.ps1 -Verbose
    Runs with per-test verbose output (uses the common -Verbose flag).
#>
[CmdletBinding()]
param(
    [string]$PhpExe = 'php'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

# Use the standard -Verbose common parameter (sets $VerbosePreference).
$verboseOn = ($VerbosePreference -ne 'SilentlyContinue')

$TphpDir  = $PSScriptRoot
$tphp     = Join-Path $TphpDir 'tphp.php'
$testRoot = Join-Path $TphpDir 'test'
$buildDir = Join-Path $TphpDir 'build'

if (-not (Test-Path -LiteralPath $tphp)) {
    throw "tphp.php not found at: $tphp"
}
if (-not (Test-Path -LiteralPath $testRoot)) {
    throw "test/ directory not found at: $testRoot"
}
if (-not (Test-Path -LiteralPath $buildDir)) {
    New-Item -ItemType Directory -Path $buildDir | Out-Null
}

# Hardcoded skips — infrastructure issues, NOT regressions.
$explicitSkip = @(
    'test\ext\libevent_min.php',
    'test\platform\posix_test.php',
    'test\platform\pcntl_test.php'
) | ForEach-Object { (Join-Path $TphpDir $_).ToLower() }

# Pattern: a `class Main` declaration at the start of a line (avoids matching
# the phrase "class Main" inside comments like `// @skip — no class Main`).
$entryPattern = '(?m)^\s*class\s+Main\b'

# Platform + compiler we run as (used to evaluate @skip:os+cc qualifiers).
$curOS = 'windows'
$curCC = 'tcc'

# ---- Discover entry candidates -------------------------------------------------

$entries = New-Object System.Collections.ArrayList

Get-ChildItem -Path $testRoot -Recurse -Filter *.php -File | ForEach-Object {
    $path    = $_.FullName
    $content = Get-Content -LiteralPath $path -Raw -ErrorAction SilentlyContinue
    if (-not $content) { return }

    # Must declare `class Main` (word-bounded, start of line).
    if ($content -notmatch $entryPattern) { return }

    # Evaluate @skip:os+cc (platform+compiler-specific skip).
    if ($content -match '@skip:(\w+)\+(\w+)') {
        $skipOS = $matches[1].ToLower()
        $skipCC = $matches[2].ToLower()
        if ($curOS -like "*$skipOS*" -and $curCC -eq $skipCC) {
            # Platform+CC matches current env → skip this entry.
            return
        }
        # Otherwise (e.g. @skip:macos+tcc on Windows+TCC) → RUN it.
    }

    # Hardcoded skip list.
    if ($path.ToLower() -in $explicitSkip) { return }

    [void]$entries.Add($path)
}

$entries.Sort()

# ---- Run each entry ------------------------------------------------------------

Write-Host "TinyPHP Regression Test Runner"
Write-Host "Tphp:      $tphp"
Write-Host "TestRoot:  $testRoot"
Write-Host "Compiler:  TCC (built-in)"
Write-Host "Entries:   $($entries.Count)"
Write-Host ('=' * 70)

$passed = New-Object System.Collections.Generic.List[string]
$failed = New-Object System.Collections.Generic.List[object]
$total  = $entries.Count
$idx    = 0

foreach ($entry in $entries) {
    $idx++
    $rel      = $entry.Substring($TphpDir.Length + 1)
    $base     = [System.IO.Path]::GetFileNameWithoutExtension($entry)
    $entryDir = [System.IO.Path]::GetDirectoryName($entry)

    # Sanitize relative path → unique output basename (avoids collisions
    # between e.g. test/main/main.php and test/object/main.php).
    $safeName = ($rel -replace '[^\w.]', '_')
    $outExe   = Join-Path $buildDir ("reg_$safeName.exe")

    # Build the positional arg list: entry + companions.
    $argsList = New-Object System.Collections.ArrayList
    [void]$argsList.Add($entry)

    $content = Get-Content -LiteralPath $entry -Raw
    $usedMulti = $false

    if ($content -match '@multi\s+@with\s+([^\r\n]+)') {
        # Explicit companion list (comma-separated; relative to entry dir,
        # may include subdirectory paths).
        $usedMulti = $true
        foreach ($c in ($matches[1] -split ',')) {
            $c = $c.Trim()
            if ($c -eq '') { continue }
            $compPath = Join-Path $entryDir $c
            $compPath = [System.IO.Path]::GetFullPath($compPath)
            if (Test-Path -LiteralPath $compPath) {
                [void]$argsList.Add($compPath)
            } else {
                Write-Warning "[$rel] @multi companion not found: $c"
            }
        }
    }

    if (-not $usedMulti) {
        # Implicit auto-include: ONLY when the directory has exactly ONE
        # `@skip` companion (general @skip mentioning "companion", not
        # platform-specific, and no `class Main` of its own). This handles
        # the test/phpc/ pattern (main.php + phpc.php) without breaking
        # directories like test/object/ where 6 different companions belong
        # to 4 different entry points — those entries MUST use @multi @with.
        $companionCandidates = @()
        Get-ChildItem -LiteralPath $entryDir -Filter *.php -File |
            Where-Object { $_.FullName -ne $entry } |
            ForEach-Object {
                $cPath = $_.FullName
                $cContent = Get-Content -LiteralPath $cPath -Raw -ErrorAction SilentlyContinue
                if (-not $cContent) { return }
                # Must have a general @skip (not @skip:os+cc platform-specific).
                if ($cContent -notmatch '@skip') { return }
                if ($cContent -match '@skip:\w+\+\w+') { return }
                # Must NOT have its own `class Main` (else it's an entry, not companion).
                if ($cContent -match $entryPattern) { return }
                # Must be a TinyPHP companion (exclude "native PHP" benchmarks etc.).
                if ($cContent -notmatch 'companion') { return }
                $companionCandidates += $cPath
            }
        if ($companionCandidates.Count -eq 1) {
            [void]$argsList.Add($companionCandidates[0])
        }
        # If 0 or >1 companions: run standalone (entry must use @multi @with
        # to specify which companions it needs).
    }

    # Compose the php invocation. Use --debug (runs binary if #debug present,
    # compares output). -o forces a unique output path per test.
    $cmdArgs = @($tphp) + $argsList + @('--debug', '-o', $outExe)

    if ($verboseOn) {
        Write-Host ""
        Write-Host "[$idx/$total] $rel  (inputs: $($argsList.Count))" -NoNewline
    } else {
        $tag = "[$idx/$total]".PadLeft(8)
        Write-Host "$tag $rel " -NoNewline
    }

    # Capture stdout+stderr together. Use a sub-shell to avoid cmdlet quirks
    # with mixed streams.
    $output = & $PhpExe @cmdArgs 2>&1 | Out-String
    $exitCode = $LASTEXITCODE

    # ---- Determine pass/fail -------------------------------------------------
    $isPass = $false
    if ($exitCode -eq 0) {
        $hasNo    = $output -match '(?m)^\s*\[NO\]'
        $hasFail  = $output -match '(?m)\[FAIL\]'
        $hasPass  = $output -match '\[PASS\]'
        $hasYes   = $output -match '\[YES\]'
        if (-not $hasNo -and -not $hasFail -and ($hasPass -or $hasYes)) {
            $isPass = $true
        }
    }

    if ($isPass) {
        $passed.Add($rel)
        Write-Host "PASS" -ForegroundColor Green
    } else {
        # Collect representative error lines for the report.
        $errLines = New-Object System.Collections.ArrayList
        foreach ($line in ($output -split "`r?`n")) {
            if ($line -match '\[NO\]|\[FAIL\]|error:|Error:|Fatal error|expected:|got\s+:|undefined|cannot|failed:') {
                $t = $line.Trim()
                if ($t -ne '') { [void]$errLines.Add($t) }
            }
        }
        if ($errLines.Count -eq 0) {
            # Fallback: last 8 non-empty lines.
            $tail = ($output -split "`r?`n") |
                Where-Object { $_.Trim() -ne '' } |
                Select-Object -Last 8
            if ($tail) { [void]$errLines.AddRange([string[]]$tail) }
        }
        # Cap to 12 lines to keep output readable.
        if ($errLines.Count -gt 12) {
            $errLines = $errLines.GetRange(0, 12)
            [void]$errLines.Add("... (truncated)")
        }
        $failed.Add([PSCustomObject]@{
            File     = $rel
            ExitCode = $exitCode
            Errors   = $errLines
        })
        Write-Host "FAIL" -ForegroundColor Red
    }
}

# ---- Summary ------------------------------------------------------------------

Write-Host ('=' * 70)
$passCount = $passed.Count
$failCount = $failed.Count
Write-Host ("PASS: {0} | FAIL: {1} | TOTAL: {2}" -f $passCount, $failCount, $total)
Write-Host ('=' * 70)

if ($failCount -gt 0) {
    Write-Host ""
    Write-Host "Failed tests:" -ForegroundColor Red
    Write-Host ""
    foreach ($f in $failed) {
        Write-Host ("  FAIL: {0}  (exit={1})" -f $f.File, $f.ExitCode) -ForegroundColor Red
        foreach ($e in $f.Errors) {
            Write-Host "    $e"
        }
        Write-Host ""
    }

    # Highlight failures likely related to the recent edits.
    Write-Host "Related-to-changes check:" -ForegroundColor Yellow
    $flagRelated   = $failed | Where-Object {
        $_.Errors -join '`n' -match '#flag|__EXT__|__INC__|__CMD__|libevent|-I|tcc\.exe'
    }
    $paramRelated  = $failed | Where-Object {
        $_.File -match 'default_params|test_default' -or
        ($_.Errors -join '`n' -match 'tphp_class_|self|\* self|overload|default')
    }

    if ($flagRelated.Count -gt 0) {
        Write-Host "  [!] Failures possibly related to #flag magic-constant change:" -ForegroundColor Yellow
        foreach ($f in $flagRelated) { Write-Host "      - $($f.File)" }
    } else {
        Write-Host "  [OK] No #flag/__EXT__-related failures detected." -ForegroundColor Green
    }
    if ($paramRelated.Count -gt 0) {
        Write-Host "  [!] Failures possibly related to self-param / default-params change:" -ForegroundColor Yellow
        foreach ($f in $paramRelated) { Write-Host "      - $($f.File)" }
    } else {
        Write-Host "  [OK] No self-param / default-params related failures detected." -ForegroundColor Green
    }

    exit 1
} else {
    Write-Host ""
    Write-Host "All tests passed." -ForegroundColor Green
    exit 0
}
