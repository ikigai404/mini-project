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
        $stmt = $db->prepare("SELECT id FROM users WHERE student_id = ? OR contact = ?");
        $stmt->execute([$_POST['student_id'], $_POST['contact']]);
        if ($stmt->fetch()) {
            $auth_message = "Student ID or Contact Number already exists.";
            $auth_type = "error";
            echo "<script>window.onload = function() { showPage('signup'); }</script>";
        } else {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, student_id, contact, password) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_POST['name'], $_POST['student_id'], $_POST['contact'], $hash]);
        $auth_message = "Account created! You can now sign in.";
        $auth_type = "success";
        echo "<script>window.onload = function() { window.location.hash = 'signin'; }</script>";
        }
    } catch(PDOException $e) {
        $auth_message = "An error occurred during registration.";
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
    // Fetch user's contact from database
    $user_stmt = $db->prepare("SELECT contact FROM users WHERE id = ?");
    $user_stmt->execute([$_SESSION['user_id']]);
    $user_contact = $user_stmt->fetchColumn();

    $otp = rand(1000, 9999);
    $stmt = $db->prepare("INSERT INTO items (user_id, title, description, item_type, x_pos, y_pos, floor, contact, otp_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['title'], $_POST['description'], $_POST['item_type'], $_POST['x_pos'], $_POST['y_pos'], $_POST['floor'], $user_contact, $otp]);
    
    header("Location: index.php?floor=" . urlencode($_POST['floor']));
    exit();
}

// 5. OTP VERIFY
if (isset($_POST['verify_otp'])) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND otp_code = ?");
    $stmt->execute([$_POST['item_id'], $_POST['entered_otp']]);
    $item = $stmt->fetch();
    if ($item) {
        $db->prepare("UPDATE items SET status = 'resolved' WHERE id = ?")->execute([$_POST['item_id']]);
        $_SESSION['auth_success'] = "Item successfully resolved! Thank you for using TraceIt.";
        $_SESSION['auth_type'] = "success";
        header("Location: index.php?floor=" . urlencode($_GET['floor'] ?? 'floor1'));
        exit();
    } else {
        $auth_message = "Invalid OTP Code! Please check with the owner.";
        $auth_type = "error";
    }
}

// 6. DELETE LOGIC
if (isset($_POST['delete_item']) && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("DELETE FROM items WHERE id = ? AND user_id = ?");
    $stmt->execute([$_POST['item_id'], $_SESSION['user_id']]);
    header("Location: index.php?floor=" . urlencode($_GET['floor'] ?? 'floor1'));
    exit();
}

// 7. UPDATE LOGIC
if (isset($_POST['update_item']) && isset($_SESSION['user_id'])) {
    $stmt = $db->prepare("UPDATE items SET title = ?, description = ?, item_type = ? WHERE id = ? AND user_id = ?");
    $stmt->execute([$_POST['title'], $_POST['description'], $_POST['item_type'], $_POST['item_id'], $_SESSION['user_id']]);
    header("Location: index.php?floor=" . urlencode($_GET['floor'] ?? 'floor1'));
    exit();
}


