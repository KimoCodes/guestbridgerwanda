<?php if (!$c['is_manager']): ?>
<p class="text-muted">Only hotel managers can manage team access.</p>
<?php return; endif; ?>
<?php settings_card_open('Hotel staff', 'Add receptionists and managers. Link staff to user accounts at login.'); ?>
<form method="post" class="settings-form mb-3">
    <?php echo csrf_field(); ?>
    <div class="settings-grid-2">
        <div class="settings-field">
            <label for="staff_name">Name</label>
            <input type="text" id="staff_name" name="staff_name" required>
        </div>
        <div class="settings-field">
            <label for="staff_phone">Phone</label>
            <input type="tel" id="staff_phone" name="staff_phone">
        </div>
        <div class="settings-field">
            <label for="staff_role">Role</label>
            <select id="staff_role" name="staff_role">
                <option value="receptionist">Staff / Receptionist</option>
                <option value="concierge">Concierge</option>
                <option value="manager">Manager</option>
            </select>
        </div>
    </div>
    <button type="submit" name="add_staff" value="1" class="btn btn-primary">Add staff member</button>
</form>
<div class="settings-table-wrap">
    <table class="settings-table">
        <thead><tr><th>Name</th><th>Phone</th><th>Role</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($c['staff'])): ?>
            <tr><td colspan="4">No staff records yet.</td></tr>
        <?php else: foreach ($c['staff'] as $s): ?>
            <tr>
                <td><?php echo sh($s['name'] ?? ''); ?></td>
                <td><?php echo sh($s['phone'] ?? ''); ?></td>
                <td><?php echo sh($s['role'] ?? ''); ?></td>
                <td>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Remove this staff member?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="remove_staff_id" value="<?php echo (int) $s['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<p class="text-muted small mt-2">Permissions: managers can edit profile, payments, and public listing; staff typically view bookings only (enforced per page).</p>
<?php settings_card_close(); ?>

<?php settings_card_open('Audit log', 'Who changed settings and when.'); ?>
<div class="settings-table-wrap">
    <table class="settings-table">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Entity</th></tr></thead>
        <tbody>
        <?php if (empty($c['audit_logs'])): ?>
            <tr><td colspan="4">No audit entries yet.</td></tr>
        <?php else: foreach ($c['audit_logs'] as $log): ?>
            <tr>
                <td><?php echo sh($log['created_at'] ?? ''); ?></td>
                <td><?php echo sh($log['actor_name'] ?? ''); ?></td>
                <td><?php echo sh($log['action'] ?? ''); ?></td>
                <td><?php echo sh(($log['entity_type'] ?? '') . ' #' . ($log['entity_id'] ?? '')); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php settings_card_close(); ?>
