<?php
// 1. DATABASE SETUP
$db = new PDO('sqlite:database.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Updated table with 'floor' column
$db->exec("CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT,
    description TEXT,
    item_type TEXT,
    x_pos REAL,
    y_pos REAL,
    floor TEXT,
    contact TEXT
)");

// 2. HANDLE FORM SUBMISSION
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_item'])) {
    $stmt = $db->prepare("INSERT INTO items (title, description, item_type, x_pos, y_pos, floor, contact) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['title'], $_POST['description'], $_POST['item_type'], 
        $_POST['x_pos'], $_POST['y_pos'], $_POST['floor'], $_POST['contact']
    ]);
    header("Location: index.php?floor=" . $_POST['floor']);
    exit();
}

// 3. FLOOR SELECTION LOGIC
$current_floor = isset($_GET['floor']) ? $_GET['floor'] : 'floor1';

// Fetch pins only for the current floor
$stmt = $db->prepare("SELECT * FROM items WHERE floor = ?");
$stmt->execute([$current_floor]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uni Lost & Found</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #e9ecef; margin: 0; padding: 20px; text-align: center; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        /* Navigation */
        .floor-nav { margin-bottom: 20px; }
        .floor-nav a { text-decoration: none; }
        .floor-btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 5px; cursor: pointer; background: #6c757d; color: white; transition: 0.3s; }
        .floor-btn.active { background: #007bff; font-weight: bold; }

        /* Map Area */
        #map-wrapper { position: relative; display: inline-block; border: 4px solid #333; border-radius: 8px; overflow: hidden; }
        #map-image { display: block; max-width: 100%; height: auto; cursor: crosshair; }

        /* Pins */
        .pin { position: absolute; width: 18px; height: 18px; border-radius: 50%; border: 2px solid white; transform: translate(-50%, -50%); cursor: pointer; z-index: 5; }
        .pin-lost { background: #ff4d4d; box-shadow: 0 0 8px #ff4d4d; }
        .pin-found { background: #2ecc71; box-shadow: 0 0 8px #2ecc71; }
        #temp-pin { background: yellow; display: none; z-index: 10; border: 2px dashed black; }

        /* Detail Popup */
        #detail-box { 
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background: white; padding: 20px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            display: none; z-index: 100; min-width: 250px; text-align: left;
        }
        #detail-box h3 { margin-top: 0; color: #007bff; }
        .close-btn { background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 4px; float: right; cursor: pointer; }

        /* Form */
        #report-form { display: none; margin-top: 30px; padding: 20px; border-top: 2px solid #eee; text-align: left; }
        input, select, textarea { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
        .save-btn { background: #28a745; color: white; border: none; padding: 12px; width: 100%; border-radius: 5px; font-size: 16px; cursor: pointer; }
    </style>
</head>
<body>

<div class="container">
    <h1>University Lost & Found</h1>
    
    <div class="floor-nav">
        <a href="?floor=floor1"><button class="floor-btn <?php echo $current_floor=='floor1'?'active':''; ?>">1st Floor</button></a>
        <a href="?floor=floor2"><button class="floor-btn <?php echo $current_floor=='floor2'?'active':''; ?>">2nd Floor</button></a>
        <a href="?floor=floor3"><button class="floor-btn <?php echo $current_floor=='floor3'?'active':''; ?>">3rd Floor</button></a>
    </div>

    <div id="map-wrapper">
        <img src="<?php echo $current_floor; ?>.jpg" id="map-image" alt="Floor Map">
        
        <?php foreach ($items as $item): ?>
            <div class="pin <?php echo ($item['item_type'] == 'lost' ? 'pin-lost' : 'pin-found'); ?>" 
                 style="left: <?php echo $item['x_pos']; ?>%; top: <?php echo $item['y_pos']; ?>%;"
                 onclick="showInfo('<?php echo addslashes($item['title']); ?>', '<?php echo addslashes($item['description']); ?>', '<?php echo addslashes($item['contact']); ?>', '<?php echo strtoupper($item['item_type']); ?>')">
            </div>
        <?php endforeach; ?>

        <div id="temp-pin" class="pin"></div>
    </div>

    <div id="detail-box">
        <button class="close-btn" onclick="closeInfo()">X</button>
        <h3 id="info-title"></h3>
        <p><strong>Status:</strong> <span id="info-type"></span></p>
        <p><strong>Details:</strong> <span id="info-desc"></span></p>
        <p><strong>Contact:</strong> <span id="info-contact"></span></p>
    </div>

    <div id="report-form">
        <h2>Report Item on <?php echo strtoupper($current_floor); ?></h2>
        <form method="POST">
            <input type="hidden" name="x_pos" id="form-x">
            <input type="hidden" name="y_pos" id="form-y">
            <input type="hidden" name="floor" value="<?php echo $current_floor; ?>">

            <input type="text" name="title" placeholder="What is the item?" required>
            <textarea name="description" placeholder="Provide details (color, brand, specific spot)"></textarea>
            <select name="item_type">
                <option value="lost">I Lost This</option>
                <option value="found">I Found This</option>
            </select>
            <input type="text" name="contact" placeholder="Your Phone/Email" required>
            <button type="submit" name="save_item" class="save-btn">Submit Report</button>
        </form>
    </div>
</div>

<script>
    const mapImage = document.getElementById('map-image');
    const tempPin = document.getElementById('temp-pin');
    const reportForm = document.getElementById('report-form');

    // Click to place a pin
    mapImage.addEventListener('click', function(e) {
        const rect = mapImage.getBoundingClientRect();
        const x = ((e.clientX - rect.left) / rect.width) * 100;
        const y = ((e.clientY - rect.top) / rect.height) * 100;

        tempPin.style.left = x + "%";
        tempPin.style.top = y + "%";
        tempPin.style.display = "block";

        document.getElementById('form-x').value = x;
        document.getElementById('form-y').value = y;
        reportForm.style.display = "block";
        reportForm.scrollIntoView({ behavior: 'smooth' });
    });

    // Function to show item details in popup
    function showInfo(title, desc, contact, type) {
        document.getElementById('info-title').innerText = title;
        document.getElementById('info-type').innerText = type;
        document.getElementById('info-desc').innerText = desc;
        document.getElementById('info-contact').innerText = contact;
        document.getElementById('detail-box').style.display = "block";
    }

    function closeInfo() {
        document.getElementById('detail-box').style.display = "none";
    }
</script>

</body>
</html>