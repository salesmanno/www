# Salesman Superportal System

## Overview
The Salesman Superportal System is an administration portal designed to centralize and streamline company operations. It provides a unified platform for managing modules and settings based on user privileges, enhancing efficiency across sales, webmail, reservations, economy, membership, rental, and events. The system aims to offer efficient oversight of diverse company functions through a single interface, serving as a single source of truth for company data.

## User Preferences
- Language: Norwegian
- Clean, compact dashboard design (not oversized)
- Simple navigation header
- Focus on functionality over visual complexity
- Modules should be displayed as tabs (faner) instead of cards
- Only show modules where user has privileges (can_access = true)
- Each module tab should have sub-tabs with the same name showing current content
- Unlock feedback must be clear and visible (green button, countdown timer, success message)
- Customer website: NO emojis (described as "useriøst" - unprofessional)
- Customer website: White header background and white body background as defaults

## System Architecture
The system is built on a PHP 8.2 backend with dual database support (MySQL production, PostgreSQL development/backup), emphasizing modularity and granular access control.

### SaaS Admin Portal (NEW)
A comprehensive system administration portal for managing all companies/tenants in the system:
- **Access**: Discrete redirect via logo click on main landing page → `/saas_portal/login.php`
- **Authentication**: Separate authentication system for `saas_admins` table with red-themed UI
- **Features**:
  - Real-time company listing with polling (updates every 3 seconds)
  - Create new companies with automatic tenant table initialization
  - Organization number lookup integration with Brønnøysundregistrene API
  - Full CRUD operations for companies, staff, and administrators
  - Module assignment at both company and admin levels
  - Safe cascade deletion via `SafeDeleteCompany` stored procedure
  - Per-company management of employees, admins, and module privileges
- **Auto-provisioning**: When creating a new company, automatically:
  - Creates all `tenant_*` table entries (customers, settings, website_config, etc.)
  - Grants all available system modules to the company
  - Does NOT create staff or admins (must be added manually)
- **Subdomain Creation**: Automated DNS A-record creation via Domeneshop API
  - Uses `production_server_ip` from `saas_system_config` (194.63.249.219 for www.salesman.no)
  - Falls back to dynamic IP detection for development environments
  - Domain ID: 2167680 for salesman.no
- **Safe Deletion**: Uses MySQL stored procedure `SafeDeleteCompany` that:
  - Maintains referential integrity (no orphaned data)
  - Explicit transaction control with automatic rollback on error
  - Deletes all related data in correct order (children before parents)
  - Covers 25+ tenant tables including events, sales, membership, reservations, etc.
  - Procedure file: `saas_portal/sql/create_safe_delete_procedure.sql`
- **Company Configuration Tabs**:
  - **Administratorer**: Manage admin accounts with module privileges
  - **Ansatte**: Manage employees (tenant_staff)
  - **Epost**: Configure SMTP/IMAP settings per company (`tenant_email_config` table)
  - **Betaling**: Configure payment gateways (Vipps, Stripe, PayPal) via `economy_payment_gateways` table
- **Activity Logging System**: Comprehensive activity tracking across all companies
  - **Database**: `tenant_activity_logs` table with indexed fields (company_id, admin_id, action_type, created_at)
  - **Service**: `ActivityLogger.php` with methods for logging various actions
  - **Dashboard Tab**: "All aktivitet" showing real-time activity stream from all companies
  - **Live Polling**: Updates every 3 seconds, configurable message visibility (5-120 seconds slider)
  - **Active Sessions Panel**: Shows currently logged-in users per company with idle time indicators
  - **Logging Coverage**: 8 critical APIs (company/admin/staff CRUD, module updates, company deletion)
  - **Pattern**: Log BEFORE delete operations to capture entity data while it still exists
- **Module Management**: Company modules (tenant_company_modules) are managed automatically during provisioning; individual admin privileges (tenant_admin_modules) are managed per administrator
- **Tables Managed**: tenant_companies, tenant_admins, tenant_staff, tenant_admin_modules, tenant_company_modules, tenant_email_config, economy_payment_gateways, and all related tenant tables

### UI/UX Decisions
The design prioritizes a clean, compact dashboard with Bootstrap 5 tabs for modules. The customer-facing website features a multi-tenant architecture with dynamic content loading, modern gradient design, mobile-first responsiveness, and configurable design elements via `tenant_website_general` settings.

### Technical Implementations
- **Authentication**: Session-based system with robust backend validation, complete session tracking, and single-session enforcement.
- **Tab Navigation System**: Utilizes Bootstrap 5's native tab component.
- **Module & Privilege Management**: Granular access control via `tenant_admin_modules` and `tenant_company_modules` tables.
- **Email Module**: Integrated email client with SMTP configuration and `EmailService.php` for automated notifications.
- **Events Module**: Comprehensive CRUD for event management with `output_products` integration, configurable registration fields, scanner-based check-in, and income/expense tracking.
- **Ditt Nettsted Module**: Manages website configuration from `tenant_website_config` with an admin password-protected lock.
- **Reservation Module**: Complete reservation management with agenda/calendar views, filtering, statistics, and visual calendar-based booking.
- **Sales Module with Shopping Cart**: Supports dual purchase flows, barcode scanner integration, customer data management, event linking, and hardcoded payment methods. Includes stock tracking and weighted average cost calculation.
- **Deres Firma Module**: Manages company profile, staff, and website settings.
- **Unified Economic System**: Centralized financial tracking via `unified_ledger` table, managed by `UnifiedLedgerService.php`.
- **Output Products System**: Polymorphic product catalog (`output_products`) aggregating all sellable items/services, auto-generated by `ProductCatalogService.php`. Implements cascade-and-filter patterns.
- **Database UUID Compliance**: MySQL production uses `char(36)` UUID format for `system_modules.id` and `output_products.module_id`.

### System Design Choices
- **Modularity**: Core system features are organized into distinct modules.
- **Staff-First Architecture**: `tenant_staff` (employees) are created independently, with `tenant_admins` (login accounts) optionally linked.
- **Clean Database Architecture**: Modular database design eliminating duplicates, standardizing tables, and introducing `service_bookings` and `unified_ledger` tables.
- **Multi-tenant Architecture**: Automatic subdomain detection for dynamic content loading, supporting public event registration, service booking, and membership registration without authentication.
- **Website Configuration**: Customizable layout settings for customer websites including colors, layout, typography, and spacing applied via CSS variables.

## External Dependencies
- **Database**: Dual database support with PDO for MySQL (production) and PostgreSQL (development/testing).
- **UI Framework**: Bootstrap 5 (admin), Custom CSS (customer view).
- **Email Protocols**: SMTP and IMAP for webmail.
- **Storage**: Object storage for contract document uploads.