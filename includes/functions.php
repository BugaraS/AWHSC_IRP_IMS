<?php
/**
 * AWHSC-IRB MIS - Shared helper functions
 */

require_once __DIR__ . '/../config/ai.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true, // enable once the site is served over HTTPS
    ]);
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!is_logged_in()) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['user_name'],
        'email' => $_SESSION['user_email'],
        'role'  => $_SESSION['user_role'],
        'photo' => $_SESSION['user_photo'] ?? null,
    ];
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;max-width:600px;margin:80px auto;text-align:center;">
                <h2>403 - Access denied</h2>
                <p>Your account role does not have permission to view this page.</p>
                <a href="' . BASE_URL . 'dashboard.php">Return to dashboard</a>
             </div>');
    }
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): void {
    if (empty($_SESSION['flash'])) return;
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['type'] === 'error' ? 'alert-danger' : ($f['type'] === 'success' ? 'alert-success' : 'alert-info');
        echo '<div class="alert ' . $cls . '">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
}

function log_action(PDO $pdo, ?int $userId, string $action, ?string $entity = null, ?int $entityId = null): void {
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, entity, entity_id) VALUES (?,?,?,?)");
    $stmt->execute([$userId, $action, $entity, $entityId]);
}

function generate_protocol_code(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->query("SELECT COUNT(*) AS c FROM protocols WHERE YEAR(submission_date) = " . (int)$year);
    $count = (int)$stmt->fetch()['c'] + 1;
    return sprintf('AWHSC-IRB/%s/%03d', $year, $count);
}