// Fetch Items
$current_floor = $_GET['floor'] ?? 'floor1';
$allowed_floors = ['floor1', 'floor2', 'floor3', 'floor4'];
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
        .text-glow { text-shadow: 0 0 15px rgba(14, 165, 233, 0.5); }
        
        /* Scrollable Page Layout */
        .page-section { width: 100%; position: relative; }
        .page-section.hidden { display: none !important; }
        html { scroll-behavior: smooth; }

        /* Global Technical Background */
        body {
            background-color: #0c4a6e;
        }

        body, #signup {
            background-image: 
                linear-gradient(rgba(14, 165, 233, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(14, 165, 233, 0.08) 1px, transparent 1px);
            background-size: 40px 40px;
            background-attachment: fixed;
        }
        .hero-bg {
            position: relative;
        }
        .hero-bg::after {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(circle at 50% 50%, transparent 0%, #0c4a6e 80%);
            pointer-events: none;
        }

        #signup {
            background-color: #000000 !important;
            background-image: linear-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, 0.05) 1px, transparent 1px) !important;
        }

        /* Glassmorphism Panel */
        .glass-panel {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        /* Entrance Animations */
        @keyframes slideUpFade {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            from { transform: scale(1); }
            to { transform: scale(1.1); }
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
        .floating-input:not(:placeholder-shown) ~ .floating-label,
        .floating-input:-webkit-autofill ~ .floating-label {
            top: 0.75rem;
            font-size: 0.75rem;
            color: #38bdf8; /* Sky Blue by default */
        }

        .floating-input.focus-emerald:focus ~ .floating-label,
        .floating-input.focus-emerald:not(:placeholder-shown) ~ .floating-label,
        .floating-input.focus-emerald:-webkit-autofill ~ .floating-label {
            color: #10b981; /* Emerald Green for Sign Up */
        }
        
        /* Map Specific Styles */
        .pin { position: absolute; width: 24px; height: 24px; border-radius: 50% 50% 50% 0; transform: translate(-50%, -100%) rotate(-45deg); cursor: pointer; z-index: 40; transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); border: 2px solid rgba(255,255,255,0.8); animation: floatPin 3s ease-in-out infinite; }
        @keyframes floatPin { 0%, 100% { margin-top: 0px; } 50% { margin-top: -8px; } }
        .pin:nth-child(odd) { animation-delay: 0.5s; animation-duration: 3.5s; }
        .pin:hover { transform: translate(-50%, -110%) rotate(-45deg) scale(1.2); z-index: 100; animation-play-state: paused; }
        .pin-lost { background: linear-gradient(135deg, #ff416c, #ff4b2b); box-shadow: 0 0 10px rgba(239, 68, 68, 0.6); }
        .pin-found { background: linear-gradient(135deg, #11786c, #96c93d); box-shadow: 0 0 10px rgba(16, 185, 129, 0.6); }
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.8); backdrop-filter:blur(8px); z-index:50; align-items:center; justify-content:center; overflow-y: auto; padding: 20px; }
        #mainMap { cursor: default; filter: invert(1) hue-rotate(195deg) brightness(1.1) contrast(1.1) saturate(0.6) drop-shadow(0 0 25px rgba(56, 189, 248, 0.15)); opacity: 0.9; }
        #mainMap { cursor: default; filter: invert(1) hue-rotate(195deg) brightness(0.9) contrast(1.1) saturate(0.4) drop-shadow(0 0 25px rgba(56, 189, 248, 0.15)); opacity: 0.85; }
        #mainMap { transition: transform 0.5s ease; border: 1px solid rgba(56, 189, 248, 0.2); }

        /* Pointer Glow Effect */
        #pointer-glow {
            position: fixed;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.15) 0%, transparent 70%);
            border-radius: 50%; pointer-events: none;
            pointer-events: none;
            transform: translate(-50%, -50%) translateZ(-1px);
            z-index: 1;
            transition: opacity 0.3s ease;
        }

        /* Map Transition */
        .map-fade { animation: mapFadeIn 0.5s ease-out; }
        @keyframes mapFadeIn { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
        #mapWrapper { transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1); }

        /* Prevent glow from appearing over modals/panels */
        .glass-panel, .modal-overlay > div { position: relative; z-index: 50; }

        /* Fade In Animation */
        .slow-fade-in {
            opacity: 0;
            animation: slowFade 2.5s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        .floating-text {
            position: absolute;
            pointer-events: none;
            white-space: nowrap;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: rgba(56, 189, 248, 0.15);
            z-index: 0;
            animation: floatAround var(--duration) linear infinite;
        }

        @keyframes floatAround {
            0% { transform: translate(0, 0) rotate(0deg); animation-timing-function: ease-in-out; }
            25% { transform: translate(calc(var(--rx, 50px) * 1), calc(var(--ry, 50px) * -1)) rotate(calc(var(--rd, 5deg) * 1)); }
            50% { transform: translate(calc(var(--rx, 50px) * -1.2), calc(var(--ry, 50px) * 1.5)) rotate(calc(var(--rd, 5deg) * -1.5)); }
            75% { transform: translate(calc(var(--rx, 50px) * 0.8), calc(var(--ry, 50px) * 0.8)) rotate(calc(var(--rd, 5deg) * 0.5)); }
            100% { transform: translate(0, 0) rotate(0deg); }
        }

        @keyframes slowFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* Hover Popup Styles */
        .pin-popup { display: none; position: absolute; bottom: 140%; left: 50%; transform: translateX(-50%); z-index: 110; width: 260px; pointer-events: none; }
        .pin:hover .pin-popup { display: block; }
        .popup-content { transform: rotate(45deg); background: rgba(15, 23, 42, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); }
    </style>
</head>
<body class="bg-zinc-950 text-white min-h-screen overflow-x-hidden relative scroll-smooth">
    <div id="landing" class="page-section <?php echo isset($_SESSION['user_id']) ? 'hidden' : ''; ?> min-h-screen flex flex-col items-center justify-center relative">
        <div id="pointer-glow"></div>
        
        <!-- Floating Background Elements -->
        <div class="floating-text text-4xl" style="top: 15%; left: 10%; --duration: 20s; --rx: 40px; --ry: 60px; --rd: 12deg;"><i class="fa-solid fa-key"></i></div>
        <div class="floating-text text-2xl" style="top: 60%; left: 80%; --duration: 25s; --rx: -70px; --ry: 30px; --rd: -8deg;"><i class="fa-solid fa-wallet"></i></div>
        <div class="floating-text text-5xl" style="top: 80%; left: 20%; --duration: 18s; --rx: 30px; --ry: -50px; --rd: 15deg;"><i class="fa-solid fa-id-card"></i></div>
        <div class="floating-text text-3xl" style="top: 20%; left: 70%; --duration: 22s; --rx: -50px; --ry: 80px; --rd: -10deg;"><i class="fa-solid fa-bag-shopping"></i></div>
        <div class="floating-text text-xl" style="top: 40%; left: 5%; --duration: 30s; --rx: 90px; --ry: 20px; --rd: 20deg;"><i class="fa-solid fa-mobile-screen-button"></i></div>
        <div class="floating-text text-2xl" style="top: 5%; left: 40%; --duration: 28s; --rx: -20px; --ry: -90px; --rd: -15deg;"><i class="fa-solid fa-headphones"></i></div>
        <div class="floating-text text-4xl" style="top: 85%; left: 60%; --duration: 24s; --rx: 60px; --ry: 40px; --rd: 10deg;"><i class="fa-solid fa-glasses"></i></div>
        <div class="floating-text text-3xl" style="top: 50%; left: 90%; --duration: 21s; --rx: -80px; --ry: -30px; --rd: -12deg;"><i class="fa-solid fa-umbrella"></i></div>
        <div class="floating-text text-2xl" style="top: 70%; left: 45%; --duration: 26s; --rx: 50px; --ry: 70px; --rd: 8deg;"><i class="fa-solid fa-laptop"></i></div>
        <div class="floating-text text-xl" style="top: 30%; left: 25%; --duration: 19s; --rx: -40px; --ry: -60px; --rd: -18deg;"><i class="fa-solid fa-book"></i></div>

        <div class="max-w-6xl mx-auto px-6 text-center z-10">
            <div class="animate-item mb-6 inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/5 border border-white/10 backdrop-blur-md">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </span>
                <span class="text-xs font-bold tracking-widest uppercase text-emerald-400/80">Campus Recovery System Live</span>
            </div>

            <h1 class="slow-fade-in logo-font text-7xl md:text-9xl font-bold mb-6 tracking-tighter bg-clip-text text-transparent bg-gradient-to-b from-white via-white to-sky-500/50">
                TraceIt .
            </h1>

            <p class="slow-fade-in text-lg md:text-xl text-zinc-400 mb-8 max-w-2xl mx-auto font-mono leading-relaxed" style="animation-delay: 0.5s;">
                <span class="inline-block">
                    TraceIt: A precision-mapped recovery network for campus essentials.
                </span>
            </p>
            
            <div class="slow-fade-in flex flex-col sm:flex-row gap-6 justify-center items-center" style="animation-delay: 1s;">
                <button onclick="showPage('signin')" class="group relative bg-sky-500 hover:bg-sky-400 text-white px-10 py-5 rounded-2xl font-bold transition-all shadow-[0_0_40px_rgba(14,165,233,0.3)] flex items-center gap-3 overflow-hidden">
                    <span class="relative z-10">Get Started</span>
                    <i class="fa-solid fa-chevron-right text-sm group-hover:translate-x-1 transition-transform relative z-10"></i>
                    <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white/20 to-transparent -translate-x-full group-hover:animate-[shimmer_1.5s_infinite]"></div>
                </button>
                <button onclick="showPage('signup')" class="bg-white/5 hover:bg-white/10 border border-white/10 text-white px-10 py-5 rounded-2xl font-bold transition-all backdrop-blur-md hover:border-white/20">
                    Create Account
                </button>
            </div>
        </div>

        <div class="absolute bottom-0 left-1/2 -translate-x-1/2 flex flex-col items-center gap-1 cursor-pointer group transition-all translate-y-1/2 z-20" onclick="document.getElementById('features').scrollIntoView({behavior: 'smooth'})">
            <span class="text-[10px] uppercase tracking-[0.4em] text-zinc-500 font-bold group-hover:text-sky-400 transition-colors">Explore Features</span>
            <div class="flex flex-col items-center">
                <i class="fa-solid fa-chevron-down text-sky-500 animate-bounce text-sm"></i>
                <div class="w-px h-12 bg-gradient-to-b from-sky-500 via-sky-500/20 to-transparent mt-1"></div>
            </div>
        </div>
    </div>

    <!-- Professional Feature Tiles Section -->
    <div id="features" class="page-section <?php echo isset($_SESSION['user_id']) ? 'hidden' : ''; ?> py-24 relative overflow-hidden">
        <div class="max-w-6xl mx-auto px-8 relative z-10">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <!-- Tile 1 -->
                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-sky-500/30 transition-all group">
                    <div class="w-14 h-14 bg-sky-500/10 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-map-location-dot text-2xl text-sky-400"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Precision Mapping</h3>
                    <p class="text-zinc-400 text-sm leading-relaxed">Pinpoint exactly where you lost or found an item using our interactive multi-floor campus blueprints.</p>
                </div>
                <!-- Tile 2 -->
                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-emerald-500/30 transition-all group">
                    <div class="w-14 h-14 bg-emerald-500/10 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-shield-halved text-2xl text-emerald-400"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3"><i class="fa-solid fa-lock-open mr-2 text-sm opacity-50"></i>Secure Exchange</h3>
                    <p class="text-zinc-400 text-sm leading-relaxed">Our proprietary 4-digit OTP system ensures items are only returned to their rightful owners.</p>
                </div>
                <!-- Tile 3 -->
                <div class="glass-panel p-8 rounded-[2rem] border border-white/5 hover:border-purple-500/30 transition-all group">
                    <div class="w-14 h-14 bg-purple-500/10 rounded-2xl flex items-center justify-center mb-6 group-hover:scale-110 transition-transform">
                        <i class="fa-solid fa-bolt text-2xl text-purple-400"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-3">Real-time Updates</h3>
                    <p class="text-zinc-400 text-sm leading-relaxed">Instant visibility across the network. As soon as a report is filed, it's live for the entire campus to see.</p>
                </div>
            </div>

            <!-- Welcome/CTA Tile -->
            <div class="mt-12 glass-panel p-12 rounded-[3rem] border border-sky-500/20 bg-gradient-to-br from-sky-500/5 to-transparent flex flex-col md:flex-row items-center justify-between gap-8">
                <div class="max-w-xl">
                    <h2 class="text-4xl font-bold mb-4">Ready to reunite?</h2>
                    <p class="text-zinc-400 text-lg">Join hundreds of students already using TraceIt to secure their campus life. Professional, secure, and efficient.</p>
                </div>
                <button onclick="showPage('signin')" class="bg-white text-black px-12 py-5 rounded-2xl font-bold hover:bg-sky-400 hover:text-white transition-all whitespace-nowrap">
                    Sign In Now
                </button>
            </div>
        </div>
        
        <!-- Decorative Background Blur -->
        <div class="absolute top-1/2 left-0 -translate-y-1/2 w-96 h-96 bg-sky-500/10 blur-[120px] rounded-full"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 bg-emerald-500/5 blur-[120px] rounded-full"></div>
    </div>

    <div id="signin" class="page-section <?php echo (isset($_SESSION['user_id']) || (isset($auth_type) && $auth_type == 'success')) ? 'hidden' : ''; ?> min-h-screen flex items-center justify-center p-6 relative">
        <div id="pointer-glow"></div>

        <!-- Floating Background Elements -->
        <div class="floating-text text-4xl" style="top: 15%; left: 10%; --duration: 20s; --rx: 40px; --ry: 60px; --rd: 12deg;"><i class="fa-solid fa-key"></i></div>
        <div class="floating-text text-2xl" style="top: 60%; left: 80%; --duration: 25s; --rx: -70px; --ry: 30px; --rd: -8deg;"><i class="fa-solid fa-wallet"></i></div>
        <div class="floating-text text-5xl" style="top: 80%; left: 20%; --duration: 18s; --rx: 30px; --ry: -50px; --rd: 15deg;"><i class="fa-solid fa-id-card"></i></div>
        <div class="floating-text text-3xl" style="top: 20%; left: 70%; --duration: 22s; --rx: -50px; --ry: 80px; --rd: -10deg;"><i class="fa-solid fa-bag-shopping"></i></div>
        <div class="floating-text text-xl" style="top: 40%; left: 5%; --duration: 30s; --rx: 90px; --ry: 20px; --rd: 20deg;"><i class="fa-solid fa-mobile-screen-button"></i></div>
        <div class="floating-text text-2xl" style="top: 5%; left: 40%; --duration: 28s; --rx: -20px; --ry: -90px; --rd: -15deg;"><i class="fa-solid fa-headphones"></i></div>
        <div class="floating-text text-4xl" style="top: 85%; left: 60%; --duration: 24s; --rx: 60px; --ry: 40px; --rd: 10deg;"><i class="fa-solid fa-glasses"></i></div>
        <div class="floating-text text-3xl" style="top: 50%; left: 90%; --duration: 21s; --rx: -80px; --ry: -30px; --rd: -12deg;"><i class="fa-solid fa-umbrella"></i></div>
        <div class="floating-text text-2xl" style="top: 70%; left: 45%; --duration: 26s; --rx: 50px; --ry: 70px; --rd: 8deg;"><i class="fa-solid fa-laptop"></i></div>
        <div class="floating-text text-xl" style="top: 30%; left: 25%; --duration: 19s; --rx: -40px; --ry: -60px; --rd: -18deg;"><i class="fa-solid fa-book"></i></div>

        <div class="glass-panel rounded-[2rem] w-full max-w-md p-10 relative overflow-hidden">
            <div class="text-center mb-8 animate-item">
                <div class="w-16 h-16 bg-sky-500/20 text-sky-400 rounded-2xl flex items-center justify-center mx-auto mb-4 border border-sky-500/30">
                    <i class="fa-solid fa-right-to-bracket text-2xl"></i>
                </div>
                <h2 class="text-3xl font-bold text-white">Welcome!</h2>
                <p class="text-zinc-400 mt-2">Sign in to your TraceIt account</p>
            </div>

            <?php if ($auth_message && isset($_POST['verify_otp'])): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 animate-item bg-red-500/10 text-red-400 border border-red-500/20">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo $auth_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($auth_message && isset($_POST['login_submit'])): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 animate-item <?php echo $auth_type == 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'; ?>">
                    <i class="fa-solid <?php echo $auth_type == 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                    <?php echo $auth_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" onsubmit="showLoading(this, 'login_btn')">
                <div class="relative animate-item delay-100">
                    <input type="text" name="student_id" id="login_id" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl px-5 pt-7 pb-3 text-white outline-none transition-all" placeholder=" ">
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

    <div id="signup" class="page-section hidden fixed inset-0 z-[100] min-h-screen flex items-center justify-center p-6 overflow-y-auto">
        <div id="pointer-glow"></div>

        <!-- Floating Background Elements -->
        <div class="floating-text text-4xl" style="top: 15%; left: 10%; --duration: 20s; --rx: 40px; --ry: 60px; --rd: 12deg;"><i class="fa-solid fa-user-plus"></i></div>
        <div class="floating-text text-2xl" style="top: 60%; left: 80%; --duration: 25s; --rx: -70px; --ry: 30px; --rd: -8deg;"><i class="fa-solid fa-wallet"></i></div>
        <div class="floating-text text-5xl" style="top: 80%; left: 20%; --duration: 18s; --rx: 30px; --ry: -50px; --rd: 15deg;"><i class="fa-solid fa-id-card"></i></div>
        <div class="floating-text text-3xl" style="top: 20%; left: 70%; --duration: 22s; --rx: -50px; --ry: 80px; --rd: -10deg;"><i class="fa-solid fa-bag-shopping"></i></div>
        <div class="floating-text text-xl" style="top: 40%; left: 5%; --duration: 30s; --rx: 90px; --ry: 20px; --rd: 20deg;"><i class="fa-solid fa-mobile-screen-button"></i></div>
        <div class="floating-text text-2xl" style="top: 5%; left: 40%; --duration: 28s; --rx: -20px; --ry: -90px; --rd: -15deg;"><i class="fa-solid fa-headphones"></i></div>
        <div class="floating-text text-4xl" style="top: 85%; left: 60%; --duration: 24s; --rx: 60px; --ry: 40px; --rd: 10deg;"><i class="fa-solid fa-glasses"></i></div>
        <div class="floating-text text-3xl" style="top: 50%; left: 90%; --duration: 21s; --rx: -80px; --ry: -30px; --rd: -12deg;"><i class="fa-solid fa-umbrella"></i></div>
        <div class="floating-text text-2xl" style="top: 70%; left: 45%; --duration: 26s; --rx: 50px; --ry: 70px; --rd: 8deg;"><i class="fa-solid fa-laptop"></i></div>
        <div class="floating-text text-xl" style="top: 30%; left: 25%; --duration: 19s; --rx: -40px; --ry: -60px; --rd: -18deg;"><i class="fa-solid fa-book"></i></div>

        <button onclick="showPage('landing')" class="absolute top-8 left-8 text-white/50 hover:text-white transition-colors flex items-center gap-2 z-[110]">
            <i class="fa-solid fa-arrow-left"></i> Back
        </button>

        <div class="glass-panel rounded-[2rem] w-full max-w-md p-6 my-4 relative overflow-hidden">
            <div class="text-center mb-6 animate-item">
                <div class="w-12 h-12 bg-sky-500/20 text-sky-400 rounded-xl flex items-center justify-center mx-auto mb-3 border border-sky-500/30">
                    <i class="fa-solid fa-user-plus text-2xl"></i>
                </div>
                <h2 class="text-2xl font-bold text-white">Join TraceIt</h2>
                <p class="text-zinc-400 text-sm mt-1">Create your campus recovery profile</p>
            </div>

            <?php if ($auth_message && isset($_POST['signup_submit'])): ?>
                <div class="mb-6 p-4 rounded-xl text-sm font-medium flex items-center gap-3 animate-item <?php echo $auth_type == 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'; ?>">
                    <i class="fa-solid <?php echo $auth_type == 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
                    <?php echo $auth_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-3" onsubmit="showLoading(this, 'signup_btn')">
                <div class="relative animate-item delay-100">
                    <input type="text" name="name" id="reg_name" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl px-5 pt-6 pb-2 text-sm text-white outline-none transition-all" placeholder=" ">
                    <label for="reg_name" class="floating-label absolute left-5 top-4 text-zinc-500 text-sm transition-all cursor-text pointer-events-none">Full Name</label>
                </div>
                <div class="relative animate-item delay-100">
                    <input type="text" name="student_id" id="reg_id" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl px-5 pt-6 pb-2 text-sm text-white outline-none transition-all" placeholder=" ">
                    <label for="reg_id" class="floating-label absolute left-5 top-4 text-zinc-500 text-sm transition-all cursor-text pointer-events-none">Student ID</label>
                </div>
                <div class="relative animate-item delay-200">
                    <input type="text" name="contact" id="reg_phone" required class="floating-input focus-emerald w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 pt-6 pb-2 text-sm text-white outline-none transition-all" placeholder=" ">
                    <label for="reg_phone" class="floating-label absolute left-5 top-4 text-zinc-500 text-sm transition-all cursor-text pointer-events-none">Contact Number</label>
                </div>
                <div class="relative animate-item delay-200">
                    <input type="password" name="password" id="reg_pwd" required class="floating-input w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-xl pl-5 pr-12 pt-6 pb-2 text-sm text-white outline-none transition-all" placeholder=" ">
                    <label for="reg_pwd" class="floating-label absolute left-5 top-4 text-zinc-500 text-sm transition-all cursor-text pointer-events-none">Password</label>
                    <button type="button" onclick="togglePassword('reg_pwd', 'eye_reg')" class="absolute right-4 top-1/2 -translate-y-1/2 text-zinc-500 hover:text-white transition-colors">
                        <i id="eye_reg" class="fa-regular fa-eye"></i>
                    </button>
                </div>
                <button type="submit" name="signup_submit" id="signup_btn" class="w-full bg-sky-500 hover:bg-sky-400 text-white font-bold py-3 rounded-xl transition-all shadow-lg shadow-sky-500/20 animate-item delay-300 flex items-center justify-center gap-2 group">
                    <span>Create Account</span>
                    <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>
            <p class="mt-4 text-center text-sm text-zinc-400 animate-item delay-300">
                Already registered? <span onclick="showPage('signin')" class="text-sky-400 cursor-pointer hover:underline">Sign in</span>
            </p>
        </div>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
    <div id="dashboard" class="page-section min-h-screen p-8">
        <div id="pointer-glow"></div>
        <div class="max-w-6xl mx-auto relative z-10">
        
        <?php if (isset($_SESSION['auth_success'])): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm font-medium flex items-center justify-between gap-3 animate-item bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 backdrop-blur-md">
                <div class="flex items-center gap-3"><i class="fa-solid fa-circle-check"></i> <?php echo $_SESSION['auth_success']; ?></div>
                <button onclick="this.parentElement.remove()" class="opacity-50 hover:opacity-100"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <?php unset($_SESSION['auth_success']); unset($_SESSION['auth_type']); ?>
        <?php endif; ?>

        <?php if ($auth_message && isset($_POST['verify_otp'])): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm font-medium flex items-center justify-between gap-3 animate-item bg-red-500/10 text-red-400 border border-red-500/20 backdrop-blur-md">
                <div class="flex items-center gap-3"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $auth_message; ?></div>
                <button onclick="this.parentElement.remove()" class="opacity-50 hover:opacity-100"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <div class="flex justify-between items-center mb-10">
            <h1 onclick="window.location.href='index.php'" class="logo-font text-5xl font-bold tracking-tighter bg-clip-text text-transparent bg-gradient-to-b from-white via-white to-sky-500/50 drop-shadow-[0_0_15px_rgba(14,165,233,0.3)] cursor-pointer">
                TraceIt <span class="text-sky-500/80">.</span>
                <span class="text-[10px] uppercase tracking-[0.5em] text-zinc-500 ml-2 font-sans align-middle opacity-70">v2.0</span>
            </h1>
            <div class="flex items-center gap-6 bg-zinc-900/50 border border-zinc-800 p-2 pl-5 rounded-2xl backdrop-blur-md">
                <div class="flex flex-col">
                    <span class="text-[10px] uppercase tracking-widest text-zinc-500 font-bold">Active User</span>
                    <span class="text-sky-400 font-semibold"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                </div>
                <a href="?logout=1" class="bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white px-4 py-2.5 rounded-xl transition-all text-sm font-bold flex items-center gap-2">
                    <i class="fa-solid fa-power-off"></i> Logout
                </a>
            </div>
        </div>

        <div class="flex gap-4 mb-8">
            <button onclick="changeFloor('floor1')" class="px-8 py-3 rounded-xl transition-all <?php echo $current_floor=='floor1'?'bg-sky-500 shadow-[0_0_20px_rgba(14,165,233,0.4)] text-white':'bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-white'; ?>">Floor 01</button>
            <button onclick="changeFloor('floor2')" class="px-8 py-3 rounded-xl transition-all <?php echo $current_floor=='floor2'?'bg-sky-500 shadow-[0_0_20px_rgba(14,165,233,0.4)] text-white':'bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-white'; ?>">Floor 02</button>
            <button onclick="changeFloor('floor3')" class="px-8 py-3 rounded-xl transition-all <?php echo $current_floor=='floor3'?'bg-sky-500 shadow-[0_0_20px_rgba(14,165,233,0.4)] text-white':'bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-white'; ?>">Floor 03</button>
            <button onclick="changeFloor('floor4')" class="px-8 py-3 rounded-xl transition-all <?php echo $current_floor=='floor4'?'bg-sky-500 shadow-[0_0_20px_rgba(14,165,233,0.4)] text-white':'bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-white'; ?>">Floor 04</button>
        </div>

        <div id="mapWrapper" class="map-fade relative inline-block border border-white/10 rounded-[2rem] overflow-hidden shadow-2xl bg-[#0a0f1d] group">
            <img src="<?php echo htmlspecialchars($current_floor); ?>.jpg" id="mainMap" class="w-full h-auto block" alt="Floor Map">
            
            <?php 
            $placed_pins = [];
            foreach ($display_items as $item): 
                $orig_x = (float)$item['x_pos'];
                $orig_y = (float)$item['y_pos'];
                $display_x = $orig_x;
                $display_y = $orig_y;
                
                // Collision avoidance: if a pin is too close to another, nudge it in a spiral
                $attempts = 0;
                $found_spot = false;
                while (!$found_spot && $attempts < 20) {
                    $found_spot = true;
                    foreach ($placed_pins as $pos) {
                        $dist = sqrt(pow($display_x - $pos['x'], 2) + pow($display_y - $pos['y'], 2));
                        if ($dist < 6.0) { // Increased collision radius
                            $found_spot = false;
                            $display_x += 4.0 * cos($attempts * 0.5);
                            $display_y += 4.0 * sin($attempts * 0.5);
                            break;
                        }
                    }
                    $attempts++;
                }
                $placed_pins[] = ['x' => $display_x, 'y' => $display_y];
            ?>
                <div class="pin <?php echo $item['item_type']=='lost'?'pin-lost':'pin-found'; ?>" 
                     style="left:<?php echo $display_x; ?>%; top:<?php echo $display_y; ?>%;"
                     onclick='openDetailModal(<?php echo json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>

                    <!-- Hover Popup -->
                    <div class="pin-popup">
                        <div class="popup-content p-4 rounded-2xl shadow-2xl text-left">
                            <p class="text-[10px] uppercase tracking-widest text-sky-400 font-bold mb-1"><?php echo strtoupper($item['item_type']); ?></p>
                            <h4 class="font-bold text-white text-sm truncate"><?php echo htmlspecialchars($item['title']); ?></h4>
                            <p class="text-zinc-400 text-xs line-clamp-2 mt-1"><?php echo htmlspecialchars($item['description']); ?></p>
                            <div class="mt-3 flex flex-col gap-1">
                                <div class="flex items-center gap-2 text-[10px] text-zinc-300">
                                    <i class="fa-solid fa-user text-sky-400/70"></i>
                                    <span><?php echo htmlspecialchars($item['owner_name']); ?></span>
                                </div>
                                <div class="flex items-center gap-2 text-[10px] text-zinc-300">
                                    <i class="fa-solid fa-phone text-emerald-400/70"></i>
                                    <span><?php echo htmlspecialchars($item['contact']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="detailModal" class="modal-overlay">
        <div class="bg-zinc-900 p-6 rounded-[2rem] w-full max-w-sm border border-zinc-800 shadow-2xl my-auto scale-90 md:scale-95">
            <h2 id="dt-title" class="text-2xl font-bold mb-1 text-white truncate"></h2>
            <p id="dt-desc" class="text-zinc-400 mb-3 text-sm line-clamp-3"></p>
            
            <div class="bg-zinc-800/50 p-3 rounded-xl mb-3 border border-zinc-700 text-xs">
                <p class="text-zinc-300"><strong><span id="dt-role-label">Owner</span>:</strong> <span id="dt-owner" class="text-white"></span></p>
                <p class="text-zinc-300 mt-1"><strong>Contact:</strong> <span id="dt-contact" class="text-white"></span></p>
            </div>

            <div id="otpForm" class="hidden space-y-3">
                <p class="text-emerald-400 text-xs font-medium mb-1"><i class="fa-solid fa-handshake"></i> <span id="dt-otp-instruction">Verify with the owner's OTP:</span></p>
                <form method="POST" class="space-y-4">
                <input type="hidden" name="item_id" id="dt-id">
                <input type="hidden" name="floor" value="<?php echo htmlspecialchars($current_floor); ?>">
                <input type="text" name="entered_otp" placeholder="Enter 4-Digit OTP from owner" class="w-full bg-zinc-800 border border-zinc-700 focus:border-sky-500 focus:ring-1 focus:ring-sky-500 rounded-2xl p-4 text-white outline-none transition-all" required>
                <button type="submit" name="verify_otp" class="w-full bg-emerald-500 hover:bg-emerald-400 text-white py-4 rounded-2xl font-bold text-lg transition-colors shadow-lg shadow-emerald-500/20">Claim & Resolve Item</button>
                </form>
            </div>
            
            <div id="otpDisplay" class="hidden space-y-3">
                <div class="text-center p-1.5 bg-sky-500/10 border border-sky-500/30 rounded-xl">
                    <p class="text-sky-400 text-[10px] font-medium">Your Secret OTP for Exchange:</p>
                    <p id="dt-otp" class="text-2xl font-mono font-bold text-white tracking-widest"></p>
                </div>
                
                <form method="POST" class="space-y-2 border-t border-zinc-800 pt-3">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-[10px] uppercase tracking-widest text-zinc-500 font-bold">Edit Report</span>
                    </div>
                    <input type="hidden" name="item_id" id="dt-id-edit">
                    <input type="text" name="title" id="edit-title" class="w-full bg-transparent border border-zinc-700/50 rounded-xl px-4 py-2 text-sm text-white outline-none focus:border-sky-500/50 transition-all" placeholder="Item Title" required>
                    <textarea name="description" id="edit-desc" class="w-full bg-transparent border border-zinc-700/50 rounded-xl px-4 py-2 text-sm text-white outline-none focus:border-sky-500/50 h-16 resize-none" placeholder="Description"></textarea>
                    <select name="item_type" id="edit-type" class="w-full bg-transparent border border-zinc-700/50 rounded-xl px-4 py-2 text-sm text-white outline-none focus:border-sky-500/50 appearance-none cursor-pointer">
                        <option value="lost">I Lost This Item</option>
                        <option value="found">I Found This Item</option>
                    </select>
                    <button type="submit" name="update_item" class="w-full bg-sky-500/80 hover:bg-sky-500 text-white py-1.5 rounded-lg font-bold text-xs transition-all mt-1">Update Details</button>
                </form>

                <form method="POST" onsubmit="return confirm('Are you sure you want to remove this report?');" class="pt-1">
                    <input type="hidden" name="item_id" id="dt-id-delete">
                    <button type="submit" name="delete_item" class="w-full bg-red-500/10 hover:bg-red-500 text-red-500 hover:text-white py-2 rounded-lg font-bold text-xs transition-all border border-red-500/20">Remove Report</button>
                </form>
            </div>
            
            <button onclick="closeModals()" class="w-full mt-2 text-zinc-500 hover:text-white transition-colors text-xs">Close</button>
        </div>
    </div>

    <div id="reportModal" class="modal-overlay">
        <div class="bg-zinc-900 p-10 rounded-[2.5rem] w-full max-w-md border border-zinc-800 shadow-2xl">
            <h2 class="text-3xl font-bold mb-6 text-white">New Report</h2>
            <form method="POST" class="space-y-4" onsubmit="showLoading(this, 'report_btn')">
                <input type="hidden" name="x_pos" id="rx">
                <input type="hidden" name="y_pos" id="ry">
                <input type="hidden" name="floor" value="<?php echo htmlspecialchars($current_floor); ?>">
                
                <input type="text" name="title" placeholder="What is the item?" class="w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 py-4 text-white outline-none transition-all" required>
                <textarea name="description" placeholder="Short description (color, brand)" class="w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 py-4 text-white outline-none transition-all resize-none h-24"></textarea>
                
                <select name="item_type" class="w-full bg-zinc-900/50 border border-zinc-700/50 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 rounded-xl px-5 py-4 text-white outline-none transition-all appearance-none cursor-pointer">
                    <option value="lost">I Lost This Item</option>
                    <option value="found">I Found This Item</option>
                </select>
                
                <button type="submit" name="save_item" id="report_btn" class="w-full bg-emerald-500 hover:bg-emerald-400 text-white py-4 rounded-xl font-bold text-lg transition-colors shadow-lg shadow-emerald-500/20 mt-2">Pin to Map</button>
            </form>
            <button onclick="closeModals()" class="w-full mt-4 text-zinc-500 hover:text-white transition-colors">Cancel</button>
        </div>
    </div>

    <footer class="relative z-10 pt-20 pb-10 border-t border-white/5 backdrop-blur-md">
        <div class="max-w-6xl mx-auto px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-12 mb-16">
                <div class="space-y-4">
                    <h3 onclick="window.location.href='index.php'" class="logo-font text-2xl font-bold text-white cursor-pointer">TraceIt<span class="text-sky-500">.</span></h3>
                    <p class="text-zinc-400 text-sm leading-relaxed">
                        The next generation of campus lost and found. Leveraging precision mapping and secure OTP verification to reunite students with their essentials.
                    </p>
                </div>
                <div class="space-y-4">
                    <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-sky-500">Development Team</h4>
                    <ul class="text-zinc-400 text-sm space-y-2">
                        <li class="flex items-center gap-2"><i class="fa-solid fa-terminal text-[10px] text-zinc-600"></i> Prompter</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-keyboard text-[10px] text-zinc-600"></i> Typist</li>
                        <li class="flex items-center gap-2"><i class="fa-solid fa-vial text-[10px] text-zinc-600"></i> Tester</li>
                    </ul>
                </div>
                <div class="space-y-4">
                    <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-sky-500">Project Info</h4>
                    <p class="text-zinc-400 text-sm">
                        Mini Project 2026<br>
                        Computer Science Department<br>
                        Campus Recovery Network v2.0<br>
                        By Group 21.
                    </p>
                </div>
            </div>
            <div class="pt-8 border-t border-white/5 text-center">
                <p class="text-zinc-600 text-[10px] tracking-[0.3em] uppercase font-medium">
                    &copy; <?php echo date("Y"); ?> TraceIt Campus Recovery System. All Rights Reserved.
                </p>
            </div>
        </div>
    </footer>

    <script>
        // 0. Pointer Glow Logic
        const glows = document.querySelectorAll('#pointer-glow');
        window.addEventListener('mousemove', (e) => {
            glows.forEach(glow => {
                glow.style.left = e.clientX + 'px';
                glow.style.top = e.clientY + 'px';
            });
        });

        // 1. UI Interactions
        function showPage(page) {
            const landing = document.getElementById('landing');
            const features = document.getElementById('features');
            const signin = document.getElementById('signin');
            const signup = document.getElementById('signup');

            if (page === 'signup') {
                signup.classList.remove('hidden');
            } else {
                signup.classList.add('hidden');
            }
            
            if (page === 'landing') window.scrollTo({top: 0, behavior: 'smooth'});
            
            const el = document.getElementById(page);
            if (el) el.scrollIntoView({behavior: 'smooth'});
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

        function changeFloor(floor) {
            const wrapper = document.getElementById('mapWrapper');
            wrapper.style.opacity = '0';
            wrapper.style.transform = 'scale(0.95)';
            setTimeout(() => {
                window.location.href = '?floor=' + floor;
            }, 300);
        }

        // 2. Map Interaction & Boundary Logic
        const mapWrapper = document.getElementById('mapWrapper');
        if(mapWrapper) {
            const mainMap = document.getElementById('mainMap');
            
            mainMap.addEventListener('mousemove', (e) => {
                mainMap.style.cursor = isPointInMap(e) ? 'crosshair' : 'default';
            });

            mainMap.onclick = function(e) {
                    if (!isPointInMap(e)) return;
                    e.stopPropagation();
                    const rect = mainMap.getBoundingClientRect();
                    const x = ((e.clientX - rect.left) / rect.width) * 100;
                    const y = ((e.clientY - rect.top) / rect.height) * 100;
                    document.getElementById('rx').value = x;
                    document.getElementById('ry').value = y;
                    document.getElementById('reportModal').style.display = 'flex';
            };

            function isPointInMap(e) {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = mainMap.naturalWidth;
                canvas.height = mainMap.naturalHeight;
                ctx.drawImage(mainMap, 0, 0);
                
                const rect = mainMap.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width) * canvas.width;
                const y = ((e.clientY - rect.top) / rect.height) * canvas.height;
                
                const pixel = ctx.getImageData(x, y, 1, 1).data;
                // Check if pixel is not pure white (255, 255, 255)
                return !(pixel[0] > 250 && pixel[1] > 250 && pixel[2] > 250);
            }
        }

        // 3. Modal Management
        function openDetailModal(item) {
            const userId = <?php echo $_SESSION['user_id'] ?? '0'; ?>;
            
            document.getElementById('dt-id').value = item.id;
            document.getElementById('dt-title').innerText = item.title;
            document.getElementById('dt-desc').innerText = item.description;
            document.getElementById('dt-owner').innerText = item.owner_name;
            document.getElementById('dt-contact').innerText = item.contact;
            document.getElementById('dt-role-label').innerText = item.item_type === 'found' ? 'Founder' : 'Owner';
            document.getElementById('dt-otp-instruction').innerText = item.item_type === 'found' ? 'Claim item from founder? Verify with their OTP:' : 'Found this item? Verify with the owner\'s OTP:';

            if(document.getElementById('dt-id-delete')) document.getElementById('dt-id-delete').value = item.id;
            if(document.getElementById('dt-id-edit')) document.getElementById('dt-id-edit').value = item.id;
            if(document.getElementById('edit-title')) document.getElementById('edit-title').value = item.title;
            if(document.getElementById('edit-desc')) document.getElementById('edit-desc').value = item.description;
            if(document.getElementById('edit-type')) document.getElementById('edit-type').value = item.item_type;

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
