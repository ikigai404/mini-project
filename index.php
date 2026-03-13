<?php
session_start();

try {
    $db = new PDO('sqlite:database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables if they don't exist (this won't change existing tables)
    $db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, password TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, claimer_id INTEGER DEFAULT NULL, title TEXT, description TEXT, item_type TEXT, x_pos REAL, y_pos REAL, floor TEXT, contact TEXT, otp_code TEXT, status TEXT DEFAULT 'active')");

} catch (PDOException $e) {
    die("Database connection failed: " . htmlspecialchars($e->getMessage()));
}

// --- AUTH LOGIC ---
$auth_msg = '';
if (isset($_POST['signup'])) {
    $hash = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
    try {
        $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['name'] ?? '', $_POST['email'] ?? '', $hash]);
        $auth_msg = "Account created! Please sign in.";
    } catch (Exception $e) {
        $auth_msg = "Error: Email may already exist or invalid data.";
    }
}

if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_POST['email'] ?? '']);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
    } else {
        $auth_msg = "Invalid email or password.";
    }
}

if (isset($_GET['logout'])) { 
    session_destroy(); 
    header("Location: index.php"); 
    exit;
}

// --- ITEM POSTING LOGIC ---
$msg = $error = '';
if (isset($_POST['save_item']) && isset($_SESSION['user_id'])) {
    $otp = rand(1000, 9999);
    try {
        $stmt = $db->prepare("
            INSERT INTO items (user_id, title, description, item_type, x_pos, y_pos, floor, contact, otp_code) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $_POST['title'] ?? '',
            $_POST['description'] ?? '',
            $_POST['item_type'] ?? 'lost',
            $_POST['x_pos'] ?? 50,
            $_POST['y_pos'] ?? 50,
            $_POST['floor'] ?? 'floor1',
            $_POST['contact'] ?? '',
            $otp
        ]);
        $msg = "Pin posted successfully! Your secret OTP (share only with finder): <strong>$otp</strong>";
    } catch (Exception $e) {
        $error = "Failed to post item.";
    }
}

// --- OTP VERIFICATION (delete item instead of updating status) ---
if (isset($_POST['verify_otp'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND otp_code = ?");
        $stmt->execute([$_POST['item_id'] ?? 0, $_POST['entered_otp'] ?? '']);
        if ($stmt->fetch()) {
            $db->prepare("DELETE FROM items WHERE id = ?")->execute([$_POST['item_id']]);
            $msg = "Exchange confirmed! Item removed from map.";
        } else {
            $error = "Invalid OTP code!";
        }
    } catch (Exception $e) {
        $error = "Verification failed.";
    }
}

// --- Fetch items (no JOIN, no status filter) ---
$current_floor = in_array($_GET['floor'] ?? 'floor1', ['floor1', 'floor2']) ? $_GET['floor'] : 'floor1';

try {
    $items_stmt = $db->prepare("SELECT * FROM items WHERE floor = ?");
    $items_stmt->execute([$current_floor]);
    $display_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $display_items = [];
    $error = "Could not load items.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraceIt • Find What Matters</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Space+Grotesk:wght@500;600&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #0ea5e9; }
        body { font-family: 'Inter', sans-serif; }
        .logo-font { font-family: 'Space Grotesk', sans-serif; }

        .hero-bg {
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c8 100%);
            position: relative;
            overflow: hidden;
        }
        
        .hero-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 30%, rgba(255,255,255,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 70%, rgba(255,255,255,0.12) 0%, transparent 50%);
            animation: float 28s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-35px) rotate(1.5deg); }
        }

        .map-pin {
            position: absolute;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            transform: translate(-50%, -50%);
            cursor: pointer;
            border: 4px solid white;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
            animation: ping 4s ease-in-out infinite;
        }

        .pin-lost { background: #ef4444; }
        .pin-found { background: #10b981; }

        @keyframes ping {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 1; }
            50% { transform: translate(-50%, -50%) scale(1.25); opacity: 0.7; }
        }

        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.8);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal-content {
            background: #1f2937;
            border-radius: 1.5rem;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
        }

        .input-focus:focus {
            box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.3);
            border-color: #0ea5e9;
        }
    </style>
