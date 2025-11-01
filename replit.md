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
- **November 1, 2025**: Customer website now active as main view with TRUE multi-tenant support
  - **Multi-tenant architecture**: Automatic subdomain detection from URL (e.g., nordicfitness.salesman.no shows Nordic Fitness data, bergsoy.salesman.no shows Bergsøy data)
  - **Generic placeholders**: No hardcoded company names; shows "Laster..." until API loads company-specific data
  - **Dynamic content loading**: Company name, logo, and all content populated from database based on subdomain
  - **Main landing page**: Root URL (`/`) now redirects to customer view (`/public/bergsoy/`)
  - **Admin login relocated**: Moved to `/admin.php` (previously at `/`)
  - **News/announcements on homepage**: Public website displays news from `tenant_announcements` table
  - **Multi-tenant routing**: Automatic subdomain detection with fallback to default (bergsøy)
  - **Three-page layout**: Om oss (left), Hjem (center), Tjenester (right) with swipe/touch navigation
  - **Modern gradient design**: Smooth animations and mobile-first responsive design
  - **Logo header**: Company logo and name (from `tenant_companies.name`) displayed at the top with tagline "en tjeneste til [company name] levert av Salesman.no" on right side
  - **Navigation header**: Clean, distinct header with clickable tabs and visual indicator for active page
  - **Navigation**: Users navigate by clicking header tabs or swipe gestures (arrow buttons hidden)
  - **Data sources**:
    * Company info: `tenant_companies` (Ditt Firma)
    * Website config: `tenant_website_config` (Ditt Nettsted)
    * Website settings: `tenant_website_general` (Ditt Nettsted)
    * News/announcements: `tenant_announcements` (displayed on homepage with date formatting)
    * Services: `output_products` (displayed on services page)
    * Events: `events_eventdata` (joined with output_products for event details)
  - **Event registration**: Full-screen overlay registration page with slide-up animation and **customizable registration fields**
    * Event cards show on "Tjenester" page with event image (280px height), description, date/time, venue, and price
    * Click "Reserver billett" opens full-screen registration overlay
    * Smooth slide-up animation from bottom (similar to fane/tab swipe transitions)
    * Registration page displays: event image (350px height), event name, event info box, and registration form
    * Event info box includes: description, date/time, venue, price, and "ℹ️ Medlemspriser tildeles automatisk" notice
    * Registration form header: "Påmeldingsinformasjon"
    * **Dynamic registration fields**: Form fields are configurable per event from admin panel
      - Admin can select which fields to include when creating/editing events: fornavn, etternavn, e-post, telefon, gateadresse, postnummer, poststed, postboks, alder
      - Each field can be marked as required or optional
      - Frontend dynamically generates form based on `event_registration_fields` table
      - **Sensible defaults**: Events without field configuration show [fornavn, etternavn, e-post (required), telefon (optional)]
      - **Backward compatibility**: Supports legacy 'name' field for existing data
      - Standard fields (name, email, phone, street, zip, city, postal) stored in dedicated columns
      - Additional custom fields stored in `custom_fields` JSON column
      - **Minimum validation**: Name and valid email always required regardless of configuration
    * Close button (×) with rotate animation on hover
    * Automatic capacity checking (only shows "Fullt" when full on cards)
    * Prevents duplicate registrations by email
    * Stores registrations in `events_checkin` table with all configured fields
    * No authentication required for customers
    * No capacity details shown to public (privacy)
    * Success message auto-closes overlay after 2 seconds
  - **Service booking**: Full-screen overlay booking page with simplified time slot selection
    * Service cards display "📅 Book time" button for regular services (non-events)
    * Click button opens full-screen booking overlay with slide-up animation
    * **Date selector**: Horizontal scrollable date picker showing next 14 weekdays
    * **Simplified time slots**: Shows simple LEDIG (available) or OPPTATT (booked) status for each time slot (30-min intervals, 08:00-18:00)
    * **No staff visibility**: Removed staff-grid columns for privacy and simplicity
    * **Customer form**: Name, email, phone (all required with validation)
    * **Smart allocation**: Automatically assigns available staff member for selected time slot
    * **Duplicate prevention**: Prevents same customer from booking same service multiple times
    * **Member pricing notice**: "ℹ️ Medlemspriser tildeles automatisk" displayed on booking page
    * Stores bookings in `service_bookings` table
    * No authentication required for public booking
    * Success message auto-closes overlay after 2.5 seconds
  - **Membership registration**: Full-screen overlay registration for membership sign-ups
    * Membership cards display "👤 Bli medlem" button
    * Click button opens full-screen registration overlay with slide-up animation
    * **Minimal required fields**: Name, email, phone (all validated on frontend and backend)
    * **Duplicate prevention**: Prevents same email from registering multiple times as active member
    * **Server-side validation**: API enforces all three required fields (name, email, phone)
    * Stores registrations in `tenant_members` table with status='pending'
    * No authentication required for public registration
    * Success message auto-closes overlay after 2.5 seconds
  - **Simplified contact section in Om oss**: Clean, minimalist contact display with embedded map
    * **Embedded Google Map**: Full interactive map displayed by default (300px height) with rounded corners and shadow
    * **Address from tenant_companies**: Dynamically builds address from separate columns (address_street, address_city, address_zip, address_state, address_country)
    * **Contact priority**: Prefers company contact info (`tenant_companies.contact_email`, `tenant_companies.contact_phone`) over settings fallback
    * **Simplified contact line**: Single centered line below map with phone (📞), email (✉️), and org_number separated by bullet points
    * **Interactive elements**: Clickable phone (tel:) and email (mailto:) links in dark color (#1a1a1a) with hover opacity effect
    * **Organization number**: Displays org_number from `tenant_companies` table
    * **No complex boxes**: Removed structured contact info section for cleaner, more accessible design
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
    * `get_public_service_availability.php` - Fetches available time slots for service booking (14 days, weekdays only, 30-min intervals)
    * `book_service_public.php` - Creates service booking with automatic staff assignment (no auth required)
    * `register_membership_public.php` - Public membership registration with minimal required fields (name, email, phone)

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