function status_badge(string $status): string {
    $map = [
        'submitted'          => 'secondary',
        'under_screening'    => 'info',
        'under_review'       => 'primary',
        'revision_required'  => 'warning',
        'deferred'           => 'warning',
        'resubmitted'        => 'info',
        'approved'           => 'success',
        'rejected'           => 'danger',
        'withdrawn'          => 'dark',
        'closed'             => 'dark',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
}

function role_label(string $role): string {
    $map = [
        'system_admin'        => 'System Administrator',
        'secretariat'         => 'IRB Secretariat',
        'chairperson'         => 'IRB Chairperson',
        'member'              => 'IRB Member',
        'external_consultant' => 'External Consultant',
        'researcher'          => 'Researcher (PI)',
    ];
    return $map[$role] ?? ucfirst($role);
}

/** Short one-line description of what each role is responsible for (used on the dashboard & user admin screens). */
function role_description(string $role): string {
    $map = [
        'system_admin'        => 'Manages user accounts, roles, and the audit trail. Does not access protocol content, to keep research and ethics data confidential.',
        'secretariat'         => 'Administrative coordination: screens submissions, assigns reviewers, schedules meetings, tracks deadlines, manages correspondence and the archive.',
        'chairperson'         => 'Holds ethical decision authority: records committee decisions, approves amendments and continuing reviews, resolves non-compliance, closes studies.',
        'member'              => 'Reviews protocols assigned by the Secretariat/Chair and records recommendations.',
        'external_consultant' => 'An outside specialist invited to review a specific protocol under a confidentiality agreement; sees only assigned protocols.',
        'researcher'          => 'Submits and manages their own research protocols, amendments, safety reports, and study closure.',
    ];
    return $map[$role] ?? '';
}

/* -----------------------------------------------------------------
 * AWHSC-IRB SOP Annex reference data
 * Centralized so every form (submission, review, SAE, monitoring,
 * meetings) uses the exact same checklist wording as the approved SOP.
 * --------------------------------------------------------------- */

/** AWHSC-IRB SOP/007 Annex 1: Contents of a Submitted Package (Checklist), by package type. */
function annex1_submission_checklist(string $packageType): array {
    $protocolDocs = [
        'info_for_subjects'  => "Information for subject's",
        'informed_consent'   => 'Informed consent form',
        'crf'                => 'Case report forms (CRF)',
        'study_budget'       => 'Study budget',
        'cv'                 => 'Curriculum vitae (CV)',
        'gcp_certificate'    => 'GCP or research ethics training certificates',
        'investigator_brochure' => "Investigator's brochure",
        'mou'                => 'MoU',
    ];

    switch ($packageType) {
        case 'resubmission':
            return [
                'resubmission_memo'  => 'Resubmission or "Correction" Memorandum',
                'revised_summary'    => 'Revised Protocol Summary Sheet (if submitted initially)',
                'application_form'   => 'Original Initial Review Application Form',
            ] + $protocolDocs;
        case 'amendment':
            return [
                'amendment_memo'     => 'Request for Amendment Memorandum',
                'amendment_form'     => 'Original Amendment Submission Form',
                'protocol_documents' => 'Protocol and Protocol-Related Documents',
            ];
        case 'continuing_review':
            return [
                'cr_memo'     => 'Request for Continuing Review Memorandum',
                'cr_form'     => 'Original Continuing Review Application Form',
                'current_icf' => 'Current Informed Consent Document (last approved by the IRB)',
            ];
        case 'final_report':
            return [
                'final_report_request' => 'Request for Final Report Review',
                'final_report_form'    => 'Original Final Report Review Application Form',
            ];
        case 'termination':
            return [
                'termination_memo' => 'Request for Termination Memorandum',
                'termination_form' => 'Original Continuing Review Application Form (Termination Submissions are contained on this form)',
            ];
        case 'initial':
        default:
            return [
                'summary_sheet'      => 'Protocol Summary Sheet',
                'application_form'   => 'Original Initial Review Application Form',
                'supporting_letter'  => 'Office memo or supporting letter',
            ] + $protocolDocs;
    }
}

function annex1_package_label(string $packageType): string {
    $map = [
        'initial'            => 'Initial Review Submitted Package',
        'resubmission'       => 'Resubmission for Re-review Submitted Package',
        'amendment'          => 'Protocol Amendment Submitted Package',
        'continuing_review'  => 'Continuing Review Package',
        'final_report'       => 'Final Report Review Package',
        'termination'        => 'Protocol Termination Package',
    ];
    return $map[$packageType] ?? ucfirst($packageType);
}

/** AWHSC-IRB SOP/008 Annex 1: Study Assessment Form — structured review categories. */
function annex1_assessment_categories(): array {
    return [
        'design' => [
            'label' => '5.2 Review of the Study Protocol',
            'items' => [
                'need_for_human_participants' => 'Need for human participants for the study',
                'objectives'                  => 'Objectives of the study clearly stated',
                'literature_review'           => 'Adequate review of literature',
                'sample_size'                 => 'Sample size justified',
                'methodology'                 => 'Methodology and data management sound',
                'inclusion_exclusion'         => 'Inclusion / exclusion criteria appropriate',
                'control_arms'                => 'Control arms (placebo, if any) justified',
                'withdrawal_criteria'         => 'Withdrawal or discontinuation criteria defined',
            ],
        ],
        'investigator' => [
            'label' => '5.3 Investigator & Study Site Qualifications',
            'items' => [
                'investigator_background'   => "Investigators' background/training relevant to the study",
                'coi_disclosed'             => 'Conflicts of interest disclosed/declared',
                'site_facilities'           => 'Facilities and infrastructure adequate for the study',
            ],
        ],
        'participant' => [
            'label' => '5.4 Study Participation & Informed Consent',
            'items' => [
                'voluntary_participation'  => 'Voluntary, non-coercive recruitment/participation',
                'consent_process'          => 'Adequate procedures for obtaining informed consent',
                'icf_content'              => 'Informed consent document content and language appropriate',
                'privacy_confidentiality'  => 'Privacy and confidentiality adequately protected',
                'risks_benefits'           => 'Risks reasonable relative to anticipated benefits',
                'compensation'             => 'Compensation arrangements reasonable',
                'vulnerable_participants'  => 'Special protections for vulnerable participants (if applicable)',
            ],
        ],
        'community' => [
            'label' => '5.5 Community Involvement & Impact',
            'items' => [
                'community_consultation'  => 'Evidence of community consultation',
                'local_capacity'          => 'Contribution to local research capacity',
                'community_benefit'       => 'Benefit to local communities',
                'results_availability'    => 'Plan to make study results available',
            ],
        ],
    ];
}

/** AWHSC-IRB SOP/019 Annex 1/2: Adverse Event report vocabulary. */
function annex_sae_seriousness_options(): array {
    return [
        'death'                     => 'Death',
        'life_threatening'          => 'Life-threatening',
        'hospitalization_initial'   => 'Hospitalization (initial)',
        'hospitalization_prolonged' => 'Hospitalization (prolonged)',
        'disability_incapacity'     => 'Disability / Incapacity',
        'congenital_anomaly'        => 'Congenital Anomaly',
        'other'                     => 'Other',
    ];
}
function annex_relation_to_study_options(): array {
    return [
        'not_related' => 'Not Related',
        'possibly'    => 'Possibly Related',
        'probably'    => 'Probably Related',
        'definitely'  => 'Definitely Related',
        'unknown'     => 'Unknown',
    ];
}
function annex_reviewer_decision_options(): array {
    return [
        'no_action'               => 'No Further Action Required',
        'request_information'     => 'Request Information',
        'recommend_further_action' => 'Recommend Further Action',
    ];
}

/* -----------------------------------------------------------------
 * AI Assistant (Anthropic Claude) — in-app help chat
 * --------------------------------------------------------------- */

/** Builds a role-aware system prompt so the assistant understands the SOP-driven app. */
function ai_system_prompt(array $u): string {
    $roleNote = [
        'system_admin'        => 'They manage user accounts and the audit trail; they cannot see protocol content, so do not discuss specific studies with them — help with account/user-management questions instead.',
        'secretariat'         => 'They handle screening, reviewer assignment, meeting logistics, continuing-review tracking, and archiving.',
        'chairperson'         => 'They hold final ethical decision authority: recording decisions, deciding amendments, resolving non-compliance, and closing studies.',
        'member'              => 'They review protocols assigned to them.',
        'external_consultant' => 'They are an outside reviewer restricted to protocols assigned to them, and must acknowledge a confidentiality agreement before reviewing.',
        'researcher'          => 'They submit and manage their own protocols, amendments, safety reports, and study closure.',
    ][$u['role']] ?? '';

    return <<<PROMPT
You are the in-app AI Assistant for the AWHSC-IRB Management Information System — a web app
for Debre Berhan University's Asrat Woldeyes Health Science Campus Institutional Review Board.
The app is built directly on the approved AWHSC-IRB Standard Operating Procedures (SOP).

Your job is to help the logged-in user navigate the SYSTEM and understand the SOP-driven
WORKFLOWS it implements — not to give official ethics rulings, legal advice, or clinical
guidance. For anything requiring an actual ethics decision, tell them to contact the IRB
Secretariat or Chairperson.

The person you're talking to is {$u['name']}, role: {$u['role']}. {$roleNote}

Key things you can help explain:
- Submitting a protocol: the Annex 1 "Contents of a Submitted Package" checklist (Protocol
  Summary Sheet, Application Form, Informed Consent, CRFs, CV, GCP certificate, Investigator's
  Brochure, MoU) is filled out on the Submit Protocol page; the Secretariat then verifies it.
- Review: reviewers fill out a structured Study Assessment Form (Study Protocol; Investigator &
  Site Qualifications; Study Participation & Informed Consent; Community Involvement), then give
  a recommendation: Approved / Approved with Minor Comments / Resubmit / Disapproved.
- Decisions: the Chairperson records Approved (Full Approval), Approved with Modifications
  Required, Deferred (More Information Needed), or Not Approved, with a certificate number for
  approvals.
- Continuing review, amendments, safety/adverse-event reports, monitoring visits, non-compliance
  reports, meetings (with agenda categories and conflict-of-interest declarations), archiving,
  messaging between users, and the audit log (System Admin only) are all separate modules
  reachable from the top navigation bar.

Keep answers short, concrete, and specific to this app. If you don't know something about this
specific installation (e.g. exact deadlines, who's on the committee), say so plainly rather than
guessing, and suggest they ask the Secretariat.
PROMPT;
}

/**
 * Calls the Anthropic Messages API. Returns ['reply' => string] on success, or
 * ['error' => string] on failure (missing key, network error, bad response).
 */
function ai_chat_completion(string $systemPrompt, array $messages): array {
    if (ANTHROPIC_API_KEY === '') {
        return ['error' => 'The AI Assistant has not been set up yet. Ask your System Administrator to add an Anthropic API key in config/ai.php.'];
    }

    $payload = json_encode([
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => 700,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    ]);

    $ch = curl_init(ANTHROPIC_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['error' => 'Could not reach the AI service (' . $curlError . '). Check the server\'s internet connection.'];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
        return ['error' => 'The AI service returned an error: ' . $msg];
    }

    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }

    return $text !== '' ? ['reply' => $text] : ['error' => 'The AI service returned an empty response.'];
}


function protocol_upload_dir(int $protocolId): string {
    $dir = __DIR__ . '/../uploads/protocols/' . $protocolId;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Validate an uploaded file by real content (not just its extension).
 * Returns true and sets $ext by reference on success, false otherwise.
 */
function is_allowed_upload(string $tmpPath, string $originalName, array $allowedExt, int $maxBytes, ?string &$ext = null): bool {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) return false;
    if (!is_uploaded_file($tmpPath)) return false;
    if (filesize($tmpPath) > $maxBytes) return false;

    $allowedMime = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
    ];
    if (!isset($allowedMime[$ext])) return false;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    return in_array($detected, $allowedMime[$ext], true);
}

