<?php
// 1. 开启 Session 记录登录状态
session_start();

// 🔥 新增：如果检测到 URL 带有 action=logout 参数，则执行清除 Session 并跳转
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = array(); // 清空变量
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy(); // 销毁会话
    header("Location: login.php"); // 跳转回登录页
    exit();
}

// 2. 鉴权：必须是管理员才能进
if (!isset($_SESSION['user']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// 3. 建立本地数据库连接
$db_host = "localhost";      
$db_user = "root";           
$db_pass = "";               
$db_name = "csproject";    
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}

// 4. 【后端核心控制】处理所有模块的 POST 行为
$error_msg = "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action_type'])) {
    
    // 【模块 1 行为 A】：删除用户功能
    if ($_POST['action_type'] === 'delete' && isset($_POST['user_id'])) {
        $user_id = intval($_POST['user_id']);
        
        $del_stmt = $conn->prepare("DELETE FROM `users` WHERE `user_id` = ? AND `user_role` != 'admin'");
        if ($del_stmt) {
            $del_stmt->bind_param("i", $user_id);
            if ($del_stmt->execute()) {
                $del_stmt->close();
                header("Location: admin.php?tab=users&msg=User record permanently purged from database.");
                exit();
            } else {
                $error_msg = "Purge Failed: " . $conn->error;
            }
        }
    }
    
    // 🔥【模块 1 行为 B】：后台添加新用户（已修改特权逻辑）
    if ($_POST['action_type'] === 'create') {
        $fullname    = trim($_POST['fullname']);
        $phonenumber = trim($_POST['phonenumber']);
        $email       = trim($_POST['email']);
        $role        = trim($_POST['role']); 
        
        // 💡 核心逻辑：管理员创建的，无论任何角色都不需要等审批，直接激活！
        $status      = 'active'; 
        // 💡 默认将 user_reason 设为 "Admin Create"
        $reason      = "Admin Create";
        
        $default_password = "12345678"; 
        $default_pic      = "default.png";

        if (!empty($fullname) && !empty($phonenumber) && !empty($email) && !empty($role)) {
            $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_email = ?");
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error_msg = "Error: The email address is already registered!";
            } else {
                $ins_stmt = $conn->prepare("INSERT INTO `users` (`user_fullname`, `user_email`, `user_phonenumber`, `user_password`, `user_profilePicture`, `user_role`, `user_reason`, `user_status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins_stmt->bind_param("ssssssss", $fullname, $email, $phonenumber, $default_password, $default_pic, $role, $reason, $status);
                
                if ($ins_stmt->execute()) {
                    $ins_stmt->close();
                    if ($role === 'teacher') {
                        $success_msg = "New teacher account deployed active instantly without verification!";
                    } elseif ($role === 'admin') {
                        $success_msg = "New System Administrator account deployed active instantly!";
                    } else {
                        $success_msg = "New student account deployed active instantly!";
                    }
                    header("Location: admin.php?tab=users&msg=" . urlencode($success_msg));
                    exit();
                } else {
                    $error_msg = "Database Error: " . $ins_stmt->error;
                }
            }
            $check_stmt->close();
        } else {
            $error_msg = "Please fill in all required fields.";
        }
    }

    // 【模块 1 行为 C】：Edit Info 更新功能
    if ($_POST['action_type'] === 'update' && isset($_POST['user_id'])) {
        $user_id      = intval($_POST['user_id']);
        $fullname     = trim($_POST['fullname']);
        $email        = trim($_POST['email']);
        $phonenumber  = trim($_POST['phonenumber']);
        $role         = trim($_POST['role']);
        $status       = trim($_POST['status']); 
        $new_password = trim($_POST['password']);

        if (!empty($fullname) && !empty($email) && !empty($phonenumber) && !empty($role) && !empty($status)) {
            if (!empty($new_password)) {
                $up_stmt = $conn->prepare("UPDATE `users` SET `user_fullname` = ?, `user_email` = ?, `user_phonenumber` = ?, `user_role` = ?, `user_status` = ?, `user_password` = ? WHERE `user_id` = ?");
                $up_stmt->bind_param("ssssssi", $fullname, $email, $phonenumber, $role, $status, $new_password, $user_id);
            } else {
                $up_stmt = $conn->prepare("UPDATE `users` SET `user_fullname` = ?, `user_email` = ?, `user_phonenumber` = ?, `user_role` = ?, `user_status` = ? WHERE `user_id` = ?");
                $up_stmt->bind_param("sssssi", $fullname, $email, $phonenumber, $role, $status, $user_id);
            }
            
            if ($up_stmt->execute()) {
                $up_stmt->close();
                header("Location: admin.php?tab=users&msg=User account settings synchronised successfully!");
                exit();
            } else {
                $error_msg = "Update Database Failed: " . $conn->error;
            }
        } else {
            $error_msg = "Required form fields cannot be submitted empty.";
        }
    }

    // 【模块 2】：教师资质审核（保留给前端注册的老师使用）
    if ($_POST['action_type'] === 'review_teacher' && isset($_POST['review_id']) && isset($_POST['status'])) {
        $review_id = intval($_POST['review_id']);
        $status = trim($_POST['status']); 
        $db_status = ($status === 'Approved') ? 'active' : 'deactive'; 
        
        $rev_stmt = $conn->prepare("UPDATE `users` SET `user_status` = ? WHERE `user_id` = ? AND `user_role` = 'teacher'");
        if ($rev_stmt) {
            $rev_stmt->bind_param("si", $db_status, $review_id);
            if ($rev_stmt->execute()) {
                $rev_stmt->close();
                header("Location: admin.php?tab=approve&msg=Teacher application has been " . $status . " successfully!");
                exit();
            }
        }
    }

    // 【模块 3 行为】：发布新的系统公告
    if ($_POST['action_type'] === 'publish_notify') {
        $title = trim($_POST['title']);
        $content = trim($_POST['content']);
        $target = trim($_POST['target_audience']);
        
        if (!empty($title) && !empty($content)) {
            $not_stmt = $conn->prepare("INSERT INTO `notifications` (`title`, `content`, `target_audience`, `created_at`) VALUES (?, ?, ?, NOW())");
            if ($not_stmt) {
                $not_stmt->bind_param("sss", $title, $content, $target);
                $not_stmt->execute();
                $not_stmt->close();
                header("Location: admin.php?tab=notify&msg=New notification broadcasted successfully!");
                exit();
            }
        } else {
            $error_msg = "Title and Content cannot be empty!";
        }
    }

    // 【模块 4 行为】：反馈工单标记为已解决
    if ($_POST['action_type'] === 'resolve_feedback' && isset($_POST['feedback_id'])) {
        $feedback_id = intval($_POST['feedback_id']);
        $fb_stmt = $conn->prepare("UPDATE `feedbacks` SET `status` = 'Resolved' WHERE `id` = ?");
        if ($fb_stmt) {
            $fb_stmt->bind_param("i", $feedback_id);
            $fb_stmt->execute();
            $fb_stmt->close();
            header("Location: admin.php?tab=feedback&msg=Ticket marked as resolved!");
            exit();
        }
    }
}

