<?php
/**
 * assistant-inventory.php - Professional Edition
 * Integrated with columns: id, item_name, stock_level, reorder_level
 */

require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$assistant_id = $_SESSION['user_id'] ?? 0;

// Handle Stock Adjustments with Audit Trail logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_adjustment'])) {
    $id = (int)$_POST['id'];
    $change_amount = (int)$_POST['change_amount'];
    $reason = sanitize($_POST['reason'] ?? 'Manual Adjustment');

    try {
        $pdo->beginTransaction();
        
        // Update stock
        $stmt = $pdo->prepare("UPDATE inventory SET stock_level = stock_level + ? WHERE id = ?");
        $stmt->execute([$change_amount, $id]);

        // Optional: Professional Audit Log (Requires an inventory_logs table)
        /*
        $logStmt = $pdo->prepare("INSERT INTO inventory_logs (item_id, user_id, change_qty, reason) VALUES (?, ?, ?, ?)");
        $logStmt->execute([$id, $assistant_id, $change_amount, $reason]);
        */

        $pdo->commit();
        $success_msg = "Inventory updated successfully.";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = "Error: Could not update inventory.";
    }
}

// Fetch Inventory Data
$inventory = $pdo->query("SELECT * FROM inventory ORDER BY item_name ASC")->fetchAll();

// Advanced Stats
$total_skus = count($inventory);
$critical_stock = count(array_filter($inventory, fn($i) => $i['stock_level'] <= $i['reorder_level']));
$out_of_stock = count(array_filter($inventory, fn($i) => $i['stock_level'] <= 0));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Professional Inventory | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --secondary: #2d6a4f;
            --bg-light: #f4f9f7;
            --white: #ffffff;
            --danger: #d90429;
            --warning: #ffb703;
            --success: #2a9d8f;
            --border: #e0e7e3;
        }

        body { font-family: 'Quicksand', sans-serif; background: var(--bg-light); color: #2d3436; margin: 0; }

        /* Top Bar */
        .header { background: var(--primary); color: white; padding: 1rem 5%; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }    
        .nav-links a { color: rgba(255,255,255,0.8); text-decoration: none; margin-left: 20px; font-weight: 500; font-size: 14px; }
        .nav-links a.active { color: white; border-bottom: 2px solid white; padding-bottom: 5px; }

        .main-container { max-width: 1300px; margin: 2rem auto; padding: 0 20px; }

        /* Dashboard Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); border-left: 5px solid var(--primary); }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.danger { border-left-color: var(--danger); }
        .stat-val { font-size: 32px; font-weight: 700; display: block; margin-top: 5px; }
        .stat-label { color: #636e72; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; }

        /* Professional Table UI */
        .inventory-wrapper { background: white; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; }
        .toolbar { padding: 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: #fff; }
        .search-box { padding: 10px 15px; border: 1px solid var(--border); border-radius: 8px; width: 300px; outline: none; }
        
        table { width: 100%; border-collapse: collapse; }
        th { background: #fcfdfc; padding: 15px 20px; text-align: left; font-size: 13px; font-weight: 600; color: #57606f; border-bottom: 2px solid var(--border); }
        td { padding: 18px 20px; border-bottom: 1px solid var(--border); font-size: 14px; }
        tr:hover { background: #f8fbfa; }

        /* Status Pills */
        .pill { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .pill-success { background: #e6f4f1; color: var(--success); }
        .pill-warning { background: #fff9db; color: #947100; animation: pulse 2s infinite; }
        .pill-danger { background: #ffe3e3; color: var(--danger); }

        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }

        .btn-action { background: var(--secondary); color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-family: inherit; font-size: 13px; transition: 0.3s; }
        .btn-action:hover { background: var(--primary); transform: translateY(-1px); }

        /* Modal Logic */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 400px; margin: 10% auto; border-radius: 12px; padding: 30px; position: relative; }
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #aaa; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; box-sizing: border-box; }
    </style>
</head>
<body>

<div class="header">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
    <div class="nav-links">
        <a href="assistant-dashboard.php">Dashboard</a>
        <a href="assistant-queue.php">Live Queue</a>
        <a href="assistant-inventory.php" class="active">Inventory Control</a>
        <a href="logout.php" style="color: var(--danger);">Logout</a>
    </div>
</div>

<div class="main-container">
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-label">Total SKUs</span>
            <span class="stat-val"><?php echo $total_skus; ?></span>
        </div>
        <div class="stat-card warning">
            <span class="stat-label">Restock Required</span>
            <span class="stat-val"><?php echo $critical_stock; ?></span>
        </div>
        <div class="stat-card danger">
            <span class="stat-label">Out of Stock</span>
            <span class="stat-val"><?php echo $out_of_stock; ?></span>
        </div>
    </div>

    <div class="inventory-wrapper">
        <div class="toolbar">
            <h3 style="margin:0;">Stock Master List</h3>
            <div>
                <button class="btn-action" style="background:#57606f; margin-right: 10px;" onclick="window.print()">Export Report</button>
                <input type="text" id="invSearch" class="search-box" placeholder="Search by item name...">
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item ID</th>
                    <th>Medical Supply Item</th>
                    <th>Current Stock Level</th>
                    <th>Reorder Threshold</th>
                    <th>Stock Status</th>
                    <th style="text-align: right;">Management</th>
                </tr>
            </thead>
            <tbody id="invTable">
                <?php foreach ($inventory as $item): 
                    $is_critical = $item['stock_level'] <= $item['reorder_level'];
                    $is_empty = $item['stock_level'] <= 0;
                ?>
                <tr>
                    <td style="color: #888; font-weight: 600;">#<?php echo $item['id']; ?></td>
                    <td><strong style="color: var(--primary);"><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                    <td>
                        <span style="font-size: 16px; font-weight: 700;">
                            <?php echo $item['stock_level']; ?>
                        </span>
                    </td>
                    <td><span style="color: #636e72;">Threshold: <?php echo $item['reorder_level']; ?></span></td>
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
                        <button class="btn-action" onclick="openAdjustmentModal(<?php echo $item['id']; ?>, '<?php echo addslashes($item['item_name']); ?>', <?php echo $item['stock_level']; ?>)">
                            Adjust Stock
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADJUSTMENT MODAL -->
<div id="adjModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal()">&times;</span>
        <h3 id="modalTitle" style="margin-top: 0; color: var(--primary);">Adjust Inventory</h3>
        <p id="modalCurrent" style="font-size: 13px; color: #666;"></p>
        <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">
        
        <form method="POST">
            <input type="hidden" name="id" id="modalItemId">
            
            <div class="form-group">
                <label>Adjustment Type</label>
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
                <input type="text" name="reason" placeholder="e.g. Weekly Restock, Clinic Use">
            </div>

            <button type="submit" name="process_adjustment" class="btn-action" style="width: 100%; padding: 12px; font-size: 15px;">
                Finalize Adjustment
            </button>
        </form>
    </div>
</div>

<script>
    // Search Filter
    document.getElementById('invSearch').addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('#invTable tr');
        rows.forEach(row => {
            const text = row.querySelector('td:nth-child(2)').innerText.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    // Modal Controls
    const modal = document.getElementById('adjModal');
    
    function openAdjustmentModal(id, name, current) {
        document.getElementById('modalItemId').value = id;
        document.getElementById('modalTitle').innerText = 'Adjust: ' + name;
        document.getElementById('modalCurrent').innerText = 'Current physical count: ' + current;
        modal.style.display = 'block';
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