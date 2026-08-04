// ------------------------------------------------------------------
// NeuroNet Quest — front-end behaviour
// ------------------------------------------------------------------

// 1. Live leaderboard + board + student-dropdown refresh (Teacher dashboard only)
const leaderboardBody = document.getElementById("leaderboardBody");
const boardTrack = document.getElementById("boardTrack");
const studentSelect = document.getElementById("student_id");
const sessionId = window.GAME_SESSION_ID || "";

function refreshLeaderboard() {
    if (!leaderboardBody) return;
    fetch("leaderboard_rows.php?session=" + sessionId + "&_=" + Date.now(), { cache: "no-store" })
        .then((response) => {
            if (!response.ok) {
                throw new Error("Bad response: " + response.status);
            }
            return response.text();
        })
        .then((html) => {
            leaderboardBody.innerHTML = html;
            // The "Move a Student" dropdown is rebuilt from these SAME
            // rows (via data-student-id etc.) instead of a separate
            // fetch — one source of truth, so it can't drift out of
            // sync with what the leaderboard just showed.
            rebuildStudentDropdownFromLeaderboard();
        })
        .catch(() => {
            // Silently ignore network hiccups; next interval will retry
        });
}

function rebuildStudentDropdownFromLeaderboard() {
    if (!studentSelect || !leaderboardBody) return;

    const previouslySelected = studentSelect.value;
    const rows = leaderboardBody.querySelectorAll("tr[data-student-id]");

    let optionsHtml = '<option value="">Select Student</option>';
    rows.forEach((row) => {
        const id = row.getAttribute("data-student-id");
        const name = row.getAttribute("data-student-name") || "";
        const node = row.getAttribute("data-node") || "0";
        const laps = parseInt(row.getAttribute("data-laps") || "0", 10);
        const score = row.getAttribute("data-score") || "0";
        const lapText = laps > 0 ? ", Epoch " + (laps + 1) : "";

        optionsHtml += '<option value="' + id + '">'
            + name + ' (Node ' + node + lapText + ' — ' + score + ' pts)'
            + '</option>';
    });

    studentSelect.innerHTML = optionsHtml;
    if (previouslySelected) {
        studentSelect.value = previouslySelected;
    }
}

function refreshBoard() {
    if (!boardTrack) return;
    fetch("board_track.php?session=" + sessionId + "&_=" + Date.now(), { cache: "no-store" })
        .then((response) => {
            if (!response.ok) {
                throw new Error("Bad response: " + response.status);
            }
            return response.text();
        })
        .then((html) => {
            boardTrack.innerHTML = html;
        })
        .catch(() => {
            // Silently ignore network hiccups; next interval will retry
        });
}

// Keep the "Move a Student" dropdown in sync with newly-joined students
// too — it's otherwise only built once when the page loads. (Rebuilt
// directly from the leaderboard's own rows — see rebuildStudentDropdownFromLeaderboard
// above — so it can never drift out of sync with what's already proven
// to be working.)

if (leaderboardBody) setInterval(refreshLeaderboard, 5000);
if (boardTrack) setInterval(refreshBoard, 5000);

// 1b. Manual "Refresh Now" button — instantly re-runs both above
// instead of waiting for the next 5-second tick.
const manualRefreshBtn = document.getElementById("manualRefreshBtn");

if (manualRefreshBtn) {
    manualRefreshBtn.addEventListener("click", () => {
        manualRefreshBtn.classList.add("is-refreshing");
        refreshLeaderboard();
        refreshBoard();
        setTimeout(() => {
            manualRefreshBtn.classList.remove("is-refreshing");
        }, 500);
    });
}

// 1c. Copy join link button (Teacher dashboard only)
const copyLinkBtn = document.getElementById("copyLinkBtn");

function showToast(message) {
    let toast = document.getElementById("appToast");
    if (!toast) {
        toast = document.createElement("div");
        toast.id = "appToast";
        toast.className = "app-toast";
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.remove("show");
    // Force reflow so the animation restarts if clicked twice quickly
    void toast.offsetWidth;
    toast.classList.add("show");
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(() => {
        toast.classList.remove("show");
    }, 2200);
}

if (copyLinkBtn) {
    copyLinkBtn.addEventListener("click", () => {
        const link = copyLinkBtn.getAttribute("data-link");

        const fallbackCopy = () => {
            const tempInput = document.createElement("textarea");
            tempInput.value = link;
            tempInput.style.position = "fixed";
            tempInput.style.opacity = "0";
            document.body.appendChild(tempInput);
            tempInput.focus();
            tempInput.select();
            let copied = false;
            try {
                copied = document.execCommand("copy");
            } catch (e) {
                copied = false;
            }
            document.body.removeChild(tempInput);
            return copied;
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard
                .writeText(link)
                .then(() => {
                    showToast("✅ Link copied to clipboard!");
                })
                .catch(() => {
                    if (fallbackCopy()) {
                        showToast("✅ Link copied to clipboard!");
                    } else {
                        showToast("Could not copy automatically — link: " + link);
                    }
                });
        } else if (fallbackCopy()) {
            showToast("✅ Link copied to clipboard!");
        } else {
            showToast("Could not copy automatically — link: " + link);
        }
    });
}

// 2. Highlight the selected answer on the question page
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