// 5. 获取当前激活的功能 Tab
$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'users';

// 6. 数据流同步读取
$real_users = [];
$real_feedbacks = [];
$pending_teachers = [];
$real_notifications = [];

if ($current_tab === 'users') {
    $result = $conn->query("SELECT user_id, user_fullname, user_email, user_phonenumber, user_role, user_status, user_reason, user_registerDate, user_profilePicture FROM users ORDER BY user_id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) { $real_users[] = $row; }
    }
} elseif ($current_tab === 'approve') {
    $result = $conn->query("SELECT user_id, user_fullname, user_email, user_reason, user_registerDate FROM users WHERE user_role = 'teacher' AND user_status = 'pending' ORDER BY user_id DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) { $pending_teachers[] = $row; }
    }
} elseif ($current_tab === 'notify') {
    $result = $conn->query("SELECT id, title, content, target_audience, created_at FROM notifications ORDER BY id DESC LIMIT 5");
    if ($result) {
        while ($row = $result->fetch_assoc()) { $real_notifications[] = $row; }
    }
} elseif ($current_tab === 'feedback') {
    $result = $conn->query("SHOW COLUMNS FROM `feedbacks` LIKE 'id'");
    if ($result && $result->num_rows > 0) {
        $fb_res = $conn->query("SELECT id, user_email, type, content, status, created_at FROM feedbacks ORDER BY CASE WHEN status = 'Pending' THEN 1 ELSE 2 END, id DESC");
        if ($fb_res) { while ($row = $fb_res->fetch_assoc()) { $real_feedbacks[] = $row; } }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TripleT Edu - Super Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
    <style>
        @media (max-width: 1023px) {
            .mobile-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }
            .mobile-sidebar.open {
                transform: translateX(0);
            }
        }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col">

    <header class="header">
        <div class="logo">
            <button id="menuToggleButton" class="lg:hidden mr-1 flex flex-col justify-between w-6 h-4.5 bg-transparent border-none cursor-pointer group focus:outline-none" aria-label="Toggle Menu">
                <span class="w-6 h-0.5 rounded-full transition-all duration-300" style="background-color: var(--cyan);"></span>
                <span class="w-6 h-0.5 rounded-full transition-all duration-300 my-1" style="background-color: var(--cyan);"></span>
                <span class="w-6 h-0.5 rounded-full transition-all duration-300" style="background-color: var(--cyan);"></span>
            </button>

            <a href="admin.php" style="display:flex; align-items:center; gap:12px; text-decoration:none; color:inherit;">
                <div class="logo-box">
                    <video autoplay muted loop playsinline>
                        <source src="../Shared/assets/logo.mp4" type="video/mp4">
                    </video>
                </div>
                <div>
                    <h1 class="text-base md:text-lg font-bold leading-tight">TripleT Admin Center</h1>
                    <p class="text-[10px] md:text-xs font-medium" style="color: var(--text-muted);">System Control & Management</p>
                </div>
            </a>
        </div>
        <div class="pill status-running">
            <span class="status-dot"></span>
            <strong class="text-xs sm:text-sm">👑 Admin: <?php echo htmlspecialchars($_SESSION['user']); ?></strong>
        </div>
    </header>

    <div id="sidebarOverlay" class="fixed inset-0 bg-black/60 z-40 hidden lg:hidden backdrop-blur-sm"></div>

    <div class="flex flex-1 p-4 md:p-8 max-w-[1400px] w-full mx-auto gap-6 items-start relative">
        
        <aside id="sidebarPanel" class="panel mobile-sidebar fixed inset-y-0 left-0 w-72 z-50 lg:z-0 lg:static lg:w-64 flex-shrink-0 h-full lg:h-auto overflow-y-auto lg:overflow-visible" style="background: var(--bg-mid);">
            <div class="flex lg:hidden justify-between items-center mb-6 pb-4 border-b" style="border-color: var(--card-border);">
                <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--cyan);">Navigation</span>
                <button id="closeSidebarButton" class="text-xl font-bold p-1 focus:outline-none" style="color: var(--text-muted);">✕</button>
            </div>

            <nav class="space-y-2">
                <a href="admin.php?tab=users" class="btn outline w-full justify-start <?php echo $current_tab === 'users' ? 'selected' : ''; ?>" style="<?php echo $current_tab === 'users' ? 'border-color:var(--cyan);color:var(--cyan);' : ''; ?>">
                    <span class="mr-2">👥</span> Manage User Account
                </a>
                <a href="admin.php?tab=approve" class="btn outline w-full justify-start <?php echo $current_tab === 'approve' ? 'selected' : ''; ?>" style="<?php echo $current_tab === 'approve' ? 'border-color:var(--cyan);color:var(--cyan);' : ''; ?>">
                    <span class="mr-2">🛡️</span> Approve Teacher
                </a>
                <a href="admin.php?tab=notify" class="btn outline w-full justify-start <?php echo $current_tab === 'notify' ? 'selected' : ''; ?>" style="<?php echo $current_tab === 'notify' ? 'border-color:var(--cyan);color:var(--cyan);' : ''; ?>">
                    <span class="mr-2">📢</span> Notification
                </a>
                <a href="admin.php?tab=feedback" class="btn outline w-full justify-start <?php echo $current_tab === 'feedback' ? 'selected' : ''; ?>" style="<?php echo $current_tab === 'feedback' ? 'border-color:var(--cyan);color:var(--cyan);' : ''; ?>">
                    <span class="mr-2">💬</span> Feedback Management
                </a>
                <div class="pt-4 mt-4 border-t" style="border-color: var(--card-border);">
                    <a href="admin.php?action=logout" class="text-xs font-bold transition" style="color: var(--error);">← Logout</a>
                </div>
            </nav>
        </aside>

        <main class="flex-1 w-full panel">
            
            <?php if(isset($_GET['msg'])): ?>
                <div class="banner banner-success">✨ <?php echo htmlspecialchars(urldecode($_GET['msg'])); ?></div>
            <?php endif; ?>
            <?php if(!empty($error_msg)): ?>
                <div class="banner banner-error">⚠️ <?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <?php if ($current_tab === 'users'): ?>
                <div class="space-y-6">
                    <div class="topbar">
                        <div>
                            <div class="eyebrow">Database Live Sync</div>
                            <h1>User Account Management</h1>
                        </div>
                        <button id="openAddUserModal" class="btn-primary" style="width: auto; padding: 10px 20px;">+ Add Account</button>
                    </div>

                    <div class="space-y-4">
                        <?php if (empty($real_users)): ?>
                            <p class="text-xs italic" style="color: var(--text-muted)">No records found.</p>
                        <?php else: ?>
                            <?php foreach ($real_users as $user): ?>
                                <div class="card bg-opacity-20">
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                                        
                                        <div class="flex items-center space-x-4">
    <div class="pill flex-shrink-0">
        <strong>
            <?php 
                if($user['user_role'] === 'teacher') echo '👨‍🏫 Host';
                elseif($user['user_role'] === 'admin') echo '👑 Admin';
                else echo '🎓 Pupil'; 
            ?>
        </strong>
    </div>
    
    <div class="flex items-center space-x-3">
        <div class="w-10 h-10 rounded-full overflow-hidden border-2 bg-slate-800/80 flex-shrink-0 flex items-center justify-center shadow-inner" style="border-color: var(--card-border);">
            <?php 
            // 获取数据库中的头像文件名
