// No dependencies: exercise the actual import script with a minimal DOM.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(tag = 'div') {
        this.tagName = tag;
        this.childNodes = [];
        this.listeners = {};
        this.attributes = {};
        this.disabled = false;
        this.textContent = '';
    }
    appendChild(child) { this.childNodes.push(child); return child; }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    replaceChildren(...children) { this.childNodes = [...children]; }
    setAttribute(key, value) { this.attributes[key] = value; }
    addEventListener(event, fn) { this.listeners[event] = fn; }
    querySelector() { return this.label; }
}

const elements = {};
for (const id of ['importBrregStaffButton', 'brregStaffImportModal', 'brregStaffImportStatus',
    'brregStaffImportCandidates', 'brregStaffImportOrgNumber', 'brregStaffImportResult',
    'brregStaffRetryButton', 'brregStaffConfirmButton']) {
    elements[id] = new Element();
}
elements.brregStaffConfirmButton.label = new Element('span');
let requests = [];
let queue = [];
let refreshedStaff = 0;
let refreshedAdmins = 0;
let shown = 0;
const context = {
    document: {
        getElementById: id => elements[id],
        createElement: tag => new Element(tag),
    },
    companyId: 'A', brregStaffCsrf: 'csrf',
    bootstrap: { Modal: { getOrCreateInstance: () => ({ show: () => { shown++; } }) } },
    window: { loadStaff: () => { refreshedStaff++; }, loadAdmins: () => { refreshedAdmins++; } },
    fetch: async (url, options) => {
        requests.push({ url, payload: JSON.parse(options.body) });
        const next = queue.shift();
        if (next instanceof Error) throw next;
        if (typeof next === 'function') return next();
        assert(next, 'Missing fixture response');
        return next;
    },
    console,
};
vm.runInNewContext(fs.readFileSync('saas_portal/assets/brreg-staff-import.js', 'utf8'), context);

const response = (data, status = 200) => ({ ok: status < 400, status, json: async () => data });
const click = id => elements[id].listeners.click();
const settle = () => new Promise(resolve => setImmediate(resolve));
const allChildren = element => [element, ...element.childNodes.flatMap(allChildren)];
const text = element => allChildren(element).map(e => e.textContent).join(' ');
const preview = candidates => response({ success: true, token: 'preview-token', org_number: '974760673', candidates });
const candidate = (id, status) => ({
    id, status, first_name: '<img src=x onerror=alert(1)>', last_name: 'Ny',
    job_title: 'Daglig leder', email: '', phone: '', message: 'Test message',
});

async function main() {
    queue.push(preview([candidate('missing', 'missing'), candidate('existing', 'existing'),
        candidate('ambiguous', 'ambiguous'), candidate('blocked', 'blocked')]));
    click('importBrregStaffButton');
    assert.equal(elements.brregStaffConfirmButton.disabled, true, 'Loading disables confirmation');
    await settle();
    assert.equal(shown, 1);
    assert.equal(requests[0].payload.action, 'preview');
    assert.equal(requests[0].payload.csrf_token, 'csrf');
    const checkboxes = allChildren(elements.brregStaffImportCandidates).filter(e => e.tagName === 'input');
    assert.equal(checkboxes.length, 1, 'Only missing candidates selectable');
    assert.equal(checkboxes[0].checked, true, 'Missing defaults selected');
    assert(text(elements.brregStaffImportCandidates).includes('<img src=x onerror=alert(1)>'), 'Names rendered as plain text');
    assert(!allChildren(elements.brregStaffImportCandidates).some(e => e.tagName === 'img'), 'Untrusted name became markup');
    assert.equal(elements.brregStaffConfirmButton.disabled, false);
    checkboxes[0].checked = false;
    checkboxes[0].listeners.change();
    assert.equal(elements.brregStaffConfirmButton.disabled, true);
    checkboxes[0].checked = true;
    checkboxes[0].listeners.change();
    queue.push(response({ success: true, created: 1, message: 'Importert' }));
    const confirmation = click('brregStaffConfirmButton');
    click('brregStaffConfirmButton');
    assert.equal(requests.length, 2, 'Double confirmation sent twice');
    assert.deepEqual(requests[1].payload.selected, ['missing']);
    assert.equal(requests[1].payload.token, 'preview-token');
    await confirmation;
    assert.equal(refreshedStaff, 1);
    assert.equal(refreshedAdmins, 1);
    assert.equal(elements.brregStaffConfirmButton.disabled, true, 'Used preview cannot be resubmitted');
    queue.push(preview([]));
    await click('brregStaffRetryButton');
    assert.equal(elements.brregStaffConfirmButton.disabled, true, 'Empty preview confirmation');
    queue.push(preview([candidate('stale', 'missing')]));
    await click('brregStaffRetryButton');
    queue.push(response({ success: false, message: 'Ansattlisten er endret' }, 409));
    await click('brregStaffConfirmButton');
    assert.equal(elements.brregStaffConfirmButton.disabled, true, 'Stale preview must be refreshed');
    assert(text(elements.brregStaffImportStatus).includes('ny forhåndsvisning'));
    queue.push(new Error('Register unavailable'));
    await click('brregStaffRetryButton');
    assert(text(elements.brregStaffImportStatus).includes('Register unavailable'));
    assert.equal(elements.brregStaffConfirmButton.disabled, true, 'Error preview confirmation');
    assert.equal(elements.brregStaffRetryButton.disabled, false, 'Retry allowed after error');
    queue.push(preview([candidate('retry', 'missing')]));
    await click('brregStaffRetryButton');
    queue.push(new Error('Network failed'));
    await click('brregStaffConfirmButton');
    assert(text(elements.brregStaffImportStatus).includes('Network failed'));
    assert.equal(refreshedStaff, 1, 'Failed import refreshed staff as success');
    console.log('Brreg UI preview, safe rendering, selection, double submit, stale/empty/error and refresh checks passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });