# KidSecure — GitHub Copilot Project Instructions

> Save this file at `.github/copilot-instructions.md` in the repo root.
> Copilot automatically loads it as background context for every suggestion
> and every Copilot Chat conversation in this workspace — you do not need
> to paste it manually each time.

---

## 1. What this project is

KidSecure is an RFID-Based Campus Entry/Exit and Student Enrollment
Management System built as a capstone/thesis project for **Rainbow 5
Christian Academy of Caloocan, Inc. (RCAC)**, a small private school with
~475 students (Kindergarten–Grade 6). It replaces paper-based enrollment
and unlogged, guard-based entry monitoring with:

- RFID tap-in/tap-out at a physical turnstile
- A web-based admin portal for school staff
- A mobile app for parents with real-time push notifications

The **end users are non-technical school principals, admin staff, and
teachers.** Every UI decision must optimize for people with limited
software experience — see Section 5 (UX Rules) before writing any
frontend code.

---

## 2. System architecture (how the pieces fit together)

```
┌─────────────┐     RFID scan      ┌──────────────────┐
│   ESP32 +   │ ─────────────────▶ │   Laravel API     │
│  RC522 RFID │   HTTP request      │  (kidsecure-      │
│  + Servo    │                     │   backend)        │
└─────────────┘                     │  hosted on Railway │
                                     └─────────┬─────────┘
                                               │ writes to BOTH
                             ┌─────────────────┴─────────────────┐
                             ▼                                   ▼
                   ┌───────────────────┐              ┌────────────────────┐
                   │   MongoDB Atlas    │              │  Firebase Realtime │
                   │  (source of truth) │              │  Database (RTDB)   │
                   │  students, parents,│              │  live layer only   │
                   │  attendance_logs,  │              │  /students/{id}    │
                   │  admin_activity_   │              │  /entryExitLogs    │
                   │  logs, etc.        │              │  /parents/{uid}    │
                   └─────────┬──────────┘              └─────────┬──────────┘
                             │                                    │
                             ▼                                    ▼
                  ┌────────────────────┐              ┌─────────────────────┐
                  │  Next.js Admin      │              │  Flutter Parent App │
                  │  Portal             │              │  (reads RTDB only)  │
                  │  (kidsecure-admin)  │              │  FCM push           │
                  │  talks to Laravel   │              │  notifications      │
                  │  API only           │              │                     │
                  └────────────────────┘              └─────────────────────┘
```

**Critical architectural rule: Laravel is the sole writer to both MongoDB
and Firebase RTDB.** The Next.js admin portal never talks to Firebase or
MongoDB directly — it only calls the Laravel REST API. The Flutter app
only *reads* from Firebase RTDB (via `kreait/laravel-firebase` writes
coming from Laravel) and receives FCM push notifications. It does not
write attendance data itself.

**Known, accepted dev-only shortcut:** during development/demo, the
ESP32 may write directly to Firebase RTDB, bypassing Laravel, to speed up
hardware testing. This is NOT the final architecture and should never be
treated as the target design when writing backend or mobile code.

**Real-time data layer is Firebase Realtime Database, NOT Firestore.**
If you (Copilot) generate Firestore-style code (`collection()`,
`doc()`, `getFirestore()`), that is wrong for this project — use the
`firebase_database` package / RTDB references instead.

---

## 3. Tech stack

| Layer | Technology | Notes |
|---|---|---|
| Admin frontend | Next.js (App Router) | `kidsecure-admin` repo/folder |
| Backend API | Laravel 12, PHP 8.2.12 | `kidsecure-backend` repo/folder, hosted on Railway |
| Primary database | MongoDB Atlas | database name `kidsecure`; uses `MongoDB\Laravel\Eloquent\Model` (mongodb/laravel-mongodb package) |
| Real-time layer | Firebase Realtime Database | written to only by Laravel via `kreait/laravel-firebase` |
| Push notifications | Firebase Cloud Messaging (FCM) | target: 95% delivery within 30 seconds |
| Parent mobile app | Flutter (Dart) | feature-based folder structure under `lib/` |
| Hardware | ESP32 + RC522 RFID reader + MG996R servo | Arduino C++, `Firebase_ESP_Client` (mobizt) library |
| Compliance | Philippine Data Privacy Act of 2012 (RA 10173) | applies to all student/parent data handling |

Laravel `.env` uses `SESSION_DRIVER=file`, `CACHE_STORE=file`,
`QUEUE_CONNECTION=sync` — there is intentionally no SQL/MySQL dependency
anywhere in this stack.

---

## 4. Data model reference

### MongoDB (source of truth, via Laravel Eloquent models)
Collections in use or planned: `admins`, `students`, `parents`,
`attendance_logs`, `admin_activity_logs`. Passwords are always hashed
with `bcrypt()` — never store plaintext credentials (this was a real bug
found and fixed in an earlier version of the login code).

### Firebase RTDB schema (locked — do not restructure without being told)

