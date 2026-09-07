# AWHSC-IRB Management Information System (MIS)

A PHP + MySQL web application for managing Institutional Review Board (IRB)
protocol submission, screening, review, decisions, meetings, amendments,
continuing review, monitoring, safety reporting, archiving, and auditing —
built around the workflow described in the **AWHSC-IRB Standard Operating
Procedures (2026)** of Debre Berhan University, and aligned to the ten
functional modules in the AWHSC-IRB MIS project proposal.

Built as plain PHP (no framework) + PDO/MySQL + Bootstrap 5, so it runs
directly on **XAMPP** and is easy to read/edit in **VS Code**.

> **v2.0 rework note:** this version adds four modules that were missing from
> the original build (Amendments, Continuing Review, Monitoring, Audit Log —
> plus Study File Archiving and CSV reporting), and closes several security
> gaps (CSRF protection, upload validation, login throttling, security
> headers). See "What changed in this rework" at the bottom for the full list.

---

## 1. What's included (mapped to the proposal's 10 modules)

| # | Module | Covers SOPs | Description |
|---|---|---|---|
| 1 | User & access management | 003, 005 | Login, self-registration, 6 roles, System Admin user management, **self-service profile, password change & photo** |
| 2 | Research & protocol management | 007, 008 | Researchers submit protocols using the **Annex 1 submission checklist**; Secretariat verifies completeness |
| 3 | Protocol review | 008, 009, 010 | Screening, reviewer assignment, and the **Annex 1 Study Assessment Form** structure for reviewer comments/recommendations |
| 4 | Continuing review & final reports | 014, 016 | Chair sets deadlines, researchers report progress, end-of-study report & closure |
| 5 | Protocol amendments | 015 | Researchers request changes; Chairperson approves or rejects |
| 6 | Safety & adverse events | 019 | Researchers report SAEs using the **Annex 1 SAE Report** fields (seriousness, relation, outcome); Secretariat/Chair record a decision |
| 7 | Monitoring & non-compliance | 018, 020 | Site visits using the **Annex 1 Monitoring Visit Checklist**; non-compliance/violation reports with resolution workflow |
| 8 | Meetings & communication | 021, 022 | Meetings with an **Annex 1 agenda structure** and **Annex 3 conflict-of-interest declarations**, plus a per-protocol/general internal messaging system |
| 9 | Study files & archive | 017 | Archive closed/approved/rejected/withdrawn studies; filterable registry |
| 10 | Audit & inspection | 023, 026 | Searchable audit log of every key action, with CSV export |

Every one of the ten modules from the project proposal now has working
create/read/update functionality end to end, and the forms in modules 2, 3, 6,
7, and 8 follow the exact Annex layouts from the approved AWHSC-IRB SOP rather
than a generic approximation — see section 7 for the full list of what was
aligned and where.

Plus a lightweight **reporting/export** capability (CSV export of the
protocol register and the audit log) covering the proposal's "reporting and
information-retrieval" objective.

### Roles (six, deliberately separated by responsibility)

| Role | Responsible for | Explicitly does **not** do |
|---|---|---|
| **System Administrator** | User accounts, roles, deactivation; the audit log; system-level exports | Has **no access** to protocol content, documents, meetings, or messages tied to a protocol — kept separate for confidentiality |
| **IRB Secretariat** | Screening submissions, assigning reviewers, scheduling meetings, tracking continuing-review deadlines, archiving closed studies, logging non-compliance issues, correspondence | Cannot record a final committee decision, decide an amendment, resolve/close a non-compliance report, or manage user accounts — no ethical decision authority |
| **IRB Chairperson** | Final review decisions, deciding amendments, signing off continuing reviews, resolving/closing non-compliance reports, closing studies — plus everything the Secretariat does | Does not manage user accounts or the audit log |
| **IRB Member** | Reviewing protocols assigned to them, reporting non-compliance they observe | Cannot screen, assign, or decide on protocols |
| **External Consultant** | An outside specialist invited to review one or more specific protocols; must acknowledge a confidentiality agreement (from their profile) before submitting a review | Restricted to protocols they are assigned to; cannot see the full registry, manage meetings, or access monitoring/audit tools |
| **Researcher (PI)** | Submitting protocols, uploading documents, requesting amendments, submitting continuing-review/final reports, reporting safety events | Only sees their own protocols |

