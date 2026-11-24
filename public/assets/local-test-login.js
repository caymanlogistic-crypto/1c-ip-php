document.querySelectorAll('[data-local-test-login] button').forEach((button) => {
    button.addEventListener('click', () => {
        const form = button.form;
        form.elements.namedItem('username').value = button.dataset.loginName;
        form.elements.namedItem('password').value = button.dataset.loginPassword;
    });
});
