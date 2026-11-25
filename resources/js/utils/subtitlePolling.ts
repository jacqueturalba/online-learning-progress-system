type QueueState = {
  connection?: string;
  queue?: string;
  waiting_seconds?: number;
  worker_stalled?: boolean;
  state?: string | null;
  worker_command?: string;
};
type WorkflowOperation = { status?: string; queue?: QueueState | null; chunks?: WorkflowOperation[] };
type Workflow = { preparations?: WorkflowOperation[]; transcripts?: WorkflowOperation[]; alignments?: WorkflowOperation[]; translations?: WorkflowOperation[] };

const activeStatuses = new Set(['queued', 'processing']);

export function subtitleWorkflowPollingState(workflow: Workflow) {
  const operations = [
    ...(workflow.preparations ?? []),
    ...(workflow.transcripts ?? []),
    ...(workflow.alignments ?? []),
    ...(workflow.translations ?? []),
  ];
  const workItems = operations.flatMap(operation => [operation, ...(operation.chunks ?? [])]);
  const stalled = workItems.find(operation => activeStatuses.has(operation.status ?? '') && operation.queue?.worker_stalled);
  const active = workItems.some(operation => activeStatuses.has(operation.status ?? '') && !operation.queue?.worker_stalled);

  return {
    shouldPoll: active,
    stalled,
    warning: stalled?.queue?.state === 'delayed'
      ? 'The WhisperX retry is delayed by its backoff. Automatic polling is paused; refresh status after the retry time.'
      : stalled?.queue?.worker_command
        ? `No worker progress has been detected for ${stalled.queue.waiting_seconds ?? 60} seconds (${stalled.queue.state ?? 'stalled'}). The ${stalled.queue.queue ?? 'queued'} queue may not be running. Check the worker: ${stalled.queue.worker_command}`
        : null,
  };
}
