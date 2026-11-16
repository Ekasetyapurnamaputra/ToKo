/**
 * ToKo - Application Client JS
 * Shared navigation and local display preferences
 */

document.addEventListener('DOMContentLoaded', function() {
    const mobileBtn = document.getElementById('mobile-menu-toggle');
    const backdrop = document.getElementById('sidebar-backdrop');

    function setSidebarOpen(isOpen) {
        document.body.classList.toggle('sidebar-open', isOpen);
        if (mobileBtn) {
            mobileBtn.setAttribute('aria-expanded', String(isOpen));
            mobileBtn.setAttribute('aria-label', isOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');
        }
    }

    if (mobileBtn) {
        mobileBtn.addEventListener('click', function() {
            setSidebarOpen(!document.body.classList.contains('sidebar-open'));
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            setSidebarOpen(false);
        });
    }

    document.querySelectorAll('.sidebar-link').forEach(function(link) {
        link.addEventListener('click', function() {
            setSidebarOpen(false);
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            setSidebarOpen(false);
        }
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768) {
            setSidebarOpen(false);
        }
    });

    const preferenceClasses = {
        'sidebar-compact': 'sidebar-compact',
        'tables-compact': 'tables-compact'
    };

    Object.keys(preferenceClasses).forEach(function(preference) {
        const enabled = localStorage.getItem('toko.' + preference) === 'true';
        document.body.classList.toggle(preferenceClasses[preference], enabled);

        const input = document.querySelector('[data-preference="' + preference + '"]');
        if (input) {
            input.checked = enabled;
            input.addEventListener('change', function() {
                document.body.classList.toggle(preferenceClasses[preference], input.checked);
                localStorage.setItem('toko.' + preference, String(input.checked));
            });
        }
    });

    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(function() {
                alert.remove();
            }, 400);
        }, 5000);
    });
});
