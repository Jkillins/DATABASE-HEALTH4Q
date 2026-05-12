<?php
/**
 * db.php - PDO Connection & Database Helper Functions
 * Health4Q - Normalized Schema Support
 */

// ============ DATABASE CONNECTION ============
function getPDO() {
    // Using 127.0.0.1 instead of localhost often resolves "actively refused" errors
    $host = '127.0.0.1'; 
    $db   = 'health4q'; // Ensure this matches your DB name exactly in phpMyAdmin
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    try {
        return new PDO($dsn, $user, $pass, $opts);
    } catch (PDOException $e) {
        // If it's a web request, return JSON; otherwise, just die with the error
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        die(json_encode([
            'error' => 'Database connection failed', 
            'details' => $e->getMessage(),
            'hint' => 'Check if MySQL is STARTED in your XAMPP/WAMP Control Panel.'
        ]));
    }
}

// ============ JSON RESPONSE HELPER ============
function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// ============ USER LOOKUPS ============

function getPatientIdByUserId(PDO $pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['patient_id'] : null;
}

function getDoctorIdByUserId(PDO $pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT doctor_id FROM doctor WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['doctor_id'] : null;
}

function getAssistantIdByUserId(PDO $pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT assistant_id FROM clinical_assistant WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['assistant_id'] : null;
}

// ============ USER PROFILE RETRIEVAL ============

function getUserById(PDO $pdo, $user_id) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ? AND is_active = 1');
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

function getUserByEmail(PDO $pdo, $email) {
    // Used in register.php to check for existing accounts
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    return $stmt->fetch();
}

function getPatientProfile(PDO $pdo, $patient_id) {
    $stmt = $pdo->prepare(
        'SELECT u.*, p.*, a.zipcode, a.barangay, a.city, a.province
         FROM patient p
         JOIN users u ON p.user_id = u.user_id
         LEFT JOIN address a ON u.user_id = a.user_id
         WHERE p.patient_id = ?'
    );
    $stmt->execute([$patient_id]);
    return $stmt->fetch();
}

function getDoctorProfile(PDO $pdo, $doctor_id) {
    $stmt = $pdo->prepare(
        'SELECT u.*, d.* FROM doctor d
         JOIN users u ON d.user_id = u.user_id
         WHERE d.doctor_id = ?'
    );
    $stmt->execute([$doctor_id]);
    return $stmt->fetch();
}

function getAssistantProfile(PDO $pdo, $assistant_id) {
    $stmt = $pdo->prepare(
        'SELECT u.*, ca.* FROM clinical_assistant ca
         JOIN users u ON ca.user_id = u.user_id
         WHERE ca.assistant_id = ?'
    );
    $stmt->execute([$assistant_id]);
    return $stmt->fetch();
}

// ============ APPOINTMENT QUERIES ============

function getPatientAppointments(PDO $pdo, $patient_id, $limit = 10) {
    $stmt = $pdo->prepare(
        'SELECT a.*, 
                u_doc.first_name as doctor_first_name, u_doc.last_name as doctor_last_name,
                d.license_no,
                vt.name as visit_type_name,
                u_pat.first_name as patient_first_name, u_pat.last_name as patient_last_name
         FROM appointment a
         JOIN patient pat ON a.patient_id = pat.patient_id
         JOIN doctor doc ON a.doctor_id = doc.doctor_id
         JOIN users u_pat ON pat.user_id = u_pat.user_id
         JOIN users u_doc ON doc.user_id = u_doc.user_id
         JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
         WHERE a.patient_id = ?
         ORDER BY a.schedule_start DESC
         LIMIT ?'
    );
    $stmt->execute([$patient_id, $limit]);
    return $stmt->fetchAll();
}

