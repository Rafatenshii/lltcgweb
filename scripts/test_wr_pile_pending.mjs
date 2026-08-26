import assert from 'node:assert/strict';

function waitingRoomCardsForDisplay(cards, pending) {
  const wr = cards || [];
  if (!pending?.size) return wr;
  return wr.filter(c => c?.instance_id && !pending.has(c.instance_id));
}

const cards = [
  { instance_id: 'a', name_en: 'Low', cost: 2 },
  { instance_id: 'b', name_en: 'Rurino 15', cost: 15 },
  { instance_id: 'c', name_en: 'Hime 15', cost: 15 },
];

const pending = new Set(['b', 'c']);
assert.equal(waitingRoomCardsForDisplay(cards, pending).length, 1, 'pending hides WR faces');
assert.equal(waitingRoomCardsForDisplay(cards, null).length, 3, 'cleared pending shows all');
assert.equal(waitingRoomCardsForDisplay(cards, new Set()).length, 3, 'empty set shows all');

const shown = waitingRoomCardsForDisplay(cards, null);
assert.equal(shown[shown.length - 1].cost, 15);
assert.equal(shown[shown.length - 1].name_en, 'Hime 15');

console.log('wr-pile-pending-repaint: ok');
