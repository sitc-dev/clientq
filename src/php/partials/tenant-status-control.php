<select class="tenant-status-action rounded-lg border px-3 py-2"
    aria-label="Status for <?= htmlspecialchars($statusBusiness['name']) ?>"
    data-business-id="<?= (int)$statusBusiness['id'] ?>"
    data-business-name="<?= htmlspecialchars($statusBusiness['name']) ?>"
    data-current-status="<?= $statusBusiness['is_active'] ? 'active' : 'inactive' ?>">
    <option value="active" <?= $statusBusiness['is_active'] ? 'selected' : '' ?>>Active</option>
    <option value="inactive" <?= !$statusBusiness['is_active'] ? 'selected' : '' ?>>Inactive</option>
    <option value="delete">Delete tenant</option>
</select>