/* -----------------------------------------------------------------
 * CSRF protection
 * Every state-changing form must include csrf_field() and every POST
 * handler must call require_csrf() before touching the database.
 * --------------------------------------------------------------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;max-width:600px;margin:80px auto;text-align:center;">
                <h2>403 - Request could not be verified</h2>
                <p>Your session may have expired. Please go back, refresh the page, and try again.</p>
                <a href="' . BASE_URL . 'dashboard.php">Return to dashboard</a>
             </div>');
    }
}

function unread_message_count(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM communications WHERE recipient_id = ? AND read_at IS NULL");
    $stmt->execute([$userId]);
    return (int)$stmt->fetch()['c'];
}

/** Whether this user has accepted the external-consultant confidentiality agreement. */
function has_confidentiality_ack(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT confidentiality_ack_at FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row && !empty($row['confidentiality_ack_at']);
}

/** Absolute URL to a user's avatar, falling back to a generated initials avatar (no external dependency). */
function avatar_url(?string $photoPath, string $fullName): string {
    if ($photoPath) {
        return BASE_URL . e($photoPath);
    }
    $initials = strtoupper(substr(trim($fullName), 0, 1)) ?: '?';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64">'
         . '<rect width="64" height="64" fill="#0b3d2e"/>'
         . '<text x="50%" y="54%" font-family="Arial,sans-serif" font-size="28" fill="#fff" text-anchor="middle">' . $initials . '</text>'
         . '</svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}
function login_throttle_check(): ?string {
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $last = $_SESSION['login_last_attempt'] ?? 0;
    if ($attempts >= 5 && (time() - $last) < 60) {
        return 'Too many attempts. Please wait a minute before trying again.';
    }
    return null;
}
function login_throttle_hit(): void {
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_last_attempt'] = time();
}
function login_throttle_reset(): void {
    unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
}
