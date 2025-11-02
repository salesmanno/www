// Configuration
const API_BASE = '/api';

// Automatically detect subdomain from URL (e.g., nordicfitness.salesman.no -> nordicfitness)
function getSubdomain() {
    const hostname = window.location.hostname;
    const parts = hostname.split('.');
    
    // If running on localhost or IP, use bergsoy as default
    if (hostname === 'localhost' || hostname.startsWith('127.0.0.1') || hostname.includes('replit')) {
        return 'bergsoy';
    }
    
    // Return first part of domain (e.g., nordicfitness from nordicfitness.salesman.no)
    return parts[0];
}

const subdomain = getSubdomain();

// State
let currentPage = 'home';
let touchStartX = 0;
let touchEndX = 0;
let isTransitioning = false;

// Page order
const pages = ['about', 'home', 'services'];

// Initialize on load
document.addEventListener('DOMContentLoaded', () => {
    // Set initial page position (home = index 1, so -100vw)
    const container = document.getElementById('pagesContainer');
    const homeIndex = pages.indexOf('home');
    const offset = -homeIndex * 100;
    container.style.transform = `translateX(${offset}vw)`;
    
    loadData();
    setupSwipeListeners();
    setupDotNavigation();
});

// Load data from API
async function loadData() {
    try {
        const response = await fetch(`${API_BASE}/get_public_website_data.php?subdomain=${subdomain}`);
        const data = await response.json();
        
        if (data.success) {
            updatePageContent(data);
        } else {
            console.error('Failed to load data:', data.error);
        }
    } catch (error) {
        console.error('Error loading data:', error);
    }
}

// Store hasMembershipModule globally
let hasMembershipModule = false;

