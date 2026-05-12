SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS session_activity_log;
DROP TABLE IF EXISTS audit_log;
DROP TABLE IF EXISTS vital_signs;
DROP TABLE IF EXISTS patient_allergy;
DROP TABLE IF EXISTS patient_insurance;
DROP TABLE IF EXISTS prescription_item;
DROP TABLE IF EXISTS medicine;
DROP TABLE IF EXISTS prescription;
DROP TABLE IF EXISTS test_result;
DROP TABLE IF EXISTS test_order;
DROP TABLE IF EXISTS test_type;
DROP TABLE IF EXISTS referral;
DROP TABLE IF EXISTS medical_record;
DROP TABLE IF EXISTS medical_request;
DROP TABLE IF EXISTS appointment;
DROP TABLE IF EXISTS visit_type;
DROP TABLE IF EXISTS doctor_availability;
DROP TABLE IF EXISTS notification;
DROP TABLE IF EXISTS clinical_assistant;
DROP TABLE IF EXISTS doctor;
DROP TABLE IF EXISTS patient;
DROP TABLE IF EXISTS address;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============ USERS TABLE ============

CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    contact_no VARCHAR(50),
    role ENUM('patient', 'doctor', 'clinical_assistant', 'admin') NOT NULL DEFAULT 'patient',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- ============ ADDRESS TABLE ============
