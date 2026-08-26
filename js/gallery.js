/**
 * Gallery lightbox.
 * Loaded from gallery.php; CSP-safe (external file, no inline handlers).
 */
document.addEventListener('DOMContentLoaded', function () {
    const lightbox = document.getElementById('lightbox');
    if (!lightbox) return;

    document.querySelectorAll('.gallery-item img').forEach(function (img) {
        img.addEventListener('click', function () {
            const caption = img.dataset.caption || '';
            const userName = img.dataset.userName || '';
            const cycleName = img.dataset.cycleName || '';
            document.getElementById('lightbox-img').src = img.src;
            const parts = [];
            if (caption) parts.push(caption);
            parts.push('by ' + userName + ' &middot; ' + cycleName);
            document.getElementById('lightbox-caption').innerHTML = parts.join('<br>');
            lightbox.classList.add('open');
        });
    });

    lightbox.addEventListener('click', function () {
        lightbox.classList.remove('open');
    });
});
