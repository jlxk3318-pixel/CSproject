// ------------------------------------------------------------------
// NeuroNet Quest — front-end behaviour
// ------------------------------------------------------------------

// 1. Live leaderboard + board refresh (Teacher dashboard only)
const leaderboardBody = document.getElementById("leaderboardBody");
const boardTrack = document.getElementById("boardTrack");

if (leaderboardBody) {
    const refreshLeaderboard = () => {
        fetch("leaderboard_rows.php")
            .then((response) => response.text())
            .then((html) => {
                leaderboardBody.innerHTML = html;
            })
            .catch(() => {
                // Silently ignore network hiccups; next interval will retry
            });
    };

    // Refresh every 5 seconds so the teacher sees live progress
    setInterval(refreshLeaderboard, 5000);
}

if (boardTrack) {
    const refreshBoard = () => {
        fetch("board_track.php")
            .then((response) => response.text())
            .then((html) => {
                boardTrack.innerHTML = html;
            })
            .catch(() => {
                // Silently ignore network hiccups; next interval will retry
            });
    };

    setInterval(refreshBoard, 5000);
}

// 2. Copy join link button (Teacher dashboard only)
const copyLinkBtn = document.getElementById("copyLinkBtn");

if (copyLinkBtn) {
    copyLinkBtn.addEventListener("click", () => {
        const link = copyLinkBtn.getAttribute("data-link");

        navigator.clipboard
            .writeText(link)
            .then(() => {
                const original = copyLinkBtn.textContent;
                copyLinkBtn.textContent = "Copied!";
                setTimeout(() => {
                    copyLinkBtn.textContent = original;
                }, 1500);
            })
            .catch(() => {
                alert("Could not copy automatically. Link: " + link);
            });
    });
}

// 3. Highlight the selected answer on the question page
document.querySelectorAll(".option").forEach((option) => {
    const input = option.querySelector('input[type="radio"]');

    if (!input) return;

    option.addEventListener("click", () => {
        document
            .querySelectorAll(".option")
            .forEach((el) => el.classList.remove("selected"));
        option.classList.add("selected");
    });
});