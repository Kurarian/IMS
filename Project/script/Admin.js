function addUser() {
    const username = document.getElementById('selectedUser').value.trim();
    const password = document.getElementById('passwordInput').value.trim();

    if (!username || !password) {
        alert('Please enter both username and password.');
        return false;
    }
    return true;
}

function selectUser(username) {
    document.getElementById('selectedUser').value = username;

    document.querySelectorAll('#userTable tr').forEach(tr => tr.classList.remove('selected'));
    event.currentTarget.classList.add('selected');
}

function deleteUser() {
    if (!document.getElementById('selectedUser').value.trim()) {
        alert('Select a user first!');
        event.preventDefault();
        return false;
    }
    return true;
}

function unblockUser() {
    if (!document.getElementById('selectedUser').value.trim()) {
        alert('Select a user first!');
        event.preventDefault();
        return false;
    }
    return true;
}


function updateTable() {
    const filter    = (document.getElementById('searchInput')?.value || '').toLowerCase();
    const tableBody = document.getElementById('userTable'); 

    if (!tableBody) return;

    Array.from(tableBody.getElementsByTagName('tr')).forEach(row => {
        const usernameCell = row.getElementsByTagName('td')[0];
        if (usernameCell) {
            const text = (usernameCell.textContent || usernameCell.innerText).toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        }
    });
}


document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('keyup', updateTable);
        updateTable(); 
    }
});