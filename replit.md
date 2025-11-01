# Salesman Superportal System

## Overview
The Salesman Superportal System is a comprehensive administration portal designed to centralize and streamline various company operations. It provides a unified platform for managing modules and settings based on user privileges, aiming to enhance efficiency across sales, webmail, reservations, economy, membership, rental, and events. The system's vision is to offer efficient oversight of diverse company functions through a single interface, delivering a single source of truth for company data.

## User Preferences
- Language: Norwegian
- Clean, compact dashboard design (not oversized)
- Simple navigation header
- Focus on functionality over visual complexity
- Modules should be displayed as tabs (faner) instead of cards
- Only show modules where user has privileges (can_access = true)
- Each module tab should have sub-tabs with the same name showing current content
- Unlock feedback must be clear and visible (green button, countdown timer, success message)

## System Architecture
The system is built on a PHP 8.2 backend with dual database support (MySQL production, PostgreSQL development/backup), emphasizing modularity and granular access control.

### UI/UX Decisions
The design prioritizes a clean, compact dashboard with a simple navigation header. Modules are presented as Bootstrap 5 tabs. The dashboard header displays the company subdomain with an active/inactive status badge and company logo.

### Technical Implementations
- **Authentication**: Session-based system with robust backend validation, failsafe messages, complete session tracking, and single-session enforcement.
- **Tab Navigation System**: Utilizes Bootstrap 5's native tab component for content display.
- **Module & Privilege Management**: Granular access control via `tenant_admin_modules` and `tenant_company_modules` tables, with a dedicated interface for staff module access management.
- **Email Module**: Integrated email client with SMTP configuration validation and `EmailService.php` for cross-module automated notifications.
- **Events Module**: Comprehensive CRUD for event management with `output_products` integration, including configurable registration fields, scanner-based check-in, and income/expense tracking.
- **Ditt Nettsted Module**: Manages website configuration from `tenant_website_config` with an admin password-protected lock mechanism.
- **Reservation Module**: Complete reservation management with agenda/calendar views, filtering, statistics, and subtabs for service types. Includes visual calendar-based booking with staff availability.
- **Sales Module with Shopping Cart**: Supports dual purchase flows (shopping cart for products, individual service sales), barcode scanner integration, customer data management, and event linking. Includes hardcoded payment methods (Kontant, Vipps, Oppmøte) and a fullscreen mode. Features purchasing for registered and non-registered items with automatic stock tracking and weighted average cost calculation.
- **Deres Firma Module**: Manages company profile, staff, and website settings.
- **Unified Economic System**: Centralized financial tracking via `unified_ledger` table, aggregating all transactions with profit tracking, managed by `UnifiedLedgerService.php`. Includes comprehensive membership income overview.
- **Output Products System**: A unified polymorphic product catalog (`output_products`) aggregating all sellable items/services from different modules, auto-generated and synchronized by `ProductCatalogService.php`. Implements cascade-and-filter patterns for product deletion and activation status.
- **Database UUID Compliance**: MySQL production uses `char(36)` UUID format for `system_modules.id` and `output_products.module_id`.

### System Design Choices
- **Modularity**: Core system features are organized into distinct modules.
- **Staff-First Architecture**: `tenant_staff` (employees) are created independently, with `tenant_admins` (login accounts) optionally linked.
- **Clean Database Architecture**: Modular database design eliminating duplicates and redundancy, standardizing tables, adding cost price tracking, and introducing `service_bookings` and `unified_ledger` tables.

## Recent Changes
- **November 1, 2025**: Customer website now active as main view
  - **Main landing page**: Root URL (`/`) now redirects to customer view (`/public/bergsoy/`)
  - **Admin login relocated**: Moved to `/admin.php` (previously at `/`)
  - **News/announcements on homepage**: Public website displays news from `tenant_announcements` table
  - **Multi-tenant routing**: Automatic subdomain detection with fallback to default (bergsøy)
  - **Three-page layout**: Om Oss (left), Hjem (center), Tjenester (right) with swipe/touch navigation
  - **Modern gradient design**: Smooth animations and mobile-first responsive design
  - **Logo header**: Company logo and name (from `tenant_companies.name`) displayed at the top
  - **Navigation header**: Clean, distinct header with clickable tabs and visual indicator for active page
  - **Dual navigation**: Users can navigate by clicking header tabs OR using arrow buttons/swipe gestures
  - **Data sources**:
    * Company info: `tenant_companies` (Ditt Firma)
    * Website config: `tenant_website_config` (Ditt Nettsted)
    * Website settings: `tenant_website_general` (Ditt Nettsted)
    * News/announcements: `tenant_announcements` (displayed on homepage with date formatting)
    * Services: `output_products` (displayed on services page)
    * Events: `events_eventdata` (joined with output_products for event details)
  - **Event registration**: Public customers can register for events directly from the website
    * Registration form: Name, email, phone
    * Automatic capacity checking (shows spots left)
    * Prevents duplicate registrations by email
    * Stores registrations in `events_checkin` table
    * No authentication required for customers
  - **Dedicated reservations page**: `/public/bergsoy/reservasjoner.php`
    * Displays all upcoming events from `events_eventdata`
    * Shows event details: title, description, date/time, venue, capacity
    * Registration form with required fields (name, email, phone)
    * Real-time capacity tracking and "full" status
    * Responsive card layout with event images
  - **API endpoints**: 
    * `get_public_website_data.php` - Fetches all public data for main website
    * `get_public_events.php` - Fetches all upcoming events for reservations page
    * `register_event_public.php` - Public event registration (no auth required)

## External Dependencies
- **Database**: Dual database support with PDO:
    - Production: MySQL (Domeneshop)
    - Development/Testing: PostgreSQL (auto-fallback when MySQL unavailable)
- **UI Framework**: Bootstrap 5 (admin), Custom CSS (customer view).
- **Email Protocols**: SMTP and IMAP for webmail.
- **Storage**: Object storage for contract document uploads.

## Project Structure
- `/admin/` - Admin interface components
- `/api/` - API endpoints for both admin and public views
- `/pages/` - Admin pages
- `/public/` - Customer-facing public websites
  - `/public/bergsoy/` - Bergsøy customer website
  - `/public/assets/` - Shared assets (CSS, JS, images)
- `/includes/` - Shared PHP utilities
- `/modules/` - Admin module content
- `/config/` - Configuration files