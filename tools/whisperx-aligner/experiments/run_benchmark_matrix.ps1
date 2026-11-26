param(
    [Parameter(Mandatory = $true)][string]$FixtureDirectory,
    [Parameter(Mandatory = $true)][string]$ResultsDirectory,
    [string]$Python = 'tools/whisperx-aligner/.venv/Scripts/python.exe',
    [int[]]$ThreadCounts = @(1, 2, 4, 8),
    [ValidateRange(1, 5)][int]$Repeats = 2,
    [switch]$SkipWarmup,
    [switch]$SkipBounded,
    [ValidateRange(1, 32)][int]$BoundedThreads = 4
)

$ErrorActionPreference = 'Stop'
$fixture = [System.IO.Path]::GetFullPath($FixtureDirectory)
$results = [System.IO.Path]::GetFullPath($ResultsDirectory)
New-Item -ItemType Directory -Force -Path $results | Out-Null
$audio = Join-Path $fixture 'synthetic-english.wav'
$transcript = Join-Path $fixture 'transcript.txt'
$windowMap = Join-Path $fixture 'window-map.json'
$python = [System.IO.Path]::GetFullPath($Python)
$scriptRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$baselineScript = Join-Path $scriptRoot 'align_transcript.py'
$boundedScript = Join-Path $scriptRoot 'align_bounded_windows.py'

function Invoke-TrackedAlignment([string]$Name, [int]$Threads, [string]$Runner, [bool]$UseWindows) {
    $output = Join-Path $results $Name
    New-Item -ItemType Directory -Force -Path $output | Out-Null
    $stdout = Join-Path $output 'stdout.log'
    $stderr = Join-Path $output 'stderr.log'
    $env:WHISPERX_CPU_THREADS = [string]$Threads
    $arguments = @($Runner, '--audio', $audio, '--transcript', $transcript, '--output', $output, '--language', 'en', '--device', 'cpu')
    if ($UseWindows) { $arguments += @('--window-map', $windowMap) }
    $process = Start-Process -FilePath $python -ArgumentList $arguments -PassThru -Wait -NoNewWindow -RedirectStandardOutput $stdout -RedirectStandardError $stderr
    if ($process.ExitCode -ne 0) { throw "$Name failed with exit code $($process.ExitCode); see $stderr" }
    $report = Get-Content -LiteralPath (Join-Path $output 'alignment-report.json') -Raw | ConvertFrom-Json
    [pscustomobject]@{
        name = $Name
        runner = if ($UseWindows) { 'bounded_windows' } else { 'single_call' }
        threads = $Threads
        wall_seconds = $report.total_script_runtime_seconds
        align_seconds = $report.alignment_duration_seconds
        peak_working_set_mib = $report.peak_working_set_mib
        average_cpu_cores = $report.average_cpu_cores
        successfully_aligned_words = $report.successfully_aligned_words
        missing_or_unreliable_words = $report.missing_or_unreliable_words
        window_errors = @($report.window_errors).Count
        report_path = (Join-Path $output 'alignment-report.json')
        json_path = (Join-Path $output 'aligned.json')
    }
}

$fixtureInfo = Get-Content -LiteralPath (Join-Path $fixture 'window-map.json') -Raw | ConvertFrom-Json
$warmupWords = (Get-Content -LiteralPath (Join-Path $fixture 'warmup-transcript.txt') -Raw) -split '\s+' | Where-Object { $_ }
$warmupInfo = [ordered]@{
    schema_version = 1
    anchor_source = 'First generated TTS sentence clip boundaries.'
    windows = @([ordered]@{
        token_start = 0
        token_end = $warmupWords.Count
        audio_start = 0.0
        audio_end = [double]$fixtureInfo.windows[0].audio_end
    })
}
$warmupMap = Join-Path $fixture 'warmup-window-map.json'
[System.IO.File]::WriteAllText($warmupMap, (ConvertTo-Json -InputObject $warmupInfo -Depth 5), [System.Text.UTF8Encoding]::new($false))
if (-not $SkipWarmup) {
    $env:WHISPERX_CPU_THREADS = '2'
    $warmupOutput = Join-Path $results 'warmup'
    New-Item -ItemType Directory -Force -Path $warmupOutput | Out-Null
    & $python $baselineScript --audio (Join-Path $fixture 'warmup.wav') --transcript (Join-Path $fixture 'warmup-transcript.txt') --output $warmupOutput --language en --device cpu
    if ($LASTEXITCODE -ne 0) { throw 'WhisperX model warm-up failed.' }
}

$rows = [System.Collections.Generic.List[object]]::new()
foreach ($threads in $ThreadCounts) {
    for ($run = 1; $run -le $Repeats; $run++) {
        $rows.Add((Invoke-TrackedAlignment "baseline-t$threads-r$run" $threads $baselineScript $false))
    }
}
if (-not $SkipBounded) {
    for ($run = 1; $run -le $Repeats; $run++) {
        $rows.Add((Invoke-TrackedAlignment "bounded-t$BoundedThreads-r$run" $BoundedThreads $boundedScript $true))
    }
}

$json = ConvertTo-Json -InputObject @($rows) -Depth 6
[System.IO.File]::WriteAllText((Join-Path $results 'benchmark-results.json'), $json, [System.Text.UTF8Encoding]::new($false))
@($rows) | Select-Object name, runner, threads, wall_seconds, align_seconds, peak_working_set_mib, average_cpu_cores, successfully_aligned_words, missing_or_unreliable_words, window_errors | Format-Table -AutoSize
