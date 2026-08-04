<?php
// ------------------------------------------------------------------
// Teacher-side notification bell dropdown. Include this once, anywhere
// in the page (placement in the DOM doesn't matter — the panel is
// positioned with CSS), on any Teacher page that already has the
// #ttq-notif-toggle button in its header.
//
// Pulls whatever the admin broadcast to 'teacher' (or 'all') from the
// `notifications` table via getNotificationsForRole() in Database.php.
// No read/unread tracking — just a simple list, newest first, re-read
// fresh on every page load.
// ------------------------------------------------------------------

// Same defensive check as the Student side — load Database.php if the
// function isn't defined yet, regardless of whether $conn happens to
// already exist.
if (!function_exists('getNotificationsForRole')) {
    include 'Database.php';
}

$notifItems = getNotificationsForRole($conn, 'teacher');
?>
<div id="ttq-notif-panel" class="ttq-hidden">
    <div id="ttq-notif-header">
        <div id="ttq-notif-title">🔔 Notifications</div>
        <button type="button" id="ttq-notif-close" aria-label="Close notifications">&#10005;</button>
    </div>

    <div id="ttq-notif-list">
        <?php if (empty($notifItems)): ?>
            <div id="ttq-notif-empty">No notifications yet.</div>
        <?php else: ?>
            <?php foreach ($notifItems as $item): ?>
                <div class="ttq-notif-item">
                    <div class="ttq-notif-item-title"><?php echo htmlspecialchars($item['title']); ?></div>
                    <div class="ttq-notif-item-body"><?php echo nl2br(htmlspecialchars($item['content'])); ?></div>
                    <div class="ttq-notif-item-date"><?php echo htmlspecialchars(date('d M Y, g:ia', strtotime($item['created_at']))); ?></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    #ttq-notif-panel, #ttq-notif-panel * {
        box-sizing: border-box;
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
    }

    #ttq-notif-panel {
        position: fixed;
        top: 68px;
        right: 24px;
        width: 320px;
        max-width: calc(100vw - 32px);
        max-height: 60vh;
        background: #111826;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 14px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    #ttq-notif-panel.ttq-hidden { display: none; }

    #ttq-notif-header {
        background: linear-gradient(135deg, #00e0ff, #6a5cff);
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }
    #ttq-notif-title { color: #fff; font-weight: 700; font-size: 14px; }
    #ttq-notif-close {
        background: none;
        border: none;
        color: #fff;
        font-size: 16px;
        cursor: pointer;
        line-height: 1;
        padding: 4px;
    }

    #ttq-notif-list {
        flex: 1;
        overflow-y: auto;
        padding: 10px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    #ttq-notif-empty {
        padding: 20px 8px;
        text-align: center;
        font-size: 13px;
        color: rgba(230, 236, 245, 0.6);
    }

    .ttq-notif-item {
        background: #1c2536;
        border: 1px solid rgba(255, 255, 255, 0.06);
        border-radius: 10px;
        padding: 10px 12px;
    }
    .ttq-notif-item-title {
        color: #e6ecf5;
        font-weight: 700;
        font-size: 13px;
        margin-bottom: 4px;
    }
    .ttq-notif-item-body {
        color: #b7c1d1;
        font-size: 12.5px;
        line-height: 1.45;
        word-wrap: break-word;
    }
    .ttq-notif-item-date {
        color: #7fe9ff;
        font-size: 10.5px;
        margin-top: 6px;
        font-family: 'JetBrains Mono', monospace;
    }

    @media (max-width: 420px) {
        #ttq-notif-panel { right: 16px; left: 16px; width: auto; }
    }
</style>

<script>
(function () {
    var toggleBtn = document.getElementById('ttq-notif-toggle');
    var panel = document.getElementById('ttq-notif-panel');
    var closeBtn = document.getElementById('ttq-notif-close');

    if (!toggleBtn || !panel) return;

    toggleBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('ttq-hidden');
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            panel.classList.add('ttq-hidden');
        });
    }

    // Click outside the panel (and outside the toggle button) closes it.
    document.addEventListener('click', function (e) {
        if (panel.classList.contains('ttq-hidden')) return;
        if (panel.contains(e.target) || toggleBtn.contains(e.target)) return;
        panel.classList.add('ttq-hidden');
    });
})();
</script>