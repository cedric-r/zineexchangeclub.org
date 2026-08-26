/**
 * Admin dashboard interactions (users + cycles tables, edit-user modal).
 * Loaded from admin/index.php; CSP-safe (external file, no inline handlers).
 */
document.addEventListener('DOMContentLoaded', function () {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = metaTag ? metaTag.content : '';

    function postAction(fields) {
        const form = document.createElement('form');
        form.method = 'POST';
        let html = '<input type="hidden" name="csrf_token" value="' + csrfToken + '">';
        for (const [name, value] of Object.entries(fields)) {
            html += '<input type="hidden" name="' + name + '" value="' + value + '">';
        }
        form.innerHTML = html;
        document.body.appendChild(form);
        form.submit();
    }

    document.querySelectorAll('[data-action]').forEach(function (button) {
        button.addEventListener('click', function () {
            const d = button.dataset;
            switch (d.action) {
                case 'edit-user': {
                    const row = document.getElementById('user-row-' + d.userId);
                    if (!row) break;
                    document.getElementById('edit_user_id').value = d.userId;
                    document.getElementById('edit_name').value = row.dataset.name;
                    document.getElementById('edit_email').value = row.dataset.email;
                    document.getElementById('edit_country').value = row.dataset.country;
                    document.getElementById('edit_postal_address').value = row.dataset.postalAddress;
                    document.getElementById('edit_accepts_adult_zines').checked = row.dataset.acceptsAdultZines === '1';
                    document.getElementById('edit_is_admin').checked = row.dataset.isAdmin === '1';
                    document.getElementById('edit_email_confirmed').checked = row.dataset.emailConfirmed === '1';
                    document.getElementById('userEditModal').style.display = 'block';
                    break;
                }
                case 'delete-user':
                    if (confirm('Are you sure you want to delete user "' + d.name + '"? This will permanently delete all their data including uploaded images and cannot be undone.')) {
                        postAction({ user_id: d.userId, delete_user: '1' });
                    }
                    break;
                case 'resend-confirmation':
                    if (confirm('Are you sure you want to resend confirmation email to "' + d.email + '"?')) {
                        postAction({ user_id: d.userId, resend_confirmation: '1' });
                    }
                    break;
                case 'delete-cycle':
                    if (confirm('Are you sure you want to delete cycle "' + d.cycleName + '"? This will permanently delete all associated data including participations and uploaded images and cannot be undone.')) {
                        postAction({ cycle_id: d.cycleId, delete_cycle: '1' });
                    }
                    break;
            }
        });
    });

    // Confirmation prompts attached to normal forms (e.g. impersonate)
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // User edit modal
    const modal = document.getElementById('userEditModal');
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { modal.style.display = 'none'; });
    });
    window.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
