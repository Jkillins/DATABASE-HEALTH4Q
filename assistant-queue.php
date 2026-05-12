    <?php
    /**
     * assistant-queue.php
     * Appointment Management - Full live queue management, patient tracking, and
     * appointment status updates. Core tool for managing patient flow through clinic.
     */

    require_once 'config.php';
    requireRole(ROLE_ASSISTANT);

    $pdo = getPDO();
    $assistant_id = getCurrentRoleId();

    $today = date('Y-m-d');

    // Handle queue status updates
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
        $queue_id = (int)$_POST['queue_id'];
        $new_status = sanitize($_POST['status']);
        
        if (in_array($new_status, ['waiting', 'called', 'in-progress', 'completed', 'canceled'])) {
            try {
                $update_data = [];
                if ($new_status === 'called') {
                    $update_data = ['status' => $new_status, 'called_time' => date('Y-m-d H:i:s')];
                } elseif ($new_status === 'in-progress') {
                    $update_data = ['status' => $new_status, 'seen_time' => date('Y-m-d H:i:s')];
                } elseif ($new_status === 'completed') {
                    $update_data = ['status' => $new_status, 'check_out_time' => date('Y-m-d H:i:s')];
                } else {
                    $update_data = ['status' => $new_status];
                }

                $set_clause = implode(', ', array_map(fn($k) => "$k = ?", array_keys($update_data)));
                $values = array_values($update_data);
                $values[] = $queue_id;

                $stmt = $pdo->prepare("UPDATE patient_queue SET $set_clause WHERE queue_id = ?");
                $stmt->execute($values);
            } catch (Exception $e) {}
        }
    }

    // Get full queue for today
    $stmt = $pdo->prepare('
        SELECT pq.*, 
            u_p.first_name as patient_fname, u_p.last_name as patient_lname, u_p.contact_no,
            u_d.first_name as doctor_fname, u_d.last_name as doctor_lname,
            vt.name as visit_type
        FROM patient_queue pq
        JOIN patient p ON pq.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        JOIN doctor d ON pq.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        LEFT JOIN visit_type vt ON pq.appointment_id IN (SELECT appointment_id FROM appointment WHERE patient_id = pq.patient_id)
        WHERE DATE(pq.check_in_time) = ?
        ORDER BY pq.queue_position ASC
    ');
    $stmt->execute([$today]);
    $queue = $stmt->fetchAll();

    // Stats
    $waiting_count = count(array_filter($queue, fn($q) => $q['status'] === 'waiting'));
    $called_count = count(array_filter($queue, fn($q) => $q['status'] === 'called'));
    $in_progress_count = count(array_filter($queue, fn($q) => $q['status'] === 'in-progress'));
    $completed_count = count(array_filter($queue, fn($q) => $q['status'] === 'completed'));
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Live Queue | Health4Q</title>
        <link rel="icon" type="image/png" href="images/Logo_only.png">
        <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            :root {
                --primary-green: #1a4d34;
                --accent-green: #2d6a4f;
                --light-bg: #c5e6e1;
                --white: #ffffff;
                --text-dark: #1b4332;
                --text-light: #666;
                --border-color: #d0e8e0;
                --danger: #d90429;
                --warning: #f77f00;
                --info: #4a7c2c;
            }

            * { margin: 0; padding: 0; box-sizing: border-box; }

            body {
                font-family: 'Quicksand', sans-serif;
                background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
                min-height: 100vh;
                color: var(--text-dark);
            }

            .top-nav {
                background: var(--primary-green);
                padding: 12px 5%;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            }

            .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
            .nav-links { display: flex; gap: 12px; }
            .nav-links a {
                color: white;
                text-decoration: none;
                font-size: 13px;
                font-weight: 500;
                padding: 8px 15px;
                border-radius: 8px;
                background: rgba(255,255,255,0.1);
                transition: 0.3s;
            }
            .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

            .logout-btn {
                background: var(--danger);
                color: white;
                padding: 8px 16px;
                border-radius: 5px;
                text-decoration: none;
                font-weight: bold;
                font-size: 12px;
            }

            .container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 30px 20px;
            }

            h1 {
                font-size: 28px;
                color: var(--primary-green);
                margin-bottom: 25px;
            }

            .stats-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
                margin-bottom: 25px;
            }

            .stat-box {
                background: var(--white);
                padding: 15px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            .stat-number {
                font-size: 24px;
                font-weight: 700;
                color: var(--accent-green);
            }

            .stat-label {
                font-size: 12px;
                color: var(--text-light);
                margin-top: 5px;
            }

            .queue-container {
                background: var(--white);
                border-radius: 12px;
                overflow-x: auto;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }

            table {
                width: 100%;
                border-collapse: collapse;
            }

            thead {
                background: var(--primary-green);
                color: white;
            }

            th {
                padding: 12px 15px;
                text-align: left;
                font-weight: 700;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            td {
                padding: 12px 15px;
                border-bottom: 1px solid var(--border-color);
                font-size: 13px;
            }

            tbody tr:hover { background: #f9f9f9; }

            .patient-name {
                font-weight: 600;
                color: var(--primary-green);
            }

            .status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
            }

            .status-waiting { background: #fff3cd; color: #856404; }
            .status-called { background: #cfe2ff; color: #084298; }
            .status-in-progress { background: #d1ecf1; color: #0c5460; }
            .status-completed { background: #d4edda; color: #155724; }
            .status-canceled { background: #f8d7da; color: #721c24; }

            .status-select {
                padding: 6px;
                border: 1px solid var(--border-color);
                border-radius: 6px;
                font-family: 'Quicksand', sans-serif;
                font-size: 12px;
                cursor: pointer;
            }

            .action-btn {
                padding: 6px 12px;
                background: var(--accent-green);
                color: white;
                border: none;
                border-radius: 6px;
                font-weight: 600;
                font-size: 12px;
                cursor: pointer;
                transition: 0.3s;
            }

            .action-btn:hover { background: var(--primary-green); }

            .empty-state {
                padding: 40px;
                text-align: center;
                color: var(--text-light);
            }

            @media (max-width: 768px) {
                .stats-row { grid-template-columns: repeat(2, 1fr); }
                table { font-size: 12px; }
                th, td { padding: 8px 10px; }
            }
        </style>
    </head>
    <body>
        <div class="top-nav">
            <div class="nav-brand">
                <img src="images/Logo_only.png" alt="Health4Q">
            </div>
            <div class="nav-links">
                <a href="assistant-dashboard.php">Overview</a>
                <a href="assistant-queue.php" class="active">📋 Live Queue</a>
                <a href="assistant-broadcast.php">📢 Alerts</a>
                <a href="assistant-referral.php">📤 Referrals</a>
                <a href="assistant-patient-search.php">🔍 Search</a>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>

        <div class="container">
            <h1>📋 Live Queue Management</h1>

            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $waiting_count; ?></div>
                    <div class="stat-label">⏳ Waiting</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $called_count; ?></div>
                    <div class="stat-label">📢 Called</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $in_progress_count; ?></div>
                    <div class="stat-label">🔄 In Progress</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo $completed_count; ?></div>
                    <div class="stat-label">✅ Completed</div>
                </div>
            </div>

            <div class="queue-container">
                <?php if (count($queue) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Patient Name</th>
                                <th>Contact</th>
                                <th>Doctor</th>
                                <th>Check-In</th>
                                <th>Current Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($queue as $index => $patient): ?>
                                <tr>
                                    <td><strong><?php echo $index + 1; ?></strong></td>
                                    <td>
                                        <div class="patient-name"><?php echo htmlspecialchars($patient['patient_fname'] . ' ' . $patient['patient_lname']); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($patient['contact_no'] ?? 'N/A'); ?></td>
                                    <td>Dr. <?php echo htmlspecialchars($patient['doctor_lname']); ?></td>
                                    <td><?php echo date('H:i', strtotime($patient['check_in_time'])); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($patient['status']); ?>">
                                            <?php echo ucfirst($patient['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: flex; gap: 5px;">
                                            <input type="hidden" name="queue_id" value="<?php echo $patient['queue_id']; ?>">
                                            <select name="status" class="status-select">
                                                <option value="waiting" <?php echo $patient['status'] === 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                                                <option value="called" <?php echo $patient['status'] === 'called' ? 'selected' : ''; ?>>Called</option>
                                                <option value="in-progress" <?php echo $patient['status'] === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                                                <option value="completed" <?php echo $patient['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                <option value="canceled" <?php echo $patient['status'] === 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                            </select>
                                            <button type="submit" name="update_status" class="action-btn">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No patients in queue today.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
