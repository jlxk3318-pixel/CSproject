<?php
// ------------------------------------------------------------------
// Floating FAQ help-widget. Include this once, right before the
// closing </body> tag, on any page that renders a full HTML document.
//
// Set $chatbotRole to 'student' | 'teacher' | 'admin' | 'guest' right
// before the include to control which topics show. If the including
// page didn't set it, this falls back to the logged-in session role,
// then 'guest'.
//
// Pure rule-based matching happens client-side in JS against the FAQ
// data from Shared/chatbot-data.php (embedded as JSON below) — no
// external API calls, no server round-trip needed to answer.
// ------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/chatbot-data.php';

if (!isset($chatbotRole) || !in_array($chatbotRole, ['student', 'teacher', 'admin', 'guest'], true)) {
    $sessionRole = $_SESSION['role'] ?? null;
    $chatbotRole = in_array($sessionRole, ['student', 'teacher', 'admin'], true) ? $sessionRole : 'guest';
}

$chatbotFaqs = getChatbotFaqsForRole($chatbotRole);

$chatbotRoleLabels = [
    'student' => 'Student Help',
    'teacher' => 'Teacher Help',
    'admin'   => 'Admin Help',
    'guest'   => 'Help',
];
$chatbotRoleLabel = $chatbotRoleLabels[$chatbotRole];
?>
<div id="ttq-chatbot-root" data-role="<?php echo htmlspecialchars($chatbotRole); ?>">
    <button type="button" id="ttq-chatbot-toggle" aria-label="Open help assistant">
        <span id="ttq-chatbot-toggle-icon">&#128172;</span>
    </button>

    <div id="ttq-chatbot-panel" class="ttq-hidden">
        <div id="ttq-chatbot-header">
            <div>
                <div id="ttq-chatbot-title">&#129302; NeuroNet Assistant</div>
                <div id="ttq-chatbot-subtitle"><?php echo htmlspecialchars($chatbotRoleLabel); ?></div>
            </div>
            <button type="button" id="ttq-chatbot-close" aria-label="Close assistant">&#10005;</button>
        </div>

        <div id="ttq-chatbot-messages"></div>

        <div id="ttq-chatbot-topics"></div>

        <form id="ttq-chatbot-form">
            <input type="text" id="ttq-chatbot-input" placeholder="Ask a question..." autocomplete="off">
            <button type="submit" id="ttq-chatbot-send" aria-label="Send">&#10148;</button>
        </form>
    </div>
</div>

<script type="application/json" id="ttq-chatbot-data"><?php
    echo json_encode($chatbotFaqs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?></script>

<style>
    #ttq-chatbot-root, #ttq-chatbot-root * {
        box-sizing: border-box;
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
    }

    #ttq-chatbot-toggle {
        position: fixed;
        right: 22px;
        bottom: 22px;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #00e0ff, #6a5cff);
        color: #fff;
        font-size: 24px;
        cursor: pointer;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.35);
        z-index: 9998;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.15s ease;
    }
    #ttq-chatbot-toggle:hover { transform: scale(1.08); }

    #ttq-chatbot-panel {
        position: fixed;
        right: 22px;
        bottom: 90px;
        width: 320px;
        max-width: calc(100vw - 32px);
        height: 440px;
        max-height: calc(100vh - 130px);
        background: #111826;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    #ttq-chatbot-panel.ttq-hidden { display: none; }

    #ttq-chatbot-header {
        background: linear-gradient(135deg, #00e0ff, #6a5cff);
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    #ttq-chatbot-title { color: #fff; font-weight: 700; font-size: 14px; }
    #ttq-chatbot-subtitle { color: rgba(255,255,255,0.85); font-size: 11px; margin-top: 2px; }
    #ttq-chatbot-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
        line-height: 1;
        padding: 4px;
    }

    #ttq-chatbot-messages {
        flex: 1;
        overflow-y: auto;
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .ttq-msg {
        max-width: 85%;
        padding: 8px 11px;
        border-radius: 10px;
        font-size: 13px;
        line-height: 1.45;
        word-wrap: break-word;
    }
    .ttq-msg-bot {
        background: #1c2536;
        color: #e6ecf5;
        align-self: flex-start;
        border-bottom-left-radius: 2px;
    }
    .ttq-msg-user {
        background: #00b8d4;
        color: #06202a;
        align-self: flex-end;
        border-bottom-right-radius: 2px;
    }

    #ttq-chatbot-topics {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 0 12px 10px;
        max-height: 92px;
        overflow-y: auto;
        flex-shrink: 0;
        border-top: 1px solid rgba(255,255,255,0.06);
        padding-top: 8px;
    }
    .ttq-chip {
        background: rgba(0, 224, 255, 0.12);
        color: #7fe9ff;
        border: 1px solid rgba(0, 224, 255, 0.35);
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 11.5px;
        cursor: pointer;
        white-space: nowrap;
    }
    .ttq-chip:hover { background: rgba(0, 224, 255, 0.22); }

    #ttq-chatbot-form {
        display: flex;
        gap: 8px;
        padding: 10px;
        border-top: 1px solid rgba(255,255,255,0.08);
        flex-shrink: 0;
    }
    #ttq-chatbot-input {
        flex: 1;
        background: #0b1220;
        border: 1px solid rgba(255,255,255,0.12);
        border-radius: 8px;
        color: #e6ecf5;
        padding: 8px 10px;
        font-size: 13px;
        outline: none;
    }
    #ttq-chatbot-input:focus { border-color: #00e0ff; }
    #ttq-chatbot-send {
        background: #00b8d4;
        border: none;
        color: #06202a;
        width: 36px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
    }

    @media (max-width: 420px) {
        #ttq-chatbot-panel { right: 16px; left: 16px; width: auto; }
        #ttq-chatbot-toggle { right: 16px; }
    }