```
/students/{studentId}
    fullName
    gradeSection
    status            // lowercase "in" | "out"
    lastScanTime
    photoUrl          // Storage URL
    rfidTag

/entryExitLogs/{studentId}/{pushKey}   // pushKey = Firebase auto-generated ID
    // scan event history — ALWAYS use push keys, never a fixed/overwritten
    // node, or history will not accumulate

/parents/{uid}
    studentIds        // map structure, one shared account per family
    notificationsEnabled
    // NOTE: no password field — Firebase Auth handles credentials

/schoolInfo             // flat, admin-managed node for contact screen

/notifications/{parentUid}   // per-parent notification history, for unread badges
```

**One shared parent account per family** is the intended design — do
not generate per-individual-parent account logic. Parent accounts are
pre-created by school admins only; there is no self-registration flow
anywhere in this system.

**Enrollment is walk-in and admin-managed only.** Do not build or
suggest an online/self-service enrollment or an "approval workflow" for
applications — that concept was in an early planning doc and has been
superseded. Admin staff enter a student directly; there is nothing to
approve or reject.

---

## 5. UX rules for all Next.js/admin frontend work

The admin portal is used by **non-technical preschool/elementary
principals and staff**, not developers or IT admins. Apply these rules
to every component, page, and copy string you generate:

1. **Plain language only.** Say "Student Records," "View Attendance
   Report," "Login Information" — never "Data Analytics Module,"
   "Authentication Administration," "Execute Query."
2. **Clear text labels on every important action/button** — icons may
   accompany text but must not replace it for primary actions.
3. **Strong visual hierarchy** — page title → key info → primary action
   → secondary actions → supporting info. Not everything gets equal
   visual weight.
4. **Consistency** — same placement for the same kind of action across
   pages (e.g., a page's main "Add" button always top-right).
5. **Guided, step-by-step flows for multi-part tasks** (e.g., Add
   Student = Student Info → Parent/Guardian Info → RFID Tag Assignment
   → Review → Done). Never put everything on one long form.
6. **Minimal cognitive load** — shortest reasonable number of steps, no
   hidden actions, no unexplained icons, no unnecessary confirmation
   screens (but DO confirm destructive actions like deleting a record).
7. **Human-readable error messages.** Never show raw exceptions/stack
   traces/SQL errors to the user. Example pattern:
   `⚠️ Student ID is required.` — identify the field, explain what's
   wrong, imply the fix.
8. **Immediate feedback on every action** — success/failure state must
   always be visible (`✅ Student successfully added.`).
9. **Color is never the only signal** — always pair status color with
   text/icon (e.g., 🟢 Present, 🟡 Late, 🔴 Absent).
10. **Accessible by default** — readable font sizes, strong contrast,
    large click targets, keyboard-navigable where practical.
11. **Responsive** for desktop, laptop, and tablet — prioritize the most
    important info/actions first on smaller viewports rather than
    shrinking everything uniformly.
12. **No over-engineering** — no gratuitous animation, 3D, decorative
    charts, or enterprise-dashboard density. Simple and clean beats
    impressive-looking.

When generating a new page or component, default to: generous
whitespace, one clear primary action, familiar school-admin
terminology, and a layout consistent with other already-built pages in
this repo.

---

## 6. Coding conventions

- **Frontend (Next.js):** App Router conventions, functional components,
  `useState`/`fetch` for simple flows (no extra state-management library
  unless explicitly requested). Match the styling/component patterns
  already present in `kidsecure-admin` rather than introducing a new UI
  library mid-project.
- **Backend (Laravel):** Eloquent models extend
  `MongoDB\Laravel\Eloquent\Model`, not the default SQL `Model`. Follow
  RESTful route/controller naming (`/api/login`, `/api/students`, etc.).
  Any endpoint that writes attendance or student data must write to
  MongoDB AND Firebase RTDB in the same request (dual-write), per the
  architecture in Section 2 — do not write to only one.
- **Flutter:** feature-based folder structure under `lib/` (screens,
  services, models, widgets already established — follow existing file
  naming like `auth_service.dart`, `firestore_service.dart` — note that
  despite the filename, this service talks to **RTDB**, not Firestore).
- **Arduino/ESP32:** uses the `Firebase_ESP_Client` (mobizt) library with
  anonymous Firebase auth; keep read/write logic minimal on-device —
  business logic belongs in Laravel, not firmware, except for the
  accepted dev-only direct-to-RTDB shortcut noted in Section 2.
- Prefer explaining *why* a change is made in comments when the reason
  is non-obvious (e.g., "// push key required here so history
  accumulates instead of overwriting").

---

## 7. What NOT to do

- Do not introduce Firestore syntax/SDKs anywhere — this project uses
  Realtime Database.
- Do not design or scaffold a parent self-registration or online
  enrollment flow — enrollment is walk-in/admin-only.
- Do not scaffold per-parent individual accounts — one shared account
  per family.
- Do not have the Next.js admin portal call Firebase or MongoDB
  directly — it must go through the Laravel API.
- Do not generate enterprise/technical UI patterns (dense tables full of
  jargon, unlabeled icon toolbars, multi-level nested settings) for the
  admin portal — see Section 5.
- Do not store or suggest storing plaintext passwords anywhere.
