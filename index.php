<?php
session_start();
$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 1. DATABASE INIT
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, student_id TEXT UNIQUE, contact TEXT, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, title TEXT, description TEXT, item_type TEXT, x_pos REAL, y_pos REAL, floor TEXT, contact TEXT, otp_code TEXT, status TEXT DEFAULT 'active')");

$auth_message = "";
$auth_type = ""; 

// 2. SIGNUP LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['signup_submit'])) {
    try {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, student_id, contact, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['student_id'], $_POST['contact'], $hash]);
        $auth_message = "Account created! You can now sign in.";
        $auth_type = "success";
        echo "<script>window.onload = function() { showPage('signin'); }</script>";
    } catch(PDOException $e) {
        $auth_message = "Student ID already exists.";
        $auth_type = "error";
        echo "<script>window.onload = function() { showPage('signup'); }</script>";
    }
}

// 3. LOGIN LOGIC
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_submit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE student_id = ?");
    $stmt->execute([$_POST['student_id']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        header("Location: index.php"); 
        exit();
    } else {
        $auth_message = "Invalid Student ID or Password.";
        $auth_type = "error";
        echo "<script>window.onload = function() { showPage('signin'); }</script>";
    }
}

if (isset($_GET['logout'])) { 
    session_destroy(); 
    header("Location: index.php"); 
    exit(); 
}

// 4. ITEM LOGIC
if (isset($_POST['save_item']) && isset($_SESSION['user_id'])) {
    $otp = rand(1000, 9999);
    $stmt = $db->prepare("INSERT INTO items (user_id, title, description, item_type, x_pos, y_pos, floor, contact, otp_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['title'], $_POST['description'], $_POST['item_type'], $_POST['x_pos'], $_POST['y_pos'], $_POST['floor'], $_POST['contact'], $otp]);
    
    header("Location: index.php?floor=" . urlencode($_POST['floor']));
    exit();
}

// 5. OTP VERIFY
if (isset($_POST['verify_otp'])) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND otp_code = ?");
    $stmt->execute([$_POST['item_id'], $_POST['entered_otp']]);
    if ($stmt->fetch()) {
        $db->prepare("UPDATE items SET status = 'resolved' WHERE id = ?")->execute([$_POST['item_id']]);
        header("Location: index.php?floor=" . urlencode($_GET['floor'] ?? 'floor1'));
        exit();
    } else {
        echo "<script>alert('Invalid OTP Code!');</script>";
    }
}

// Fetch Items
$current_floor = $_GET['floor'] ?? 'floor1';
$allowed_floors = ['floor1', 'floor2', 'floor3'];
if (!in_array($current_floor, $allowed_floors)) $current_floor = 'floor1';

