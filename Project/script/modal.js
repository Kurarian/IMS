function toggle_new() {
    const password = document.getElementById("new_password");
    password.type = password.type === "password" ? "text" : "password";
}

function toggle_confirm() {
    const password = document.getElementById("confirm_password");
    password.type = password.type === "password" ? "text" : "password";
}

function closeOpenDropdowns(e) {
	let openDropdownEls = document.querySelectorAll("details.dropdown[open]");

	if (openDropdownEls.length > 0) {
		if (e.target.parentElement.nodeName.toUpperCase() !== "SUMMARY") {
			openDropdownEls.forEach((dropdown) => {
				dropdown.removeAttribute("open");
			});
		}
	}
}

document.addEventListener("click", closeOpenDropdowns);


function openAccountModal(e) {
    if (e) e.preventDefault();

   
    const avatarMenu = document.getElementById('avatarMenu');
    if (avatarMenu) avatarMenu.classList.remove('open');

   
    document.querySelectorAll('details.dropdown').forEach(d => d.removeAttribute('open'));

  
    setTimeout(() => {
        const modal = document.getElementById('accountModal');
        if (modal) modal.style.display = 'flex';
    }, 50);
}

function closeAccountModal() {
    document.getElementById("accountModal").style.display = "none";
}

window.addEventListener("click", function (e) {
    const modal = document.getElementById("accountModal");
    if (e.target === modal) {
        modal.style.display = "none";
    }
});


function openInfoModal() {
   
    document.getElementById("accountModal").style.display = "none";

   
    setTimeout(() => {
        document.getElementById("info_modal").style.display = "flex";
    }, 100);
}

function closeInfoModal() {
    document.getElementById("info_modal").style.display = "none";
}