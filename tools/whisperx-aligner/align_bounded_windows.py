#!/usr/bin/env python3
"""Experimental timestamp-anchored bounded WhisperX alignment runner."""
from __future__ import annotations
import argparse, gc, json, math, os, sys, time
from pathlib import Path
from typing import Any
import align_transcript as baseline
from process_metrics import average_cpu_cores, peak_working_set_mib

SAMPLE_RATE = 16_000

def merge_window_results(source_words: list[str], windows: list[dict[str, Any]], aligned_windows: list[list[dict[str, Any]] | None], duration: float) -> tuple[list[dict[str, Any]], list[dict[str, Any]], int]:
    """Merge candidate timings by source-token position; repeated spellings remain distinct."""
    if len(windows) != len(aligned_windows):
        raise ValueError('Each window must have a corresponding result slot.')
    candidates: list[list[tuple[float, float, dict[str, Any]]]] = [[] for _ in source_words]
    errors: list[dict[str, Any]] = []
    duplicate_count = 0
    for wi, (window, entries) in enumerate(zip(windows, aligned_windows)):
        if entries is None:
            errors.append({'window_index': wi, 'error_type': 'WindowAlignmentFailed'})
            continue
        start_index, end_index = window['token_start'], window['token_end']
        mapped, _ = baseline.word_mapping(source_words[start_index:end_index], entries)
        if len(mapped) < end_index - start_index:
            errors.append({'window_index': wi, 'error_type': 'UnmatchedWindowTokens', 'unmatched_token_count': end_index - start_index - len(mapped)})
        for local_index, word in mapped.items():
            token_index = start_index + local_index
            if candidates[token_index]: duplicate_count += 1
            start, end, score = word.get('start'), word.get('end'), word.get('score')
            if not (baseline.valid_time(start, duration) and baseline.valid_time(end, duration) and end > start
                    and float(start) >= float(window['audio_start']) and float(end) <= float(window['audio_end'])):
                continue
            margin = min(float(start) - float(window['audio_start']), float(window['audio_end']) - float(end))
            finite_score = isinstance(score, (int, float)) and math.isfinite(score)
            candidates[token_index].append((margin, float(score) if finite_score else -math.inf, word))

    output = []
    for index, token in enumerate(source_words):
        choices = candidates[index]
        chosen = max(choices, key=lambda item: (item[0], item[1]))[2] if choices else {}
        start, end, score = chosen.get('start'), chosen.get('end'), chosen.get('score')
        valid = baseline.valid_time(start, duration) and baseline.valid_time(end, duration) and end > start
        finite_score = isinstance(score, (int, float)) and math.isfinite(score)
        output.append({'text': token, 'start': round(float(start), 3) if valid else None,
                       'end': round(float(end), 3) if valid else None,
                       'score': float(score) if finite_score else None,
                       'status': 'review_low_score' if valid and finite_score and score <= 0 else ('aligned' if valid else 'missing_or_unreliable'),
                       'source_token_index': index})
    return output, errors, duplicate_count