</head>
<body class="bg-zinc-950 text-white min-h-screen">

    <!-- LANDING / HERO -->
    <div id="landing" class="hero-bg min-h-screen flex items-center justify-center relative <?php echo isset($_SESSION['user_id']) ? 'hidden' : ''; ?>">
        <div class="max-w-6xl mx-auto px-6 text-center z-10">
            <div class="flex items-center justify-center gap-3 mb-8">
                <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-2xl map-pin">
                    <i class="fa-solid fa-map-pin text-4xl text-sky-600"></i>
                </div>
                <h1 class="logo-font text-7xl font-semibold tracking-tighter text-white">TraceIt</h1>
            </div>
            <p class="text-2xl md:text-3xl font-medium text-white/90 mb-4 tracking-tight">
                Never lose anything again
            </p>
            <p class="max-w-lg mx-auto text-xl text-white/70 mb-12">
                Pin lost or found devices on every floor of your department.<br>
                Real-time. Student-powered. Instant reunions.
            </p>
            <div class="flex flex-col sm:flex-row gap-6 justify-center items-center">
                <button onclick="showPage('signin')" 
                        class="group bg-white text-zinc-950 hover:bg-zinc-100 transition-all duration-300 px-10 py-6 rounded-3xl font-semibold text-2xl flex items-center gap-4 shadow-2xl shadow-sky-500/30">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                </button>
                <button onclick="showPage('signup')" 
                        class="group border-4 border-white/90 hover:bg-white/10 hover:border-white transition-all duration-300 px-10 py-6 rounded-3xl font-semibold text-2xl flex items-center gap-4">
                    <span class="text-white">Create Account</span>
                    <i class="fa-solid fa-user-plus text-white"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- SIGN IN -->
    <div id="signin" class="hidden min-h-screen bg-zinc-950 flex items-center justify-center p-6">
        <div class="bg-zinc-900 rounded-3xl shadow-2xl max-w-md w-full relative">
            <button onclick="showPage('landing')" class="absolute top-6 left-6 flex items-center gap-2 text-white/80 hover:text-white px-4 py-2 rounded-xl bg-white/5">
                <i class="fa-solid fa-arrow-left"></i><span>Back</span>
            </button>
            <div class="bg-gradient-to-r from-sky-600 to-cyan-500 p-8 text-center pt-20">
                <h2 class="text-4xl font-semibold text-white">Welcome Back</h2>
                <p class="text-white/70 mt-2">Sign in to start locating</p>
            </div>
            <div class="p-8 pt-10">
                <?php if ($auth_msg): ?>
                    <p class="text-center text-amber-300 mb-6"><?php echo htmlspecialchars($auth_msg); ?></p>
                <?php endif; ?>
                <form method="POST" class="space-y-6">
                    <input type="email" name="email" required placeholder="Email" class="input-focus w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg outline-none text-white placeholder-zinc-500">
                    <input type="password" name="password" required placeholder="Password" class="input-focus w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg outline-none text-white placeholder-zinc-500">
                    <button name="login" class="w-full bg-white text-zinc-950 hover:bg-sky-200 py-5 rounded-3xl text-xl font-semibold">Sign In</button>
                </form>
                <p class="mt-6 text-center text-zinc-400">
                    No account? <span onclick="showPage('signup')" class="text-sky-400 cursor-pointer">Sign up</span>
                </p>
            </div>
        </div>
    </div>

    <!-- SIGN UP -->
    <div id="signup" class="hidden min-h-screen bg-zinc-950 flex items-center justify-center p-6">
        <div class="bg-zinc-900 rounded-3xl shadow-2xl max-w-md w-full relative">
            <button onclick="showPage('landing')" class="absolute top-6 left-6 flex items-center gap-2 text-white/80 hover:text-white px-4 py-2 rounded-xl bg-white/5">
                <i class="fa-solid fa-arrow-left"></i><span>Back</span>
            </button>
            <div class="bg-gradient-to-r from-emerald-500 to-teal-500 p-8 text-center pt-20">
                <h2 class="text-4xl font-semibold text-white">Join TraceIt</h2>
                <p class="text-white/70 mt-2">One account. Every floor.</p>
            </div>
            <div class="p-8 pt-10">
                <?php if ($auth_msg): ?>
                    <p class="text-center text-amber-300 mb-6"><?php echo htmlspecialchars($auth_msg); ?></p>
                <?php endif; ?>
                <form method="POST" class="space-y-6">
                    <input type="text" name="name" placeholder="Full Name" required class="input-focus w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg outline-none text-white placeholder-zinc-500">
                    <input type="email" name="email" placeholder="Email" required class="input-focus w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg outline-none text-white placeholder-zinc-500">
                    <input type="password" name="password" placeholder="Password" required class="input-focus w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg outline-none text-white placeholder-zinc-500">
                    <button name="signup" class="w-full bg-white text-zinc-950 hover:bg-emerald-200 py-5 rounded-3xl text-xl font-semibold">Create Account</button>
                </form>
                <p class="mt-6 text-center text-zinc-400">
                    Have an account? <span onclick="showPage('signin')" class="text-emerald-400 cursor-pointer">Sign in</span>
                </p>
            </div>
        </div>
    </div>

    <!-- MAIN APP -->
    <div id="main-app" class="<?php echo isset($_SESSION['user_id']) ? '' : 'hidden'; ?> bg-zinc-950 min-h-screen">
        <header class="bg-zinc-900 border-b border-zinc-800 py-4 px-6 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <i class="fa-solid fa-map-pin text-3xl text-sky-500"></i>
                <span class="logo-font text-3xl font-semibold">TraceIt</span>
            </div>
            <div class="flex items-center gap-6">
                <span>Welcome, <strong><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Guest'); ?></strong></span>
                <a href="?logout=1" class="text-red-400 hover:text-red-300">Logout</a>
            </div>
        </header>

        <main class="p-6 max-w-7xl mx-auto">
            <?php if ($msg): ?>
                <div class="bg-green-900/40 border border-green-700 text-green-300 p-4 rounded-2xl mb-6">
                    <?php echo $msg; ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="bg-red-900/40 border border-red-700 text-red-300 p-4 rounded-2xl mb-6">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <div class="flex justify-center gap-6 mb-8">
                <a href="?floor=floor1" class="px-6 py-3 rounded-full <?php echo $current_floor === 'floor1' ? 'bg-sky-600' : 'bg-zinc-800 hover:bg-zinc-700'; ?>">Floor 1</a>
                <a href="?floor=floor2" class="px-6 py-3 rounded-full <?php echo $current_floor === 'floor2' ? 'bg-sky-600' : 'bg-zinc-800 hover:bg-zinc-700'; ?>">Floor 2</a>
            </div>

            <div class="relative rounded-3xl overflow-hidden border-4 border-zinc-800 shadow-2xl">
                <img src="<?php echo htmlspecialchars($current_floor); ?>.jpg" id="map-image" class="w-full h-auto" alt="Floor Map">

                <?php foreach ($display_items as $i): ?>
                    <div class="map-pin <?php echo ($i['item_type'] ?? 'lost') === 'lost' ? 'pin-lost' : 'pin-found'; ?>" 
                         style="left:<?php echo ($i['x_pos'] ?? 50); ?>%; top:<?php echo ($i['y_pos'] ?? 50); ?>%;"
                         onclick='showDetails(<?php echo json_encode($i, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="report-form" class="hidden mt-10 bg-zinc-900 rounded-3xl p-8 border border-zinc-800">
                <h3 class="text-2xl font-semibold mb-6">Report New Item</h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="x_pos" id="fx">
                    <input type="hidden" name="y_pos" id="fy">
                    <input type="hidden" name="floor" value="<?php echo htmlspecialchars($current_floor); ?>">
                    <input type="text" name="title" placeholder="Item Title (e.g. AirPods Pro)" required class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg text-white placeholder-zinc-500">
                    <textarea name="description" placeholder="Description..." rows="4" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg text-white placeholder-zinc-500"></textarea>
                    <select name="item_type" class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg text-white">
                        <option value="lost">I Lost This</option>
                        <option value="found">I Found This</option>
                    </select>
                    <input type="text" name="contact" placeholder="Your Contact (phone/email)" required class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg text-white placeholder-zinc-500">
                    <button name="save_item" class="w-full bg-sky-600 hover:bg-sky-500 py-5 rounded-3xl text-xl font-semibold">Post Pin</button>
                </form>
            </div>
        </main>
    </div>

    <!-- OTP MODAL (no owner name anymore) -->
    <div id="otp-modal" class="modal">
        <div class="modal-content">
            <button onclick="document.getElementById('otp-modal').style.display='none'" class="float-right text-3xl text-zinc-400 hover:text-white">×</button>
            <h3 id="m-title" class="text-2xl font-bold mb-4"></h3>
            <p id="m-desc" class="text-zinc-300 mb-6"></p>

            <?php if (isset($_SESSION['user_id'])): ?>
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="item_id" id="m-id">
                    <input type="text" name="entered_otp" placeholder="4-Digit OTP" maxlength="4" required class="w-full bg-zinc-800 border border-zinc-700 rounded-2xl px-5 py-4 text-lg text-white placeholder-zinc-500">
                    <button name="verify_otp" class="w-full bg-emerald-600 hover:bg-emerald-500 py-4 rounded-3xl text-lg font-semibold">Confirm & Resolve</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function showPage(page) {
            document.querySelectorAll('#landing, #signin, #signup, #main-app').forEach(el => {
                el.classList.toggle('hidden', el.id !== page);
            });
        }

        const map = document.getElementById('map-image');
        if (map) {
            map.onclick = (e) => {
                const rect = map.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * 100;
                const y = ((e.clientY - rect.top) / rect.height) * 100;

                document.getElementById('fx').value = x.toFixed(2);
                document.getElementById('fy').value = y.toFixed(2);
                document.getElementById('report-form').classList.remove('hidden');
                document.getElementById('report-form').scrollIntoView({ behavior: 'smooth' });
            };
        }

        function showDetails(item) {
            document.getElementById('m-id').value = item.id || '';
            document.getElementById('m-title').innerText = item.title || 'Untitled Item';
            document.getElementById('m-desc').innerText = item.description || 'No description provided.';
            document.getElementById('otp-modal').style.display = 'flex';
        }

        <?php if (isset($_SESSION['user_id'])): ?>
            document.getElementById('main-app').classList.remove('hidden');
        <?php endif; ?>
    </script>

</body>
</html>