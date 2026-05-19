<?php
/**
 * assistant-inventory.php - PREMIUM CLINICAL INVENTORY & MEDICINE REGISTRY MANAGEMENT
 */

require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$assistant_id = $_SESSION['user_id'] ?? 0;

$success_msg = '';
$error_msg = '';

// --- 1. HANDLE STOCK ADJUSTMENTS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_adjustment'])) {
    $id = (int)$_POST['id'];
    $item_type = sanitize($_POST['item_type'] ?? 'supply');
    $change_amount = (int)$_POST['change_amount'];
    $reason = sanitize($_POST['reason'] ?? 'Manual Adjustment');

    try {
        $pdo->beginTransaction();
        
        if ($item_type === 'medicine') {
            // Update stock in medicine table
            $stmt = $pdo->prepare("UPDATE medicine SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE med_id = ?");
            $stmt->execute([$change_amount, $id]);
        } else {
            // Update stock in inventory table (clinical supply)
            $stmt = $pdo->prepare("UPDATE inventory SET stock_level = GREATEST(0, stock_level + ?) WHERE id = ?");
            $stmt->execute([$change_amount, $id]);
        }

        $pdo->commit();
        $success_msg = "✓ Stock level successfully adjusted.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "✗ Error: Could not adjust stock level: " . $e->getMessage();
    }
}

// --- 2. HANDLE ADD NEW SUPPLY OR MEDICINE SKU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_item'])) {
    $register_type = sanitize($_POST['register_type'] ?? 'supply');
    
    try {
        if ($register_type === 'medicine') {
            $name = sanitize($_POST['med_name'] ?? '');
            $strength = sanitize($_POST['strength'] ?? '');
            $form = sanitize($_POST['form'] ?? '');
            $description = sanitize($_POST['description'] ?? '');
            $stock_quantity = (int)($_POST['stock_quantity'] ?? 0);
            
            if (empty($name) || empty($strength) || empty($form)) {
                throw new Exception("Medicine Name, Strength, and Form are required fields.");
            }
            
            $stmt = $pdo->prepare("INSERT INTO medicine (name, strength, form, description, stock_quantity) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $strength, $form, $description, $stock_quantity]);
            $success_msg = "✓ Successfully registered new medicine '{$name} {$strength} ({$form})' in the registry!";
        } else {
            $item_name = sanitize($_POST['item_name'] ?? '');
            $stock_level = (int)($_POST['stock_level'] ?? 0);
            $reorder_level = (int)($_POST['reorder_level'] ?? 10);
            
            if (empty($item_name)) {
                throw new Exception("Clinical Supply Name cannot be empty.");
            }
            
            $stmt = $pdo->prepare("INSERT INTO inventory (item_name, stock_level, reorder_level) VALUES (?, ?, ?)");
            $stmt->execute([$item_name, $stock_level, $reorder_level]);
            $success_msg = "✓ Successfully registered new clinical stock supply '{$item_name}'!";
        }
    } catch (Exception $e) {
        $error_msg = "✗ Error: " . $e->getMessage();
    }
}

// --- 3. FETCH LEDGER DATA ---
$supplies = $pdo->query("SELECT * FROM inventory ORDER BY item_name ASC")->fetchAll();
$medicines = $pdo->query("SELECT * FROM medicine ORDER BY name ASC")->fetchAll();

// Advanced Stats
$total_supplies_skus = count($supplies);
$total_medicines_skus = count($medicines);

$low_supplies_count = count(array_filter($supplies, fn($s) => $s['stock_level'] <= $s['reorder_level']));
$low_medicines_count = count(array_filter($medicines, fn($m) => $m['stock_quantity'] <= 10));
$total_critical_stock = $low_supplies_count + $low_medicines_count;

