document.addEventListener("keydown", function (e) {

    if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;

    if (e.ctrlKey && e.shiftKey && e.key.toLowerCase() === "q") {
        e.preventDefault();

        if (!IS_POWERUSER) return;

        if (CURRENT_PAGE === "user") {
            window.location.href = "Power_User.php";
        } else {
            window.location.href = "user_power.php";
        }
    }
});