$avatar_filename = !empty($user['user_profilePicture']) ? $user['user_profilePicture'] : 'default.png';
// 拼接存放头像的文件夹路径
$avatar_path = "uploads/" . $avatar_filename;

// 【修改后的逻辑】：只要这个图片在 uploads 文件夹里存在，就直接显示图片
if (file_exists($avatar_path)) {
    echo '<img src="'.htmlspecialchars($avatar_path).'" alt="Avatar" class="w-full h-full object-cover">';
} else {
    // 只有当文件在服务器上彻底找不到时（比如被误删了），才降级显示首字母
    echo '<span class="text-sm font-bold text-cyan-400 font-mono">'.strtoupper(substr($user['user_fullname'], 0, 1)).'</span>';
}
            ?>
        </div>
        
        <div>
            <h4 class="font-bold text-sm md:text-base text-slate-200 break-all leading-snug"><?php echo htmlspecialchars($user['user_fullname']); ?></h4>
            <p class="text-xs text-slate-400 flex items-center gap-1.5 flex-wrap">
                <span class="break-all"><?php echo htmlspecialchars($user['user_email']); ?></span>
                <span class="text-slate-600">•</span> 
                
                <?php 
                $status_lower = strtolower($user['user_status']);
                if ($status_lower === 'active') {
                    echo '<span class="uppercase font-mono text-[10px] font-bold tracking-wider text-green-400">ACTIVE</span>';
                } elseif ($status_lower === 'pending') {
                    echo '<span class="uppercase font-mono text-[10px] font-bold tracking-wider text-yellow-400">PENDING</span>';
                } else {
                    echo '<span class="uppercase font-mono text-[10px] font-bold tracking-wider text-red-500">INACTIVE</span>';
                }
                ?>
            </p>
        </div>
    </div>
