document.querySelectorAll('[data-server-role-login]').forEach((group) => {
    const form = group.closest('form');
    const buttons = [...group.querySelectorAll('[data-login-role]')];
    const error = group.querySelector('[role="alert"]');

    group.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-login-role]');
        if (!button) return;

        error.hidden = true;
        buttons.forEach((item) => { item.disabled = true; });
        const body = new URLSearchParams({
            _csrf: form.elements.namedItem('_csrf').value,
            role_preset: 'fill',
            role: button.dataset.loginRole,
        });
        try {
            const response = await fetch('/login', { method: 'POST', body, cache: 'no-store' });
            if (!response.ok) throw new Error('Preset unavailable');
            const { username, password } = await response.json();
            if (typeof username !== 'string' || typeof password !== 'string') throw new Error('Preset unavailable');
            form.elements.namedItem('username').value = username;
            form.elements.namedItem('password').value = password;
        } catch {
            error.hidden = false;
        } finally {
            buttons.forEach((item) => { item.disabled = false; });
            button.focus();
        }
    });
});
