function updateLog(search='') {
    fetch('load_log.php?search=' + encodeURIComponent(search))
        .then(res => res.text())
        .then(data => {
            document.getElementById('logBody').innerHTML = data;
        });
}

// Initial load
updateLog();

// Refresh every 5 seconds
setInterval(updateLog, 5000);

// Optional: search filter
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('keyup', function() {
        updateLog(this.value);
    });
}