</style>

<script>
(function () {
    var faqData = JSON.parse(document.getElementById('ttq-chatbot-data').textContent);

    var root = document.getElementById('ttq-chatbot-root');
    var toggleBtn = document.getElementById('ttq-chatbot-toggle');
    var panel = document.getElementById('ttq-chatbot-panel');
    var closeBtn = document.getElementById('ttq-chatbot-close');
    var messagesEl = document.getElementById('ttq-chatbot-messages');
    var topicsEl = document.getElementById('ttq-chatbot-topics');
    var form = document.getElementById('ttq-chatbot-form');
    var input = document.getElementById('ttq-chatbot-input');

    var greeted = false;

    function addMessage(text, sender, isHtml) {
        var bubble = document.createElement('div');
        bubble.className = 'ttq-msg ' + (sender === 'user' ? 'ttq-msg-user' : 'ttq-msg-bot');
        if (isHtml) {
            bubble.innerHTML = text;
        } else {
            bubble.textContent = text;
        }
        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function renderTopics() {
        topicsEl.innerHTML = '';
        faqData.forEach(function (item) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ttq-chip';
            chip.textContent = item.q;
            chip.addEventListener('click', function () {
                addMessage(item.q, 'user', false);
                addMessage(item.a, 'bot', true);
            });
            topicsEl.appendChild(chip);
        });
    }

    function findBestMatch(query) {
        var q = query.toLowerCase();
        var words = q.split(/\s+/).filter(function (w) { return w.length > 2; });
        var best = null;
        var bestScore = 0;

        faqData.forEach(function (item) {
            var score = 0;
            (item.keywords || []).forEach(function (kw) {
                if (q.indexOf(kw.toLowerCase()) !== -1) {
                    score += 3;
                }
            });
            var qLower = item.q.toLowerCase();
            words.forEach(function (w) {
                if (qLower.indexOf(w) !== -1) {
                    score += 1;
                }
            });
            if (score > bestScore) {
                bestScore = score;
                best = item;
            }
        });

        return bestScore > 0 ? best : null;
    }

    function handleQuery(rawQuery) {
        var query = rawQuery.trim();
        if (!query) return;

        addMessage(query, 'user', false);

        var match = findBestMatch(query);
        if (match) {
            addMessage(match.a, 'bot', true);
        } else {
            addMessage(
                "I'm not sure about that one &mdash; try one of the topics below, rephrase your question, or use the Feedback page to reach your teacher/admin.",
                'bot',
                true
            );
        }
    }

    toggleBtn.addEventListener('click', function () {
        var isHidden = panel.classList.contains('ttq-hidden');
        panel.classList.toggle('ttq-hidden');
        if (isHidden && !greeted) {
            greeted = true;
            addMessage("Hi! I'm the NeuroNet Assistant. Ask me how to use the system, or tap a topic below.", 'bot', false);
            renderTopics();
        }
        if (isHidden) {
            input.focus();
        }
    });

    closeBtn.addEventListener('click', function () {
        panel.classList.add('ttq-hidden');
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleQuery(input.value);
        input.value = '';
    });
})();
</script>
