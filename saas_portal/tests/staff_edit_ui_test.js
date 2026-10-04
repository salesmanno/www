// Exercise the real modal handlers without external browser dependencies.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const page = fs.readFileSync('saas_portal/pages/company_details.php', 'utf8');
const modalHandlers = page.slice(page.indexOf('        function showCreateStaff()'), page.indexOf('        function createAdmin()'));
const saveHandler = page.slice(page.indexOf('        async function saveStaff()'), page.indexOf('        function manageAdminModules('));

function element() {
    const classes = new Set();
    return {
        value: '', textContent: '', className: '', disabled: false, readOnly: false,
        classList: {
            add: name => classes.add(name),
            toggle: (name, add) => add ? classes.add(name) : classes.delete(name),
            contains: name => classes.has(name)
        }
    };
}
const fields = {};
for (const name of ['first_name', 'last_name', 'email', 'phone', 'job_title']) fields[name] = element();
let valid = true;
let reported = 0;
const form = {
    elements: fields,
    checkValidity: () => valid,
    reportValidity: () => { reported++; },
    reset: () => { Object.values(fields).forEach(field => { field.value = ''; }); }
};
const elements = {createStaffForm: form};
for (const id of ['staffId', 'staffModalTitle', 'saveStaffButton', 'staffEmailAccountNote', 'staffFormStatus', 'createStaffModal']) elements[id] = element();
elements.staffEmail = fields.email;
const originalStaff = {
    id: 'existing', first_name: 'Ola', last_name: 'Nordmann', email: 'ola@example.test',
    phone: '12345678', job_title: 'Daglig leder', has_account: 0
};
let refreshedStaff = 0;
let refreshedAdmins = 0;
let shown = 0;
let hidden = 0;
const requests = [];
const responses = [];
class Modal {
    show() { shown++; }
    static getInstance() { return {hide: () => { hidden++; }}; }
}
class FormDataFixture {
    constructor(source) {
        this.values = new Map(Object.entries(source.elements).map(([name, field]) => [name, field.value]));
    }
    get(name) { return this.values.get(name); }
    append(name, value) { this.values.set(name, value); }
}
const context = {
    document: {getElementById: id => elements[id]},
    bootstrap: {Modal},
    availableAdminStaff: {existing: originalStaff, linked: {...originalStaff, id: 'linked', has_account: '1'}},
    companyId: 'A', brregStaffCsrf: 'csrf',
    FormData: FormDataFixture,
    alert: () => {},
    loadStaff: () => { refreshedStaff++; },
    loadAdmins: () => { refreshedAdmins++; },
    fetch: async (url, options) => {
        requests.push({url, options});
        const response = responses.shift();
        if (response instanceof Error) throw response;
        assert(response, 'No response fixture');
        return {ok: response.success, json: async () => response};
    }
};
vm.runInNewContext(modalHandlers + saveHandler, context);

async function main() {
    assert(page.includes('onclick="showEditStaff('), 'Edit button missing from staff list');
    context.showEditStaff('existing');
    assert.equal(shown, 1);
    assert.equal(elements.staffId.value, 'existing');
    assert.equal(elements.staffModalTitle.textContent, 'Rediger ansatt');
    assert.equal(fields.first_name.value, 'Ola');
    assert.equal(fields.phone.value, '12345678');
    assert.equal(fields.email.readOnly, false);
    fields.first_name.value = 'Endret';
    assert.equal(originalStaff.first_name, 'Ola', 'Opening/editing form mutated staff before save');
    valid = false;
    await context.saveStaff();
    assert.equal(reported, 1);
    assert.equal(requests.length, 0, 'Invalid form submitted');
    valid = true;
    responses.push({success: false, message: 'Kunne ikke lagre'});
    await context.saveStaff();
    assert.equal(elements.staffFormStatus.textContent, 'Kunne ikke lagre');
    assert.equal(fields.first_name.value, 'Endret', 'Error lost entered values');
    assert.equal(elements.saveStaffButton.disabled, false);
    assert.equal(hidden, 0);
    responses.push({success: true});
    const save = context.saveStaff();
    context.saveStaff();
    assert.equal(requests.length, 2, 'Double submission sent duplicate request');
    await save;
    assert.equal(requests[1].url, '/saas_portal/api/update_staff.php');
    const payload = JSON.parse(requests[1].options.body);
    assert.equal(payload.staff_id, 'existing');
    assert.equal(payload.company_id, 'A');
    assert.equal(payload.csrf_token, 'csrf');
    assert.equal(payload.first_name, 'Endret');
    assert.equal(refreshedStaff, 1);
    assert.equal(refreshedAdmins, 1);
    assert.equal(hidden, 1);
    context.showEditStaff('linked');
    assert.equal(fields.email.readOnly, true, 'Linked account email must be protected');
    assert.equal(elements.staffEmailAccountNote.classList.contains('d-none'), false);
    context.showCreateStaff();
    assert.equal(elements.staffId.value, '');
    assert.equal(fields.first_name.value, '');
    assert.equal(fields.email.readOnly, false);
    assert.equal(elements.staffEmailAccountNote.classList.contains('d-none'), true);
    assert.equal(elements.staffModalTitle.textContent, 'Opprett ansatt');
    assert.equal(elements.saveStaffButton.textContent, 'Opprett');
    fields.first_name.value = 'Ny';
    fields.last_name.value = 'Ansatt';
    responses.push({success: true});
    await context.saveStaff();
    assert.equal(requests[2].url, '/saas_portal/api/create_staff.php', 'Create uses update endpoint');
    assert.equal(requests[2].options.body.get('company_id'), 'A');
    assert.equal(refreshedStaff, 2);
    assert.equal(refreshedAdmins, 1);
    console.log('Employee edit UI field loading, validation, errors, save, double-submit and create-mode reset passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });