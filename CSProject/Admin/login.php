<?php
// 开启 Session 用来记录登录状态
session_start();

$error_message = "";
$success_message = "";

// 数据库连接配置
$db_host = "localhost";      
$db_user = "root";           
$db_pass = "";               
$db_name = "csproject";    

// 检查是否有关联的 POST 提交
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_type'])) {
    
    // 创建数据库连接
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error_message = "连接本地数据库失败: " . $conn->connect_error;
    } else {
        
        // ==========================================
        // 逻辑 1：处理 登录 (Login) 行为
        // ==========================================
        if ($_POST['action_type'] === 'login') {
            $email = isset($_POST['user_email']) ? trim($_POST['user_email']) : '';
            $password = isset($_POST['user_password']) ? trim($_POST['user_password']) : '';
            
            if (empty($email) || empty($password)) {
                $error_message = "Please enter both email and password.";
            } else {
                // 精准匹配用户的 users.sql 结构
                $stmt = $conn->prepare("SELECT user_id, user_email, user_password, user_role, user_status FROM users WHERE user_email = ?");
                if ($stmt) {
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $result = $stmt->get_result();

                    if ($result->num_rows === 1) {
                        $user = $result->fetch_assoc();
                        
                        // 验证明文密码
                        if ($password === $user['user_password']) {
                            
                            // ==========================================
                            // 🔒 安全状态拦截校验（重构为白名单逻辑）
                            // ==========================================
                            if ($user['user_status'] === 'active') {
                                // 💡 只有状态是唯一的 'active'，才发放会话凭证进入系统
                                $_SESSION['user'] = $user['user_email'];
                                $_SESSION['role'] = $user['user_role'];
                                
                                $stmt->close();
                                $conn->close();
                                
                                // 根据角色分流跳转
                                if ($user['user_role'] === 'admin') {
                                    header("Location: admin.php");
                                } else if ($user['user_role'] === 'teacher') {
                                    header("Location: ../Teacher/teacher-dashboard.php");
                                } else if ($user['user_role'] === 'student') {
                                    header("Location: ../Student/student-dashboard.php");
                                }
                                exit();

                            } elseif ($user['user_status'] === 'pending') {
                                // 还在审核中的教师
                                $error_message = "Your teacher account is pending approval by the Admin.";
                            } else {
                                // 剩下的所有非激活状态（不管是 deactive、inactive 还是空值），全部拦截
                                $error_message = "Your account has been deactivated by the Admin.";
                            }

                        } else {
                            $error_message = "Password incorrect! Please verify your inputs.";
                        }
                    } else {
                        $error_message = "Account not found in the system!";
                    }
                    $stmt->close();
                } else {
                    $error_message = "SQL Error: " . $conn->error;
                }
            }
        }
        
        // ==========================================
        // 逻辑 2：处理 注册 (Sign Up) 行为
        // ==========================================
        if ($_POST['action_type'] === 'signup') {
            $fullname    = trim($_POST['user_fullname']);
            $email       = trim($_POST['user_email']);
            $phonenumber = trim($_POST['user_phonenumber']);
            $password    = trim($_POST['user_password']);
            $role        = trim($_POST['user_role']); 
            $reason      = trim($_POST['user_reason'] ?? '');
            
            // 后台自动拿 email 的前缀或随机数来填补 username 防止数据库报错
            $username    = explode('@', $email)[0] . rand(10, 99); 
            
            $default_pic = "default.png"; 
            $status = ($role === 'teacher') ? 'pending' : 'active';

            if (empty($fullname) || empty($email) || empty($phonenumber) || empty($password) || empty($role)) {
                $error_message = "Please fill in all required fields.";
            } else {
                // 查重：防止邮箱被二次注册
                $dup_stmt = $conn->prepare("SELECT user_id FROM users WHERE user_email = ?");
                $dup_stmt->bind_param("s", $email);
                $dup_stmt->execute();
                if ($dup_stmt->get_result()->num_rows > 0) {
                    $error_message = "This email is already registered!";
                    $dup_stmt->close();
                } else {
                    $dup_stmt->close();
                    
                    // 统一写入全字段单表 users
                    $sql = "INSERT INTO `users` (`user_fullname`, `user_email`, `user_phonenumber`, `user_password`, `user_profilePicture`, `user_role`, `user_reason`, `user_status`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $ins_stmt = $conn->prepare($sql);
                    
                    if ($ins_stmt === false) {
                        $error_message = "SQL Prepare Error: " . $conn->error;
                    } else {
                        $ins_stmt->bind_param("ssssssss", $fullname, $email, $phonenumber, $password, $default_pic, $role, $reason, $status);
                        
                        if ($ins_stmt->execute()) {
                            if ($role === 'teacher') {
                                $success_message = "Application submitted! Please wait for Admin to approve your account.";
                            } else {
                                $success_message = "Student account created successfully! You can login now.";
                            }
                        } else {
                            $error_message = "Database Execution Error: " . $ins_stmt->error;
                        }
                        $ins_stmt->close();
                    }
                }
            }
        }
        
        $conn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TripleT Edu - Secure Node Terminal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="antialiased flex items-center justify-center p-4">

    <div class="panel max-w-md w-full space-y-6 relative overflow-hidden">
        
        <div class="absolute top-0 left-0 right-0 h-0.5 bg-gradient-to-r from-transparent via-cyan-500 to-transparent"></div>

        <?php if (!empty($error_message)): ?>
            <div class="banner banner-error">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="banner banner-success">✨ <?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <div id="loginSection" class="space-y-5">
            <div class="text-center">
                <div class="logo-box mx-auto mb-3">TT</div>
                <h1>System Security Authentication</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Please decrypt your secure credentials node to connect</p>
            </div>

            <form action="login.php" method="POST" class="space-y-4">
                <input type="hidden" name="action_type" value="login">
                
                <div>
                    <label for="loginEmail">Email Address</label>
                    <input type="email" id="loginEmail" name="user_email" required placeholder="name@tripletedu.com">
                </div>

                <div>
                    <label for="loginPassword">Credential Token (Password)</label>
                    <input type="password" id="loginPassword" name="user_password" required placeholder="••••••••">
                </div>

                <button type="submit" class="btn-primary">Connect Account Node</button>
            </form>

            <div class="text-center pt-2 border-t" style="border-color: var(--card-border);">
                <p class="text-xs" style="color: var(--text-muted);">
                    Don't have an internal token node? 
                    <button type="button" onclick="switchView('signup')" class="text-cyan-400 font-bold hover:underline bg-transparent border-none p-0 cursor-pointer">Deploy Signup</button>
                </p>
            </div>
        </div>

        <div id="signupSection" class="space-y-5 hidden">
            <div class="text-center">
                <h1 style="color: var(--cyan);">Deploy New User Token</h1>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Register your node into database live sync</p>
            </div>

            <form action="login.php" method="POST" class="space-y-3">
                <input type="hidden" name="action_type" value="signup">
                <input type="hidden" id="hiddenRoleInput" name="user_role" value="student">

                <div class="grid grid-cols-2 gap-3 mb-2">
                    <button type="button" id="roleBtnStudent" onclick="setRole('student')" class="py-2.5 rounded-xl border text-xs font-bold transition duration-200 bg-cyan-900/40 border-cyan-500 text-cyan-400">
                        🎓 Join as Student
                    </button>
                    <button type="button" id="roleBtnTeacher" onclick="setRole('teacher')" class="py-2.5 rounded-xl border text-xs font-bold transition duration-200 border-slate-700 text-slate-400">
                        👨‍🏫 Apply as Teacher
                    </button>
                </div>

                <div>
                    <label>Full Legal Name</label>
                    <input type="text" name="user_fullname" required placeholder="e.g. Alexander Pierce">
                </div>

                <div>
                    <label>Internal Corporate Email</label>
                    <input type="email" name="user_email" required placeholder="name@tripletedu.com">
                </div>

                <div>
                    <label>Phone Line Number</label>
                    <input type="text" name="user_phonenumber" required placeholder="e.g. +6012345678">
                </div>

                <div>
                    <label>Create Account Password</label>
                    <input type="password" name="user_password" required placeholder="Minimum 8 characters...">
                </div>

                <div id="reasonField" class="hidden">
                    <label class="text-purple-400 font-bold">🛡️ Application Reason / Background</label>
                    <textarea id="reasonInput" name="user_reason" placeholder="Please state your major, background or teaching purpose for admin audit..." rows="3" class="w-full p-3 rounded-xl text-xs font-mono focus:outline-none focus:border-purple-500 mt-1" style="background: rgba(6,11,31,0.4); border: 1px solid var(--card-border); color: var(--text);"></textarea>
                </div>

                <button type="submit" class="btn-primary mt-2">Transmit Deployment Node</button>
            </form>

            <div class="text-center pt-2 border-t" style="border-color: var(--card-border);">
                <p class="text-xs" style="color: var(--text-muted);">
                    Already verified in system? 
                    <button type="button" onclick="switchView('login')" class="text-cyan-400 font-bold hover:underline bg-transparent border-none p-0 cursor-pointer">Back to Login</button>
                </p>
            </div>
        </div>

    </div>

    <script>
        function switchView(view) {
            if (view === 'signup') {
                document.getElementById('loginSection').classList.add('hidden');
                document.getElementById('signupSection').classList.remove('hidden');
            } else {
                document.getElementById('signupSection').classList.add('hidden');
                document.getElementById('loginSection').classList.remove('hidden');
            }
        }

        function setRole(role) {
            document.getElementById('hiddenRoleInput').value = role;
            const studentBtn = document.getElementById('roleBtnStudent');
            const teacherBtn = document.getElementById('roleBtnTeacher');
            const reasonField = document.getElementById('reasonField');
            const reasonInput = document.getElementById('reasonInput');

            if (role === 'teacher') {
                teacherBtn.className = "py-2.5 rounded-xl border text-xs font-bold transition duration-200 bg-purple-900/40 border-purple-500 text-purple-400";
                studentBtn.className = "py-2.5 rounded-xl border text-xs font-bold transition duration-200 border-slate-700 text-slate-400";
                reasonField.classList.remove('hidden');
                reasonInput.required = true;
            } else {
                studentBtn.className = "py-2.5 rounded-xl border text-xs font-bold transition duration-200 bg-cyan-900/40 border-cyan-500 text-cyan-400";
                teacherBtn.className = "py-2.5 rounded-xl border text-xs font-bold transition duration-200 border-slate-700 text-slate-400";
                reasonField.classList.add('hidden');
                reasonInput.required = false;
            }
        }
    </script>

    <?php $chatbotRole = 'guest'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>