$items_stmt = $db->prepare("SELECT items.*, users.name as owner_name FROM items JOIN users ON items.user_id = users.id WHERE floor = ? AND status = 'active'");
$items_stmt->execute([$current_floor]);
$display_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceIt • Find What Matters</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap');
        
        body { font-family: 'Inter', sans-serif; }
        .logo-font { font-family: 'Space Grotesk', sans-serif; }
        
        /* Backgrounds & Animations */
        .hero-bg { background: radial-gradient(circle at top left, #0ea5e9, #0284c8, #0f172a 80%); }
        
        /* Glassmorphism Panel */
        .glass-panel {
            background: rgba(24, 24, 27, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* Entrance Animations */
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-item { opacity: 0; animation: slideUpFade 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards; }
        .delay-100 { animation-delay: 100ms; }
        .delay-200 { animation-delay: 200ms; }
        .delay-300 { animation-delay: 300ms; }

        /* Floating Input Styles & Fix */
        .floating-input::placeholder {
            color: transparent;
        }

        .floating-input:focus ~ .floating-label,
        .floating-input:not(:placeholder-shown) ~ .floating-label {
            top: 0.5rem;
            font-size: 0.75rem;
            color: #38bdf8; /* Sky Blue by default */
        }

        .floating-input.focus-emerald:focus ~ .floating-label,
        .floating-input.focus-emerald:not(:placeholder-shown) ~ .floating-label {
            color: #10b981; /* Emerald Green for Sign Up */
        }
        
        /* Map Specific Styles */
        .pin { position: absolute; width: 20px; height: 20px; border-radius: 50%; border: 2px solid white; transform: translate(-50%, -50%); cursor: pointer; z-index: 10; transition: transform 0.2s; }
        .pin:hover { transform: translate(-50%, -50%) scale(1.3); z-index: 20; }
        .pin-lost { background: #ef4444; box-shadow: 0 0 10px #ef4444; }
        .pin-found { background: #10b981; box-shadow: 0 0 10px #10b981; }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); backdrop-filter:blur(8px); z-index:50; align-items:center; justify-content:center; }
    </style>
</head>
<body class="bg-zinc-950 text-white min-h-screen overflow-x-hidden">

    <div id="landing" class="<?php echo isset($_SESSION['user_id']) ? 'hidden' : ''; ?> hero-bg min-h-screen flex items-center justify-center relative">
        <div class="max-w-6xl mx-auto px-6 text-center z-10 animate-item">
            <h1 class="logo-font text-7xl font-bold mb-4">TraceIt</h1>
            <p class="text-2xl text-white/80 mb-10">Never lose anything again</p>
            <div class="flex gap-4 justify-center">
                <button onclick="showPage('signin')" class="bg-sky-500 hover:bg-sky-400 text-white px-8 py-4 rounded-full font-bold transition-all shadow-lg shadow-sky-500/30">Sign In</button>
                <button onclick="showPage('signup')" class="bg-white/10 hover:bg-white/20 border border-white/20 text-white px-8 py-4 rounded-full font-bold transition-all backdrop-blur-md">Create Account</button>
            </div>
        </div>
    </div>

    <div id="signin" class="hidden min-h-screen hero-bg flex items-center justify-center p-6 relative">
        <button onclick="showPage('landing')" class="absolute top-8 left-8 text-white/50 hover:text-white transition-colors flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>

        <div class="glass-panel rounded-[2rem] w-full max-w-md p-10 relative overflow-hidden">
            <div class="text-center mb-8 animate-item">
                <div class="w-16 h-16 bg-sky-500/20 text-sky-400 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-sky-500/30">
                    <i class="fa-solid fa-right-to-bracket text-2xl"></i>
                </div>
                <h2 class="text-3xl font-bold text-white">Welcome Back</h2>
                <p class="text-zinc-400 mt-2">Sign in to your TraceIt account</p>
            </div>

            <?php if ($auth_message && isset($_POST['login_submit'])): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 animate-item <?php echo $auth_type == 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'; ?>">
                    <i class="fa-solid <?php echo $auth_type == 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                    <?php echo $auth_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" onsubmit="showLoading(this, 'login_btn')">
                <div class="relative animate-item delay-100">
                    <input type="text" name="student_id" id="login_id" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl px-5 pt-7 pb-3 text-white outline-none transition-all" placeholder="Student ID">
                    <label for="login_id" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all cursor-text pointer-events-none">Student ID</label>
                </div>

                <div class="relative animate-item delay-200">
                    <input type="password" name="password" id="login_pwd" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl pl-5 pr-12 pt-7 pb-3 text-white outline-none transition-all" placeholder="Password">
                    <label for="login_pwd" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all cursor-text pointer-events-none">Password</label>
                    <button type="button" onclick="togglePassword('login_pwd', 'eye_login')" class="absolute right-4 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white transition-colors">
                        <i id="eye_login" class="fa-regular fa-eye"></i>
                    </button>
                </div>

                <button type="submit" name="login_submit" id="login_btn" class="w-full bg-sky-500 hover:bg-sky-400 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-sky-500/20 animate-item delay-300 flex items-center justify-center gap-2 group">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>
            <p class="mt-8 text-center text-zinc-400 animate-item delay-300">
                New to TraceIt? <span onclick="showPage('signup')" class="text-sky-400 cursor-pointer hover:underline">Create account</span>
            </p>
        </div>
    </div>

    <div id="signup" class="hidden min-h-screen hero-bg flex items-center justify-center p-6 relative">
        <button onclick="showPage('landing')" class="absolute top-8 left-8 text-white/50 hover:text-white transition-colors flex items-center gap-2">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>

        <div class="glass-panel rounded-[2rem] w-full max-w-md p-10 relative overflow-hidden my-8">
            <div class="text-center mb-8 animate-item">
                <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-emerald-500/30">
                    <i class="fa-solid fa-user-plus text-2xl"></i>
                </div>
                <h2 class="text-3xl font-bold text-white">Join TraceIt</h2>
            </div>

            <?php if ($auth_message && isset($_POST['signup_submit'])): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 animate-item <?php echo $auth_type == 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'; ?>">
                    <i class="fa-solid <?php echo $auth_type == 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                    <?php echo $auth_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4" onsubmit="showLoading(this, 'signup_btn')">
                <div class="relative animate-item delay-100">
                    <input type="text" name="name" id="reg_name" required class="floating-input focus-emerald w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 pt-7 pb-3 text-white outline-none transition-all" placeholder="Full Name">
                    <label for="reg_name" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all pointer-events-none">Full Name</label>
                </div>
                <div class="relative animate-item delay-100">
                    <input type="text" name="student_id" id="reg_id" required class="floating-input focus-emerald w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 pt-7 pb-3 text-white outline-none transition-all" placeholder="Student ID">
                    <label for="reg_id" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all pointer-events-none">Student ID</label>
                </div>
                <div class="relative animate-item delay-200">
                    <input type="text" name="contact" id="reg_phone" required class="floating-input focus-emerald w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 pt-7 pb-3 text-white outline-none transition-all" placeholder="Contact">
                    <label for="reg_phone" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all pointer-events-none">Contact Number</label>
                </div>
                <div class="relative animate-item delay-200">
                    <input type="password" name="password" id="reg_pwd" required class="floating-input focus-emerald w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl pl-5 pr-12 pt-7 pb-3 text-white outline-none transition-all" placeholder="Password">
                    <label for="reg_pwd" class="floating-label absolute left-5 top-5 text-zinc-500 text-base transition-all pointer-events-none">Password</label>
                    <button type="button" onclick="togglePassword('reg_pwd', 'eye_reg')" class="absolute right-4 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white transition-colors">
                        <i id="eye_reg" class="fa-regular fa-eye"></i>
                    </button>
                </div>
                <button type="submit" name="signup_submit" id="signup_btn" class="w-full bg-emerald-500 hover:bg-emerald-400 text-white font-bold py-4 rounded-xl transition-all shadow-lg shadow-emerald-500/20 animate-item delay-300 mt-2">
                    Create Account
                </button>
            </form>
            <p class="mt-8 text-center text-zinc-400 animate-item delay-300">
                Already registered? <span onclick="showPage('signin')" class="text-emerald-400 cursor-pointer hover:underline">Sign in</span>
            </p>
        </div>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
    <div id="dashboard" class="min-h-screen p-8 max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-10">
            <h1 class="logo-font text-4xl font-bold text-sky-400">TraceIt Dashboard</h1>
            <div class="flex items-center gap-4">
                <span class="text-zinc-400">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                <a href="?logout=1" class="bg-red-500/10 text-red-500 border border-red-500/20 px-4 py-2 rounded-xl hover:bg-red-500/20 transition-colors">Logout</a>
            </div>
        </div>

        <div class="flex gap-4 mb-8">
            <a href="?floor=floor1" class="px-8 py-3 rounded-2xl transition-colors <?php echo $current_floor=='floor1'?'bg-sky-500 text-white':'bg-zinc-900 border border-zinc-800 text-zinc-400 hover:text-white'; ?>">Floor 1</a>
            <a href="?floor=floor2" class="px-8 py-3 rounded-2xl transition-colors <?php echo $current_floor=='floor2'?'bg-sky-500 text-white':'bg-zinc-900 border border-zinc-800 text-zinc-400 hover:text-white'; ?>">Floor 2</a>
        </div>

        <div class="relative inline-block border-8 border-zinc-900 rounded-[2rem] overflow-hidden shadow-2xl bg-zinc-800">
            <img src="<?php echo htmlspecialchars($current_floor); ?>.jpg" id="mainMap" class="w-full h-auto cursor-crosshair" alt="Floor Map">
            
            <?php foreach ($display_items as $item): ?>
                <div class="pin <?php echo $item['item_type']=='lost'?'pin-lost':'pin-found'; ?>" 
                     style="left:<?php echo $item['x_pos']; ?>%; top:<?php echo $item['y_pos']; ?>%;"
                     onclick='openDetailModal(<?php echo json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div id="detailModal" class="modal-overlay">
        <div class="bg-zinc-900 p-10 rounded-[2.5rem] w-full max-w-md border border-zinc-800 shadow-2xl">
            <h2 id="dt-title" class="text-3xl font-bold mb-2 text-white"></h2>
            <p id="dt-desc" class="text-zinc-400 mb-6"></p>
            
            <div class="bg-zinc-800/50 p-5 rounded-2xl mb-6 border border-zinc-700">
                <p class="text-zinc-300"><strong>Owner:</strong> <span id="dt-owner" class="text-white"></span></p>
                <p class="text-zinc-300 mt-2"><strong>Contact:</strong> <span id="dt-contact" class="text-white"></span></p>
            </div>

            <form method="POST" id="otpForm" class="space-y-4">
                <input type="hidden" name="item_id" id="dt-id">
                <input type="hidden" name="floor" value="<?php echo htmlspecialchars($current_floor); ?>">
                <input type="text" name="entered_otp" placeholder="Enter 4-Digit OTP from owner" class="w-full bg-zinc-800 border border-zinc-700 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-2xl p-4 text-white outline-none transition-all" required>
                <button type="submit" name="verify_otp" class="w-full bg-sky-500 hover:bg-sky-400 text-white py-4 rounded-2xl font-bold text-lg transition-colors shadow-lg shadow-sky-500/20">Confirm Handshake</button>
            </form>

            <div id="otpDisplay" class="hidden text-center p-6 bg-sky-500/10 border border-sky-500/30 rounded-2xl">
                <p class="text-sky-400 text-sm font-medium">Your Secret OTP for Exchange:</p>
                <p id="dt-otp" class="text-5xl font-mono font-bold mt-2 text-white tracking-widest"></p>
            </div>
            
            <button onclick="closeModals()" class="w-full mt-6 text-zinc-500 hover:text-white transition-colors">Close</button>
        </div>
    </div>

    <div id="reportModal" class="modal-overlay">
        <div class="bg-zinc-900 p-10 rounded-[2.5rem] w-full max-w-md border border-zinc-800 shadow-2xl">
            <h2 class="text-3xl font-bold mb-6 text-white">New Report</h2>
            <form method="POST" class="space-y-4" onsubmit="showLoading(this, 'report_btn')">
                <input type="hidden" name="x_pos" id="rx">
                <input type="hidden" name="y_pos" id="ry">
                <input type="hidden" name="floor" value="<?php echo htmlspecialchars($current_floor); ?>">
                
                <input type="text" name="title" placeholder="What is the item?" class="w-full bg-zinc-800 border border-zinc-700 focus:border-emerald-500 rounded-2xl p-4 text-white outline-none transition-all" required>
                <textarea name="description" placeholder="Short description (color, brand)" class="w-full bg-zinc-800 border border-zinc-700 focus:border-emerald-500 rounded-2xl p-4 text-white outline-none transition-all resize-none h-24"></textarea>
                
                <select name="item_type" class="w-full bg-zinc-800 border border-zinc-700 focus:border-emerald-500 rounded-2xl p-4 text-white outline-none transition-all appearance-none cursor-pointer">
                    <option value="lost">I Lost This Item</option>
                    <option value="found">I Found This Item</option>
                </select>
                
                <input type="text" name="contact" placeholder="Your Phone/WhatsApp" class="w-full bg-zinc-800 border border-zinc-700 focus:border-emerald-500 rounded-2xl p-4 text-white outline-none transition-all" required>
                
                <button type="submit" name="save_item" id="report_btn" class="w-full bg-emerald-500 hover:bg-emerald-400 text-white py-4 rounded-2xl font-bold text-lg transition-colors shadow-lg shadow-emerald-500/20 mt-2">Pin to Map</button>
            </form>
            <button onclick="closeModals()" class="w-full mt-4 text-zinc-500 hover:text-white transition-colors">Cancel</button>
        </div>
    </div>

    <script>
        // 1. UI Interactions
        function showPage(page) {
            ['landing','signin','signup'].forEach(id => {
                const el = document.getElementById(id);
                if(el) el.classList.toggle('hidden', id !== page);
            });
            const currentSection = document.getElementById(page);
            if(currentSection) {
                const items = currentSection.querySelectorAll('.animate-item');
                items.forEach(item => {
                    item.style.animation = 'none';
                    item.offsetHeight; 
                    item.style.animation = null; 
                });
            }
        }

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function showLoading(form, buttonId) {
            const btn = document.getElementById(buttonId);
            btn.innerHTML = `<i class="fa-solid fa-circle-notch fa-spin"></i> Processing...`;
            btn.classList.add('opacity-80', 'cursor-not-allowed');
        }

        // 2. Map Interaction & Boundary Logic
        const map = document.getElementById('mainMap');
        if(map) {
            map.onclick = (e) => {
                if(e.target.classList.contains('pin')) return; 

                const rect = map.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;

                // --- 🛑 BOUNDARY BOX 🛑 ---
                const minX = 10; // Left edge of building
                const maxX = 90; // Right edge of building
                const minY = 10; // Top edge of building
                const maxY = 90; // Bottom edge of building

                if (x < minX || x > maxX || y < minY || y > maxY) {
                    alert("⚠️ You can only drop pins inside the designated building area.");
                    return; 
                }

                document.getElementById('rx').value = x;
                document.getElementById('ry').value = y;
                document.getElementById('reportModal').style.display = 'flex';
            };
        }

        // 3. Modal Management
        function openDetailModal(item) {
            const userId = <?php echo $_SESSION['user_id'] ?? '0'; ?>;
            
            document.getElementById('dt-id').value = item.id;
            document.getElementById('dt-title').innerText = item.title;
            document.getElementById('dt-desc').innerText = item.description;
            document.getElementById('dt-owner').innerText = item.owner_name;
            document.getElementById('dt-contact').innerText = item.contact;

            if(item.user_id == userId) {
                document.getElementById('otpForm').classList.add('hidden');
                document.getElementById('otpDisplay').classList.remove('hidden');
                document.getElementById('dt-otp').innerText = item.otp_code;
            } else {
                document.getElementById('otpForm').classList.remove('hidden');
                document.getElementById('otpDisplay').classList.add('hidden');
            }
            
            document.getElementById('detailModal').style.display = 'flex';
        }

        function closeModals() {
            document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
        }
    </script>
</body>
</html>