// Update page content with API data
function updatePageContent(data) {
    // Store membership module status
    hasMembershipModule = data.hasMembershipModule || false;
    
    // Update company info in header
    if (data.company) {
        // Update page title
        document.title = data.company.name;
        
        // Update company name in header
        const headerNameEl = document.getElementById('headerCompanyName');
        if (headerNameEl) {
            headerNameEl.textContent = data.company.name;
        }
        
        // Update company name in tagline
        const companyNameTagline = document.getElementById('companyNameTagline');
        if (companyNameTagline) {
            companyNameTagline.textContent = data.company.name;
        }
        
        // Update logo
        const headerLogoEl = document.getElementById('headerLogo');
        if (headerLogoEl && data.company.logo_url) {
            headerLogoEl.src = data.company.logo_url;
            headerLogoEl.style.display = 'block';
            headerLogoEl.alt = data.company.name + ' Logo';
        }
    }
    
    // Update hero section with settings
    if (data.settings) {
        const subtitleEl = document.getElementById('heroSubtitle');
        if (subtitleEl && data.settings.hero_description) {
            subtitleEl.textContent = data.settings.hero_description;
        }
        
        // Apply custom colors and layout from settings
        if (data.settings.header_bg_color) {
            document.documentElement.style.setProperty('--header-bg-color', data.settings.header_bg_color);
        }
        if (data.settings.body_bg_color) {
            document.documentElement.style.setProperty('--body-bg-color', data.settings.body_bg_color);
        }
        if (data.settings.primary_color) {
            document.documentElement.style.setProperty('--primary-color', data.settings.primary_color);
        }
        if (data.settings.secondary_color) {
            document.documentElement.style.setProperty('--secondary-color', data.settings.secondary_color);
        }
        if (data.settings.logo_height) {
            document.documentElement.style.setProperty('--logo-height', data.settings.logo_height);
        }
        if (data.settings.header_height) {
            document.documentElement.style.setProperty('--header-height', data.settings.header_height);
        }
        if (data.settings.font_family) {
            document.documentElement.style.setProperty('--font-family', data.settings.font_family);
        }
        if (data.settings.border_radius) {
            document.documentElement.style.setProperty('--border-radius', data.settings.border_radius);
        }
        if (data.settings.spacing_unit) {
            document.documentElement.style.setProperty('--spacing-unit', data.settings.spacing_unit);
        }
    }
    
    // Update About Us content
    if (data.aboutUs) {
        const aboutContent = document.getElementById('aboutContent');
        if (aboutContent) {
            let content = data.aboutUs;
            
            // Add simplified contact info
            const company = data.company || {};
            const settings = data.settings || {};
            
            // Build address from company fields
            let fullAddress = '';
            if (company.address_street || company.address_city) {
                const addressParts = [];
                if (company.address_street) addressParts.push(company.address_street);
                
                const cityLine = [];
                if (company.address_zip) cityLine.push(company.address_zip);
                if (company.address_city) cityLine.push(company.address_city);
                if (cityLine.length > 0) addressParts.push(cityLine.join(' '));
                
                if (company.address_state) addressParts.push(company.address_state);
                if (company.address_country) addressParts.push(company.address_country);
                
                fullAddress = addressParts.join(', ');
            }
            
            // Prefer company contact info, fallback to settings
            const contactPhone = company.contact_phone || settings.contact_phone;
            const contactEmail = company.contact_email || settings.contact_email;
            const contactAddress = fullAddress || settings.contact_address;
            const companyName = company.name || 'Oss';
            
            // Add embedded Google Map first
            if (contactAddress) {
                const mapQuery = encodeURIComponent(contactAddress);
                content += `
                    <div class="simple-map-container">
                        <iframe 
                            src="https://maps.google.com/maps?q=${mapQuery}&t=&z=13&ie=UTF8&iwloc=&output=embed"
                            width="100%" 
                            height="300" 
                            style="border:0; border-radius: 12px; margin-top: 2rem;" 
                            allowfullscreen="" 
                            loading="lazy" 
                            referrerpolicy="no-referrer-when-downgrade">
                        </iframe>
                    </div>`;
            }
            
            // Add simple contact info below map
            const orgNumber = company.org_number;
            
            if (contactPhone || contactEmail || orgNumber) {
                content += '<div class="simple-contact">';
                content += `<p style="margin-top: 1.5rem; font-size: 1rem; color: #1a1a1a; line-height: 1.8;">`;
                
                if (contactPhone) {
                    content += `<a href="tel:${escapeHtml(contactPhone)}" style="color: #1a1a1a; text-decoration: none; font-weight: 500;">${escapeHtml(contactPhone)}</a>`;
                }
                
                if (contactPhone && contactEmail) {
                    content += ` • `;
                }
                
                if (contactEmail) {
                    content += `<a href="mailto:${escapeHtml(contactEmail)}" style="color: #1a1a1a; text-decoration: none; font-weight: 500;">${escapeHtml(contactEmail)}</a>`;
                }
                
                if ((contactPhone || contactEmail) && orgNumber) {
                    content += ` • `;
                }
                
                if (orgNumber) {
                    content += `Org.nr: ${escapeHtml(orgNumber)}`;
                }
                
                content += '</p></div>';
            }
            
            aboutContent.innerHTML = content;
        }
    } else {
        const aboutContent = document.getElementById('aboutContent');
        if (aboutContent) {
            aboutContent.innerHTML = '<p>Informasjon kommer snart...</p>';
        }
    }
    
    // Update News/Announcements
    if (data.announcements && data.announcements.length > 0) {
        const newsContainer = document.getElementById('newsContainer');
        if (newsContainer) {
            newsContainer.innerHTML = data.announcements.map(news => `
                <div class="news-card ${news.is_pinned ? 'pinned' : ''}">
                    ${news.image_url ? `<img src="${escapeHtml(news.image_url)}" alt="${escapeHtml(news.title)}" class="news-image">` : ''}
                    <h3>${escapeHtml(news.title)}</h3>
                    <p>${escapeHtml(news.content)}</p>
                    <div class="news-date">${formatDate(news.publish_at || news.created_at)}</div>
                </div>
            `).join('');
        }
    } else {
        const newsContainer = document.getElementById('newsContainer');
        if (newsContainer) {
            newsContainer.innerHTML = '<p>Ingen nyheter for øyeblikket.</p>';
        }
    }
    
    // Update Services
    if (data.services && data.services.length > 0) {
        const servicesGrid = document.getElementById('servicesGrid');
        if (servicesGrid) {
            servicesGrid.innerHTML = data.services.map(service => {
                if (service.product_type === 'event') {
                    // Event card - opens registration overlay
                    const spotsLeft = service.max_attendees ? service.max_attendees - (service.registered_count || 0) : null;
                    const isFull = spotsLeft !== null && spotsLeft <= 0;
                    const eventData = JSON.stringify(service).replace(/"/g, '&quot;');
                    
                    return `
                        <div class="service-card event-card">
                            ${service.image_url ? `<img src="${escapeHtml(service.image_url)}" alt="${escapeHtml(service.name)}" class="event-image">` : ''}
                            
                            <div class="event-card-content">
                                <h3 class="event-title">${escapeHtml(service.name)}</h3>
                                
                                ${service.description ? `<p class="event-description">${escapeHtml(service.description)}</p>` : ''}
                                
                                <div class="event-info-grid">
                                    ${service.start_date ? `
                                        <div class="event-info-row">
                                            <span class="event-info-label">Dato:</span>
                                            <span class="event-info-value">${formatEventDate(service.start_date, service.start_time)}</span>
                                        </div>
                                    ` : ''}
                                    ${service.venue_name ? `
                                        <div class="event-info-row">
                                            <span class="event-info-label">Sted:</span>
                                            <span class="event-info-value">${escapeHtml(service.venue_name)}</span>
                                        </div>
                                    ` : ''}
                                    ${service.ticket_price ? `
                                        <div class="event-info-row">
                                            <span class="event-info-label">Pris:</span>
                                            <span class="event-info-value">${formatPrice(service.ticket_price)} kr</span>
                                        </div>
                                    ` : `
                                        <div class="event-info-row">
                                            <span class="event-info-label">Pris:</span>
                                            <span class="event-info-value">Gratis</span>
                                        </div>
                                    `}
                                    ${isFull ? `
                                        <div class="event-info-row">
                                            <span class="event-info-label">Status:</span>
                                            <span class="event-info-value event-full">Fullt booket</span>
                                        </div>
                                    ` : ''}
                                </div>
                                
                                ${!isFull ? `
                                    <button class="register-btn" onclick='openEventRegistration(${eventData})'>
                                        Reserver billett
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                } else {
                    // Regular service/product card
                    const isService = service.product_type === 'service';
                    const bookingData = {
                        ...service,
                        service_id: service.id  // Use output_products.id for booking API
                    };
                    
                    return `
                        <div class="service-card">
                            <h3>${escapeHtml(service.name)}</h3>
                            ${service.description ? `<p>${escapeHtml(service.description)}</p>` : ''}
                            ${service.price ? `<div class="service-price">${formatPrice(service.price)} kr</div>` : ''}
                            ${isService ? `
                                <button class="book-service-btn" onclick='openServiceBooking(${JSON.stringify(bookingData).replace(/'/g, "&apos;")})'>
                                    Book time
                                </button>
                            ` : ''}
                            ${service.product_type === 'membership' ? `
                                <button class="book-service-btn" onclick='openMembershipRegistration(${JSON.stringify(service).replace(/'/g, "&apos;")})'>
                                    Bli medlem
                                </button>
                            ` : ''}
                        </div>
                    `;
                }
            }).join('');
        }
    } else {
        const servicesGrid = document.getElementById('servicesGrid');
        if (servicesGrid) {
            servicesGrid.innerHTML = '<p>Ingen tjenester tilgjengelig for øyeblikket.</p>';
        }
    }
}

