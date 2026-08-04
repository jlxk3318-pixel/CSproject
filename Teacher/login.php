<?php
// 开启 Session 用来记录登录状态
session_start();

$error_message = "";

// 数据库连接配置
$db_host = "localhost";      
$db_user = "root";           
$db_pass = "";               
$db_name = "triplet_edu";    

// 检查是否有关联的 POST 提交
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    
    // 创建数据库连接
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($conn->connect_error) {
        $error_message = "连接本地数据库失败: " . $conn->connect_error;
    } else {
        // 💡 核心改变：只用 Email 去数据库找这个人，完全不再依赖前端传什么 role
        $stmt = $conn->prepare("SELECT id, email, password, role FROM users WHERE email = ?");
        
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            // 检查系统里是否有这个邮箱
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // 验证密码
                if ($password === $user['password']) {
                    // 密码正确，将数据库里该用户的真实身份（role）和邮箱写入 Session
                    $_SESSION['user'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    $stmt->close();
                    $conn->close();
                    
                    // 🚀 纯后端智能分流跳转
                    if ($user['role'] === 'admin') {
                        header("Location: admin.php");
                    } else if ($user['role'] === 'teacher') {
                        header("Location: index.php");
                    } else if ($user['role'] === 'student') {
                        header("Location: game.php");
                    }
                    exit();
                } else {
                    $error_message = "Password incorrect! Please verify your inputs.";
                }
            } else {
                $error_message = "Account not found in the system!";
            }
            $stmt->close();
        } else {
            $error_message = "SQL statement execution failed.";
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
    <title>TripleT Edu - Monopoly Quiz Arena Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-[#BCD2E8] min-h-screen flex items-center justify-center antialiased">

    <div class="w-full min-h-screen lg:min-h-[800px] lg:max-w-[1440px] lg:mx-auto lg:shadow-2xl lg:rounded-3xl overflow-hidden flex flex-col lg:flex-row bg-[#F4F7F6]">
        
        <div class="hidden lg:flex lg:w-1/2 bg-[#1E3F66] p-16 flex-col justify-between relative overflow-hidden select-none">
            <div class="absolute inset-0 opacity-10 grid grid-cols-4 gap-4 p-8 pointer-events-none transform -rotate-12 scale-110">
                <?php for($i=0; $i<16; $i++): ?>
                    <div class="bg-white rounded-2xl aspect-video border-2 border-white/40"></div>
                <?php endfor; ?>
            </div>

            <div class="flex items-center space-x-3 z-10">
                <div class="bg-white text-[#1E3F66] font-bold w-10 h-10 flex items-center justify-center rounded-lg text-lg shadow-md">
                    TT
                </div>
                <h1 class="font-bold text-white text-lg tracking-wide">TripleT Edu</h1>
            </div>

            <div class="z-10 mt-auto">
                <div class="relative mb-6">
                    <h2 class="text-6xl font-extrabold text-white leading-none tracking-tight">
                        Monopoly Quiz<br>Arena
                    </h2>
                    <div class="flex items-center space-x-6 mt-6 opacity-30">
                        <div class="w-4 h-4 rounded-full bg-white"></div>
                        <div class="h-0.5 flex-1 bg-white"></div>
                        <div class="w-8 h-8 rounded-full bg-white border-4 border-[#1E3F66]"></div>
                        <div class="h-0.5 w-12 bg-white"></div>
                    </div>
                </div>
                <p class="text-sm text-[#BCD2E8] font-medium leading-relaxed max-w-md">
                    A classroom educational game that combines Kahoot-style questions with a Monopoly board, dice movement, QR access, student layers, streak rewards, ranking, and a 50-point winning target.
                </p>
            </div>
        </div>

        <div class="flex-1 bg-[#F4F7F6] flex items-center justify-center p-6 md:p-12 min-h-screen lg:min-h-0">
            
            <div class="absolute top-6 left-6 flex items-center space-x-2 lg:hidden">
                <div class="bg-[#1E3F66] text-white font-bold w-8 h-8 flex items-center justify-center rounded-lg text-sm">
                    TT
                </div>
                <span class="font-bold text-[#1E3F66] text-sm">TripleT Edu</span>
            </div>

            <div class="w-full max-w-[420px] bg-white p-8 md:p-10 rounded-2xl shadow-xl border border-[#91BAD6]/20">
                
                <span class="text-[10px] uppercase tracking-widest font-extrabold text-[#528AAE] block mb-2">Smart Database Login</span>
                <h3 class="text-2xl md:text-3xl font-bold text-[#1E3F66] mb-3">Welcome back</h3>
                <p class="text-xs md:text-sm text-[#528AAE]/80 font-medium leading-relaxed mb-6">
                    Enter your organization email. The system will automatically direct you to your dedicated arena panel.
                </p>

                <?php if (!empty($error_message)): ?>
                    <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-xl text-xs font-semibold mb-4 flex items-center space-x-2">
                        <span>⚠️</span>
                        <span><?php echo $error_message; ?></span>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="space-y-5">
                    
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label for="email" class="block text-xs font-bold text-[#1E3F66]">Email Address</label>
                            <span class="text-[10px] text-[#528AAE]/80 font-semibold bg-[#BCD2E8]/30 px-2 py-0.5 rounded">@tripletedu.com</span>
                        </div>
                        <input type="email" id="email" name="email" 
                               placeholder="username@tripletedu.com"
                               value="" required autocomplete="off"
                               class="w-full px-4 py-3 bg-white border border-[#91BAD6]/30 rounded-xl text-sm font-medium text-[#1E3F66] placeholder-gray-400 focus:outline-none focus:border-[#2E5984] focus:ring-2 focus:ring-[#2E5984]/20 transition shadow-inner">
                    </div>

                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <label for="password" class="block text-xs font-bold text-[#1E3F66]">Password</label>
                            <span class="text-[10px] text-[#528AAE]/80 font-semibold bg-[#BCD2E8]/30 px-2 py-0.5 rounded">At least 8 digits</span>
                        </div>
                        <div class="relative">
                            <input type="password" id="password" name="password" 
                                   placeholder="12345678"
                                   value="" required autocomplete="new-password"
                                   class="w-full pl-4 pr-12 py-3 bg-white border border-[#91BAD6]/30 rounded-xl text-sm font-medium text-[#1E3F66] placeholder-gray-400 focus:outline-none focus:border-[#2E5984] focus:ring-2 focus:ring-[#2E5984]/20 transition shadow-inner">
                            
                            <button type="button" id="togglePassword" class="absolute inset-y-0 right-0 pr-4 flex items-center text-[#528AAE]/70 hover:text-[#1E3F66] transition focus:outline-none">
                                <svg id="eyeClosed" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18" />
                                </svg>
                                <svg id="eyeOpen" class="h-5 w-5 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full py-3.5 bg-[#1E3F66] text-white font-bold text-sm rounded-xl shadow-md hover:bg-[#2E5984] hover:shadow-lg transition-all duration-200 transform active:scale-[0.98]">
                            Enter Dashboard
                        </button>
                    </div>
                </form>

            </div>
        </div>

    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // 💡 已移除：以前切换 Teacher/Student 按钮更改 placeholder 的繁琐 JS
            const passwordInput = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const eyeClosedIcon = document.getElementById('eyeClosed');
            const eyeOpenIcon = document.getElementById('eyeOpen');

            togglePasswordBtn.addEventListener('click', function() {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeClosedIcon.classList.add('hidden');
                    eyeOpenIcon.classList.remove('hidden');
                } else {
                    passwordInput.type = 'password';
                    eyeOpenIcon.classList.add('hidden');
                    eyeClosedIcon.classList.remove('hidden');
                }
            });
        });
    </script>

    <?php $chatbotRole = 'guest'; include __DIR__ . '/../Shared/chatbot-widget.php'; ?>

</body>
</html>