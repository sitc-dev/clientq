const statusDialog = document.getElementById('deleteConfirmation');
const statusInput = document.getElementById('deleteConfirmationInput');
const statusError = document.getElementById('deleteConfirmationError');
const statusSubmit = document.getElementById('deleteConfirmationSubmit');
const statusCancel = document.getElementById('deleteConfirmationCancel');
let statusControl = null;
let statusAction = null;
let statusBusy = false;

const tenantStatusMessages = {
    inactive: {
        title: 'Make this tenant inactive?',
        warning: 'The waitlist will show only its logo, business name, and an inactive notice. Sign-up, QR codes, and the tenant staff portal will be unavailable. All tenant data is kept, and you can reactivate it later.',
        confirm: 'Confirm inactive'
    },
    active: {
        title: 'Reactivate this tenant?',
        warning: 'Restore the tenant waitlist, sign-up tabs, QR code, and staff portal using its existing data.',
        confirm: 'Confirm reactivation'
    },
    delete: {
        title: 'Delete this tenant permanently?',
        warning: "Permanently remove this tenant database, staff portal, waitlist records, invoices, uploads, and admin listing. If this is the owner's last tenant, their central account and notes are also deleted. Other tenants remain untouched. Only the logo, business name, and an unavailable notice are retained at this address. This cannot be undone.",
        confirm: 'Confirm deletion'
    }
};

document.querySelectorAll('.tenant-status-action').forEach(control => {
    control.addEventListener('change', () => {
        const action = control.value;
        control.value = control.dataset.currentStatus;
        if (action === control.dataset.currentStatus) return;
        statusControl = control;
        statusAction = action;
        const message = tenantStatusMessages[action];
        document.getElementById('deleteConfirmationTitle').textContent = message.title;
        document.getElementById('deleteConfirmationTarget').textContent = control.dataset.businessName;
        document.getElementById('deleteConfirmationWarning').textContent = message.warning;
        document.getElementById('deleteConfirmationLabel').textContent =
            `Type the exact business name (${control.dataset.businessName}) to confirm`;
        statusInput.value = '';
        statusError.classList.add('hidden');
        statusSubmit.textContent = message.confirm;
        statusDialog.showModal();
    });
});

statusCancel.addEventListener('click', () => statusDialog.close());
statusDialog.addEventListener('cancel', event => {
    if (statusBusy) event.preventDefault();
});

document.getElementById('deleteConfirmationForm').addEventListener('submit', async event => {
    event.preventDefault();
    if (statusBusy) return;
    statusError.classList.add('hidden');
    if (statusInput.value !== statusControl.dataset.businessName) {
        statusError.textContent = 'Enter the exact business name to confirm this action.';
        statusError.classList.remove('hidden');
        return;
    }
    statusBusy = true;
    statusDialog.setAttribute('aria-busy', 'true');
    statusSubmit.disabled = true;
    statusCancel.disabled = true;
    statusInput.disabled = true;
    statusControl.disabled = true;
    try {
        const response = await fetch('/platform-admin/api/update-tenant-status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: statusDialog.dataset.csrfToken,
                business_id: Number(statusControl.dataset.businessId),
                action: statusAction,
                confirmation: statusInput.value
            })
        });
        const data = await response.json();
        if (!response.ok || data.success !== true) {
            throw new Error(data.error || 'The tenant status could not be updated.');
        }
        statusDialog.close();
        if (statusAction === 'delete') {
            window.location.href = 'dashboard';
        } else {
            window.location.reload();
        }
    } catch (error) {
        statusError.textContent = error.message;
        statusError.classList.remove('hidden');
    } finally {
        statusBusy = false;
        statusDialog.removeAttribute('aria-busy');
        statusSubmit.disabled = false;
        statusCancel.disabled = false;
        statusInput.disabled = false;
        statusControl.disabled = false;
    }
});
