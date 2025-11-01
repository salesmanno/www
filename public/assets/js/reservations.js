const subdomain = 'bergsoy';
const API_BASE = '/api';

// Load events on page load
document.addEventListener('DOMContentLoaded', () => {
    loadEvents();
});

// Load events from database
async function loadEvents() {
    try {
        const response = await fetch(`${API_BASE}/get_public_events.php?subdomain=${subdomain}`);
        const data = await response.json();
        
        if (data.success && data.events) {
            displayEvents(data.events);
        } else {
            showError('Kunne ikke laste arrangementer');
        }
    } catch (error) {
        console.error('Error loading events:', error);
        showError('Noe gikk galt ved lasting av arrangementer');
    }
}

// Display events
function displayEvents(events) {
    const container = document.getElementById('eventsContainer');
    
    if (!events || events.length === 0) {
        container.innerHTML = '<p class="loading">Ingen kommende arrangementer for øyeblikket.</p>';
        return;
    }
    
    container.innerHTML = events.map(event => {
        const spotsLeft = event.max_attendees ? event.max_attendees - (event.registered_count || 0) : null;
        const isFull = spotsLeft !== null && spotsLeft <= 0;
        const isLowCapacity = spotsLeft !== null && spotsLeft > 0 && spotsLeft <= 10;
        
        return `
            <div class="event-card">
                ${event.event_image_url ? 
                    `<img src="${escapeHtml(event.event_image_url)}" alt="${escapeHtml(event.title)}" class="event-image">` : 
                    '<div class="event-image"></div>'
                }
                <div class="event-body">
                    <h2 class="event-title">${escapeHtml(event.title)}</h2>
                    ${event.description ? `<p class="event-description">${escapeHtml(event.description)}</p>` : ''}
                    
                    <div class="event-details">
                        <div class="event-detail-item">
                            <span class="event-detail-icon">📅</span>
                            <span>${formatEventDate(event.start_date, event.start_time)}</span>
                        </div>
                        ${event.venue_name ? `
                            <div class="event-detail-item">
                                <span class="event-detail-icon">📍</span>
                                <span>${escapeHtml(event.venue_name)}</span>
                            </div>
                        ` : ''}
                        ${event.max_attendees ? `
                            <div class="event-detail-item">
                                <span class="event-detail-icon">👥</span>
                                <span>Maks ${event.max_attendees} deltakere</span>
                            </div>
                        ` : ''}
                    </div>
                    
                    <div class="event-price">${formatPrice(event.ticket_price)} kr</div>
                    
                    ${spotsLeft !== null ? `
                        <div class="event-capacity ${isFull ? 'full' : isLowCapacity ? 'warning' : ''}">
                            ${isFull ? '❌ Fullt booket' : isLowCapacity ? `⚠️ Kun ${spotsLeft} plasser igjen!` : `✓ ${spotsLeft} plasser tilgjengelig`}
                        </div>
                    ` : ''}
                    
                    ${!isFull ? `
                        <form class="registration-form" onsubmit="registerForEvent(event, '${event.id}', '${escapeHtml(event.title)}')">
                            <h3 class="form-title">Meld deg på</h3>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Fullt navn <span class="required">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    name="name" 
                                    class="form-input" 
                                    placeholder="Ola Nordmann"
                                    required
                                    minlength="2"
                                >
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    E-post <span class="required">*</span>
                                </label>
                                <input 
                                    type="email" 
                                    name="email" 
                                    class="form-input" 
                                    placeholder="ola@example.com"
                                    required
                                >
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">
                                    Telefon <span class="required">*</span>
                                </label>
                                <input 
                                    type="tel" 
                                    name="phone" 
                                    class="form-input" 
                                    placeholder="912 34 567"
                                    required
                                    pattern="[0-9]{8,15}"
                                    title="Vennligst skriv inn et gyldig telefonnummer (8-15 siffer)"
                                >
                            </div>
                            
                            <div class="form-message-${event.id}" style="display: none;"></div>
                            
                            <button type="submit" class="btn btn-primary">
                                Meld meg på
                            </button>
                        </form>
                    ` : '<p style="text-align: center; color: #c62828; font-weight: 600;">Dette arrangementet er fullt booket</p>'}
                </div>
            </div>
        `;
    }).join('');
}

// Register for event
async function registerForEvent(e, eventId, eventTitle) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const messageContainer = document.querySelector(`.form-message-${eventId}`);
    
    const formData = {
        event_id: eventId,
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        phone: form.phone.value.trim()
    };
    
    // Disable submit button
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sender...';
    messageContainer.style.display = 'none';
    
    try {
        const response = await fetch(`${API_BASE}/register_event_public.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success modal
            showSuccessModal(eventTitle);
            form.reset();
            // Reload events to update capacity
            setTimeout(() => loadEvents(), 1000);
        } else {
            showFormMessage(messageContainer, result.message, 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Meld meg på';
        }
    } catch (error) {
        console.error('Registration error:', error);
        showFormMessage(messageContainer, 'Noe gikk galt. Vennligst prøv igjen.', 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Meld meg på';
    }
}

// Show form message
function showFormMessage(container, message, type) {
    container.className = `form-message ${type}`;
    container.textContent = message;
    container.style.display = 'block';
    
    setTimeout(() => {
        container.style.display = 'none';
    }, 5000);
}

// Show success modal
function showSuccessModal(eventTitle) {
    const modal = document.getElementById('successModal');
    const message = document.getElementById('successMessage');
    message.textContent = `Du er nå påmeldt "${eventTitle}". Du vil motta bekreftelse på e-post.`;
    modal.classList.remove('hidden');
}

// Close success modal
function closeSuccessModal() {
    const modal = document.getElementById('successModal');
    modal.classList.add('hidden');
}

// Show error
function showError(message) {
    const container = document.getElementById('eventsContainer');
    container.innerHTML = `<p class="loading" style="color: #f44336;">${escapeHtml(message)}</p>`;
}

// Format event date
function formatEventDate(dateStr, timeStr) {
    if (!dateStr) return '';
    
    const date = new Date(dateStr);
    const dateFormatted = date.toLocaleDateString('no-NO', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    });
    
    if (timeStr) {
        return `${dateFormatted} kl. ${timeStr.substring(0, 5)}`;
    }
    
    return dateFormatted;
}

// Format price
function formatPrice(price) {
    return parseFloat(price).toFixed(0);
}

// Escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