Every account can manage its own **profile** — name, phone, affiliation/department,
specialization, password, and a profile photo — from the account menu.

---

## 2. Requirements

- [XAMPP](https://www.apachefriends.org/) (Apache + MySQL + PHP 8.0+)
- A code editor — [VS Code](https://code.visualstudio.com/) recommended, with the
  **PHP Intelephense** extension for autocompletion (optional but helpful)

---

## 3. Installation (XAMPP)

1. **Copy the project folder**
   Copy the whole `awhsc_irb_mis` folder into your XAMPP `htdocs` directory, e.g.:
   - Windows: `C:\xampp\htdocs\awhsc_irb_mis`
   - macOS: `/Applications/XAMPP/htdocs/awhsc_irb_mis`
   - Linux: `/opt/lampp/htdocs/awhsc_irb_mis`

2. **Start services**
   Open the XAMPP Control Panel and click **Start** next to both **Apache** and **MySQL**.

3. **Create the database**
   - Open `http://localhost/phpmyadmin` in your browser.
   - If you are upgrading from the previous version of this project, **drop the
     old `awhsc_irb_mis` database first** (or import into a fresh database
     name) — the schema below adds new tables and columns.
   - Click **Import**, choose the file `sql/awhsc_irb.sql` from this project, and click **Go**.
   - This creates the `awhsc_irb_mis` database with all tables (no demo users yet).

4. **Check the database config**
   Open `config/db.php`. The defaults match a standard XAMPP install:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'awhsc_irb_mis');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
   Only change these if your MySQL root user has a password or runs on a different port.

5. **Create demo accounts**
   Visit `http://localhost/awhsc_irb_mis/setup.php` in your browser and click
   **"Create demo accounts"**. This uses PHP's own `password_hash()` function so
   the passwords are guaranteed to work on your server. It creates one account
   per role, all with the password: **`Passw0rd!`**

   | Role | Email |
   |---|---|
   | System Administrator | sysadmin@awhsc-irb.dbu.edu.et |
   | IRB Secretariat | secretariat@awhsc-irb.dbu.edu.et |
   | IRB Chairperson | chair@awhsc-irb.dbu.edu.et |
   | IRB Member | member1@awhsc-irb.dbu.edu.et |
   | IRB Member | member2@awhsc-irb.dbu.edu.et |
   | External Consultant | consultant@example-university.edu |
   | Researcher | researcher@dbu.edu.et |

   For security, delete `setup.php` and `setup.lock` once you're done exploring
   (or before deploying anywhere public).

6. **Log in**
   Go to `http://localhost/awhsc_irb_mis/login.php` and sign in with any of the
   accounts above, or register a new researcher account from the login page.

7. **(Optional) Turn on the AI Assistant**
   Every logged-in user gets a chat bubble in the bottom-right corner that helps
   them navigate the system and understand the SOP-driven workflow (submitting a
   protocol, the Annex 1 checklist, the review process, safety reporting,
   monitoring, meetings). To enable it:
   - Get an API key from [console.anthropic.com](https://console.anthropic.com/)
   - Open `config/ai.php` and paste it into `ANTHROPIC_API_KEY`
   - Reload the page — no server restart needed

   Until a key is added, the chat bubble still appears but tells the user to ask
   their System Administrator to set one up, instead of failing silently.

---

## 4. Opening the project in VS Code

1. Open VS Code → **File → Open Folder** → select the `awhsc_irb_mis` folder.
2. (Optional) Install extensions for a smoother experience:
   - **PHP Intelephense** — autocompletion & error checking
   - **PHP Debug** (Xdebug) — step debugging with XAMPP's `php.ini` Xdebug settings
   - **SQLTools** (+ MySQL driver) — browse the database from inside VS Code
3. You do **not** run the PHP server from VS Code directly — Apache (from
   XAMPP) serves the files from `htdocs`. Just edit files in VS Code and
   refresh the browser to see changes; keep the XAMPP Control Panel running
   in the background.
4. If you'd rather use VS Code's built-in preview loop without XAMPP's Apache,
   you can instead run PHP's built-in server from the project folder:
   ```bash
   php -S localhost:8000
   ```
   and browse to `http://localhost:8000`. (MySQL from XAMPP still needs to be running.)

---

## 5. Folder structure

```
awhsc_irb_mis/
├── config/db.php               Database connection settings
├── config/ai.php               AI Assistant configuration (Anthropic API key) (NEW)
├── includes/                   Shared header, footer, auth helpers, CSRF, functions
├── assets/css/style.css        Site styling
├── assets/js/app.js            Small UI behaviours
├── protocols/                  Submit / list / view / review / amend / archive / download
├── profile/edit.php            Self-service profile, password change & photo upload (NEW)
├── communications/             Inbox, sent, compose, view/reply — internal message log (NEW)
├── continuing_review/list.php  Org-wide continuing review tracker (NEW)
├── amendments/list.php         Org-wide amendment queue (NEW)
├── monitoring/                 Site monitoring visit list & create form (NEW)
├── non_compliance/list.php     Org-wide non-compliance / violation queue (NEW)
├── audit/list.php              Searchable audit log viewer (System Admin only) (NEW)
├── chat/                       AI Assistant endpoints: send, history, clear (NEW)
├── reports/export.php          CSV export: protocol register & audit log (NEW)
├── meetings/                   Schedule / list / view (agenda, minutes, attendance)
├── users/                      System Admin: manage accounts & roles
├── uploads/protocols/<id>/     Uploaded protocol documents (auto-created)
├── uploads/avatars/            Uploaded profile photos (auto-created) (NEW)
├── sql/awhsc_irb.sql           Database schema (import this first)
├── setup.php                   One-time demo-account seeder (delete after use)
├── login.php / register.php / logout.php
├── dashboard.php               Role-aware landing page
└── index.php                   Redirects to login or dashboard
```

---

## 6. Security notes before using this beyond a demo

- Change all demo passwords immediately, or delete the demo accounts and
  create real ones from **Users → Add user** (as Admin).
- Set a MySQL root password in production and update `config/db.php` accordingly.
- Delete `setup.php` and `setup.lock` after initial setup.
- Keep `config/ai.php` (and your Anthropic API key) out of version control and
  off any public-facing repository — treat it like `config/db.php`.
- Serve the site over HTTPS if deployed outside your local machine, then
  uncomment `'secure' => true` in the session cookie settings in
  `includes/functions.php`.
- The `uploads/` folder has a `.htaccess` that blocks script execution;
  keep it in place if you move to a different web server, add an equivalent rule.
- Every form in the app now submits a CSRF token; if you add new forms,
  call `csrf_field()` inside them and `require_csrf()` at the top of the
  handling POST block.
- Uploaded files are validated by real file content (not just their
  extension) and capped at 15 MB each; adjust the limit in
  `protocols/submit.php` if needed.
- Login attempts are throttled (5 attempts per minute per session) to slow
  down password guessing on the demo login form.

---

## 7. What changed in this rework

**v2.4 — this update: AI Assistant + code review pass**

*AI Assistant (new):* every logged-in user now has a chat bubble (bottom-right,
all pages) backed by Anthropic's Claude API. It knows the app's SOP-driven
workflow (submission checklist, review categories, decision types, safety
reporting, monitoring, meetings/COI) and helps users navigate, but is
explicitly scoped to *not* give ethics rulings — the system prompt tells it to
defer those to the Secretariat/Chairperson, and System Admin conversations are
kept away from protocol content per the same separation of duties as the rest
of the app. Configuration is a single API key in `config/ai.php`; conversation
history is stored per-user in a new `chat_messages` table (with a "clear
conversation" option), and there's no external dependency if the key is left
blank — the widget degrades to a friendly setup message instead of failing.

*Code review pass:* while adding the AI Assistant, I re-audited the whole
codebase (lint, CSRF coverage, role-check consistency, stale references) and
fixed four real gaps that the `deferred` protocol status (added in v2.3)
had been left out of:
- `protocols/list.php` — the status filter dropdown didn't list "Deferred"
- `dashboard.php` — deferred protocols weren't counted in "Needs Action" or
  "Pending" stats for any role
- `meetings/create.php` — deferred protocols weren't offered on the agenda
  builder, and didn't map to an agenda category

All 33 PHP files pass `php -l`, and every POST handler (including the two new
AJAX chat endpoints, which use a header-based CSRF token since they don't
submit a form) is confirmed to check its token before touching the database.

**Schema addition for this update** (`sql/awhsc_irb.sql`): new table
`chat_messages` (per-user AI Assistant conversation history).

**v2.3:**

This pass reads the approved AWHSC-IRB SOP (2026 edition) directly and rebuilds the
forms that touch it so the wording, fields, and workflow match the SOP's own Annexes
rather than a generic approximation:

- **Protocol submission** (`protocols/submit.php`) now implements **SOP/007 Annex 1:
  Contents of a Submitted Package (Checklist)** for the Initial Review package —
  every item (Protocol Summary Sheet, Application Form, Informed Consent, CRFs, CV,
  GCP certificates, Investigator's Brochure, MoU, "Others") is its own checkbox with
  its own file-upload slot, and the three SOP-required items are enforced before
  submission is accepted. The declaration is stored in a new `submission_checklists`
  table (also modeled for the other five package types: resubmission, amendment,
  continuing review, final report, termination — ready to reuse in those workflows).
- **Screening** (`protocols/view.php`) adds a **Secretariat verification step**
  matching SOP/007 §5.2.3 "Verify Contents of Submitted Package": the Secretariat/
  Chair checks off each item against what was declared; marking it incomplete
  automatically returns the protocol to the investigator for correction (the SOP
  flow chart's step 4a), while marking it complete lets screening proceed.
- **Reviewer assessment** (`protocols/view.php`) is now the actual **SOP/008 Annex 1
  Study Assessment Form** structure — four categories (Study Protocol; Investigator &
  Site Qualifications; Study Participation & Informed Consent; Community Involvement),
  each with its real sub-criteria, answered Yes/No/N-A — instead of one free-text box.
  Recommendation options now use the SOP's own wording: Approved / Approved with Minor
  Comments / Resubmit / Disapproved.
- **IRB decisions** (`protocols/view.php`) now offer all four SOP branches — Approved
  (Full Approval), Approved with Modifications Required, **Deferred (More Information
  Needed)** (a new protocol status, previously missing), and Not Approved — and the
  certificate number field auto-prefixes `AWHSC-IRB-` per the Annex 5 numbering
  convention.
- **Safety/adverse event reports** now capture the actual **SOP/019 Annex 1** fields:
  seriousness (death, life-threatening, hospitalization, disability, congenital
  anomaly), relation to study, outcome, and whether a protocol or ICF change is
  recommended — plus a Secretariat/Chair decision step (No Further Action / Request
  Information / Recommend Further Action) as in the Annex's own "Decision" block.
- **Monitoring visits** (`monitoring/create.php`) now implement the full **SOP/020
  Annex 1 Checklist of a Monitoring Visit**: expected vs. enrolled subjects, site
  facilities, consent currency, adverse events, protocol non-compliance, CRF status,
  storage security, a participant-protection rating (Good/Fair/Not good), visit
  duration, and the same three-option decision. If non-compliance is flagged during
  a visit, a non-compliance report is opened automatically for follow-up.
- **Meetings** (`meetings/create.php`, `meetings/view.php`) now categorize agenda
  items the way **SOP/021 Annex 1** structures a meeting agenda (Initial Review,
  Resubmitted, Amendments, Pending, Safety Reports, Expedited Review, Other Business),
  and add a **conflict-of-interest declaration** (Annex 3) any member/chairperson can
  file against a specific agenda protocol — flagged directly on the agenda so it's
  visible before discussion and voting begin.

**Schema additions for this update** (`sql/awhsc_irb.sql`):
- New table `submission_checklists` (Annex 1 declarations + Secretariat verification)
- `reviews.assessment_json` (structured Study Assessment Form answers)
- `sae_reports`: `seriousness`, `relation_to_study`, `outcome`,
  `protocol_change_recommended`, `icf_change_recommended`, `reviewer_decision`,
  `reviewer_comment`, `reviewed_by`, `reviewed_at`
- `monitoring_visits`: full Annex 1 checklist columns (facilities, consents, AEs,
  non-compliance, CRFs, storage, protection rating, duration, decision)
- `protocols.status` / `decisions.decision` gain a new `deferred` value
- `meeting_agenda_items.category`, new table `meeting_coi_declarations`

> **Upgrading from an earlier version?** These are additive/expanded columns and
> new tables, but several ENUMs changed (`protocols.status`, `decisions.decision`,
> `sae_reports`, `monitoring_visits`). Re-import `sql/awhsc_irb.sql` into a fresh
> database rather than trying to reuse an older one.

**v2.2:**
- Split the old combined **"admin"** role into two separate actors with
  distinct responsibilities and no overlapping authority: **System
  Administrator** (accounts, roles, audit trail — explicitly walled off from
  all protocol content, documents, and messages for confidentiality) and
  **IRB Secretariat** (day-to-day administrative coordination: screening,
  reviewer assignment, meeting logistics, deadline tracking, archiving,
  and non-compliance intake — but no ethical decision authority).
- The **Chairperson** is now the only role that can record a final decision,
  decide an amendment, sign off a continuing review, resolve/close a
  non-compliance report, or close a study — reinforcing separation between
  administrative work and ethical authority.
- Added a sixth actor, **External Consultant**: an ad-hoc outside reviewer
  who only sees protocols they're explicitly assigned to, and who must
  acknowledge a confidentiality agreement from their profile before the
  system will accept a review from them.
- Added a **profile photo** add-on: every user can upload a JPG/PNG (2 MB
  max, validated by real file content) from their profile page, or remove
  it to fall back to a generated initials avatar. Photos show in the
  navigation bar, the user-management table, and the profile page.
- `role_label()` and a new `role_description()` helper now describe what
  each of the six roles is (and isn't) responsible for; this text also
  appears at the top of the profile page.
- Rebuilt the navigation menu and dashboard around the six roles — System
  Admin gets an accounts/audit-focused dashboard with **no protocol data**;
  Secretariat and Chairperson share the operational dashboard; Members and
  External Consultants share the reviewer dashboard (with a confidentiality
  reminder banner for consultants who haven't acknowledged yet).
- `protocols/list.php`, `protocols/view.php`, and `protocols/download.php`
  now actively block System Admin from viewing or downloading any protocol
  content, by design.

**v2.1:**
- Added a self-service **user profile** page (`profile/`), the
  **Communications** module (`communications/`), and the **Non-Compliance /
  Violation Management** workflow (`non_compliance/`) inside Monitoring.
  Dashboard stats surface unread messages and open non-compliance counts.

**v2.0:**
- New modules: Amendments, Continuing Review tracker, Final report & study
  closure, Study file archiving, Monitoring, Audit Log viewer, CSV export.
- Security fixes: CSRF protection everywhere, hardened session cookies and
  security headers, real MIME-type upload validation with a size cap, login
  throttling.
- Bug fix: removed a dead/incorrect `ON DUPLICATE KEY UPDATE` statement and
  added the missing unique key it depended on.

**Schema additions across all updates** (`sql/awhsc_irb.sql`):
- `users.role` ENUM expanded to `system_admin`, `secretariat`, `chairperson`,
  `member`, `external_consultant`, `researcher`
- `users.photo_path`, `users.confidentiality_ack_at`
- `protocols.archived`, `protocols.archived_at`
- New tables: `amendments`, `final_reports`, `monitoring_visits`,
  `communications`, `non_compliance_reports`
- New unique key on `reviews (protocol_id, reviewer_id)`

> **Upgrading from an earlier version?** The role values changed (`admin` no
> longer exists — it's now `system_admin` or `secretariat`), so re-import
> `sql/awhsc_irb.sql` into a fresh database and re-run `setup.php` rather than
> reusing an old database.

---

## 8. Extending the system further

Natural next steps beyond this rework:
- Email notifications (the SOP calls for notices to investigators/reviewers;
  this build stores everything but doesn't send email yet).
- Real-time/unread badges via polling or WebSockets for the Messages inbox
  (currently refreshes on page load).
- Role-based dashboards for aggregate SOP-compliance reporting (e.g. average
  turnaround time from submission to decision), building on the CSV export
  already in place.
- Participant request/complaint intake, matching step 14 of the SOP workflow
  diagram — could reuse the same pattern as Amendments/Non-Compliance.
