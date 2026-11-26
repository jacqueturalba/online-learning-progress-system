param(
    [Parameter(Mandatory = $true)][string]$OutputDirectory,
    [ValidateRange(2, 20)][int]$SentencesPerWindow = 6,
    [ValidateRange(0, 5)][int]$OverlapSentences = 1
)

$ErrorActionPreference = 'Stop'
if ($OverlapSentences -ge $SentencesPerWindow) { throw 'Overlap must be smaller than the window.' }
$output = [System.IO.Path]::GetFullPath($OutputDirectory)
New-Item -ItemType Directory -Force -Path $output | Out-Null
$clips = Join-Path $output 'sentence-clips'
New-Item -ItemType Directory -Force -Path $clips | Out-Null

Add-Type -AssemblyName System.Speech
$synth = New-Object System.Speech.Synthesis.SpeechSynthesizer
$synth.Rate = -1
$format = New-Object System.Speech.AudioFormat.SpeechAudioFormatInfo(
    16000,
    [System.Speech.AudioFormat.AudioBitsPerSample]::Sixteen,
    [System.Speech.AudioFormat.AudioChannel]::Mono
)
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
$sentenceRanges = @()
$audioCursor = 0.0
$tokenCursor = 0
$tokenCounts = @()
$concatLines = [System.Collections.Generic.List[string]]::new()

try {
    for ($i = 0; $i -lt $sentences.Count; $i++) {
        $clip = Join-Path $clips ('sentence-{0:D3}.wav' -f $i)
        $synth.SetOutputToWaveFile($clip, $format)
        $synth.Speak($sentences[$i])
        $synth.SetOutputToNull()
        $bytes = [System.IO.File]::ReadAllBytes($clip)
        $sampleRate = [BitConverter]::ToInt32($bytes, 24)
        $byteRate = [BitConverter]::ToInt32($bytes, 28)
        # System.Speech emits an 18-byte fmt chunk, so the data size follows
        # its header at byte 42 (rather than byte 40 in the minimal fmt layout).
        $dataBytes = [BitConverter]::ToInt32($bytes, 42)
        if ($byteRate -le 0 -or $dataBytes -le 0 -or $sampleRate -ne 16000) { throw "Invalid generated WAV: $clip" }
        $clipDuration = $dataBytes / [double]$byteRate
        $words = $sentences[$i] -split '\s+'
        $sentenceRanges += [pscustomobject]@{
            token_start = $tokenCursor
            token_end = $tokenCursor + $words.Count
            audio_start = [math]::Round($audioCursor, 6)
            audio_end = [math]::Round(($audioCursor + $clipDuration), 6)
        }
        $tokenCursor += $words.Count
        $tokenCounts += $words.Count
        $audioCursor += $clipDuration
        $concatLines.Add("file '$($clip.Replace('\', '/'))'")
    }
} finally {
    $synth.Dispose()
}

$concatFile = Join-Path $output 'concat-list.txt'
[System.IO.File]::WriteAllLines($concatFile, $concatLines, [System.Text.UTF8Encoding]::new($false))
$audio = Join-Path $output 'synthetic-english.wav'
& ffmpeg -nostdin -hide_banner -v error -y -f concat -safe 0 -i $concatFile -c:a pcm_s16le -ar 16000 -ac 1 $audio
if ($LASTEXITCODE -ne 0) { throw 'FFmpeg failed to concatenate synthetic WAV clips.' }

$transcript = Join-Path $output 'transcript.txt'
[System.IO.File]::WriteAllText($transcript, ($sentences -join ' '), [System.Text.UTF8Encoding]::new($false))
$windows = @()
$startSentence = 0
while ($startSentence -lt $sentenceRanges.Count) {
    $endSentence = [math]::Min($sentenceRanges.Count, $startSentence + $SentencesPerWindow)
    $first = $sentenceRanges[$startSentence]
    $last = $sentenceRanges[$endSentence - 1]
    $windows += [pscustomobject]@{
        token_start = $first.token_start
        token_end = $last.token_end
        audio_start = $first.audio_start
        audio_end = $last.audio_end
    }
    if ($endSentence -eq $sentenceRanges.Count) { break }
    $startSentence = [math]::Max($startSentence + 1, $endSentence - $OverlapSentences)
}
$map = [ordered]@{
    schema_version = 1
    anchor_source = 'Synthetic TTS sentence-clip boundaries generated with this fixture.'
    windows = $windows
}
$windowMap = Join-Path $output 'window-map.json'
$json = ConvertTo-Json -InputObject $map -Depth 8
[System.IO.File]::WriteAllText($windowMap, $json, [System.Text.UTF8Encoding]::new($false))

$warmupEnd = $sentenceRanges[5].audio_end
$warmupAudio = Join-Path $output 'warmup.wav'
$warmupTranscript = Join-Path $output 'warmup-transcript.txt'
& ffmpeg -nostdin -hide_banner -v error -y -i $audio -t $warmupEnd -c:a pcm_s16le -ar 16000 -ac 1 $warmupAudio
if ($LASTEXITCODE -ne 0) { throw 'FFmpeg failed to create the short warm-up audio.' }
[System.IO.File]::WriteAllText($warmupTranscript, ($sentences[0..5] -join ' '), [System.Text.UTF8Encoding]::new($false))

$probe = & ffprobe -v error -show_entries format=duration -of json $audio | ConvertFrom-Json
[pscustomobject]@{
    audio_path = $audio
    transcript_path = $transcript
    window_map_path = $windowMap
    warmup_audio_path = $warmupAudio
    warmup_transcript_path = $warmupTranscript
    duration_seconds = [math]::Round([double]$probe.format.duration, 3)
    transcript_words = $tokenCursor
    transcript_characters = ($sentences -join ' ').Length
    window_count = $windows.Count
    overlap_sentences = $OverlapSentences
} | ConvertTo-Json -Compress
