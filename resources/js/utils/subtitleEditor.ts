export type SubtitleCue = { index: number; start: string; end: string; text: string };
export type SubtitleSection = { key: number; cues: SubtitleCue[]; range: string };

export function parseSubtitleSrt(contents: string): SubtitleCue[] | null {
  const normalized = contents.replace(/^\uFEFF/, '').trim();
  if (!normalized) return null;
  const blocks = normalized.split(/\r?\n\s*\r?\n/);
  const cues: SubtitleCue[] = [];
  const indexes = new Set<number>();
  for (const block of blocks) {
    const lines = block.trim().split(/\r?\n/);
    if (lines.length < 3 || !/^\d+$/.test(lines[0].trim())) return null;
    const index = Number(lines[0].trim());
    const timing = lines[1].trim().match(/^(\d{2}:\d{2}:\d{2},\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2},\d{3})$/);
    const text = lines.slice(2).join('\n');
    if (!Number.isSafeInteger(index) || index < 1 || indexes.has(index) || !timing || !text.trim()) return null;
    indexes.add(index);
    cues.push({ index, start: timing[1], end: timing[2], text });
  }
  return cues.length ? cues : null;
}

export function renderSubtitleSrt(cues: SubtitleCue[]): string {
  return `${cues.map(cue => `${cue.index}\n${cue.start} --> ${cue.end}\n${cue.text}`).join('\n\n')}\n`;
}

export function subtitleSections(cues: SubtitleCue[], minutes = 5): SubtitleSection[] {
  const durationMs = minutes * 60_000;
  const sections = new Map<number, SubtitleCue[]>();
  for (const cue of cues) {
    const [hours, mins, rest] = cue.start.split(':');
    const [seconds, millis] = rest.split(',');
    const startMs = ((Number(hours) * 60 + Number(mins)) * 60 + Number(seconds)) * 1000 + Number(millis);
    const key = Math.floor(startMs / durationMs);
    const section = sections.get(key) ?? [];
    section.push(cue);
    sections.set(key, section);
  }
  return [...sections.entries()].sort(([left], [right]) => left - right).map(([key, items]) => ({
    key,
    cues: items,
    range: `${items[0].start} – ${items[items.length - 1].end}`,
  }));
}
