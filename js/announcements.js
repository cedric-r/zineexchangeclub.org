/**
 * Announcements admin interactions (edit modal, delete, send-to-all).
 * Loaded from announcements.php when isAdmin(); CSP-safe (external file, no inline handlers).
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
                case 'edit-announcement': {
                    document.getElementById('edit_announcement_id').value = d.id;
                    document.getElementById('edit_title').value = d.title;
                    document.getElementById('edit_content').value = d.content;
                    document.getElementById('editModal').style.display = 'block';
                    break;
                }
                case 'delete-announcement':
                    if (confirm('Are you sure you want to delete announcement "' + d.title + '"? This action cannot be undone.')) {
                        postAction({ announcement_id: d.id, delete_announcement: '1' });
                    }
                    break;
                case 'send-announcement-to-all':
                    if (confirm('Are you sure you want to send this announcement to all registered users: "' + d.title + '"?')) {
                        postAction({ announcement_id: d.id, send_announcement_to_all: '1' });
                    }
                    break;
            }
        });
    });

    // Edit modal close controls
    const modal = document.getElementById('editModal');
    document.querySelectorAll('[data-close-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () { modal.style.display = 'none'; });
    });
    window.addEventListener('click', function (event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
});