def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--audio', required=True, type=Path)
    parser.add_argument('--transcript', required=True, type=Path)
    parser.add_argument('--window-map', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--language', default='en')
    parser.add_argument('--device', default='cpu')
    args = parser.parse_args()
    started = time.perf_counter(); cpu_started = time.process_time()
    stage = {key: None for key in ('torch_import_seconds','whisperx_import_seconds','pytorch_thread_setup_seconds','audio_decode_seconds','alignment_model_load_seconds','whisperx_align_seconds','window_merge_seconds','output_processing_seconds')}
    report: dict[str, Any] = {'total_words':0,'successfully_aligned_words':0,'missing_or_unreliable_words':0,'low_score_word_count':0,'alignment_duration_seconds':None,'total_script_runtime_seconds':None,'peak_working_set_mib':None,'average_cpu_cores':None,'stage_timings_seconds':stage,'audio_duration_seconds':None,'processing_device':args.device,'cpu_thread_count':None,'model_used':None,'warnings':[],'errors':[],'window_errors':[],'duplicate_candidates_resolved':0,'timestamp_order_violations':[],'timeline':'Relative to the supplied audio chunk; no original timeline offset is applied.'}
    args.output.mkdir(parents=True, exist_ok=True)
    try:
        transcript_text=args.transcript.read_text(encoding='utf-8-sig')
        source_words=transcript_text.split()
        if not source_words: raise ValueError('Transcript is empty.')
        report['total_words']=len(source_words)
        data=json.loads(args.window_map.read_text(encoding='utf-8'))
        windows=data.get('windows') if isinstance(data,dict) else None
        if not isinstance(windows,list) or not windows: raise ValueError('Window map is empty.')
        if args.device == 'cpu':
            threads=max(1,int(os.environ.get('WHISPERX_CPU_THREADS','2'))); report['cpu_thread_count']=threads
            os.environ['OMP_NUM_THREADS']=str(threads); os.environ['MKL_NUM_THREADS']=str(threads)
            tick=time.perf_counter(); import torch; stage['torch_import_seconds']=round(time.perf_counter()-tick,3)
            tick=time.perf_counter(); torch.set_num_threads(threads); torch.set_num_interop_threads(1); stage['pytorch_thread_setup_seconds']=round(time.perf_counter()-tick,3)
        tick=time.perf_counter(); import whisperx; from whisperx.alignment import DEFAULT_ALIGN_MODELS_TORCH; stage['whisperx_import_seconds']=round(time.perf_counter()-tick,3)
        model_name=DEFAULT_ALIGN_MODELS_TORCH.get(args.language)
        if not model_name: raise ValueError(f'No default alignment model for {args.language!r}.')
        report['model_used']=model_name
        tick=time.perf_counter(); audio=whisperx.load_audio(str(args.audio)); stage['audio_decode_seconds']=round(time.perf_counter()-tick,3)
        duration=len(audio)/SAMPLE_RATE; report['audio_duration_seconds']=round(duration,3)
        tick=time.perf_counter(); model,metadata=whisperx.load_align_model(language_code=args.language,device=args.device); stage['alignment_model_load_seconds']=round(time.perf_counter()-tick,3)
        coverage=[0]*len(source_words); errors=[]; aligned_windows=[]
        for wi,window in enumerate(windows):
            ts,te=window.get('token_start'),window.get('token_end'); a0,a1=window.get('audio_start'),window.get('audio_end')
            if not isinstance(ts,int) or not isinstance(te,int) or ts<0 or te<=ts or te>len(source_words) or not isinstance(a0,(int,float)) or not isinstance(a1,(int,float)) or a0<0 or a1<=a0 or a1>duration:
                raise ValueError(f'Window {wi} has invalid or out-of-audio anchors.')
            for ti in range(ts,te): coverage[ti]+=1
        if any(count==0 for count in coverage): raise ValueError('Window map does not cover every source token.')
        align_started=time.perf_counter()
        for wi,window in enumerate(windows):
            ts,te=window['token_start'],window['token_end']; text=' '.join(source_words[ts:te])
            try:
                result=whisperx.align([{'start':float(window['audio_start']),'end':float(window['audio_end']),'text':text}],model,metadata,audio,args.device,interpolate_method='ignore')
                entries=result.get('word_segments',[]); aligned_windows.append(entries)
            except Exception as exc:
                aligned_windows.append(None)
                errors.append({'window_index':wi,'error_type':type(exc).__name__,'message':str(exc)[:500]})
        stage['whisperx_align_seconds']=round(time.perf_counter()-align_started,3)
        tick=time.perf_counter(); output,merge_errors,duplicates=merge_window_results(source_words,windows,aligned_windows,duration)
        errors.extend(error for error in merge_errors if error['error_type'] != 'WindowAlignmentFailed')
        report['window_errors']=errors; report['duplicate_candidates_resolved']=duplicates
        report['timestamp_order_violations']=[]
        previous_start=None
        for index,word in enumerate(output):
            if word['start'] is None: continue
            if previous_start is not None and word['start']<previous_start: report['timestamp_order_violations'].append(index)
            previous_start=word['start']
        report['successfully_aligned_words']=sum(w['status']=='aligned' for w in output)
        report['low_score_word_count']=sum(w['status']=='review_low_score' for w in output)
        report['missing_or_unreliable_words']=len(output)-report['successfully_aligned_words']
        if errors: report['warnings'].append(f'{len(errors)} bounded window(s) had errors or unmatched tokens.')
        stage['window_merge_seconds']=round(time.perf_counter()-tick,3)
        tick=time.perf_counter(); baseline.write_json(args.output/'aligned.json',{'language':args.language,'audio_duration_seconds':round(duration,3),'words':output})
        srt,omitted=baseline.make_srt(output); (args.output/'aligned.srt').write_text(srt,encoding='utf-8')
        if omitted: report['warnings'].append(f'{omitted} untimed word(s) omitted from timed cues.')
        stage['output_processing_seconds']=round(time.perf_counter()-tick,3)
        report['alignment_duration_seconds']=stage['whisperx_align_seconds']; report['total_script_runtime_seconds']=round(time.perf_counter()-started,3)
        report['peak_working_set_mib']=peak_working_set_mib(); report['average_cpu_cores']=average_cpu_cores(cpu_started,time.perf_counter()-started)
        baseline.write_json(args.output/'alignment-report.json',report)
        del audio,model,metadata; gc.collect()
        print(json.dumps({'total_words':report['total_words'],'successfully_aligned_words':report['successfully_aligned_words'],'missing_or_unreliable_words':report['missing_or_unreliable_words'],'window_errors':len(errors)}))
        return 0
    except Exception as exc:
        report['alignment_duration_seconds']=stage['whisperx_align_seconds']; report['errors'].append({'type':type(exc).__name__,'message':str(exc)[:500]})
        report['total_script_runtime_seconds']=round(time.perf_counter()-started,3); report['peak_working_set_mib']=peak_working_set_mib(); report['average_cpu_cores']=average_cpu_cores(cpu_started,time.perf_counter()-started)
        baseline.write_json(args.output/'alignment-report.json',report)
        print(f'Bounded alignment failed: {type(exc).__name__}: {exc}',file=sys.stderr)
        return 1

if __name__=='__main__': raise SystemExit(main())
