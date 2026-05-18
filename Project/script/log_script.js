function toggle() {
    const password = document.getElementById('password');
    password.type = password.type === 'password' ? 'text' : 'password';
}

function LogUser() {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value.trim();
    const errorMsg = document.getElementById('error-msg');

    if (!username || !password) {
        errorMsg.textContent = 'Username and Password cannot be empty!';
        errorMsg.style.color = 'red';
        return false;
    }

    errorMsg.textContent = '';
    return true;
}

function clearError() {
    document.getElementById('error-msg').textContent = '';
}

function Clear(event) {
    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value.trim();
    const errorMsg = document.getElementById('error-msg');

    if (!username && !password) {
        errorMsg.textContent = 'There are no characters entered in the fields!';
        errorMsg.style.color = 'red';
        if (event) event.preventDefault();
    } else {
        errorMsg.textContent = '';
    }
}