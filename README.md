# SkillBridge

A community-driven peer-to-peer skill-sharing platform where tutors can list their expertise and learners can discover, enroll in, and communicate with tutors across a wide range of disciplines.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Database Schema](#database-schema)
- [Project Structure](#project-structure)
- [Getting Started](#getting-started)
- [Configuration](#configuration)
- [Default Admin Account](#default-admin-account)
- [User Roles](#user-roles)
- [Security](#security)

---

## Overview

SkillBridge connects people who want to learn with those who want to teach. Tutors create skill listings — free or paid — across categories such as Programming, Design, Music, Languages, and more. Learners browse, enroll, access course materials, leave reviews, and message tutors directly through the platform. An admin panel provides full oversight of users, skills, enrollments, and platform analytics.

---

## Features

### For Learners
- **Browse & Search Skills** — filter by category, skill level, price, and keyword
- **Skill Detail Pages** — view tutor profile, qualifications, ratings, and reviews
- **Enrollment** — enroll in free or paid skill offerings with payment tracking
- **Course Materials** — access tutor-uploaded PDFs and video links after enrollment
- **Reviews** — leave a star rating and written review after completing a course
- **Messaging** — send and receive messages with tutors via the internal inbox
- **Dashboard** — overview of active enrollments, messages, and account stats
- **Profile Management** — update bio, photo, phone, and social links

### For Tutors
- **Create Skill Listings** — set title, description, category, level, price (or mark as free)
- **Upload Course Materials** — attach a course materials PDF, a learning roadmap PDF, and an external video URL
- **Manage Enrollments** — approve or reject student enrollment requests
- **Manage Skills** — edit and deactivate existing listings
- **Tutor Verification Badge** — display a verified badge once approved by an admin

### For Admins
- **Admin Dashboard** — platform-wide statistics (users, skills, enrollments, reviews)
- **User Management** — view, search, and deactivate user accounts
- **Tutor Verification** — approve or revoke tutor verified status
- **Skill Management** — review and remove skill listings
- **Enrollment Management** — monitor and manage all platform enrollments
- **Reports & Analytics** — charts and breakdowns of platform activity
- **Audit Log** — all admin actions are recorded in `admin_actions`

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP (procedural + PDO) |
| Database | MySQL 5.7+ / MariaDB |
| Frontend | Bootstrap 5.3, Font Awesome 6.4 |
| File Uploads | Native PHP (`$_FILES`), stored on disk |
| Sessions | PHP native sessions with CSRF protection |
| Password Hashing | PHP `password_hash` / `password_verify` (bcrypt, cost 12) |

---

## Database Schema

The database is named `skillbridge` and uses `utf8mb4` character encoding. The core tables are:

| Table | Purpose |
|---|---|
| `users` | All user accounts (learners, tutors, admins) |
| `social_links` | Tutor social media profile links |
| `categories` | Skill categories (seeded with 10 defaults) |
| `skill_offerings` | Tutor skill listings with pricing and materials |
| `enrollments` | Student enrollment records with payment status |
| `messages` | Internal platform messaging between users |
| `reviews` | Learner reviews and star ratings per skill |
| `admin_actions` | Audit log of all admin operations |
| `user_sessions` | Optional server-side session management |

Two SQL views are provided for common queries:

- **`tutor_stats`** — aggregated rating, review count, and skill count per tutor
- **`popular_skills`** — skill listings ranked by review count and average rating

The full schema, including seed data for categories and the default admin user, is in `skillbridge_database_schema.sql`.

---

## Project Structure

```
skillbridge/
│
├── config.php                    # DB connection, constants, helper functions
├── skillbridge_database_schema.sql  # Full DB schema + seed data
│
├── index.php                     # Public landing page
├── register.php                  # User registration
├── login.php                     # User login
├── logout.php                    # Session destruction
├── reset-password.php            # Password reset
│
├── dashboard.php                 # Logged-in user dashboard
├── profile.php                   # View user profile
├── edit-profile.php              # Edit profile details
│
├── browse-skills.php             # Public skill search & browse
├── skill-details.php             # Single skill detail page
├── create-skill.php              # Tutor: create a new skill listing
├── edit-skill.php                # Tutor: edit an existing skill listing
├── my-skills.php                 # Tutor: manage own skills
│
├── enroll.php                    # Learner: enroll in a skill
├── my-enrollments.php            # Learner: view own enrollments
├── manage-enrollments.php        # Tutor: approve/reject enrollment requests
├── course-materials.php          # Enrolled learner: access course materials
│
├── messages.php                  # Inbox / conversation list
├── conversation.php              # Single conversation thread
│
├── verify-tutor.php              # Admin: tutor verification
├── admin-dashboard.php           # Admin: platform statistics
├── admin-users.php               # Admin: user management
├── admin-skills.php              # Admin: skill management
├── admin-enrollments.php         # Admin: enrollment management
├── admin-reports.php             # Admin: reports and analytics
│
├── debug-conversation.php        # Development debug helper
│
└── uploads/
    ├── profiles/                 # User profile photos
    ├── documents/                # General uploads
    ├── materials/                # Course materials PDFs
    └── roadmaps/                 # Learning roadmap PDFs
```

---

## Getting Started

### Prerequisites

- PHP 7.4 or higher
- MySQL 5.7+ or MariaDB
- A web server (Apache or Nginx) with `mod_rewrite` enabled (Apache) or equivalent URL handling
- The PHP extensions `pdo`, `pdo_mysql`, and `fileinfo` must be enabled

### Installation

1. **Clone or extract** the project into your web server's document root (e.g., `htdocs/skillbridge` or `/var/www/html/skillbridge`).

2. **Create the database** by importing the schema file:
   ```bash
   mysql -u root -p < skillbridge_database_schema.sql
   ```
   This creates the `skillbridge` database, all tables, views, seed categories, and a default admin user.

3. **Configure the application** — edit `config.php` to match your environment (see [Configuration](#configuration) below).

4. **Set upload directory permissions** so PHP can write to the `uploads/` subdirectories:
   ```bash
   chmod -R 755 uploads/
   ```

5. **Open the site** in your browser at the URL you configured in `SITE_URL`.

---

## Configuration

All application settings live in `config.php`. Update these constants before running in production:

```php
// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'skillbridge');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');

// Site
define('SITE_NAME', 'SkillBridge');
define('SITE_URL', 'https://yourdomain.com/skillbridge');

// Uploads
define('MAX_FILE_SIZE', 5242880); // 5 MB — adjust as needed

// Session
define('SESSION_LIFETIME', 3600); // 1 hour
```

> **Production note:** Move `config.php` outside the web root and reference it via an absolute path, or use environment variables, to avoid exposing database credentials if the PHP interpreter is misconfigured.

---

## Default Admin Account

The schema seeds a single admin account:

| Field | Value |
|---|---|
| Email | `admin@skillbridge.com` |
| Password | `admin123` |

**Change this password immediately after first login.**

---

## User Roles

| Role | Description |
|---|---|
| `learner` | Can browse skills, enroll, review, and message tutors |
| `tutor` | Can create and manage skill listings, handle enrollment requests, and upload course materials |
| `both` | Combined learner and tutor capabilities |
| Admin | A flag (`is_admin`) on top of any role; grants access to all admin panel pages |

Users self-select their role at registration. Tutors must be verified by an admin before a verified badge appears on their profile.

---

## Security

The following security measures are implemented:

- **Passwords** — hashed with bcrypt (`password_hash`, cost factor 12)
- **SQL Injection** — all database queries use PDO prepared statements
- **XSS** — all user-supplied output is escaped with `htmlspecialchars` via the `escape()` helper
- **CSRF Protection** — all state-changing POST forms include and verify a CSRF token (`generateCSRFToken` / `verifyCSRFToken`)
- **File Uploads** — MIME type and size validation on all uploaded files; stored outside the PHP namespace where feasible
- **Session Hardening** — configurable session name and lifetime; sessions are destroyed cleanly on logout
- **Access Control** — every protected page checks `isLoggedIn()` and, where applicable, `isAdmin()` before executing any logic
