export function reconcileServerDraft(
  drafts: Record<number, string>,
  serverValues: Record<number, string>,
  operationId: number,
  serverText: string,
): void {
  const previousServerText = serverValues[operationId];
  if (drafts[operationId] === undefined || drafts[operationId] === previousServerText) {
    drafts[operationId] = serverText;
  }
  serverValues[operationId] = serverText;
}
