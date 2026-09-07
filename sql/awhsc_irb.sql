-- =====================================================================
-- AWHSC-IRB Management Information System
-- Database Schema for MySQL / phpMyAdmin (XAMPP)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS awhsc_irb_mis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE awhsc_irb_mis;

-- ---------------------------------------------------------------------
-- Users: Researchers, IRB Members, External Consultants, Chairperson, Secretariat, System Admin
--
-- Roles are deliberately separated by responsibility (separation of duties):
--   system_admin        Accounts, roles, audit trail. No access to protocol content.
--   secretariat         Administrative coordination (screening, scheduling, records,
--                        correspondence, archiving). No ethical decision authority.
--   chairperson         Ethical decision authority: final decisions, amendment/continuing
--                        review sign-off, non-compliance resolution, study closure.
--   member              Internal IRB reviewer.
--   external_consultant Ad-hoc outside reviewer, restricted to protocols they are assigned
--                        to, gated behind a confidentiality acknowledgment.
--   researcher           Principal investigator / submitter.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('system_admin','secretariat','chairperson','member','external_consultant','researcher') NOT NULL DEFAULT 'researcher',
    department VARCHAR(150) DEFAULT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    specialization VARCHAR(150) DEFAULT NULL,
    photo_path VARCHAR(255) DEFAULT NULL,
    confidentiality_ack_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Protocols: research protocol submissions (AWHSC-IRB-007 to 011)
