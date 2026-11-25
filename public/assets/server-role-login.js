document.querySelectorAll('[data-server-role-login] [data-login-name]').forEach((button) => {
    const { username, password } = button.form.elements;
    button.addEventListener('click', () => {
        username.value = button.dataset.loginName;
        password.focus();
    });
});