CREATE TABLE address (
    address_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    zipcode VARCHAR(20),
    barangay VARCHAR(100),
    city VARCHAR(100),
    province VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_address_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ============ PATIENT TABLE ============
CREATE TABLE patient (
    patient_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    date_of_birth DATE,
    sex ENUM('male','female','other'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_patient_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- ============ DOCTOR TABLE ============
CREATE TABLE doctor (
    doctor_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    license_no VARCHAR(100) NOT NULL UNIQUE,
    specialty VARCHAR(100),
    clinic VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_doctor_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_license (license_no)
);

-- ============ DOCTOR AVAILABILITY TABLE ============
CREATE TABLE doctor_availability (
    availability_id INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id INT NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_availability_doctor FOREIGN KEY (doctor_id) REFERENCES doctor(doctor_id) ON DELETE CASCADE,
    INDEX idx_doctor (doctor_id),
    UNIQUE KEY unique_doctor_day (doctor_id, day_of_week)
);

-- ============ CLINICAL ASSISTANT TABLE ============
CREATE TABLE clinical_assistant (
    assistant_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    clinic VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_assistant_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- ============ NOTIFICATION TABLE ============
CREATE TABLE notification (
    notif_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    status ENUM('sent','read') DEFAULT 'sent',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_status (status)
);

-- ============ VISIT TYPE TABLE ============
CREATE TABLE visit_type (
    visit_type_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

-- ============ APPOINTMENT TABLE ============
CREATE TABLE appointment (
    appointment_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    assistant_id INT NULL,
    visit_type_id INT NOT NULL,
    schedule_start DATETIME NOT NULL,
    schedule_end DATETIME NOT NULL,
    status ENUM('scheduled','in-progress','completed','canceled','no-show') DEFAULT 'scheduled',
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    notes TEXT,
    cancellation_reason TEXT,
    CONSTRAINT fk_appointment_patient FOREIGN KEY (patient_id) REFERENCES patient(patient_id) ON DELETE CASCADE,
    CONSTRAINT fk_appointment_doctor FOREIGN KEY (doctor_id) REFERENCES doctor(doctor_id) ON DELETE CASCADE,
    CONSTRAINT fk_appointment_assistant FOREIGN KEY (assistant_id) REFERENCES clinical_assistant(assistant_id) ON DELETE SET NULL,
    CONSTRAINT fk_appointment_visit FOREIGN KEY (visit_type_id) REFERENCES visit_type(visit_type_id),
    CONSTRAINT fk_appointment_creator FOREIGN KEY (created_by) REFERENCES users(user_id),
    INDEX idx_patient (patient_id),
    INDEX idx_doctor (doctor_id),
    INDEX idx_schedule (schedule_start),
    INDEX idx_status (status)
);

-- ============ MEDICAL REQUEST TABLE ============
CREATE TABLE medical_request (
    request_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    request_type ENUM('medical_record', 'test_result', 'prescription', 'referral', 'other') NOT NULL,
    description TEXT,
    status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    rejection_reason TEXT,
    CONSTRAINT fk_medreq_patient FOREIGN KEY (patient_id) REFERENCES patient(patient_id) ON DELETE CASCADE,
    CONSTRAINT fk_medreq_doctor FOREIGN KEY (doctor_id) REFERENCES doctor(doctor_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_doctor (doctor_id),
    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at)
);

-- ============ MEDICAL RECORD TABLE ============
CREATE TABLE medical_record (
    record_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    appointment_id INT NOT NULL,
    doctor_id INT NOT NULL,
    date_time DATETIME NOT NULL,
    diagnosis TEXT,
    treatment_summary TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_medical_patient FOREIGN KEY (patient_id) REFERENCES patient(patient_id) ON DELETE CASCADE,
    CONSTRAINT fk_medical_appointment FOREIGN KEY (appointment_id) REFERENCES appointment(appointment_id) ON DELETE CASCADE,
    CONSTRAINT fk_medical_doctor FOREIGN KEY (doctor_id) REFERENCES doctor(doctor_id),
    INDEX idx_patient (patient_id),
    INDEX idx_doctor (doctor_id),
    INDEX idx_date (date_time)
);

-- ============ REFERRAL TABLE ============
CREATE TABLE referral (
    referral_id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    to_whom VARCHAR(255) NOT NULL,
    reason TEXT,
    status ENUM('issued','received','completed') DEFAULT 'issued',
    issued_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_referral_record FOREIGN KEY (record_id) REFERENCES medical_record(record_id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_issued_at (issued_at)
);

-- ============ TEST TYPE TABLE ============
CREATE TABLE test_type (
    test_type_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT
);

-- ============ TEST ORDER TABLE ============
CREATE TABLE test_order (
    test_order_id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    ordered_by INT NOT NULL,
    test_type_id INT NOT NULL,
    status ENUM('ordered','completed','canceled') DEFAULT 'ordered',
    ordered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_testorder_record FOREIGN KEY (record_id) REFERENCES medical_record(record_id) ON DELETE CASCADE,
    CONSTRAINT fk_testorder_doctor FOREIGN KEY (ordered_by) REFERENCES doctor(doctor_id),
    CONSTRAINT fk_testorder_type FOREIGN KEY (test_type_id) REFERENCES test_type(test_type_id),
    INDEX idx_status (status)
);

-- ============ TEST RESULT TABLE ============
CREATE TABLE test_result (
    test_result_id INT AUTO_INCREMENT PRIMARY KEY,
    test_order_id INT NOT NULL,
    result TEXT,
    date_time DATETIME,
    findings TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_testresult_order FOREIGN KEY (test_order_id) REFERENCES test_order(test_order_id) ON DELETE CASCADE
);

-- ============ PRESCRIPTION TABLE ============
CREATE TABLE prescription (
    prescription_id INT AUTO_INCREMENT PRIMARY KEY,
    record_id INT NOT NULL,
    doctor_id INT NOT NULL,
    issued_at DATETIME NOT NULL,
    notes TEXT,
    status ENUM('active','inactive','expired') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_prescription_record FOREIGN KEY (record_id) REFERENCES medical_record(record_id) ON DELETE CASCADE,
    CONSTRAINT fk_prescription_doctor FOREIGN KEY (doctor_id) REFERENCES doctor(doctor_id),
    INDEX idx_status (status)
);

-- ============ MEDICINE TABLE ============
CREATE TABLE medicine (
    med_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    strength VARCHAR(100),
    form VARCHAR(100),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_medicine (name, strength, form)
);

-- ============ PRESCRIPTION ITEM TABLE ============
CREATE TABLE prescription_item (
    prescription_id INT NOT NULL,
    line_no INT NOT NULL,
    med_id INT NOT NULL,
    dose VARCHAR(100),
    frequency VARCHAR(100),
    duration VARCHAR(100),
    instructions TEXT,
    PRIMARY KEY (prescription_id, line_no),
    CONSTRAINT fk_prescriptionitem_prescription FOREIGN KEY (prescription_id) REFERENCES prescription(prescription_id) ON DELETE CASCADE,
    CONSTRAINT fk_prescriptionitem_medicine FOREIGN KEY (med_id) REFERENCES medicine(med_id),
    INDEX idx_medicine (med_id)
);


-- ============ PATIENT ALLERGY TABLE ============
CREATE TABLE patient_allergy (
    allergy_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    allergen_type ENUM('medication', 'food', 'environmental', 'other') NOT NULL,
    allergen_name VARCHAR(255) NOT NULL,
    reaction VARCHAR(255),
    severity ENUM('mild', 'moderate', 'severe') DEFAULT 'moderate',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_allergy_patient FOREIGN KEY (patient_id) REFERENCES patient(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_allergen_type (allergen_type)
);

-- ============ PATIENT INSURANCE TABLE ============
CREATE TABLE patient_insurance (
    insurance_id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    provider_name VARCHAR(255) NOT NULL,
    policy_number VARCHAR(100) NOT NULL UNIQUE,
    group_number VARCHAR(100),
    coverage_type VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    effective_date DATE,
    expiry_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_insurance_patient FOREIGN KEY (patient_id) REFERENCES patient(patient_id) ON DELETE CASCADE,
    INDEX idx_patient (patient_id),
    INDEX idx_policy (policy_number)
);

-- ============ VITAL SIGNS TABLE ============
CREATE TABLE vital_signs (
    vitals_id INT AUTO_INCREMENT PRIMARY KEY,
    medical_record_id INT NOT NULL,
    temperature DECIMAL(4,1),
    systolic_bp INT,
    diastolic_bp INT,
    heart_rate INT,
    respiratory_rate INT,
    oxygen_saturation DECIMAL(4,1),
    weight DECIMAL(5,2),
    height DECIMAL(5,2),
    bmi DECIMAL(5,2),
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT,
    CONSTRAINT fk_vitals_record FOREIGN KEY (medical_record_id) REFERENCES medical_record(record_id) ON DELETE CASCADE,
    CONSTRAINT fk_vitals_user FOREIGN KEY (recorded_by) REFERENCES users(user_id),
    INDEX idx_medical_record (medical_record_id),
    INDEX idx_recorded_at (recorded_at)
);


-- ============ AUDIT LOG TABLE ============
CREATE TABLE audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action_type VARCHAR(100) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(50),
    user_agent VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(user_id),
    INDEX idx_user (user_id),
    INDEX idx_action (action_type),
    INDEX idx_table (table_name),
    INDEX idx_created_at (created_at)
);

-- ============ SESSION ACTIVITY LOG TABLE ============
CREATE TABLE session_activity_log (
    activity_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    activity_type ENUM('login', 'logout', 'view', 'edit', 'delete', 'other') NOT NULL,
    entity_type VARCHAR(100),
    entity_id INT,
    ip_address VARCHAR(50),
    user_agent VARCHAR(500),
    status VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activity_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_created_at (created_at)
);

-- INDEXES FOR OPTIMAL QUERY PERFORMANCE
CREATE INDEX idx_patient_user ON patient(user_id);
CREATE INDEX idx_doctor_user ON doctor(user_id);
CREATE INDEX idx_appointment_patient ON appointment(patient_id);
CREATE INDEX idx_appointment_doctor ON appointment(doctor_id);
CREATE INDEX idx_med_record_patient ON medical_record(patient_id);
CREATE INDEX idx_med_record_doctor ON medical_record(doctor_id);
CREATE INDEX idx_prescription_record ON prescription(record_id);
CREATE INDEX idx_prescription_doctor ON prescription(doctor_id);
CREATE INDEX idx_test_order_status ON test_order(status);
CREATE INDEX idx_medical_request_patient ON medical_request(patient_id);
CREATE INDEX idx_medical_request_doctor ON medical_request(doctor_id);
CREATE INDEX idx_medical_request_status ON medical_request(status);
CREATE INDEX idx_patient_allergy ON patient_allergy(patient_id);
CREATE INDEX idx_patient_allergy_type ON patient_allergy(allergen_type);
CREATE INDEX idx_patient_insurance ON patient_insurance(patient_id);
CREATE INDEX idx_vital_signs_record ON vital_signs(medical_record_id);
CREATE INDEX idx_vital_signs_date ON vital_signs(recorded_at);
CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_action ON audit_log(action_type);
CREATE INDEX idx_audit_log_date ON audit_log(created_at);
CREATE INDEX idx_session_activity_user ON session_activity_log(user_id);
CREATE INDEX idx_session_activity_type ON session_activity_log(activity_type);
CREATE INDEX idx_session_activity_date ON session_activity_log(created_at);