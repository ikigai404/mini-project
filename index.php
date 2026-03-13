<?php
session_start();
$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Initialize Tables
$db->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT UNIQUE, password TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS items (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, claimer_id INTEGER DEFAULT NULL, title TEXT, description TEXT, item_type TEXT, x_pos REAL, y_pos REAL, floor TEXT, contact TEXT, otp_code TEXT, status TEXT DEFAULT 'active')");

// --- AUTH LOGIC ---
if (isset($_POST['signup'])) {
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$_POST['name'], $_POST['email'], $hash]);
}

if (isset($_POST['login'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_POST['email']]);
    $user = $stmt->fetch();
    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
    }
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); }

// --- ITEM LOGIC ---
if (isset($_POST['save_item']) && isset($_SESSION['user_id'])) {
    $otp = rand(1000, 9999); // Generate Secret OTP
    $stmt = $db->prepare("INSERT INTO items (user_id, title, description, item_type, x_pos, y_pos, floor, contact, otp_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $_POST['title'], $_POST['description'], $_POST['item_type'], $_POST['x_pos'], $_POST['y_pos'], $_POST['floor'], $_POST['contact'], $otp]);
}

// --- OTP HANDSHAKE LOGIC ---
if (isset($_POST['verify_otp'])) {
    $stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND otp_code = ?");
    $stmt->execute([$_POST['item_id'], $_POST['entered_otp']]);
    if ($stmt->fetch()) {
        $db->prepare("UPDATE items SET status = 'resolved', claimer_id = ? WHERE id = ?")->execute([$_SESSION['user_id'], $_POST['item_id']]);
        $msg = "Exchange Confirmed! Item removed.";
    } else {
        $error = "Invalid OTP code!";
    }
}

$current_floor = $_GET['floor'] ?? 'floor1';
$items = $db->prepare("SELECT items.*, users.name as owner_name FROM items JOIN users ON items.user_id = users.id WHERE floor = ? AND status = 'active'");
$items->execute([$current_floor]);
$display_items = $items->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>UCSC Lost & Found Pro</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; text-align: center; }
        .container { max-width: 900px; margin: auto; background: white; padding: 20px; border-radius: 10px; }
        #map-wrapper { position: relative; display: inline-block; border: 5px solid #333; }
        .pin { position: absolute; width: 20px; height: 20px; border-radius: 50%; transform: translate(-50%, -50%); cursor: pointer; border: 2px solid white; }
        .pin-lost { background: red; } .pin-found { background: green; }
        .modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.5); display: none; z-index: 100; }
        .auth-box { background: #eee; padding: 15px; margin-bottom: 20px; border-radius: 5px; }
        input, select { margin: 5px; padding: 8px; width: 80%; }
    </style>
</head>
<body>

<div class="container">
    <h1>Uni Exchange Hub</h1>

    <?php if (!isset($_SESSION['user_id'])): ?>
        <div class="auth-box">
            <h3>Login or Signup to Post/Claim</h3>
            <form method="POST">
                <input type="text" name="name" placeholder="Name (for Signup)">
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <br>
                <button name="login">Login</button> <button name="signup">Signup</button>
            </form>
        </div>
    <?php else: ?>
        <p>Welcome, <strong><?php echo $_SESSION['user_name']; ?></strong>! <a href="?logout=1">Logout</a></p>
    <?php endif; ?>

    <div>
        <a href="?floor=floor1">Floor 1</a> | <a href="?floor=floor2">Floor 2</a>
    </div>

    <div id="map-wrapper">
        <img src="<?php echo $current_floor; ?>.jpg" id="map-image" style="width:100%; max-width:800px;">
        <?php foreach ($display_items as $i): ?>
            <div class="pin <?php echo $i['item_type']=='lost'?'pin-lost':'pin-found'; ?>" 
                 style="left:<?php echo $i['x_pos']; ?>%; top:<?php echo $i['y_pos']; ?>%;"
                 onclick="showDetails(<?php echo htmlspecialchars(json_encode($i)); ?>)"></div>
        <?php endforeach; ?>
    </div>

    <div id="otp-modal" class="modal">
        <h3 id="m-title"></h3>
        <p id="m-desc"></p>
        <p><strong>Posted by:</strong> <span id="m-owner"></span></p>
        
        <?php if (isset($_SESSION['user_id'])): ?>
            <hr>
            <form method="POST">
                <input type="hidden" name="item_id" id="m-id">
                <p>Enter OTP provided by the owner to confirm exchange:</p>
                <input type="text" name="entered_otp" placeholder="4-Digit OTP" required>
                <button name="verify_otp" style="background: #28a745; color: white;">Confirm & Resolve</button>
            </form>
            
            <div id="owner-tools" style="display:none; color: blue; font-weight: bold; margin-top: 10px;">
                Your OTP for this item: <span id="m-otp"></span>
            </div>
        <?php endif; ?>
        <button onclick="document.getElementById('otp-modal').style.display='none'">Close</button>
    </div>

    <div id="report-form" style="display:none; margin-top: 20px;">
        <h3>Report Item</h3>
        <form method="POST">
            <input type="hidden" name="x_pos" id="fx">
            <input type="hidden" name="y_pos" id="fy">
            <input type="hidden" name="floor" value="<?php echo $current_floor; ?>">
            <input type="text" name="title" placeholder="Item Title" required>
            <textarea name="description" placeholder="Description"></textarea>
            <select name="item_type"><option value="lost">I Lost This</option><option value="found">I Found This</option></select>
            <input type="text" name="contact" placeholder="Contact Info" required>
            <button name="save_item">Post Pin</button>
        </form>
    </div>
</div>

<script>
    const map = document.getElementById('map-image');
    map.onclick = (e) => {
        <?php if(!isset($_SESSION['user_id'])) { echo "alert('Please login first'); return;"; } ?>
        const rect = map.getBoundingClientRect();
        document.getElementById('fx').value = ((e.clientX - rect.left) / rect.width) * 100;
        document.getElementById('fy').value = ((e.clientY - rect.top) / rect.height) * 100;
        document.getElementById('report-form').style.display = 'block';
    };

    function showDetails(item) {
        document.getElementById('m-id').value = item.id;
        document.getElementById('m-title').innerText = item.title;
        document.getElementById('m-desc').innerText = item.description;
        document.getElementById('m-owner').innerText = item.owner_name;
        
        // If current user is the one who posted it, show the OTP
        const loggedInUser = <?php echo $_SESSION['user_id'] ?? 'null'; ?>;
        const ownerTools = document.getElementById('owner-tools');
        if (loggedInUser == item.user_id) {
            ownerTools.style.display = 'block';
            document.getElementById('m-otp').innerText = item.otp_code;
        } else {
            ownerTools.style.display = 'none';
        }
        
        document.getElementById('otp-modal').style.display = 'block';
    }
</script>

</body>
</html>