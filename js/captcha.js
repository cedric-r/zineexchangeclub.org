/**
 * Captcha frontend — verifies the user's answer via AJAX and gates form submission.
 */
(function () {
    'use strict';

    var container = document.getElementById('captcha-container');
    if (!container) return;

    var input = container.querySelector('.captcha-answer');
    var status = container.querySelector('.captcha-status');
    var form = container.closest('form');
    var debounceTimer = null;
    var verified = false;

    function setStatus(msg, type) {
        if (status) {
            status.textContent = msg;
            status.className = 'captcha-status ' + (type || '');
        }
    }

    function setVerified() {
        verified = true;
        if (input) input.disabled = true;
        setStatus('Human verified!', 'success');
    }

    function setBlocked() {
        if (input) input.disabled = true;
        setStatus('Too many failed attempts. Please reload the page to try again.', 'error');
    }

    function checkAnswer() {
        var answer = input ? input.value.trim() : '';
        if (answer === '') return;

        fetch('captcha.php?action=verify', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ answer: answer })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.valid) {
                setVerified();
            } else if (data.blocked) {
                setBlocked();
            } else {
                setStatus(data.error || 'Wrong answer, try again.', 'error');
                if (input) { input.value = ''; input.focus(); }
            }
        })
        .catch(function () {
            setStatus('Verification failed. Please try again.', 'error');
        });
    }

    if (input) {
        input.addEventListener('input', function () {
            if (verified) return;
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(checkAnswer, 500);
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!verified) {
                e.preventDefault();
                setStatus('Please answer the captcha question first.', 'error');
            }
        });
    }
})();