$out_of_stock_supplies = count(array_filter($supplies, fn($s) => $s['stock_level'] <= 0));
$out_of_stock_medicines = count(array_filter($medicines, fn($m) => $m['stock_quantity'] <= 0));
$total_out_of_stock = $out_of_stock_supplies + $out_of_stock_medicines;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinical Inventory Control | Health4Q+</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Unified Mint & Forest Green Theme */
        :root {
            --bg-mint: #d8f3dc;
            --header-green: #1b4332;
            --accent-green: #2d6a4f;
            --white: #ffffff;
            --logout-red: #d90429;
            --text-dark: #1b4332;
            --light-mint: #e8f5e9;
            --danger: #d90429;
            --warning: #ffb703;
            --success: #2a9d8f;
            --border: #d0e8e0;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Quicksand', sans-serif; background-color: var(--bg-mint); color: var(--text-dark); min-height: 100vh; }

        /* Navigation Bar */
        .navbar {
            background-color: var(--header-green);
            padding: 10px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links { display: flex; gap: 10px; }
        .nav-links a {
            color: var(--white);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            transition: 0.3s;
            background: rgba(255,255,255,0.08);
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }
        .btn-logout { background: var(--logout-red) !important; font-weight: 700 !important; }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        /* Stat Cards Dashboard */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { 
            background: var(--white); 
            padding: 25px; 
            border-radius: 15px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            border-left: 6px solid var(--accent-green); 
        }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-val { font-size: 32px; font-weight: 700; display: block; margin-top: 5px; color: var(--header-green); }
        .stat-label { color: #555; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }

        /* Premium Tab System */
        .tabs-header { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 2px solid rgba(45, 106, 79, 0.15); padding-bottom: 10px; }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            color: #555;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        .tab-btn:hover { background: rgba(45, 106, 79, 0.08); color: var(--header-green); }
        .tab-btn.active { background: var(--header-green); color: var(--white); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* High-Fidelity Data Cards */
        .data-card {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid rgba(255,255,255,0.4);
            margin-bottom: 30px;
        }

        .toolbar { padding-bottom: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .toolbar h3 { font-size: 1.3rem; color: var(--header-green); font-weight: 700; }
        .search-box { padding: 10px 15px; border: 1px solid var(--border); border-radius: 8px; width: 300px; outline: none; font-family: inherit; font-size: 13px; }
        
        /* Clinical Tables */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 20px; font-size: 11px; font-weight: 800; color: #6c757d; border-bottom: 2px solid var(--border); text-transform: uppercase; }
        td { padding: 18px 20px; border-bottom: 1px solid #f1f3f5; font-size: 14px; font-weight: 600; }
        tr:hover { background: var(--light-mint); }

        /* Status Pills */
        .pill { padding: 5px 12px; border-radius: 20px; font-size: 10px; font-weight: 700; text-transform: uppercase; display: inline-block; }
        .pill-success { background: #e6f4f1; color: var(--success); }
        .pill-warning { background: #fff9db; color: #947100; animation: pulse 2s infinite; }
        .pill-danger { background: #ffe3e3; color: var(--danger); }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }

        .btn-action { 
            background: var(--accent-green); 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 8px; 
            cursor: pointer; 
            font-family: inherit; 
            font-size: 13px; 
            font-weight: 700;
            transition: all 0.2s ease; 
        }
        .btn-action:hover { background: var(--header-green); transform: translateY(-1px); }

        /* Modal Layout */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 450px; margin: 12% auto; border-radius: 15px; padding: 30px; position: relative; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #aaa; transition: 0.2s; }
        .close:hover { color: var(--danger); }

        /* Forms styling */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 13px; color: var(--header-green); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-family: inherit; outline: none; transition: 0.3s; font-size: 14px; font-weight: 600; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--accent-green); box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1); }

        /* Alert notifications */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: center;
        }
        .alert-success { background: #b7e4c7; color: #1b4332; border: 1px solid #95d5b2; }
        .alert-error { background: #ffccd5; color: #a4133c; border: 1px solid #ffb3c1; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; gap: 15px; align-items: stretch; }
            .search-box { width: 100%; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="assistant-dashboard.php">Dashboard</a>
            <a href="assistant-queue.php">Live Queue</a>
            <a href="assistant-inventory.php" class="active">Inventory Control</a>
            <a href="assistant-broadcast.php">Clinic Alerts</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if($success_msg): ?> <div class="alert alert-success"><?= $success_msg ?></div> <?php endif; ?>
        <?php if($error_msg): ?> <div class="alert alert-error"><?= $error_msg ?></div> <?php endif; ?>

        <!-- Stats dashboard grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Clinical Supplies / Medicine SKUs</span>
                <span class="stat-val"><?= $total_supplies_skus; ?> / <?= $total_medicines_skus; ?></span>
            </div>
            <div class="stat-card warning">
                <span class="stat-label">Critical Stock Alerts</span>
                <span class="stat-val"><?= $total_critical_stock; ?> items</span>
            </div>
            <div class="stat-card danger">
                <span class="stat-label">Stockout / Zero Stock</span>
                <span class="stat-val"><?= $total_out_of_stock; ?> items</span>
            </div>
        </div>

        <!-- Dynamic Tabs Header -->
        <div class="tabs-header">
            <button class="tab-btn active" id="btn-suppliesTab" onclick="switchTab('suppliesTab')">📦 Clinical Supplies</button>
            <button class="tab-btn" id="btn-medicinesTab" onclick="switchTab('medicinesTab')">💊 Medicine Registry</button>
            <button class="tab-btn" id="btn-registerTab" onclick="switchTab('registerTab')">➕ Register New Item</button>
        </div>

        <!-- TAB 1: CLINICAL SUPPLIES LEDGER -->
        <div id="suppliesTab" class="tab-content active">
            <div class="data-card">
                <div class="toolbar">
                    <h3>Clinical Supplies Stock Ledger</h3>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button class="btn-action" style="background:#57606f;" onclick="window.print()">Export supplies</button>
                        <input type="text" id="suppliesSearch" class="search-box" placeholder="Search clinical supplies...">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Item ID</th>
                                <th>Clinical Supply Item</th>
                                <th>Current Stock Level</th>
                                <th>Reorder Threshold</th>
                                <th>Stock Status</th>
                                <th style="text-align: right;">Management</th>
                            </tr>
                        </thead>
                        <tbody id="suppliesTable">
                            <?php foreach ($supplies as $item): 
                                $is_critical = $item['stock_level'] <= $item['reorder_level'];
                                $is_empty = $item['stock_level'] <= 0;
                            ?>
                            <tr>
                                <td style="color: #888; font-weight: 700;">#SUP-<?= $item['id']; ?></td>
                                <td><strong style="color: var(--header-green);"><?= htmlspecialchars($item['item_name']); ?></strong></td>
                                <td>
                                    <span style="font-size: 16px; font-weight: 800; color: <?= $is_empty ? 'var(--danger)' : ($is_critical ? 'var(--warning)' : 'var(--success)') ?>">
                                        <?= $item['stock_level']; ?>
                                    </span>
                                </td>
                                <td><span style="color: #555; font-weight: 600;">Threshold: <?= $item['reorder_level']; ?></span></td>
                                <td>
                                    <?php if($is_empty): ?>
                                        <span class="pill pill-danger">Stockout</span>
                                    <?php elseif($is_critical): ?>
                                        <span class="pill pill-warning">Low Stock</span>
                                    <?php else: ?>
                                        <span class="pill pill-success">Sufficient</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button class="btn-action" onclick="openAdjustmentModal(<?= $item['id']; ?>, 'supply', '<?= addslashes($item['item_name']); ?>', <?= $item['stock_level']; ?>)">
                                        Adjust Stock
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: MEDICINE REGISTRY LEDGER -->
        <div id="medicinesTab" class="tab-content">
            <div class="data-card">
                <div class="toolbar">
                    <h3>Medicine Registry List</h3>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <button class="btn-action" style="background:#57606f;" onclick="window.print()">Export registry</button>
                        <input type="text" id="medicinesSearch" class="search-box" placeholder="Search medicines...">
                    </div>
                </div>

                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Med ID</th>
                                <th>Medicine Details</th>
                                <th>Strength & Form</th>
                                <th>Current Stock Quantity</th>
                                <th>Status</th>
                                <th style="text-align: right;">Management</th>
                            </tr>
                        </thead>
                        <tbody id="medicinesTable">
                            <?php foreach ($medicines as $med): 
                                $is_critical = $med['stock_quantity'] <= 10;
                                $is_empty = $med['stock_quantity'] <= 0;
                            ?>
                            <tr>
                                <td style="color: #888; font-weight: 700;">#MED-<?= $med['med_id']; ?></td>
                                <td>
                                    <strong style="color: var(--header-green); display: block;"><?= htmlspecialchars($med['name']); ?></strong>
                                    <span style="font-size: 11px; color: #666; font-weight: 500;"><?= htmlspecialchars($med['description']); ?></span>
                                </td>
                                <td><span class="pill" style="background:#d8f3dc; color:#1b4332; font-weight:700;"><?= htmlspecialchars($med['strength']); ?> - <?= htmlspecialchars($med['form']); ?></span></td>
                                <td>
                                    <span style="font-size: 16px; font-weight: 800; color: <?= $is_empty ? 'var(--danger)' : ($is_critical ? 'var(--warning)' : 'var(--success)') ?>">
                                        <?= $med['stock_quantity']; ?> units
                                    </span>
                                </td>
                                <td>
                                    <?php if($is_empty): ?>
                                        <span class="pill pill-danger">Stockout</span>
                                    <?php elseif($is_critical): ?>
                                        <span class="pill pill-warning">Low Stock</span>
                                    <?php else: ?>
                                        <span class="pill pill-success">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button class="btn-action" onclick="openAdjustmentModal(<?= $med['med_id']; ?>, 'medicine', '<?= addslashes($med['name'] . " " . $med['strength']); ?>', <?= $med['stock_quantity']; ?>)">
                                        Adjust Stock
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: REGISTER FORM -->
        <div id="registerTab" class="tab-content">
            <div class="data-card" style="max-width: 600px; margin: 0 auto;">
                <h3 style="color: var(--header-green); margin-bottom: 25px; font-weight: 700; border-bottom: 1px solid var(--border); padding-bottom: 10px;">Register New Clinical Item</h3>
                
                <form method="POST">
                    <div class="form-group">
                        <label for="register_type">Register SKU Type</label>
                        <select name="register_type" id="register_type" onchange="toggleFormFields()" required>
                            <option value="supply" selected>📦 Clinical Medical Supply (syringes, gloves, antiseptics...)</option>
                            <option value="medicine">💊 Medicine Registry Item (Paracetamol, Amoxicillin...)</option>
                        </select>
                    </div>

                    <!-- Supply Fields -->
                    <div id="supplyFields">
                        <div class="form-group">
                            <label for="item_name">Supply Item Name</label>
                            <input type="text" name="item_name" id="item_name" placeholder="e.g. Sterile Syringes 5ml">
                        </div>

                        <div class="form-group">
                            <label for="stock_level">Initial Supply Stock Count</label>
                            <input type="number" name="stock_level" id="stock_level" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label for="reorder_level">Low Stock Alert Threshold (Reorder Level)</label>
                            <input type="number" name="reorder_level" id="reorder_level" min="1" value="10">
                        </div>
                    </div>

                    <!-- Medicine Fields -->
                    <div id="medicineFields" style="display:none;">
                        <div class="form-group">
                            <label for="med_name">Medicine Name</label>
                            <input type="text" name="med_name" id="med_name" placeholder="e.g. Amoxicillin">
                        </div>

                        <div class="form-group" style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                            <div>
                                <label for="strength">Strength / Dosage</label>
                                <input type="text" name="strength" id="strength" placeholder="e.g. 500mg">
                            </div>
                            <div>
                                <label for="form">Medicine Form</label>
                                <select name="form" id="form">
                                    <option value="Tablet" selected>Tablet</option>
                                    <option value="Capsule">Capsule</option>
                                    <option value="Syrup">Syrup</option>
                                    <option value="Suspension">Suspension</option>
                                    <option value="Injection">Injection</option>
                                    <option value="Ointment">Ointment</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="stock_quantity">Initial Medicine Stock Quantity</label>
                            <input type="number" name="stock_quantity" id="stock_quantity" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label for="description">Medicine Indications / Description</label>
                            <textarea name="description" id="description" rows="3" placeholder="e.g. Broad-spectrum penicillin antibiotic used to treat bacterial infections."></textarea>
                        </div>
                    </div>

                    <button type="submit" name="add_new_item" class="btn-action" style="width: 100%; padding: 14px; font-size: 15px;">
                        ➕ Register & Save to Inventory
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ADJUSTMENT MODAL -->
    <div id="adjModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3 id="modalTitle" style="margin-top: 0; color: var(--header-green); font-weight: 700;">Adjust Inventory</h3>
            <p id="modalCurrent" style="font-size: 13px; color: #555; font-weight: 600; margin-top: 5px;"></p>
            <hr style="border: 0; border-top: 1px solid var(--border); margin: 20px 0;">
            
            <form method="POST">
                <input type="hidden" name="id" id="modalItemId">
                <input type="hidden" name="item_type" id="modalItemType">
                
                <div class="form-group">
                    <label>Adjustment Action</label>
                    <select id="adjType" onchange="updateAmountSign()">
                        <option value="add">Restock / Addition (+)</option>
                        <option value="remove">Usage / Removal (-)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="qty_input" id="qtyInput" min="1" required placeholder="Enter amount">
                    <input type="hidden" name="change_amount" id="finalAmount">
                </div>

                <div class="form-group">
                    <label>Reason / Note</label>
                    <input type="text" name="reason" placeholder="e.g. Weekly Restock, Clinic Dispensing">
                </div>

                <button type="submit" name="process_adjustment" class="btn-action" style="width: 100%; padding: 12px; font-size: 15px;">
                    Finalize Stock Adjustment
                </button>
            </form>
        </div>
    </div>

    <script>
        // Tab Switcher with Memory persistence
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });

            document.getElementById(tabId).classList.add('active');
            
            const activeBtn = document.getElementById('btn-' + tabId);
            if (activeBtn) activeBtn.classList.add('active');
            
            localStorage.setItem('assistant_inventory_active_tab', tabId);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const preservedTab = localStorage.getItem('assistant_inventory_active_tab') || 'suppliesTab';
            switchTab(preservedTab);
            toggleFormFields();
        });

        // Search Filter for Clinical Supplies
        document.getElementById('suppliesSearch').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#suppliesTable tr');
            rows.forEach(row => {
                const text = row.querySelector('td:nth-child(2)').innerText.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Search Filter for Medicines
        document.getElementById('medicinesSearch').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const rows = document.querySelectorAll('#medicinesTable tr');
            rows.forEach(row => {
                const text = row.querySelector('td:nth-child(2)').innerText.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });

        // Switch Register Form Type Fields
        function toggleFormFields() {
            const type = document.getElementById('register_type').value;
            const suppliesDiv = document.getElementById('supplyFields');
            const medicinesDiv = document.getElementById('medicineFields');
            
            if (type === 'medicine') {
                suppliesDiv.style.display = 'none';
                medicinesDiv.style.display = 'block';
                
                document.getElementById('item_name').removeAttribute('required');
                document.getElementById('stock_level').removeAttribute('required');
                document.getElementById('reorder_level').removeAttribute('required');
                
                document.getElementById('med_name').setAttribute('required', 'required');
                document.getElementById('strength').setAttribute('required', 'required');
                document.getElementById('form').setAttribute('required', 'required');
                document.getElementById('stock_quantity').setAttribute('required', 'required');
            } else {
                suppliesDiv.style.display = 'block';
                medicinesDiv.style.display = 'none';
                
                document.getElementById('item_name').setAttribute('required', 'required');
                document.getElementById('stock_level').setAttribute('required', 'required');
                document.getElementById('reorder_level').setAttribute('required', 'required');
                
                document.getElementById('med_name').removeAttribute('required');
                document.getElementById('strength').removeAttribute('required');
                document.getElementById('form').removeAttribute('required');
                document.getElementById('stock_quantity').removeAttribute('required');
            }
        }

        // Modal Controls
        const modal = document.getElementById('adjModal');
        
        function openAdjustmentModal(id, type, name, current) {
            document.getElementById('modalItemId').value = id;
            document.getElementById('modalItemType').value = type;
            document.getElementById('modalTitle').innerText = 'Adjust Stock: ' + name;
            document.getElementById('modalCurrent').innerText = 'Current stock count: ' + current;
            modal.style.display = 'block';
            updateAmountSign();
        }

        function closeModal() { modal.style.display = 'none'; }

        // Logic to handle positive/negative adjustment
        function updateAmountSign() {
            const type = document.getElementById('adjType').value;
            const val = document.getElementById('qtyInput').value;
            document.getElementById('finalAmount').value = (type === 'remove') ? (val * -1) : val;
        }

        document.getElementById('qtyInput').addEventListener('input', updateAmountSign);
        document.getElementById('adjType').addEventListener('change', updateAmountSign);

        window.onclick = function(event) { if (event.target == modal) closeModal(); }
    </script>

</body>
</html>