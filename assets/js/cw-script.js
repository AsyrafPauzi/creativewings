jQuery(document).ready(function($) {
    
    // ======================================================
    // 1. POPUPS (SweetAlert2)
    // Handles URL parameters ?login=failed, ?reg_error=...
    // ======================================================
    const urlParams = new URLSearchParams(window.location.search);
    
    // Login Errors
    if (urlParams.has('login')) {
        const status = urlParams.get('login');
        if (status === 'failed') {
            Swal.fire({ 
                icon: 'error', 
                title: 'Login Failed', 
                text: 'Incorrect email or password.', 
                confirmButtonColor: '#105B9A' 
            });
        }
        if (status === 'empty') {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Oops', 
                text: 'Please enter both email and password.', 
                confirmButtonColor: '#105B9A' 
            });
        }
    }

    // Registration Errors
    if (urlParams.has('reg_error')) {
        const err = urlParams.get('reg_error');
        let msg = 'Registration failed.';
        
        if (err === 'email_exists') msg = 'This email is already registered. Please log in.';
        if (err === 'missing_fields') msg = 'Please fill in all required fields.';
        if (err === 'generic') msg = 'A system error occurred. Please try again later.';
        
        Swal.fire({ 
            icon: 'error', 
            title: 'Registration Failed', 
            text: msg, 
            confirmButtonColor: '#105B9A' 
        });
    }

    // Server-side Transients (Flash Messages)
    if (typeof cw_vars !== 'undefined' && cw_vars.popup_msg) {
        let type = cw_vars.popup_type || 'info';
        let title = 'Notification';
        
        if (type === 'error') title = 'Error';
        if (type === 'success') title = 'Success';
        
        Swal.fire({ 
            icon: type, 
            title: title, 
            text: cw_vars.popup_msg, 
            confirmButtonColor: '#105B9A' 
        });
    }

    // ======================================================
    // 2. DATEPICKER
    // ======================================================
    if ($("#birthdate").length) {
        $("#birthdate").datepicker({ 
            changeMonth: true, 
            changeYear: true, 
            yearRange: "c-80:c", // Dynamic: 80 years ago to Current Year
            dateFormat: "dd/mm/yy" 
        });
    }

    // ======================================================
    // 3. PASSWORD STRENGTH METER LOGIC
    // ======================================================
    const passInput = document.getElementById('creator_password');
    const strengthBar = document.getElementById('strength-fill');
    
    if(passInput && strengthBar) {
        passInput.addEventListener('keyup', function() {
            const val = passInput.value;
            let strength = 0;
            
            // 1. Length Check
            if (val.length > 5) strength++;
            if (val.length > 8) strength++;
            
            // 2. Complexity Check
            if (/[A-Z]/.test(val)) strength++;       // Has Uppercase
            if (/[0-9]/.test(val)) strength++;       // Has Number
            if (/[^A-Za-z0-9]/.test(val)) strength++; // Has Symbol

            // 3. Update UI
            let width = 0;
            let color = '#e0e0e0'; // Default light gray

            if (val.length > 0) {
                // Map strength 0-5 to Width percentage
                width = Math.min(100, (strength / 5) * 100);
                if (width < 20) width = 20; // Ensure a minimum visible bar
                
                if (strength < 2) {
                    color = '#e74c3c'; // Red (Weak)
                } else if (strength < 4) {
                    color = '#f39c12'; // Orange (Medium)
                } else {
                    color = '#2ecc71'; // Green (Strong)
                }
            }

            strengthBar.style.width = width + '%';
            strengthBar.style.backgroundColor = color;
        });
    }


// ======================================================
// 4. PUBLIC PROFILE MODAL FUNCTIONS (Global)
// ======================================================

function openPublicProject(data) {
    const modal = document.getElementById('cw-public-modal');
    if(!modal) return;

    // Set Text Content
    document.getElementById('cw-pm-title').innerText = data.title || '';
    document.getElementById('cw-pm-cat').innerText = data.cat || '';
    
    // Set Description (innerHTML allows line breaks from textarea)
    // Note: Ensure backend uses wp_kses_post before saving description to avoid XSS
    document.getElementById('cw-pm-desc').innerHTML = data.desc || '';

    // Set Main Image
    const mainImg = document.getElementById('cw-pm-main-img');
    if(data.img) {
        mainImg.src = data.img;
        mainImg.style.display = 'block';
    } else {
        mainImg.style.display = 'none';
    }

    // Build Gallery Thumbs
    const galleryDiv = document.getElementById('cw-pm-gallery');
    galleryDiv.innerHTML = ''; // Clear previous

    // Add main image as the first thumb if it exists
    if(data.img) {
        let img = document.createElement('img');
        img.src = data.img;
        img.className = 'cw-thumb-active'; // Optional class for styling
        img.onclick = function() { document.getElementById('cw-pm-main-img').src = this.src; };
        galleryDiv.appendChild(img);
    }

    // Add Gallery Images
    if (data.gal && Array.isArray(data.gal)) {
        data.gal.forEach(url => {
            let img = document.createElement('img');
            img.src = url;
            img.onclick = function() { document.getElementById('cw-pm-main-img').src = this.src; };
            galleryDiv.appendChild(img);
        });
    }

    // Show Modal
    modal.style.display = 'flex';
}

function closePublicModal() {
    const modal = document.getElementById('cw-public-modal');
    if(modal) modal.style.display = 'none';
}

// Close modal when clicking outside the box
window.onclick = function(event) {
    const modal = document.getElementById('cw-public-modal');
    if (event.target == modal) {
        modal.style.display = "none";
    }
}

// Global Modal Closing
window.onclick = function(event) {
    if (event.target.classList.contains('cw-modal')) {
        event.target.style.display = "none";
    }
};
});