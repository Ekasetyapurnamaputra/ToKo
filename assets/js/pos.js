document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('form-transaksi');
    const dataElement = document.getElementById('pos-data');
    if (!form || !dataElement) return;

    let data;
    try {
        data = JSON.parse(dataElement.textContent || '{}');
    } catch (error) {
        console.error('Data produk kasir tidak dapat dibaca.', error);
        return;
    }

    const products = Array.isArray(data.products) ? data.products : [];
    
    // Elements
    const searchInput = document.getElementById('pos-product-search');
    const catTabs = document.getElementById('pos-cat-tabs');
    const productsGrid = document.getElementById('pos-products-grid');
    const cartList = document.getElementById('pos-cart-list');
    const emptyCart = document.getElementById('pos-cart-empty');
    const itemCounter = document.getElementById('pos-item-counter');
    
    const memberSelect = document.getElementById('id_pelanggan');
    const memberCode = document.getElementById('kode_member');
    const balanceDisplay = document.getElementById('display-saldo-member');
    const balanceWarning = document.getElementById('saldo-warning');
    const submitButton = document.getElementById('btn-submit-transaksi');
    
    const paymentType = document.getElementById('metode_pembayaran');
    const tabPaySaldo = document.getElementById('tab-pay-saldo');
    const tabPayDuitku = document.getElementById('tab-pay-duitku');
    const boxSaldoMember = document.getElementById('box-saldo-member');
    const duitkuBox = document.getElementById('duitku-payment-box');
    const duitkuMethod = document.getElementById('duitku_payment_method');
    const duitkuEmail = document.getElementById('email_pembayaran');
    const duitkuMethodStatus = document.getElementById('duitku-method-status');
    const discountInput = document.getElementById('diskon');

    // Cart state: { [id_produk]: { id_produk, nama_produk, kode_produk, harga, stok, qty } }
    const cartItems = {};
    let totalDue = 0;
    let methodsRequest = 0;
    let methodsTimer = null;
    let methodsState = 'idle';

    function formatCurrency(amount) {
        return 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    }

    // ====================================================================
    // CART OPERATIONS
    // ====================================================================
    function addToCart(productId, qtyToAdd = 1) {
        const product = products.find(p => Number(p.id_produk) === Number(productId));
        if (!product) return;

        const pId = Number(product.id_produk);
        const maxStock = Number.parseInt(product.stok, 10) || 0;
        if (maxStock <= 0) {
            alert('Stok produk "' + product.nama_produk + '" sedang habis.');
            return;
        }

        if (cartItems[pId]) {
            const currentQty = cartItems[pId].qty;
            if (currentQty + qtyToAdd > maxStock) {
                cartItems[pId].qty = maxStock;
                alert('Maksimal stok tercapai untuk "' + product.nama_produk + '" (' + maxStock + ' pcs).');
            } else {
                cartItems[pId].qty += qtyToAdd;
            }
        } else {
            cartItems[pId] = {
                id_produk: pId,
                nama_produk: product.nama_produk,
                kode_produk: product.kode_produk || '',
                harga: Number(product.harga) || 0,
                stok: maxStock,
                qty: Math.min(qtyToAdd, maxStock)
            };
        }

        renderCart();
        updateTotal();
    }

    function updateCartQty(productId, newQty) {
        const pId = Number(productId);
        if (!cartItems[pId]) return;

        const qty = Number.parseInt(newQty, 10);
        if (isNaN(qty) || qty <= 0) {
            delete cartItems[pId];
        } else if (qty > cartItems[pId].stok) {
            cartItems[pId].qty = cartItems[pId].stok;
            alert('Stok hanya tersedia ' + cartItems[pId].stok + ' pcs.');
        } else {
            cartItems[pId].qty = qty;
        }

        renderCart();
        updateTotal();
    }

    function removeFromCart(productId) {
        const pId = Number(productId);
        if (cartItems[pId]) {
            delete cartItems[pId];
            renderCart();
            updateTotal();
        }
    }

    function renderCart() {
        const itemIds = Object.keys(cartItems);
        if (itemIds.length === 0) {
            if (emptyCart) emptyCart.style.display = 'block';
            if (cartList) {
                cartList.style.display = 'none';
                cartList.innerHTML = '';
            }
            if (itemCounter) itemCounter.textContent = '0 Item';
            return;
        }

        if (emptyCart) emptyCart.style.display = 'none';
        if (cartList) cartList.style.display = 'block';

        let totalQty = 0;
        let html = '';

        itemIds.forEach(id => {
            const item = cartItems[id];
            const subtotal = item.harga * item.qty;
            const isMax = item.qty >= item.stok;
            totalQty += item.qty;

            html += `
                <div class="pos-cart-item" data-id="${item.id_produk}">
                    <div class="pos-cart-item-info">
                        <div class="pos-cart-item-name" title="${escapeHtml(item.nama_produk)}">${escapeHtml(item.nama_produk)}</div>
                        <div class="pos-cart-item-price">${formatCurrency(item.harga)} &bull; Sisa: ${item.stok}</div>
                        <input type="hidden" name="item_id_produk[]" value="${item.id_produk}">
                        <input type="hidden" name="item_jumlah[]" value="${item.qty}">
                    </div>
                    <div class="pos-cart-item-stepper">
                        <button type="button" class="pos-stepper-btn btn-qty-minus" data-id="${item.id_produk}" aria-label="Kurangi">−</button>
                        <input type="number" class="pos-stepper-val" data-id="${item.id_produk}" value="${item.qty}" min="1" max="${item.stok}">
                        <button type="button" class="pos-stepper-btn btn-qty-plus" data-id="${item.id_produk}" ${isMax ? 'disabled' : ''} aria-label="Tambah">+</button>
                    </div>
                    <div class="pos-cart-item-subtotal">
                        ${formatCurrency(subtotal)}
                    </div>
                    <button type="button" class="pos-cart-item-remove btn-remove-item" data-id="${item.id_produk}" title="Hapus">
                        ✕
                    </button>
                </div>
            `;
        });

        if (cartList) cartList.innerHTML = html;
        if (itemCounter) itemCounter.textContent = itemIds.length + ' Item (' + totalQty + ' pcs)';
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ====================================================================
    // PRODUCT CATALOG CARD CLICK & SEARCH FILTER
    // ====================================================================
    if (productsGrid) {
        productsGrid.addEventListener('click', function (e) {
            const card = e.target.closest('.pos-prod-card');
            if (!card) return;
            const pId = card.dataset.id;
            addToCart(pId, 1);
        });
    }

    // Live search filter on catalog cards
    if (searchInput && productsGrid) {
        searchInput.addEventListener('input', function () {
            const q = this.value.trim().toLowerCase();
            const cards = productsGrid.querySelectorAll('.pos-prod-card');
            
            cards.forEach(card => {
                const name = card.dataset.name || '';
                const code = card.dataset.code || '';
                if (!q || name.includes(q) || code.includes(q)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Barcode gun / Enter shortcut
        searchInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const q = this.value.trim().toLowerCase();
                if (!q) return;

                // Cek apakah ada kecocokan tepat kode barcode
                let matched = products.find(p => (p.kode_produk || '').toLowerCase() === q);
                if (!matched) {
                    // Cari nama yang paling mirip
                    matched = products.find(p => (p.nama_produk || '').toLowerCase().includes(q));
                }

                if (matched) {
                    addToCart(matched.id_produk, 1);
                    this.value = '';
                    // Reset filter tampilan
                    const cards = productsGrid.querySelectorAll('.pos-prod-card');
                    cards.forEach(c => c.style.display = 'flex');
                } else {
                    alert('Produk "' + q + '" tidak ditemukan.');
                }
            }
        });
    }

    // Category Tabs filter
    if (catTabs && productsGrid) {
        catTabs.addEventListener('click', function (e) {
            const tab = e.target.closest('.pos-cat-tab');
            if (!tab) return;

            catTabs.querySelectorAll('.pos-cat-tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');

            const cat = tab.dataset.cat;
            const cards = productsGrid.querySelectorAll('.pos-prod-card');

            cards.forEach(card => {
                const cardCat = card.dataset.cat || '';
                if (cat === 'all' || cardCat === cat) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });

            if (searchInput) searchInput.value = '';
        });
    }

    // ====================================================================
    // CART STEPPER & REMOVE CLICK DELEGATION
    // ====================================================================
    if (cartList) {
        cartList.addEventListener('click', function (e) {
            const plusBtn = e.target.closest('.btn-qty-plus');
            if (plusBtn) {
                const pId = plusBtn.dataset.id;
                if (cartItems[pId]) addToCart(pId, 1);
                return;
            }

            const minusBtn = e.target.closest('.btn-qty-minus');
            if (minusBtn) {
                const pId = minusBtn.dataset.id;
                if (cartItems[pId]) updateCartQty(pId, cartItems[pId].qty - 1);
                return;
            }

            const removeBtn = e.target.closest('.btn-remove-item');
            if (removeBtn) {
                const pId = removeBtn.dataset.id;
                removeFromCart(pId);
                return;
            }
        });

        cartList.addEventListener('change', function (e) {
            if (e.target.matches('.pos-stepper-val')) {
                const pId = e.target.dataset.id;
                updateCartQty(pId, e.target.value);
            }
        });
    }

    // ====================================================================
    // PAYMENT METHOD TABS (SALDO MEMBER VS DUITKU)
    // ====================================================================
    function setPaymentMethod(method) {
        if (paymentType) paymentType.value = method;
        if (method === 'saldo_member') {
            if (tabPaySaldo) tabPaySaldo.classList.add('active');
            if (tabPayDuitku) tabPayDuitku.classList.remove('active');
            if (boxSaldoMember) boxSaldoMember.hidden = false;
            if (duitkuBox) duitkuBox.hidden = true;
            if (duitkuEmail) duitkuEmail.required = false;
            if (duitkuMethod) duitkuMethod.required = false;
        } else {
            if (tabPaySaldo) tabPaySaldo.classList.remove('active');
            if (tabPayDuitku) tabPayDuitku.classList.add('active');
            if (boxSaldoMember) boxSaldoMember.hidden = true;
            if (duitkuBox) duitkuBox.hidden = false;
            if (duitkuEmail) duitkuEmail.required = true;
            if (duitkuMethod) duitkuMethod.required = true;
            scheduleDuitkuMethods();
        }
        updateBalanceStatus();
    }

    if (tabPaySaldo) {
        tabPaySaldo.addEventListener('click', function () {
            setPaymentMethod('saldo_member');
        });
    }
    if (tabPayDuitku) {
        tabPayDuitku.addEventListener('click', function () {
            setPaymentMethod('duitku');
        });
    }

    // ====================================================================
    // TOTALS & BALANCE CALCULATION
    // ====================================================================
    function updateTotal() {
        let subtotal = 0;
        Object.values(cartItems).forEach(item => {
            subtotal += (item.harga * item.qty);
        });

        const discountRate = Math.min(100, Math.max(0, Number(discountInput ? discountInput.value : 0) || 0));
        const discount = subtotal * discountRate / 100;
        totalDue = Math.max(0, subtotal - discount);

        const subtotalEl = document.getElementById('display-subtotal');
        const diskonEl = document.getElementById('display-diskon');
        const totalEl = document.getElementById('display-total');
        const bigTotalEl = document.getElementById('pos-big-total');

        if (subtotalEl) subtotalEl.textContent = formatCurrency(subtotal);
        if (diskonEl) diskonEl.textContent = '- ' + formatCurrency(discount);
        if (totalEl) totalEl.textContent = formatCurrency(totalDue);
        if (bigTotalEl) bigTotalEl.textContent = formatCurrency(totalDue);

        if (paymentType && paymentType.value === 'duitku') scheduleDuitkuMethods();
        updateBalanceStatus();
    }

    function updateBalanceStatus() {
        if (!memberSelect) return;
        const selected = memberSelect.options[memberSelect.selectedIndex];
        const hasMember = Boolean(memberSelect.value);
        const available = hasMember ? Number(selected.dataset.saldo) || 0 : 0;
        const usingWallet = paymentType ? paymentType.value === 'saldo_member' : true;
        
        if (balanceDisplay) {
            balanceDisplay.textContent = hasMember ? formatCurrency(available) : 'Pilih kartu member';
        }
        
        const insufficient = usingWallet && hasMember && available < totalDue;
        if (balanceWarning) balanceWarning.hidden = !usingWallet || !insufficient;

        if (!usingWallet && hasMember && duitkuEmail && !duitkuEmail.value.trim()) {
            const rawName = (selected.textContent || '').trim().split(' ')[0].toLowerCase().replace(/[^a-z0-9]/g, '');
            duitkuEmail.value = (rawName || 'member') + '@tokokita.local';
        }

        if (submitButton) {
            submitButton.textContent = usingWallet ? 'Bayar dengan Saldo Member' : 'Lanjutkan Pembayaran Duitku';
            const duitkuReady = usingWallet || (methodsState === 'ready' && duitkuMethod && duitkuMethod.value !== '' && duitkuEmail.validity.valid);
            const hasItems = Object.keys(cartItems).length > 0;
            submitButton.disabled = !hasMember || totalDue <= 0 || !hasItems || (usingWallet && insufficient) || !duitkuReady;
        }
    }

    function scheduleDuitkuMethods() {
        window.clearTimeout(methodsTimer);
        if (totalDue <= 0) {
            methodsState = 'idle';
            if (duitkuMethod) duitkuMethod.disabled = true;
            if (duitkuMethodStatus) duitkuMethodStatus.textContent = 'Pilih produk untuk memuat kanal Duitku.';
            updateBalanceStatus();
            return;
        }

        methodsState = 'loading';
        if (duitkuMethod) duitkuMethod.disabled = true;
        if (duitkuMethodStatus) duitkuMethodStatus.textContent = 'Memuat kanal pembayaran Duitku…';
        updateBalanceStatus();
        methodsTimer = window.setTimeout(loadDuitkuMethods, 350);
    }

    function loadDuitkuMethods() {
        const requestId = ++methodsRequest;
        const amount = Math.round(totalDue);
        fetch('index.php?page=transaksi&action=duitku_methods&amount=' + encodeURIComponent(String(amount)), {
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().then(function (body) {
                if (!response.ok) throw new Error(body.message || 'Kanal pembayaran gagal dimuat.');
                return body;
            });
        }).then(function (body) {
            if (requestId !== methodsRequest || (paymentType && paymentType.value !== 'duitku')) return;
            const previousValue = duitkuMethod.value;
            duitkuMethod.replaceChildren();
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '-- Pilih kanal pembayaran Duitku --';
            duitkuMethod.appendChild(placeholder);

            body.methods.forEach(function (method) {
                const option = document.createElement('option');
                option.value = method.paymentMethod;
                option.textContent = method.paymentName + (Number(method.totalFee) > 0 ? ' (Biaya Rp ' + Number(method.totalFee).toLocaleString('id-ID') + ')' : '');
                duitkuMethod.appendChild(option);
            });
            if (Array.from(duitkuMethod.options).some(function (option) { return option.value === previousValue; })) {
                duitkuMethod.value = previousValue;
            } else if (duitkuMethod.options.length > 1) {
                const hasQris = Array.from(duitkuMethod.options).some(function (o) { return o.value === 'NQ'; });
                duitkuMethod.value = hasQris ? 'NQ' : duitkuMethod.options[1].value;
            }
            methodsState = body.methods.length ? 'ready' : 'error';
            duitkuMethod.disabled = body.methods.length === 0;
            duitkuMethodStatus.textContent = body.methods.length
                ? 'Pilih kanal pembayaran Duitku (QRIS / Virtual Account).'
                : 'Tidak ada kanal pembayaran aktif untuk nominal ini.';
            updateBalanceStatus();
        }).catch(function (error) {
            if (requestId !== methodsRequest || (paymentType && paymentType.value !== 'duitku')) return;
            methodsState = 'error';
            duitkuMethod.replaceChildren();
            duitkuMethod.disabled = true;
            duitkuMethodStatus.textContent = error.message || 'Kanal pembayaran Duitku tidak dapat dimuat.';
            updateBalanceStatus();
        });
    }

    if (discountInput) discountInput.addEventListener('input', updateTotal);
    if (memberSelect) memberSelect.addEventListener('change', updateBalanceStatus);
    if (duitkuMethod) duitkuMethod.addEventListener('change', updateBalanceStatus);
    if (duitkuEmail) duitkuEmail.addEventListener('input', updateBalanceStatus);

    if (memberCode) {
        memberCode.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const code = memberCode.value.trim().toLowerCase();
            const option = Array.from(memberSelect.options).find(function (item) {
                return item.dataset.kode && item.dataset.kode.toLowerCase() === code;
            });

            if (option) {
                memberSelect.value = option.value;
                alert('Member terdeteksi: ' + option.textContent.trim());
            } else {
                alert('Kartu member "' + code + '" tidak ditemukan.');
                memberSelect.value = '';
            }
            updateBalanceStatus();
        });
    }

    form.addEventListener('submit', function (event) {
        const itemKeys = Object.keys(cartItems);
        if (itemKeys.length === 0) {
            event.preventDefault();
            alert('Pilih minimal satu produk untuk transaksi kasir.');
            if (searchInput) searchInput.focus();
            return;
        }

        const selected = memberSelect.options[memberSelect.selectedIndex];
        if (!memberSelect.value) {
            event.preventDefault();
            alert('Pilih pelanggan terlebih dahulu.');
            memberSelect.focus();
            return;
        }

        if (paymentType.value === 'saldo_member' && Number(selected.dataset.saldo) < totalDue) {
            event.preventDefault();
            alert('Saldo kartu member tidak mencukupi untuk pembayaran ini.');
            return;
        }

        if (paymentType.value === 'duitku' && (!duitkuEmail.validity.valid || !duitkuMethod.value || methodsState !== 'ready')) {
            event.preventDefault();
            alert('Masukkan email dan pilih kanal pembayaran Duitku yang tersedia.');
            return;
        }

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = 'Memproses Transaksi Kasir...';
        }
    });

    // Inisialisasi awal
    renderCart();
    updateTotal();
    setPaymentMethod('saldo_member');
    if (searchInput) searchInput.focus();
});
