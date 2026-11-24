# Local WhisperX forced aligner

This prototype only aligns a supplied transcript. It must not run transcription.

Install CPU-only PyTorch in `.venv`, then WhisperX:

```powershell
.venv\Scripts\python.exe -m pip install --index-url https://download.pytorch.org/whl/cpu torch==2.8.0+cpu torchaudio==2.8.0+cpu torchvision==0.23.0+cpu
.venv\Scripts\python.exe -m pip install whisperx==3.8.6
```

Run `align_transcript.py` with `--audio`, `--transcript`, `--output`, and optionally `--language` and `--device`. The runner calls only WhisperX's audio loader, alignment model loader, and `align()` API. It passes `interpolate_method="ignore"`; missing word times remain null in JSON. Alignment times are relative to the supplied chunk.

For a future Laravel integration, keep these relative timestamps in the alignment result and add the stored original chunk offset exactly once when converting to the lesson timeline. Do not add it in this runner or again during later result handling. Chunk merging and overlap deduplication are out of scope.

The transcript and audio used for this prototype belong under Laravel's private local storage. Keep alignment outputs there as well.
