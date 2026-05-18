function toggle() {
    const password = document.getElementById("password");
    password.type = password.type === "password" ? "text" : "password";
}
    
function LogUser() {
    const username = document.getElementById("username").value.trim();
    const password = document.getElementById("password").value.trim();
    const errorMsg = document.getElementById("error-msg");

    if (username === "" || password === "") {
        errorMsg.textContent = "Required field!";
    } else {
        errorMsg.style.display = "none";
  
    }
}

function Clear(){
    let username = document.getElementById('usernameInput').value="";
    let password = document.getElementById('passwordInput').value="";
}