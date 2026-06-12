/**
 * Captcha frontend — verifies the user's answer on form submit and gates submission.
 */
(function () {
    'use strict';

    var container = document.getElementById('captcha-container');
    if (!container) return;

    var input = container.querySelector('.captcha-answer');
    var status = container.querySelector('.captcha-status');
    var form = container.closest('form');
    var submitting = false;

    function setStatus(msg, type) {
        if (status) {
            status.textContent = msg;
            status.className = 'captcha-status ' + (type || '');
        }
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (submitting) return;

            var answer = input ? input.value.trim() : '';
            if (answer === '') {
                e.preventDefault();
                setStatus('Please answer the captcha question.', 'error');
                return;
            }

            e.preventDefault();
            submitting = true;
            setStatus('Verifying...', '');

            fetch('captcha.php?action=verify', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ answer: answer })
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.valid) {
                    if (input) input.disabled = true;
                    setStatus('Human verified!', 'success');
                    form.submit();
                } else if (data.blocked) {
                    if (input) input.disabled = true;
                    setStatus('Too many failed attempts. Please reload the page to try again.', 'error');
                } else {
                    submitting = false;
                    setStatus(data.error || 'Wrong answer, try again.', 'error');
                    if (input) { input.value = ''; input.focus(); }
                }
            })
            .catch(function () {
                submitting = false;
                setStatus('Verification failed. Please try again.', 'error');
            });
        });
    }
})();
