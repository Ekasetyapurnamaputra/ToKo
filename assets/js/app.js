/**
 * ToKo - Application Client JS
 * Shared navigation, local display preferences, and interactive product cards
 */

const ToKo = (function () {
    function toast(message, type) {
        type = type || 'info';
        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.innerHTML = '<span>' + message + '</span>';
        container.appendChild(el);
        setTimeout(function () {
            el.style.opacity = '0';
            el.style.transform = 'translateX(100%)';
            setTimeout(function () { el.remove(); }, 300);
        }, 4000);
        return el;
    }

    function addToCart(productId) {
        var btn = document.querySelector('.quick-add[data-id="' + productId + '"]');
        if (!btn) return;
        var originalText = btn.textContent;
        btn.textContent = '✓';
        btn.style.background = '#10b981';
        toast('Produk berhasil ditambahkan ke keranjang', 'success');
        setTimeout(function () {
            btn.textContent = originalText;
            btn.style.background = '';
        }, 1200);
    }

    function confirmDelete(form, productName) {
        if (confirm('Apakah Anda yakin ingin menonaktifkan produk "' + productName + '"?nnProduk yang dinonaktifkan tidak akan muncul di katalog.')) {
            var btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.textContent = 'Memproses...';
                btn.disabled = true;
            }
            return true;
        }
        return false;
    }

    function initProductCardNavigation() {
        document.querySelectorAll('.product-card').forEach(function (card) {
            card.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    var firstAction = card.querySelector('.btn-primary, .btn-secondary, .quick-add');
                    if (firstAction) {
                        firstAction.focus();
                        firstAction.click();
                    }
                }
            });
            card.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) return;
                card.focus();
            });
        });
    }
    function init() {
        var mobileBtn = document.getElementById('mobile-menu-toggle');
        var backdrop = document.getElementById('sidebar-backdrop');

        function setSidebarOpen(isOpen) {
            document.body.classList.toggle('sidebar-open', isOpen);
            if (mobileBtn) {
                mobileBtn.setAttribute('aria-expanded', String(isOpen));
                mobileBtn.setAttribute('aria-label', isOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi');
            }
        }

        if (mobileBtn) {
            mobileBtn.addEventListener('click', function () {
                setSidebarOpen(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (backdrop) {
            backdrop.addEventListener('click', function () {
                setSidebarOpen(false);
            });
        }

        document.querySelectorAll('.sidebar-link').forEach(function (link) {
            link.addEventListener('click', function () {
                setSidebarOpen(false);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setSidebarOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                setSidebarOpen(false);
            }
        });

        var preferenceClasses = {
            'sidebar-compact': 'sidebar-compact',
            'tables-compact': 'tables-compact'
        };

        Object.keys(preferenceClasses).forEach(function (preference) {
            var enabled = localStorage.getItem('toko.' + preference) === 'true';
            document.body.classList.toggle(preferenceClasses[preference], enabled);

            var input = document.querySelector('[data-preference="' + preference + '"]');
            if (input) {
                input.checked = enabled;
                input.addEventListener('change', function () {
                    document.body.classList.toggle(preferenceClasses[preference], input.checked);
                    localStorage.setItem('toko.' + preference, String(input.checked));
                });
            }
        });

        var alerts = document.querySelectorAll('.alert');
        alerts.forEach(function (alert) {
            setTimeout(function () {
                alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function () {
                    alert.remove();
                }, 400);
            }, 5000);
        });

        initProductCardNavigation();
    }

    window.ToKo = {
        addToCart: addToCart,
        confirmDelete: confirmDelete,
        toast: toast,
        init: init
    };

    document.addEventListener('DOMContentLoaded', init);
})();



