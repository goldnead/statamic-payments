// node --test tests/js/
//
// The licence notice belongs to the control panel a person is working in. Inside a framed
// control panel (an add-on's overlay, a live preview) it sat over the frame's own controls,
// e.g. Inline Edit's Save button, and the same person sees it in the outer CP anyway.
import test from 'node:test';
import assert from 'node:assert/strict';

function fakeDom({ framed }) {
    const appended = [];
    const element = () => ({
        style: {}, dataset: {}, setAttribute() {}, append() {}, appendChild() {}, addEventListener() {},
    });
    const top = {};
    globalThis.window = { self: framed ? {} : top, top, localStorage: { getItem: () => null, setItem() {} } };
    globalThis.document = {
        createElement: element,
        querySelector: () => null,
        body: { appendChild: (n) => appended.push(n) },
    };
    globalThis.Statamic = { $config: { get: () => ({ needed: true, days: 30, url: 'https://example.test' }) } };
    return appended;
}

const { suiteLicenceNotice } = await import('../../resources/js/suite-licence.js');

test('shows the notice in the top-level control panel', () => {
    const appended = fakeDom({ framed: false });
    suiteLicenceNotice();
    assert.equal(appended.length, 1);
});

test('stays out of a framed control panel', () => {
    const appended = fakeDom({ framed: true });
    suiteLicenceNotice();
    assert.equal(appended.length, 0);
});