-- ---------------------------------------------------------------------
CREATE TABLE protocols (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_code VARCHAR(40) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    pi_id INT NOT NULL,
    department VARCHAR(150) DEFAULT NULL,
    study_type VARCHAR(100) DEFAULT NULL,
    summary TEXT,
    review_type ENUM('expedited','full_board','exempt') DEFAULT NULL,
    risk_level ENUM('minimal','greater_than_minimal') DEFAULT NULL,
    status ENUM(
        'submitted',
        'under_screening',
        'under_review',
        'revision_required',
        'deferred',
        'resubmitted',
        'approved',
        'rejected',
        'withdrawn',
        'closed'
    ) NOT NULL DEFAULT 'submitted',
    archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME DEFAULT NULL,
    submission_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pi_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Submission package checklists (AWHSC-IRB SOP/007 Annex 1: Contents of a
-- Submitted Package). Captures what the submitter declares as included,
-- and what the Secretariat verifies during screening (AWHSC-IRB-008/009).
-- ---------------------------------------------------------------------
CREATE TABLE submission_checklists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    package_type ENUM('initial','resubmission','amendment','continuing_review','final_report','termination') NOT NULL DEFAULT 'initial',
    declared_items_json TEXT NOT NULL,
    declared_other TEXT,
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_items_json TEXT,
    is_complete TINYINT(1) DEFAULT NULL,
    deficiency_notes TEXT,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Protocol documents (consent forms, questionnaires, CVs, etc.)
-- ---------------------------------------------------------------------
CREATE TABLE protocol_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    doc_type VARCHAR(100) DEFAULT 'General',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Reviewer assignment (AWHSC-IRB-009, 010)
-- ---------------------------------------------------------------------
CREATE TABLE protocol_reviewers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','completed') NOT NULL DEFAULT 'pending',
    UNIQUE KEY uniq_protocol_reviewer (protocol_id, reviewer_id),
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Individual review comments/recommendations
-- ---------------------------------------------------------------------
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    reviewer_id INT NOT NULL,
    recommendation ENUM('approve','minor_revisions','major_revisions','reject') NOT NULL,
    assessment_json TEXT,
    comments TEXT,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_review_protocol_reviewer (protocol_id, reviewer_id),
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Final IRB decisions (Chairperson) - AWHSC-IRB-009, 012, 013
-- ---------------------------------------------------------------------
CREATE TABLE decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    decision ENUM('approved','rejected','revision_required','deferred') NOT NULL,
    decided_by INT NOT NULL,
    certificate_no VARCHAR(60) DEFAULT NULL,
    comments TEXT,
    decision_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Meetings (AWHSC-IRB-021, 022)
-- ---------------------------------------------------------------------
CREATE TABLE meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_date DATETIME NOT NULL,
    meeting_type ENUM('regular','emergency') NOT NULL DEFAULT 'regular',
    venue VARCHAR(150) DEFAULT NULL,
    status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Meeting agenda items - link protocols to a meeting
-- ---------------------------------------------------------------------
CREATE TABLE meeting_agenda_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    protocol_id INT DEFAULT NULL,
    item_order INT NOT NULL DEFAULT 1,
    -- Category matches the AWHSC-IRB SOP/021 Annex 1 agenda structure (item 5: Protocol
    -- Presentations, Review, Discussion and Voting)
    category ENUM('initial_review','resubmitted','amendment','pending','sae_report','expedited_review','other') NOT NULL DEFAULT 'other',
    topic VARCHAR(255) DEFAULT NULL,
    notes TEXT,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Conflict-of-interest declarations before an IRB meeting
-- (AWHSC-IRB SOP/021 Annex 3)
-- ---------------------------------------------------------------------
CREATE TABLE meeting_coi_declarations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    member_id INT NOT NULL,
    protocol_id INT NOT NULL,
    reason TEXT NOT NULL,
    declared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_meeting_member_protocol (meeting_id, member_id, protocol_id),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Meeting attendance
-- ---------------------------------------------------------------------
CREATE TABLE meeting_attendees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    user_id INT NOT NULL,
    present TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_meeting_user (meeting_id, user_id),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Meeting minutes
-- ---------------------------------------------------------------------
CREATE TABLE meeting_minutes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL UNIQUE,
    minutes_text TEXT,
    recorded_by INT NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Adverse Event / Safety reports (AWHSC-IRB-019)
-- ---------------------------------------------------------------------
CREATE TABLE sae_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    report_date DATE NOT NULL,
    event_type ENUM('SAE','Unanticipated Problem','Protocol Deviation') NOT NULL,
    severity ENUM('mild','moderate','severe','fatal') DEFAULT 'moderate',
    description TEXT,
    -- Fields aligned to AWHSC-IRB SOP/019 Annex 1: Serious Adverse Event Report
    seriousness ENUM('death','life_threatening','hospitalization_initial','hospitalization_prolonged','disability_incapacity','congenital_anomaly','other') DEFAULT NULL,
    relation_to_study ENUM('not_related','possibly','probably','definitely','unknown') DEFAULT 'unknown',
    outcome ENUM('resolved','ongoing') DEFAULT 'ongoing',
    protocol_change_recommended TINYINT(1) NOT NULL DEFAULT 0,
    icf_change_recommended TINYINT(1) NOT NULL DEFAULT 0,
    reported_by INT NOT NULL,
    status ENUM('open','under_review','closed') NOT NULL DEFAULT 'open',
    -- Reviewer decision per the Annex 1 form's "Decision" block
    reviewer_decision ENUM('no_action','request_information','recommend_further_action') DEFAULT NULL,
    reviewer_comment TEXT,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Continuing review reminders (AWHSC-IRB-014)
-- ---------------------------------------------------------------------
CREATE TABLE continuing_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    due_date DATE NOT NULL,
    submitted_date DATE DEFAULT NULL,
    status ENUM('pending','submitted','reviewed','overdue') NOT NULL DEFAULT 'pending',
    comments TEXT,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Protocol amendments (AWHSC-IRB-015)
-- ---------------------------------------------------------------------
CREATE TABLE amendments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    description TEXT NOT NULL,
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    decision_comments TEXT,
    decided_by INT DEFAULT NULL,
    decided_at DATETIME DEFAULT NULL,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- End-of-study final reports & closure (AWHSC-IRB-016)
-- ---------------------------------------------------------------------
CREATE TABLE final_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    summary TEXT NOT NULL,
    submitted_by INT NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Site / protocol implementation monitoring (AWHSC-IRB-018)
-- ---------------------------------------------------------------------
-- Fields aligned to AWHSC-IRB SOP/020 Annex 1: Checklist of a Monitoring Visit
CREATE TABLE monitoring_visits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    visit_date DATE NOT NULL,
    monitor_id INT NOT NULL,
    total_expected_subjects INT DEFAULT NULL,
    total_enrolled_subjects INT DEFAULT NULL,
    site_facilities_appropriate TINYINT(1) DEFAULT NULL,
    site_facilities_comment TEXT,
    informed_consents_recent TINYINT(1) DEFAULT NULL,
    informed_consents_comment TEXT,
    adverse_events_found TINYINT(1) DEFAULT NULL,
    adverse_events_comment TEXT,
    protocol_noncompliance_found TINYINT(1) DEFAULT NULL,
    protocol_noncompliance_comment TEXT,
    case_record_forms_updated TINYINT(1) DEFAULT NULL,
    case_record_forms_comment TEXT,
    storage_secured TINYINT(1) DEFAULT NULL,
    storage_secured_comment TEXT,
    participant_protection_rating ENUM('good','fair','not_good') DEFAULT NULL,
    outstanding_tasks TINYINT(1) DEFAULT NULL,
    outstanding_tasks_detail TEXT,
    visit_duration_hours DECIMAL(4,1) DEFAULT NULL,
    visit_start_time TIME DEFAULT NULL,
    visit_end_time TIME DEFAULT NULL,
    decision ENUM('no_action','request_information','recommend_further_action') NOT NULL DEFAULT 'no_action',
    compliance_status ENUM('compliant','minor_findings','major_findings','non_compliant') NOT NULL DEFAULT 'compliant',
    findings TEXT,
    action_required TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (monitor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Audit log
-- ---------------------------------------------------------------------
CREATE TABLE audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(255) NOT NULL,
    entity VARCHAR(100) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Communication records between IRB and investigators
-- ---------------------------------------------------------------------
CREATE TABLE communications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT DEFAULT NULL,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE SET NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Non-compliance / violation management (AWHSC-IRB-020)
-- ---------------------------------------------------------------------
CREATE TABLE non_compliance_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    protocol_id INT NOT NULL,
    reported_by INT NOT NULL,
    description TEXT NOT NULL,
    severity ENUM('minor','moderate','serious') NOT NULL DEFAULT 'minor',
    status ENUM('open','under_investigation','resolved','closed') NOT NULL DEFAULT 'open',
    resolution TEXT,
    resolved_by INT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (protocol_id) REFERENCES protocols(id) ON DELETE CASCADE,
    FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- AI Assistant conversation history (one running thread per user)
-- ---------------------------------------------------------------------
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    role ENUM('user','assistant') NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
-- NOTE ON DEMO ACCOUNTS
-- This schema intentionally contains NO seeded users/passwords.
-- After importing this file, open setup.php once in your browser
-- (e.g. http://localhost/awhsc_irb_mis/setup.php). It uses PHP's own
-- password_hash() function to create correctly-hashed demo accounts
-- for every role (system_admin, secretariat, chairperson, member,
-- external_consultant, researcher), and then
-- disables itself. This avoids shipping a hard-coded hash that could
-- be stale or mismatched with your PHP version.
-- =====================================================================
