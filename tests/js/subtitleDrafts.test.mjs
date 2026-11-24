import test from 'node:test';
import assert from 'node:assert/strict';
import { reconcileServerDraft } from '../../resources/js/utils/subtitleDrafts.ts';

test('replaces an empty queued draft when the transcript arrives', () => {
  const drafts = {};
  const serverValues = {};
  reconcileServerDraft(drafts, serverValues, 3, '');
  reconcileServerDraft(drafts, serverValues, 3, 'Transcript text from the completed operation.');

  assert.equal(drafts[3], 'Transcript text from the completed operation.');
});

test('refreshes changed server text when the admin has not edited the draft', () => {
  const drafts = {};
  const serverValues = {};
  reconcileServerDraft(drafts, serverValues, 3, 'First server text');
  reconcileServerDraft(drafts, serverValues, 3, 'Updated server text');

  assert.equal(drafts[3], 'Updated server text');
});

test('preserves unsaved admin edits during background refreshes', () => {
  const drafts = {};
  const serverValues = {};
  reconcileServerDraft(drafts, serverValues, 3, 'Server text');
  drafts[3] = 'Admin edit not saved';
  reconcileServerDraft(drafts, serverValues, 3, 'Server text after refresh');

  assert.equal(drafts[3], 'Admin edit not saved');
  assert.equal(serverValues[3], 'Server text after refresh');
});