// Swipe navigation
function swipeTo(pageName) {
    if (isTransitioning) return;
    
    // Update header tabs
    document.querySelectorAll('.nav-tab').forEach(tab => {
        tab.classList.remove('active');
        if (tab.getAttribute('data-page') === pageName) {
            tab.classList.add('active');
        }
    });
    
    const pageIndex = pages.indexOf(pageName);
    if (pageIndex === -1) return;
    
    isTransitioning = true;
    currentPage = pageName;
    
    const container = document.getElementById('pagesContainer');
    const offset = -pageIndex * 100;
    
    container.style.transform = `translateX(${offset}vw)`;
    container.classList.add('transitioning');
    
    updateActivePage();
    
    setTimeout(() => {
        isTransitioning = false;
        container.classList.remove('transitioning');
    }, 400);
}

// Update active page class
function updateActivePage() {
    document.querySelectorAll('.page').forEach(page => {
        page.classList.remove('active');
    });
    
    const activePage = document.getElementById(`${currentPage}Page`);
    if (activePage) {
        activePage.classList.add('active');
    }
}

// Update navigation tabs (handled in swipeTo function)

// Setup swipe listeners
function setupSwipeListeners() {
    const container = document.getElementById('pagesContainer');
    
    container.addEventListener('touchstart', (e) => {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });
    
    container.addEventListener('touchend', (e) => {
        touchEndX = e.changedTouches[0].clientX;
        handleSwipe();
    }, { passive: true });
}

