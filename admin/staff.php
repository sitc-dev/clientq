<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/../lib/staff-profile.php';
requireStaffAdministrator();

$staff = getLoggedInStaff();
$db = getTenantDB();
$manageableRoles = $staff['role'] === 'owner' ? '1 = 1' : "s.role = 'staff'";
$employees = $db->query("
    SELECT s.id, s.name, s.first_name, s.last_name, s.goes_by, s.email, s.position, s.additional_info, s.role,
        (SELECT COUNT(*) FROM clients c WHERE c.staff_id = s.id AND c.is_active = 1) AS client_count
    FROM staff s
    WHERE $manageableRoles AND s.is_active = 1
    ORDER BY s.name
")->fetchAll();
foreach ($employees as &$employee) {
    $employee = array_replace($employee, staffNameParts($employee));
}
unset($employee);

adminHeader('Users', $staff, 'staff');
?>

        <div class="p-4 md:p-6 border-b">
            <div id="messagePanel" class="hidden px-4 py-3 mb-4 rounded-lg text-sm"></div>

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h2 id="formTitle" class="text-lg font-bold text-gray-800">Add User</h2>
                    <p class="text-sm text-gray-500">New users receive a temporary password by email and choose a new password at first login. Owners can assign Owner, Manager, or Staff access. Managers can manage Staff accounts only; billing and Business Settings remain Owner-only.</p>
                </div>
                <button id="cancelEdit" type="button" class="hidden text-sm text-gray-500 hover:text-gray-800">Cancel edit</button>
            </div>

            <form id="employeeForm" class="grid md:grid-cols-2 gap-4">
                <input type="hidden" id="employeeId">
                <div>
                    <label for="firstName" class="block text-sm font-semibold mb-1">First Name</label>
                    <input id="firstName" required maxlength="100" autocomplete="given-name" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                </div>
                <div>
                    <label for="lastName" class="block text-sm font-semibold mb-1">Last Name</label>
                    <input id="lastName" maxlength="100" autocomplete="family-name" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                </div>
                <div>
                    <label for="goesBy" class="block text-sm font-semibold mb-1">Nickname (optional)</label>
                    <input id="goesBy" maxlength="50" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">Leave blank to display the first name.</p>
                </div>
                <div>
                    <label for="position" class="block text-sm font-semibold mb-1">Position</label>
                    <input id="position" maxlength="100" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                </div>
                <div>
                    <label for="email" class="block text-sm font-semibold mb-1">Email</label>
                    <input id="email" type="email" required maxlength="255" autocomplete="email" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                </div>
                <div>
                    <label for="accessLevel" class="block text-sm font-semibold mb-1">Access level</label>
                    <select id="accessLevel" required class="w-full border rounded-lg px-3 py-2">
                        <?php foreach (staffAssignableRoles($staff) as $accessLevel): ?>
                        <option value="<?= htmlspecialchars($accessLevel) ?>" <?= $accessLevel === 'staff' ? 'selected' : '' ?>><?= htmlspecialchars(STAFF_ACCESS_LEVELS[$accessLevel]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="passwordFields" class="hidden md:col-span-2">
                    <label class="block text-sm font-semibold mb-1">Password</label>
                    <input id="password" type="password" disabled minlength="8" maxlength="255" autocomplete="new-password" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none">
                    <p class="text-xs text-gray-500 mt-1">Leave blank to keep the existing password. Editing an employee does not send an invitation.</p>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-semibold mb-1">Profile Details</label>
                    <textarea id="additionalInfo" rows="3" class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-accent focus:outline-none"></textarea>
                </div>
                <button id="saveButton" class="md:col-span-2 bg-accent text-white py-3 rounded-lg font-semibold hover:bg-accentDark transition">
                    Add User
                </button>
            </form>
        </div>

        <div class="divide-y">
            <?php if (!$employees): ?>
                <div class="p-8 text-center text-gray-400">No users are available to manage.</div>
            <?php endif; ?>

            <?php foreach ($employees as $employee): ?>
            <div class="p-4 md:p-6 flex flex-col md:flex-row md:items-center gap-4 justify-between">
                <div>
                    <p class="font-semibold text-gray-800"><?= htmlspecialchars(staffDisplayName($employee)) ?></p>
                    <p class="text-sm text-gray-500">
                        <?= htmlspecialchars($employee['position'] ?: 'Employee') ?>
                        · <?= htmlspecialchars(staffAccessLabel($employee)) ?>
                        · <?= (int)$employee['client_count'] ?> waiting client(s)
                    </p>
                    <p class="text-xs text-gray-400 mt-1">Email: <?= htmlspecialchars($employee['email'] ?: 'Not set - add an email to enable login') ?></p>
                </div>
                <div class="flex gap-2">
                    <button type="button"
                        data-employee="<?= htmlspecialchars(json_encode($employee, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG), ENT_QUOTES) ?>"
                        class="edit-employee px-4 py-2 bg-gray-100 rounded-lg text-sm font-semibold hover:bg-gray-200">
                        Edit
                    </button>
                    <?php if ((int)$employee['id'] !== (int)$staff['id']): ?>
                    <button type="button" data-id="<?= (int)$employee['id'] ?>" data-name="<?= htmlspecialchars(staffDisplayName($employee)) ?>"
                        class="deactivate-employee px-4 py-2 bg-red-50 text-red-600 rounded-lg text-sm font-semibold hover:bg-red-100">
                        Deactivate
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
            const employeeCsrfToken = <?= json_encode(platformCsrfToken()) ?>;
            const fields = {
                id: document.getElementById('employeeId'),
                first_name: document.getElementById('firstName'),
                last_name: document.getElementById('lastName'),
                goes_by: document.getElementById('goesBy'),
                email: document.getElementById('email'),
                password: document.getElementById('password'),
                position: document.getElementById('position'),
                additional_info: document.getElementById('additionalInfo'),
                role: document.getElementById('accessLevel')
            };

            document.getElementById('employeeForm').addEventListener('submit', async function(event) {
                event.preventDefault();
                const button = document.getElementById('saveButton');
                button.disabled = true;

                const payload = {};
                payload.csrf_token = employeeCsrfToken;
                Object.entries(fields).forEach(([key, field]) => payload[key] = field.value);
                if (!fields.id.value) delete payload.password;

                try {
                    const response = await fetch('api/save-employee', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    });
                    const data = await response.json();
                    if (!data.success) throw new Error(data.error || 'Employee could not be saved.');
                    window.location.reload();
                } catch (error) {
                    showMessage(error.message, false);
                    button.disabled = false;
                }
            });

            document.querySelectorAll('.edit-employee').forEach(button => {
                button.addEventListener('click', function() {
                    const employee = JSON.parse(this.dataset.employee);
                    Object.entries(fields).forEach(([key, field]) => {
                        field.value = key === 'password' ? '' : (employee[key] ?? '');
                    });
                    document.getElementById('formTitle').textContent = 'Edit User';
                    document.getElementById('saveButton').textContent = 'Save User';
                    document.getElementById('passwordFields').classList.remove('hidden');
                    fields.password.disabled = false;
                    document.getElementById('cancelEdit').classList.remove('hidden');
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            });

            document.getElementById('cancelEdit').addEventListener('click', resetForm);

            document.querySelectorAll('.deactivate-employee').forEach(button => {
                button.addEventListener('click', async function() {
                    if (!confirm(`Deactivate ${this.dataset.name}?`)) return;
                    try {
                        const response = await fetch('api/deactivate-employee', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ id: this.dataset.id, csrf_token: employeeCsrfToken })
                        });
                        const data = await response.json();
                        if (!data.success) throw new Error(data.error || 'Employee could not be deactivated.');
                        window.location.reload();
                    } catch (error) {
                        showMessage(error.message, false);
                    }
                });
            });

            function resetForm() {
                document.getElementById('employeeForm').reset();
                fields.id.value = '';
                document.getElementById('formTitle').textContent = 'Add User';
                document.getElementById('saveButton').textContent = 'Add User';
                document.getElementById('passwordFields').classList.add('hidden');
                fields.password.disabled = true;
                document.getElementById('cancelEdit').classList.add('hidden');
            }

            function showMessage(message, success) {
                const panel = document.getElementById('messagePanel');
                panel.textContent = message;
                panel.className = success
                    ? 'bg-green-100 border border-green-400 text-green-700 px-4 py-3 mb-4 rounded-lg text-sm'
                    : 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 mb-4 rounded-lg text-sm';
            }
        </script>

<?php adminFooter(); ?>
