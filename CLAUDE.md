# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Ayuni Beta is a PHP-based web application for creating and chatting with AI companions (AEIs). It features a beta access system, user onboarding, AEI creation, and chat functionality.

## Architecture

### Core Structure
- **Entry Point**: `index.php` - Main application entry with session management, routing, and HTML layout
- **Router**: `includes/router.php` - Simple URL routing system mapping routes to pages
- **Functions**: `includes/functions.php` - Authentication, session management, CSRF protection, and utility functions
- **Database**: `config/database.example.php` - Database connection, table creation, and migrations
- **Pages**: `pages/` directory contains individual page implementations

### Database Schema
The application uses MySQL with the following core tables:
- `beta_codes` - Invitation system for beta access
- `users` - User accounts with profile data and admin flags
- `aeis` - AI companion entities linked to users
- `chat_sessions` - Chat sessions between users and AEIs
- `chat_messages` - Individual messages within chat sessions

### Security Features
- CSRF token generation and verification
- Password hashing with PHP's `password_hash()`
- Session regeneration on login
- SQL injection protection via prepared statements
- Input sanitization with `htmlspecialchars()`
- Admin role-based access control

## Development Setup

### Database Configuration
1. Copy `config/database.example.php` to `config/database.php`
2. Update database credentials in the copied file
3. The application auto-creates database and tables on first run
4. Default admin account: `fabian.budde@nexinnovations.us` / `Fabian,123`

### Local Development
- Requires PHP 7.4+ with MySQL/MariaDB
- Uses CDN resources (Tailwind CSS, Font Awesome, Google Fonts)
- No build process or package manager required
- Simply serve the root directory with PHP built-in server or Apache/Nginx

### File Structure
```
/
├── index.php              # Main entry point and HTML layout
├── config/
│   └── database.example.php # Database configuration template
├── includes/
│   ├── functions.php       # Core utility functions
│   └── router.php         # URL routing system
├── pages/                 # Individual page implementations
│   ├── home.php           # Landing/login page
│   ├── onboarding.php     # User profile setup
│   ├── create-aei.php     # AEI creation form
│   ├── chat.php           # Chat interface
│   ├── dashboard.php      # User dashboard
│   └── admin.php          # Admin panel
├── assets/
│   └── ayuni.png         # Application logo
└── database.sql          # Initial database schema
```

## Key Implementation Patterns

### Authentication Flow
- Session-based authentication using `$_SESSION['user_id']`
- `requireAuth()` and `requireAdmin()` functions for access control
- Automatic session regeneration on login for security

### Database Operations
- Global `$pdo` connection available in all files
- Use prepared statements for all queries
- ID generation via `generateId()` function using `bin2hex(random_bytes(16))`

### Routing System
- Clean URLs handled by router class
- Route definitions in `router.php`
- Dynamic routes supported (e.g., `chat/aei-id`)
- Route parameters merged into `$_GET` superglobal

### UI Framework
- Tailwind CSS for styling via CDN
- Custom color scheme: ayuni-aqua (#39D2DF), ayuni-blue (#546BEC), ayuni-dark (#10142B)
- Dark mode support with localStorage persistence
- Responsive design patterns

## Common Operations

### Adding New Pages
1. Create PHP file in `pages/` directory
2. Add route mapping in `router.php`
3. Update `$allowed_pages` array in `index.php`
4. Add page title in the `match()` statement

### Database Migrations
- Add new table creation in `createTablesIfNotExist()` function
- Column additions handled in the comprehensive migration section
- Always use `IF NOT EXISTS` for safe repeated execution

### CSRF Protection
- Generate tokens with `generateCSRFToken()`
- Verify with `verifyCSRFToken($token)`
- Include hidden input in all forms: `<input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">`