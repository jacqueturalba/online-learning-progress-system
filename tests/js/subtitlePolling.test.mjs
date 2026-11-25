import test from 'node:test';
import assert from 'node:assert/strict';
import { subtitleWorkflowPollingState } from '../../resources/js/utils/subtitlePolling.ts';

test('stops polling after every operation reaches a terminal status', () => {
  const state = subtitleWorkflowPollingState({
    preparations: [{ status: 'succeeded' }],
    transcripts: [{ status: 'succeeded' }],
    alignments: [{ status: 'failed' }],
    translations: [],
  });

  assert.equal(state.shouldPoll, false);
  assert.equal(state.warning, null);
});

test('polls while a queued operation is still within its worker wait window', () => {
  const state = subtitleWorkflowPollingState({
    preparations: [{ status: 'queued', queue: { worker_stalled: false, waiting_seconds: 20 } }],
  });

  assert.equal(state.shouldPoll, true);
  assert.equal(state.warning, null);
});

test('stops polling a queued operation with no progress and gives the worker command', () => {
  const state = subtitleWorkflowPollingState({
    preparations: [{ status: 'queued', queue: {
      worker_stalled: true,
      queue: 'whisperx-alignment',
      waiting_seconds: 90,
      worker_command: 'php artisan queue:work whisperx --queue=whisperx-alignment --sleep=3 --tries=2 --timeout=7500',
    } }],
  });

  assert.equal(state.shouldPoll, false);
  assert.match(state.warning, /No worker progress/);
  assert.match(state.warning, /php artisan queue:work whisperx --queue=whisperx-alignment --sleep=3 --tries=2 --timeout=7500/);
});

test('does not poll a queued alignment when the dedicated worker is absent', () => {
  const state = subtitleWorkflowPollingState({
    alignments: [{ status: 'queued', queue: {
      state: 'pending_no_worker', worker_stalled: true, queue: 'whisperx-alignment', waiting_seconds: 100,
      worker_command: 'php artisan queue:work whisperx --queue=whisperx-alignment --sleep=3 --tries=2 --timeout=7500',
    } }],
  });

  assert.equal(state.shouldPoll, false);
  assert.match(state.warning, /No worker progress/);
  assert.match(state.warning, /pending_no_worker/);
});

test('pauses polling during a scheduled alignment retry delay', () => {
  const state = subtitleWorkflowPollingState({
    alignments: [{ status: 'queued', queue: { state: 'delayed', worker_stalled: true } }],
  });

  assert.equal(state.shouldPoll, false);
  assert.match(state.warning, /retry is delayed/);
});
