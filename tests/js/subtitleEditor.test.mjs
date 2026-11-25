import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSubtitleSrt, renderSubtitleSrt, subtitleSections } from '../../resources/js/utils/subtitleEditor.ts';

test('parses and renders long multiline subtitle cues without changing indexes or timestamps', () => {
  const source = '1\r\n00:00:02,000 --> 00:00:06,000\r\nA long English sentence that wraps naturally\r\nand continues on the next line.\r\n\r\n2\r\n00:05:01,250 --> 00:05:05,000\r\nIsang mahabang pangungusap na Filipino.';
  const cues = parseSubtitleSrt(source);

  assert.ok(cues);
  assert.equal(cues[0].text, 'A long English sentence that wraps naturally\nand continues on the next line.');
  assert.equal(cues[1].text, 'Isang mahabang pangungusap na Filipino.');
  assert.equal(renderSubtitleSrt(cues), '1\n00:00:02,000 --> 00:00:06,000\nA long English sentence that wraps naturally\nand continues on the next line.\n\n2\n00:05:01,250 --> 00:05:05,000\nIsang mahabang pangungusap na Filipino.\n');
});

test('groups cues into five-minute sections while retaining full order and timestamps', () => {
  const cues = parseSubtitleSrt([
    '1\n00:00:05,000 --> 00:00:08,000\nFirst',
    '2\n00:04:59,000 --> 00:05:02,000\nCrosses the section edge',
    '3\n00:05:02,000 --> 00:05:06,000\nSecond section',
    '4\n01:00:00,000 --> 01:00:03,000\nFinal section',
  ].join('\n\n'));
  assert.ok(cues);
  const sections = subtitleSections(cues);

  assert.deepEqual(sections.map(section => section.cues.map(cue => cue.index)), [[1, 2], [3], [4]]);
  assert.equal(sections[0].range, '00:00:05,000 – 00:05:02,000');
  assert.equal(sections[2].range, '01:00:00,000 – 01:00:03,000');
  sections[0].cues[1].text = 'Edited in the first section';
  sections[2].cues[0].text = 'Edited much later without losing section one';
  assert.equal(cues[1].text, 'Edited in the first section');
  assert.equal(cues[3].text, 'Edited much later without losing section one');
  assert.deepEqual(sections.flatMap(section => section.cues), cues);
});

test('returns null instead of dropping malformed cues', () => {
  assert.equal(parseSubtitleSrt('1\nnot a timestamp\nText'), null);
});