// Handle swipe gesture
function handleSwipe() {
    const swipeThreshold = 50;
    const diff = touchStartX - touchEndX;
    
    if (Math.abs(diff) < swipeThreshold) return;
    
    const currentIndex = pages.indexOf(currentPage);
    
    if (diff > 0 && currentIndex < pages.length - 1) {
        // Swipe left - go to next page
        swipeTo(pages[currentIndex + 1]);
    } else if (diff < 0 && currentIndex > 0) {
        // Swipe right - go to previous page
        swipeTo(pages[currentIndex - 1]);
    }
}

// Setup dot navigation clicks
function setupDotNavigation() {
    document.querySelectorAll('.dot').forEach(dot => {
        dot.addEventListener('click', () => {
            swipeTo(dot.dataset.page);
        });
    });
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatPrice(price) {
    return parseFloat(price).toLocaleString('no-NO', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDate(dateString) {
    if (!dateString) return '';
    
    const date = new Date(dateString);
    const now = new Date();
    const diffTime = Math.abs(now - date);
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) {
        return 'I dag';
    } else if (diffDays === 1) {
        return 'I går';
    } else if (diffDays < 7) {
        return `${diffDays} dager siden`;
    } else {
        return date.toLocaleDateString('no-NO', {
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
    }
}

function formatEventDate(dateStr, timeStr) {
    if (!dateStr) return '';
    
    const date = new Date(dateStr);
    const dateFormatted = date.toLocaleDateString('no-NO', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric'
    });
    
    if (timeStr) {
        return `${dateFormatted} kl. ${timeStr.substring(0, 5)}`;
    }
    
    return dateFormatted;
}

// Global payment methods cache
let paymentMethods = [];
let paymentMethodsLoaded = false;
let paymentMethodsPromise = null;

// Fetch payment methods on page load
async function fetchPaymentMethods() {
    if (paymentMethodsPromise) {
        return paymentMethodsPromise; // Return existing promise if already fetching
    }
    
    paymentMethodsPromise = (async () => {
        try {
            const response = await fetch('/api/get_payment_methods.php');
            const result = await response.json();
            if (result.success && result.payment_methods) {
                paymentMethods = result.payment_methods;
            }
        } catch (error) {
            console.error('Error fetching payment methods:', error);
        } finally {
            paymentMethodsLoaded = true;
        }
    })();
    
    return paymentMethodsPromise;
}

// Call on page load
fetchPaymentMethods();

// Generate payment method dropdown HTML
function renderPaymentMethodDropdown(required = false) {
    if (!paymentMethods || paymentMethods.length === 0) {
        return ''; // Return empty if no payment methods configured
    }
    
    const requiredAttr = required ? 'required' : '';
    const requiredLabel = required ? ' *' : '';
    
    return `
        <div class="form-field">
            <label for="payment_method">Betalingsmetode${requiredLabel}</label>
            <select id="payment_method" name="payment_method" ${requiredAttr}>
                ${paymentMethods.map(method => 
                    `<option value="${method.provider_name}">${escapeHtml(method.display_name)}</option>`
                ).join('')}
            </select>
        </div>
    `;
}

// Open event registration overlay
async function openEventRegistration(eventData) {
    // Ensure payment methods are loaded before rendering form
    await fetchPaymentMethods();
    
    const overlay = document.getElementById('eventRegistrationOverlay');
    const content = document.getElementById('registrationContent');
    
    // Generate form fields based on registration_fields configuration
    let fields = eventData.registration_fields || [];
    
    // If no fields configured, use sensible defaults (name and email required)
    if (fields.length === 0) {
        fields = [
            {field_name: 'first_name', field_label: 'Fornavn', field_type: 'text', is_required: true},
            {field_name: 'last_name', field_label: 'Etternavn', field_type: 'text', is_required: true},
            {field_name: 'email', field_label: 'E-postadresse', field_type: 'email', is_required: true},
            {field_name: 'phone', field_label: 'Telefonnummer', field_type: 'tel', is_required: false}
        ];
    }
    
    // Generate HTML for form fields
    const formFieldsHtml = fields.map(field => {
        const fieldId = `reg_${field.field_name}`;
        const isRequired = field.is_required;
        const requiredAttr = isRequired ? 'required' : '';
        const requiredLabel = isRequired ? ' *' : '';
        
        // Determine input attributes based on field type
        let inputAttrs = '';
        let placeholder = '';
        
        switch (field.field_type) {
            case 'email':
                placeholder = 'ola@example.com';
                break;
            case 'tel':
                inputAttrs = 'pattern="[0-9]{8,15}"';
                placeholder = '912 34 567';
                break;
            case 'number':
                inputAttrs = 'min="0" max="150"';
                placeholder = '25';
                break;
            case 'text':
            default:
                inputAttrs = 'minlength="2"';
                placeholder = '';
                break;
        }
        
        return `
            <div class="form-field">
                <label for="${fieldId}">${escapeHtml(field.field_label)}${requiredLabel}</label>
                <input type="${field.field_type}" id="${fieldId}" name="${field.field_name}" ${requiredAttr} ${inputAttrs} placeholder="${placeholder}">
            </div>
        `;
    }).join('');
    
    // Build registration page content
    content.innerHTML = `
        ${eventData.image_url ? `<img src="${escapeHtml(eventData.image_url)}" alt="${escapeHtml(eventData.name)}" class="registration-event-image">` : ''}
        
        <h2 class="registration-event-title">${escapeHtml(eventData.name)}</h2>
        
        <div class="registration-event-info">
            ${eventData.description ? `<p class="event-info-description">${escapeHtml(eventData.description)}</p>` : ''}
            ${eventData.start_date ? `<div class="event-info-item">${formatEventDate(eventData.start_date, eventData.start_time)}</div>` : ''}
            ${eventData.venue_name ? `<div class="event-info-item">${escapeHtml(eventData.venue_name)}</div>` : ''}
            ${eventData.ticket_price ? `<div class="event-info-item">${formatPrice(eventData.ticket_price)} kr</div>` : ''}
            <div class="event-info-notice">Medlemspriser tildeles automatisk</div>
        </div>
        
        <form id="eventRegistrationForm" class="registration-form" onsubmit="submitEventRegistration(event, '${eventData.product_id}')">
            <h3 class="form-title">Påmeldingsinformasjon</h3>
            
            ${formFieldsHtml}
            
            ${renderPaymentMethodDropdown(true)}
            
            <div id="registrationMessage" class="registration-message" style="display: none;"></div>
            
            <button type="submit" class="submit-registration-btn">Send reservasjon</button>
        </form>
    `;
    
    // Show overlay with slide-up animation
    overlay.classList.add('active');
}

// Close event registration overlay
function closeEventRegistration() {
    const overlay = document.getElementById('eventRegistrationOverlay');
    overlay.classList.remove('active');
}

// Open service booking overlay
let currentServiceData = null;
let currentAvailability = null;
let selectedDate = null;
let selectedTime = null;

async function openServiceBooking(serviceData) {
    currentServiceData = serviceData;
    const overlay = document.getElementById('serviceBookingOverlay');
    const content = document.getElementById('serviceBookingContent');
    
    // Use service_id if available, otherwise fall back to id
    const serviceId = serviceData.service_id || serviceData.id;
    
    // Show loading state
    content.innerHTML = `
        <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
        <div style="text-align: center; padding: 40px;">
            <p>Laster tilgjengelige tider...</p>
        </div>
    `;
    
    overlay.classList.add('active');
    
    // Fetch availability
    try {
        const response = await fetch(`/api/get_public_service_availability.php?service_id=${serviceId}&days=14`);
        const result = await response.json();
        
        if (result.success) {
            currentAvailability = result;
            renderServiceBookingModal();
        } else {
            content.innerHTML = `
                <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
                <div style="text-align: center; padding: 40px;">
                    <p style="color: #e74c3c;">${result.message || 'Kunne ikke hente tilgjengelige tider'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error fetching availability:', error);
        content.innerHTML = `
            <h2 class="registration-event-title">Book ${escapeHtml(serviceData.name)}</h2>
            <div style="text-align: center; padding: 40px;">
                <p style="color: #e74c3c;">En feil oppstod. Vennligst prøv igjen senere.</p>
            </div>
        `;
    }
}

async function renderServiceBookingModal() {
    const content = document.getElementById('serviceBookingContent');
    const service = currentAvailability.service;
    const availability = currentAvailability.availability;
    const dates = Object.keys(availability).sort();
    
    // Auto-select first date if none selected
    if (!selectedDate && dates.length > 0) {
        selectedDate = dates[0];
    }
    
    // If we need to render booking form, ensure payment methods are loaded
    const bookingFormHtml = selectedTime ? await renderBookingForm() : '';
    
    content.innerHTML = `
        <h2 class="registration-event-title">${escapeHtml(service.name)}</h2>
        
        <div class="registration-event-info">
            ${service.description ? `<p class="event-info-description">${escapeHtml(service.description)}</p>` : ''}
            <div class="event-info-item">Varighet: ${service.duration} minutter</div>
            ${service.price ? `<div class="event-info-item">${formatPrice(service.price)} kr</div>` : ''}
            <div class="event-info-notice">Medlemspriser tildeles automatisk</div>
        </div>
        
        <div class="booking-calendar-section">
            <h3 class="form-title">Velg dato</h3>
            <div class="date-selector">
                ${dates.map(date => {
                    const dateObj = new Date(date);
                    const dayName = dateObj.toLocaleDateString('no-NO', { weekday: 'short' });
                    const dayNum = dateObj.getDate();
                    const monthName = dateObj.toLocaleDateString('no-NO', { month: 'short' });
                    const isSelected = selectedDate === date;
                    
                    return `
                        <button type="button" class="date-option ${isSelected ? 'selected' : ''}" onclick="selectDate('${date}')">
                            <div class="date-day">${dayName}</div>
                            <div class="date-num">${dayNum}</div>
                            <div class="date-month">${monthName}</div>
                        </button>
                    `;
                }).join('')}
            </div>
        </div>
        
        ${selectedDate ? renderTimeSlots() : ''}
        
        ${bookingFormHtml}
    `;
}

function renderTimeSlots() {
    const dateData = currentAvailability.availability[selectedDate];
    if (!dateData) return '';
    
    const slots = dateData.slots;
    const timeSlots = Object.values(slots);
    
    return `
        <div class="booking-time-section">
            <h3 class="form-title">Velg tidspunkt</h3>
            <div class="time-slots-list">
                ${timeSlots.map(slot => {
                    const isSelected = selectedTime === slot.time;
                    const isAvailable = slot.is_available;
                    const statusText = isAvailable ? 'LEDIG' : 'OPPTATT';
                    const statusClass = isAvailable ? 'available' : 'unavailable';
                    
                    return `
                        <button type="button" 
                                class="time-slot-button ${statusClass} ${isSelected ? 'selected' : ''}" 
                                ${isAvailable ? `onclick="selectSimpleTimeSlot('${slot.time}')"` : 'disabled'}>
                            <span class="time-slot-time">${slot.time_display}</span>
                            <span class="time-slot-status ${statusClass}">${statusText}</span>
                        </button>
                    `;
                }).join('')}
            </div>
        </div>
    `;
}

async function renderBookingForm() {
    // Ensure payment methods are loaded before rendering form
    await fetchPaymentMethods();
    
    const timeObj = new Date(`${selectedDate} ${selectedTime}`);
    const timeDisplay = timeObj.toLocaleTimeString('no-NO', { hour: '2-digit', minute: '2-digit' });
    const dateDisplay = new Date(selectedDate).toLocaleDateString('no-NO', { 
        weekday: 'long', 
        day: 'numeric', 
        month: 'long' 
    });
    
    return `
        <form id="serviceBookingForm" class="registration-form" onsubmit="submitServiceBooking(event)">
            <h3 class="form-title">Bekreft booking</h3>
            
            <div class="booking-summary">
                <div class="summary-item">${dateDisplay}</div>
                <div class="summary-item">Kl. ${timeDisplay}</div>
            </div>
            
            <div class="form-field">
                <label for="booking_name">Fullt navn *</label>
                <input type="text" id="booking_name" name="name" required minlength="2" placeholder="Ola Nordmann">
            </div>
            
            <div class="form-field">
                <label for="booking_email">E-postadresse *</label>
                <input type="email" id="booking_email" name="email" required placeholder="ola@example.com">
            </div>
            
            <div class="form-field">
                <label for="booking_phone">Telefonnummer *</label>
                <input type="tel" id="booking_phone" name="phone" required pattern="[0-9]{8,15}" placeholder="912 34 567">
            </div>
            
            ${renderPaymentMethodDropdown(true)}
            
            <div id="bookingMessage" class="registration-message" style="display: none;"></div>
            
            <button type="submit" class="submit-registration-btn">Bekreft booking</button>
        </form>
    `;
}

function selectDate(date) {
    selectedDate = date;
    selectedTime = null;
    renderServiceBookingModal();
}

async function selectSimpleTimeSlot(time) {
    selectedTime = time;
    await renderServiceBookingModal();
    
    // Scroll to booking form
    setTimeout(() => {
        const form = document.getElementById('serviceBookingForm');
        if (form) {
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 100);
}

async function submitServiceBooking(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageEl = document.getElementById('bookingMessage');
    
    // Use service_id if available, otherwise fall back to id
    const serviceId = currentServiceData.service_id || currentServiceData.id;
    
    const formData = {
        service_id: serviceId,
        date: selectedDate,
        time: selectedTime,
        customer_name: form.name.value.trim(),
        customer_email: form.email.value.trim(),
        customer_phone: form.phone.value.trim(),
        payment_method: form.payment_method ? form.payment_method.value : null
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.style.display = 'none';
    
    try {
        const response = await fetch('/api/book_service_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'registration-message success';
            messageEl.textContent = result.message || 'Bookingen er bekreftet!';
            messageEl.style.display = 'block';
            
            form.reset();
            
            setTimeout(() => {
                closeServiceBooking();
            }, 2500);
        } else {
            messageEl.className = 'registration-message error';
            messageEl.textContent = result.message || 'En feil oppstod. Vennligst prøv igjen.';
            messageEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Bekreft booking';
        }
    } catch (error) {
        console.error('Booking error:', error);
        messageEl.className = 'registration-message error';
        messageEl.textContent = 'En feil oppstod. Vennligst prøv igjen senere.';
        messageEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Bekreft booking';
    }
}

function closeServiceBooking() {
    const overlay = document.getElementById('serviceBookingOverlay');
    overlay.classList.remove('active');
    selectedDate = null;
    selectedTime = null;
    currentServiceData = null;
    currentAvailability = null;
}

// Membership Registration
function openMembershipRegistration(membershipData) {
    const overlay = document.getElementById('membershipRegistrationOverlay');
    const content = document.getElementById('membershipContent');
    
    content.innerHTML = `
        <h2 class="registration-event-title">${escapeHtml(membershipData.name)}</h2>
        
        <div class="registration-event-info">
            ${membershipData.description ? `<p class="event-info-description">${escapeHtml(membershipData.description)}</p>` : ''}
            <div class="event-info-item">${formatPrice(membershipData.price)} kr per år</div>
            <div class="event-info-notice">Du vil motta bekreftelse på e-post</div>
        </div>
        
        <form id="membershipForm" class="registration-form" onsubmit="submitMembershipRegistration(event, '${membershipData.id}')">
            <h3 class="form-title">Registrer deg som medlem</h3>
            
            <div class="form-field">
                <label for="member_name">Fullt navn *</label>
                <input type="text" id="member_name" name="name" required minlength="2" placeholder="Ola Nordmann">
            </div>
            
            <div class="form-field">
                <label for="member_email">E-postadresse *</label>
                <input type="email" id="member_email" name="email" required placeholder="ola@example.com">
            </div>
            
            <div class="form-field">
                <label for="member_phone">Telefonnummer *</label>
                <input type="tel" id="member_phone" name="phone" required pattern="[0-9]{8,15}" placeholder="912 34 567">
            </div>
            
            <div id="membershipMessage" class="registration-message" style="display: none;"></div>
            
            <button type="submit" class="submit-registration-btn">Bli medlem</button>
        </form>
    `;
    
    overlay.classList.add('active');
}

async function submitMembershipRegistration(e, membershipId) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageEl = document.getElementById('membershipMessage');
    
    const formData = {
        membership_id: membershipId,
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim()
    };
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.style.display = 'none';
    
    try {
        const response = await fetch('/api/register_membership_public.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'registration-message success';
            messageEl.textContent = result.message || 'Medlemskap registrert! Du vil motta bekreftelse på e-post.';
            messageEl.style.display = 'block';
            form.reset();
            
            setTimeout(() => {
                closeMembershipRegistration();
            }, 2500);
        } else {
            messageEl.className = 'registration-message error';
            messageEl.textContent = result.message || 'En feil oppstod. Vennligst prøv igjen.';
            messageEl.style.display = 'block';
        }
    } catch (error) {
        console.error('Error submitting membership:', error);
        messageEl.className = 'registration-message error';
        messageEl.textContent = 'En feil oppstod. Vennligst prøv igjen senere.';
        messageEl.style.display = 'block';
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Bli medlem';
    }
}

function closeMembershipRegistration() {
    const overlay = document.getElementById('membershipRegistrationOverlay');
    overlay.classList.remove('active');
}

// Submit event registration
async function submitEventRegistration(e, eventId) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageEl = document.getElementById('registrationMessage');
    
    // Collect all form fields dynamically
    const formData = { event_id: eventId };
    const formElements = form.elements;
    
    for (let element of formElements) {
        if (element.name && element.type !== 'submit') {
            formData[element.name] = element.value.trim();
        }
    }
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageEl.style.display = 'none';
    
    try {
        const response = await fetch('/api/register_event_public.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            messageEl.className = 'registration-message success';
            messageEl.textContent = '✓ ' + result.message;
            messageEl.style.display = 'block';
            form.reset();
            
            setTimeout(() => {
                closeEventRegistration();
                loadData(); // Reload to update capacity
            }, 2000);
        } else {
            messageEl.className = 'registration-message error';
            messageEl.textContent = result.message;
            messageEl.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Send reservasjon';
        }
    } catch (error) {
        messageEl.className = 'registration-message error';
        messageEl.textContent = 'Noe gikk galt. Vennligst prøv igjen.';
        messageEl.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send reservasjon';
    }
}

// Keyboard navigation (optional)
document.addEventListener('keydown', (e) => {
    if (isTransitioning) return;
    
    const currentIndex = pages.indexOf(currentPage);
    
    if (e.key === 'ArrowLeft' && currentIndex > 0) {
        swipeTo(pages[currentIndex - 1]);
    } else if (e.key === 'ArrowRight' && currentIndex < pages.length - 1) {
        swipeTo(pages[currentIndex + 1]);
    }
});