function getDoctorAppointments(PDO $pdo, $doctor_id, $limit = 10) {
    $stmt = $pdo->prepare(
        'SELECT a.*, 
                u_doc.first_name as doctor_first_name, u_doc.last_name as doctor_last_name,
                u_pat.first_name as patient_first_name, u_pat.last_name as patient_last_name,
                vt.name as visit_type_name
         FROM appointment a
         JOIN patient pat ON a.patient_id = pat.patient_id
         JOIN doctor doc ON a.doctor_id = doc.doctor_id
         JOIN users u_pat ON pat.user_id = u_pat.user_id
         JOIN users u_doc ON doc.user_id = u_doc.user_id
         JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
         WHERE a.doctor_id = ?
         ORDER BY a.schedule_start DESC
         LIMIT ?'
    );
    $stmt->execute([$doctor_id, $limit]);
    return $stmt->fetchAll();
}

// ============ MEDICAL RECORDS QUERIES ============

function getPatientMedicalRecords(PDO $pdo, $patient_id, $limit = 10) {
    $stmt = $pdo->prepare(
        'SELECT mr.*, 
                u_doc.first_name as doctor_first_name, u_doc.last_name as doctor_last_name,
                a.schedule_start, a.schedule_end,
                vt.name as visit_type_name
         FROM medical_record mr
         JOIN appointment a ON mr.appointment_id = a.appointment_id
         JOIN doctor doc ON a.doctor_id = doc.doctor_id
         JOIN users u_doc ON doc.user_id = u_doc.user_id
         JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
         WHERE mr.patient_id = ?
         ORDER BY mr.date_time DESC
         LIMIT ?'
    );
    $stmt->execute([$patient_id, $limit]);
    return $stmt->fetchAll();
}

function getDoctorMedicalRecords(PDO $pdo, $doctor_id, $limit = 10) {
    $stmt = $pdo->prepare(
        'SELECT mr.*, 
                u_pat.first_name as patient_first_name, u_pat.last_name as patient_last_name,
                a.schedule_start, a.schedule_end,
                vt.name as visit_type_name
         FROM medical_record mr
         JOIN appointment a ON mr.appointment_id = a.appointment_id
         JOIN patient pat ON a.patient_id = pat.patient_id
         JOIN users u_pat ON pat.user_id = u_pat.user_id
         JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
         WHERE a.doctor_id = ?
         ORDER BY mr.date_time DESC
         LIMIT ?'
    );
    $stmt->execute([$doctor_id, $limit]);
    return $stmt->fetchAll();
}

// ============ DIRECTORY QUERIES ============

function getAllDoctors(PDO $pdo) {
    $stmt = $pdo->prepare(
        'SELECT u.*, d.* FROM doctor d
         JOIN users u ON d.user_id = u.user_id
         WHERE u.is_active = 1
         ORDER BY u.last_name, u.first_name'
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

function getAllPatients(PDO $pdo) {
    $stmt = $pdo->prepare(
        'SELECT u.*, p.* FROM patient p
         JOIN users u ON p.user_id = u.user_id
         WHERE u.is_active = 1
         ORDER BY u.last_name, u.first_name'
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

// ============ REFERRAL QUERIES ============

function getReferralsIssuedByDoctor(PDO $pdo, $doctor_id) {
    $stmt = $pdo->prepare(
        'SELECT r.*, 
                mr.diagnosis, mr.treatment_summary,
                u_doc.first_name as from_doctor_first, u_doc.last_name as from_doctor_last,
                u_pat.first_name as patient_first_name, u_pat.last_name as patient_last_name
         FROM referral r
         JOIN medical_record mr ON r.record_id = mr.record_id
         JOIN appointment a ON mr.appointment_id = a.appointment_id
         JOIN doctor doc ON a.doctor_id = doc.doctor_id
         JOIN users u_doc ON doc.user_id = u_doc.user_id
         JOIN patient pat ON a.patient_id = pat.patient_id
         JOIN users u_pat ON pat.user_id = u_pat.user_id
         WHERE a.doctor_id = ?
         ORDER BY r.issued_at DESC'
    );
    $stmt->execute([$doctor_id]);
    return $stmt->fetchAll();
}

?>