</div>

                                        <div class="flex items-center space-x-2">
                                            <button onclick="toggleEditRow(<?php echo $user['user_id']; ?>)" class="btn-primary py-1 px-4 text-xs" style="width:auto;">Edit Info</button>
                                            
                                            <?php if ($user['user_role'] !== 'admin'): ?>
                                                <form action="admin.php?tab=users" method="POST" onsubmit="return confirm('⚠️ WARNING: Are you sure you want to PERMANENTLY DELETE this user?');" style="display:inline;">
                                                    <input type="hidden" name="action_type" value="delete">
                                                    <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                    <button type="submit" class="text-xs font-bold px-3 py-1 border rounded-xl hover:bg-red-900/40 transition duration-200" style="color: var(--error); border-color: rgba(239, 68, 68, 0.4);">Delete</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div id="edit-row-<?php echo $user['user_id']; ?>" class="hidden mt-4 pt-4 border-t space-y-4" style="border-color: var(--card-border);">
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 bg-black/40 p-3 rounded-xl border border-dashed text-xs font-mono" style="border-color: var(--card-border);">
                                            <div>
                                                <span class="text-slate-400">🔢 User ID:</span> 
                                                <span class="text-cyan-400 font-bold"><?php echo $user['user_id']; ?></span>
                                            </div>
                                            <div>
                                                <span class="text-slate-400">📅 Register Date:</span> 
                                                <span class="text-slate-300"><?php echo $user['user_registerDate']; ?></span>
                                            </div>
                                            <div class="md:col-span-3">
                                                <span class="text-slate-400">📝 Application Reason:</span> 
                                                <span class="text-purple-300 italic"><?php echo !empty($user['user_reason']) ? htmlspecialchars($user['user_reason']) : 'None'; ?></span>
                                            </div>
                                        </div>

                                        <form action="admin.php?tab=users" method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
                                            <input type="hidden" name="action_type" value="update">
                                            <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                            
                                            <div>
                                                <label>Full Name</label>
                                                <input type="text" name="fullname" required value="<?php echo htmlspecialchars($user['user_fullname']); ?>">
                                            </div>

                                            <div>
                                                <label>Email Address</label>
                                                <input type="email" name="email" required value="<?php echo htmlspecialchars($user['user_email']); ?>">
                                            </div>

                                            <div>
                                                <label>Phone Number</label>
                                                <input type="text" name="phonenumber" required value="<?php echo htmlspecialchars($user['user_phonenumber']); ?>">
                                            </div>

                                            <div>
                                                <label>System Privilege Group</label>
                                                <select name="role">
                                                    <option value="student" <?php if($user['user_role'] === 'student') echo 'selected'; ?>>Student</option>
                                                    <option value="teacher" <?php if($user['user_role'] === 'teacher') echo 'selected'; ?>>Teacher</option>
                                                    <option value="admin" <?php if($user['user_role'] === 'admin') echo 'selected'; ?>>Admin</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label>Account Status</label>
                                                <select name="status">
                                                    <option value="active" <?php if($user['user_status'] === 'active') echo 'selected'; ?>>Active</option>
                                                    <option value="pending" <?php if($user['user_status'] === 'pending') echo 'selected'; ?>>Pending</option>
                                                    <option value="deactive" <?php if($user['user_status'] === 'deactive') echo 'selected'; ?>>Inactive</option>
                                                </select>
                                            </div>

                                            <div>
                                                <label>Reset Password (Optional)</label>
                                                <input type="text" name="password" placeholder="Leave blank to keep old...">
                                            </div>

                                            <div class="md:col-span-2 lg:col-span-3 flex justify-end space-x-2 pt-2">
                                                <button type="submit" class="btn-primary py-2 px-6 text-xs" style="width: auto;">Save Synchronization</button>
                                                <button type="button" onclick="toggleEditRow(<?php echo $user['user_id']; ?>)" class="btn-secondary py-2 px-6 text-xs" style="width: auto;">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($current_tab === 'approve'): ?>
                <div class="space-y-6">
                    <div class="topbar">
                        <div>
                            <div class="eyebrow">Verification Center</div>
                            <h1>Approve Teacher Applications</h1>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php if (empty($pending_teachers)): ?>
                            <p class="text-xs italic text-center p-6" style="color: var(--text-muted)">No pending teacher verification requests.</p>
                        <?php else: ?>
                            <?php foreach ($pending_teachers as $teacher): ?>
                                <div class="card space-y-3">
                                    <div class="flex justify-between items-start sm:items-center flex-col sm:flex-row gap-2">
                                        <div>
                                            <h3 class="text-base font-bold" style="color: var(--cyan);"><?php echo htmlspecialchars($teacher['user_fullname']); ?></h3>
                                            <p class="text-xs text-slate-400"><?php echo htmlspecialchars($teacher['user_email']); ?></p>
                                        </div>
                                        <span class="pill">📝 Status: Pending Approval</span>
                                    </div>

                                    <div class="text-xs p-3 rounded-xl font-mono" style="background: rgba(6,11,31,0.6); border: 1px solid var(--card-border);">
                                        <span class="text-purple-400 font-bold">[Application Reason]:</span><br>
                                        <?php echo htmlspecialchars($teacher['user_reason']); ?>
                                    </div>
                                    
                                    <div class="flex justify-between items-center text-xs border-t pt-3" style="border-color: var(--card-border);">
                                        <span style="color: var(--text-muted);">Applied at: <?php echo $teacher['user_registerDate']; ?></span>
                                        <div class="flex space-x-2">
                                            <form action="admin.php?tab=approve" method="POST">
                                                <input type="hidden" name="action_type" value="review_teacher">
                                                <input type="hidden" name="review_id" value="<?php echo $teacher['user_id']; ?>">
                                                <input type="hidden" name="status" value="Approved">
                                                <button type="submit" class="btn outline" style="border-color: var(--success); color: var(--success); padding: 4px 12px;">Approve ✓</button>
                                            </form>
                                            <form action="admin.php?tab=approve" method="POST" onsubmit="return confirm('Reject this application?');">
                                                <input type="hidden" name="action_type" value="review_teacher">
                                                <input type="hidden" name="review_id" value="<?php echo $teacher['user_id']; ?>">
                                                <input type="hidden" name="status" value="Rejected">
                                                <button type="submit" class="text-xs font-bold hover:underline" style="color: var(--error);">Reject</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($current_tab === 'notify'): ?>
                <div class="space-y-6">
                    <div class="topbar">
                        <div>
                            <div class="eyebrow">Global Broadcasting</div>
                            <h1>System Notifications</h1>
                        </div>
                    </div>

                    <div class="card" style="border-color: var(--violet);">
                        <h3 class="text-sm font-bold mb-3" style="color: var(--violet);">📢 Broadcast New Announcement</h3>
                        <form action="admin.php?tab=notify" method="POST" class="space-y-4">
                            <input type="hidden" name="action_type" value="publish_notify">
                            <div>
                                <label>Notice Title</label>
                                <input type="text" name="title" required placeholder="e.g., System Maintenance Schedule">
                            </div>
                            <div>
                                <label>Target Audience</label>
                                <select name="target_audience">
                                    <option value="all">All Users (Students & Teachers)</option>
                                    <option value="teacher">Teachers Only</option>
                                    <option value="student">Students Only</option>
                                </select>
                            </div>
                            <div>
                                <label>Notice Content</label>
                                <textarea name="content" rows="3" required placeholder="Write internal notice details here..." style="background: rgba(6,11,31,0.4); border: 1px solid var(--card-border); color: var(--text);" class="w-full p-3 rounded-xl text-xs font-mono focus:outline-none focus:border-purple-500"></textarea>
                            </div>
                            <button type="submit" class="btn-primary" style="background: linear-gradient(135deg, #a855f7, #7c3aed);">Push Notice Node</button>
                        </form>
                    </div>

                    <div class="space-y-4">
                        <h3 class="text-xs uppercase tracking-wider font-bold" style="color: var(--text-muted);">Recent Transmissions</h3>
                        <?php if (empty($real_notifications)): ?>
                            <p class="text-xs italic" style="color: var(--text-muted);">No broadcast logs in database sync.</p>
                        <?php else: ?>
                            <?php foreach ($real_notifications as $notice): ?>
                                <div class="card p-4 space-y-2" style="background: rgba(255,255,255,0.02)">
                                    <div class="flex justify-between items-center">
                                        <h4 class="text-sm font-bold text-slate-200"><?php echo htmlspecialchars($notice['title']); ?></h4>
                                        <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-slate-800 text-slate-400">To: <?php echo ucfirst($notice['target_audience']); ?></span>
                                    </div>
                                    <p class="text-xs font-mono text-slate-400"><?php echo nl2br(htmlspecialchars($notice['content'])); ?></p>
                                    <div class="text-[10px] text-right" style="color: var(--text-muted);"><?php echo $notice['created_at']; ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($current_tab === 'feedback'): ?>
                <div class="space-y-6">
                    <div class="topbar">
                        <div>
                            <div class="eyebrow">Support Tickets</div>
                            <h1>Feedback Management</h1>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <?php if (empty($real_feedbacks)): ?>
                            <p class="text-xs italic text-center p-6" style="color: var(--text-muted)">Hurray! No feedback logs found.</p>
                        <?php else: ?>
                            <?php foreach ($real_feedbacks as $fb): ?>
                                <div class="card space-y-3">
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs font-bold font-mono break-all" style="color: var(--cyan);"><?php echo htmlspecialchars($fb['user_email']); ?></span>
                                        <span class="badge <?php echo $fb['status'] === 'Pending' ? 'badge-winner' : 'badge-offline'; ?>">
                                            <?php echo $fb['status']; ?>
                                        </span>
                                    </div>
                                    
                                    <div class="text-xs font-mono" style="color: var(--violet);">[ Type: <?php echo htmlspecialchars($fb['type']); ?> ]</div>
                                    <p class="text-xs p-3 rounded-xl font-mono" style="background: rgba(6,11,31,0.6); border: 1px solid var(--card-border); color: var(--text);">
                                        <?php echo nl2br(htmlspecialchars($fb['content'])); ?>
                                    </p>
                                    
                                    <div class="flex justify-between items-center text-[10px]" style="color: var(--text-muted);">
                                        <span>Logged: <?php echo $fb['created_at']; ?></span>
                                        <?php if ($fb['status'] === 'Pending'): ?>
                                            <form action="admin.php?tab=feedback" method="POST">
                                                <input type="hidden" name="action_type" value="resolve_feedback">
                                                <input type="hidden" name="feedback_id" value="<?php echo $fb['id']; ?>">
                                                <button type="submit" class="btn outline" style="padding: 4px 10px; font-size: 11px; border-color: var(--success); color: var(--success);">
                                                    Mark Resolved ✓
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
        </main>
    </div>

    <div id="addUserModal" class="fixed inset-0 bg-black/70 z-50 flex items-center justify-center hidden p-4">
        <div class="panel max-w-md w-full space-y-5" style="background: var(--bg-mid); border-color: var(--cyan);">
            <div class="flex justify-between items-center border-b pb-3" style="border-color: var(--card-border);">
                <h3 class="text-lg font-bold font-display" style="color: var(--cyan);">Create Token Account</h3>
                <button id="closeAddUserModal" class="text-xl font-bold" style="color: var(--text-muted);">✕</button>
            </div>
            <form action="admin.php?tab=users" method="POST" class="space-y-4">
                <input type="hidden" name="action_type" value="create">
                
                <div>
                    <label>Full Name</label>
                    <input type="text" name="fullname" required placeholder="e.g. Alexander Pierce">
                </div>

                <div>
                    <label>Phone Number</label>
                    <input type="text" name="phonenumber" required placeholder="e.g. +6012345678">
                </div>

                <div>
                    <label>User Email Address</label>
                    <input type="email" name="email" required placeholder="name@tripletedu.com">
                </div>

                <div>
                    <label>System Privilege Group</label>
                    <select name="role" required>
                        <option value="student">Student Account</option>
                        <option value="admin">Admin Account</option>
                        <option value="teacher">Teacher Account</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-3 pt-2">
                    <button type="button" id="cancelAddUserModal" class="btn-secondary" style="width:auto;">Cancel</button>
                    <button type="submit" class="btn-primary" style="width:auto;">Deploy Node</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const openModalBtn = document.getElementById('openAddUserModal');
        const closeModalBtn = document.getElementById('closeAddUserModal');
        const cancelModalBtn = document.getElementById('cancelAddUserModal');
        const addUserModal = document.getElementById('addUserModal');

        if(openModalBtn) openModalBtn.addEventListener('click', () => addUserModal.classList.remove('hidden'));
        if(closeModalBtn) closeModalBtn.addEventListener('click', () => { addUserModal.classList.add('hidden'); });
        if(cancelModalBtn) cancelModalBtn.addEventListener('click', () => { addUserModal.classList.add('hidden'); });

        function toggleEditRow(userId) {
            const editRow = document.getElementById('edit-row-' + userId);
            if(editRow) editRow.classList.toggle('hidden');
        }

        const menuToggleButton = document.getElementById('menuToggleButton');
        const closeSidebarButton = document.getElementById('closeSidebarButton');
        const sidebarPanel = document.getElementById('sidebarPanel');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function openMobileSidebar() {
            sidebarPanel.classList.add('open');
            sidebarOverlay.classList.remove('hidden');
        }

        function closeMobileSidebar() {
            sidebarPanel.classList.remove('open');
            sidebarOverlay.classList.add('hidden');
        }

        if(menuToggleButton) menuToggleButton.addEventListener('click', openMobileSidebar);
        if(closeSidebarButton) closeSidebarButton.addEventListener('click', closeMobileSidebar);
        if(sidebarOverlay) sidebarOverlay.addEventListener('click', closeMobileSidebar);
    </script>

    <?php $chatbotRole = 'admin'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>
<?php 
$conn->close(); 
?>