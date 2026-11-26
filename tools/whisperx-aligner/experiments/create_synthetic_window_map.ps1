param(
    [Parameter(Mandatory = $true)][string]$FixtureDirectory,
    [ValidateRange(2, 20)][int]$SentencesPerWindow = 3,
    [ValidateRange(1, 5)][int]$OverlapSentences = 1
)

$ErrorActionPreference = 'Stop'
if ($OverlapSentences -ge $SentencesPerWindow) { throw 'Overlap must be smaller than the window.' }
$fixture = [System.IO.Path]::GetFullPath($FixtureDirectory)
$parts = @(
    'Today we study careful language and compare each phrase with its surrounding passage.',
    'Readers preserve repeated words, punctuation, and the exact order of the supplied transcript.',
    'A quiet pause gives the next sentence room to begin clearly and naturally.',
    'Every window uses trusted sentence times instead of guessing from a word count.',
    'Overlapping context helps keep a word intact when its sound crosses a boundary.',
    'The model returns word times, scores, and gaps that require human review.',
    'A repeated phrase remains distinct because each occurrence has its own source position.',
    'Clear anchors keep the audio interval synchronized with the matching transcript tokens.',
    'This generated sample contains ordinary speech, varied punctuation, and recurring vocabulary.',
    'The experiment compares runtime while preserving each original token without alteration.'
)
$sentences = for ($i = 0; $i -lt 50; $i++) { $parts[$i % $parts.Count] }
$ranges = @()
$audioCursor = 0.0
$tokenCursor = 0
for ($i = 0; $i -lt $sentences.Count; $i++) {
    $clip = Join-Path (Join-Path $fixture 'sentence-clips') ('sentence-{0:D3}.wav' -f $i)
    $bytes = [System.IO.File]::ReadAllBytes($clip)
    $sampleRate = [BitConverter]::ToInt32($bytes, 24)
    $byteRate = [BitConverter]::ToInt32($bytes, 28)
    $dataSize = [BitConverter]::ToInt32($bytes, 42)
    if ($sampleRate -ne 16000 -or $byteRate -le 0 -or $dataSize -le 0) { throw "Invalid synthetic audio clip: $clip" }
    $duration = $dataSize / [double]$byteRate
    $wordCount = ($sentences[$i] -split '\s+').Count
    $ranges += [pscustomobject]@{
        token_start = $tokenCursor
        token_end = $tokenCursor + $wordCount
        audio_start = [math]::Round($audioCursor, 6)
        audio_end = [math]::Round(($audioCursor + $duration), 6)
    }
    $tokenCursor += $wordCount
    $audioCursor += $duration
}

$windows = @()
$startSentence = 0
while ($startSentence -lt $ranges.Count) {
    $endSentence = [math]::Min($ranges.Count, $startSentence + $SentencesPerWindow)
    $windows += [pscustomobject]@{
        token_start = $ranges[$startSentence].token_start
        token_end = $ranges[$endSentence - 1].token_end
        audio_start = $ranges[$startSentence].audio_start
        audio_end = $ranges[$endSentence - 1].audio_end
    }
    if ($endSentence -eq $ranges.Count) { break }
    $startSentence = [math]::Max($startSentence + 1, $endSentence - $OverlapSentences)
}

$path = Join-Path $fixture "window-map-$SentencesPerWindow-sentences.json"
$map = [ordered]@{
    schema_version = 1
    anchor_source = 'Synthetic TTS sentence-clip boundaries generated with the local fixture.'
    windows = $windows
}
[System.IO.File]::WriteAllText($path, (ConvertTo-Json -InputObject $map -Depth 8), [System.Text.UTF8Encoding]::new($false))
[pscustomobject]@{ path = $path; window_count = $windows.Count; sentences_per_window = $SentencesPerWindow; overlap_sentences = $OverlapSentences } | ConvertTo-Json -Compress
