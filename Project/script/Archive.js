/* 

function selectUser(username){
    document.getElementById("selectedUser").value = username;

    document.querySelectorAll("#userTable tr").forEach(tr => tr.classList.remove("selected"));

    event.currentTarget.classList.add("selected");
}

function updateTable(search='') {
    fetch('load_for_Archive.php?search=' + encodeURIComponent(search))
        .then(res => res.text())
        .then(data => {
            document.getElementById('userTable').innerHTML = data;
        });
}

updateTable();

document.getElementById('selectedUser').addEventListener('keyup', function() {
    updateTable(this.value);
});


function doAction(action) {
    const username = document.getElementById('selectedUser').value;
    const password = document.getElementById('passwordInput').value;

    if(action === 'add' && (!username || !password)) {
        alert("Enter username and password!");
        return;
    }
    if((action === 'Status' || action === 'Deactivate') && !username){
        alert("Select a user first!");
        return;
    }

    const data = new URLSearchParams();
    data.append('action', action);
    data.append('username', username);
    if(action === 'add') data.append('password', password);

    fetch('Admin.php', {
        method: 'POST',
        body: data
    })
    .then(res => res.text())
    .then(msg => {
        alert(msg);
        updateTable();
    });
}  */


function selectUser(username, row){
    document.getElementById("selectedUser").value = username;

    document.querySelectorAll("#userTable tr")
        .forEach(tr => tr.classList.remove("selected"));

    row.classList.add("selected");
}

function updateTable(search='') {
    fetch('../frack/load_for_Archive.php?search=' + encodeURIComponent(search))
        .then(res => res.text())
        .then(html => {
            document.getElementById('userTable').innerHTML = html;
        });
}

function unArchiveUser() {
    const username = document.getElementById('selectedUser').value;
    if (!username) {
        alert("Select a user first!");
        return;
    }

    const data = new URLSearchParams();
    data.append('action', 'unArchive');
    data.append('username', username);

    fetch('../frack/Archive.php', {
        method: 'POST',
        body: data
    }).then(() => {
        updateTable();      
        document.getElementById('selectedUser').value = '';
    });
}

document.getElementById('searchInput')
    .addEventListener('keyup', e => updateTable(e.target.value));

updateTable();