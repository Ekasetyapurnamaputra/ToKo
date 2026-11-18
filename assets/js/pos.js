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
    const cart = document.getElementById('cart-rows');
    const memberSelect = document.getElementById('id_pelanggan');
    const memberCode = document.getElementById('kode_member');
    const memberStatus = document.getElementById('member-status');
    const balanceDisplay = document.getElementById('display-saldo-member');
    const totalDisplay = document.getElementById('display-total-member');
    const balanceWarning = document.getElementById('saldo-warning');
    const submitButton = document.getElementById('btn-submit-transaksi');
    let rowNumber = 0;
    let totalDue = 0;

    function formatCurrency(amount) {
        return 'Rp ' + Math.round(amount).toLocaleString('id-ID');
    }

    function createCell(row, className) {
        const cell = document.createElement('td');
        if (className) cell.className = className;
        row.appendChild(cell);
        return cell;
    }

    function addRow() {
        rowNumber += 1;
        const row = document.createElement('tr');
        row.className = 'item-row';
        row.id = 'row-' + rowNumber;

        const productCell = createCell(row);
        const productSelect = document.createElement('select');
        productSelect.name = 'item_id_produk[]';
        productSelect.className = 'form-control select-produk';
        productSelect.required = true;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Pilih Produk --';
        productSelect.appendChild(placeholder);

        products.forEach(function (product) {
            const option = document.createElement('option');
            option.value = product.id_produk;
            option.dataset.harga = product.harga;
            option.dataset.stok = product.stok;
            option.textContent = product.nama_produk + ' (Stok: ' + product.stok + ') — ' + formatCurrency(product.harga);
            productSelect.appendChild(option);
        });
        productCell.appendChild(productSelect);

        const priceCell = createCell(row);
        const priceDisplay = document.createElement('input');
        priceDisplay.type = 'text';
        priceDisplay.className = 'form-control display-harga';
        priceDisplay.value = 'Rp 0';
        priceDisplay.disabled = true;
        priceCell.appendChild(priceDisplay);
        const priceValue = document.createElement('input');
        priceValue.type = 'hidden';
        priceValue.className = 'input-harga';
        priceValue.value = '0';
        priceCell.appendChild(priceValue);

        const quantityCell = createCell(row);
        const quantity = document.createElement('input');
        quantity.type = 'number';
        quantity.name = 'item_jumlah[]';
        quantity.className = 'form-control input-qty';
        quantity.min = '1';
        quantity.max = '1';
        quantity.value = '1';
        quantity.required = true;
        quantityCell.appendChild(quantity);

        const subtotalCell = createCell(row);
        subtotalCell.className = 'text-right';
        const subtotal = document.createElement('strong');
        subtotal.className = 'display-subtotal-row';
        subtotal.textContent = 'Rp 0';
        subtotalCell.appendChild(subtotal);

        const actionCell = createCell(row);
        const removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn btn-danger btn-sm remove-cart-row';
        removeButton.setAttribute('aria-label', 'Hapus produk dari keranjang');
        removeButton.textContent = '×';
        actionCell.appendChild(removeButton);

        cart.appendChild(row);
        updateTotal();
    }

    function updateRow(row) {
        const select = row.querySelector('.select-produk');
        const selected = select.options[select.selectedIndex];
        const price = Number(selected.dataset.harga) || 0;
        const stock = Number.parseInt(selected.dataset.stok, 10) || 0;
        const quantity = row.querySelector('.input-qty');

        row.querySelector('.display-harga').value = formatCurrency(price);
        row.querySelector('.input-harga').value = String(price);
        quantity.max = String(Math.max(stock, 1));
        if (Number(quantity.value) > stock && stock > 0) quantity.value = String(stock);
        if (!select.value) {
            quantity.value = '1';
            quantity.max = '1';
        }
        updateTotal();
    }

    function updateTotal() {
        let subtotal = 0;
        cart.querySelectorAll('.item-row').forEach(function (row) {
            const price = Number(row.querySelector('.input-harga').value) || 0;
            const quantity = Number.parseInt(row.querySelector('.input-qty').value, 10) || 0;
            const rowSubtotal = price * quantity;
            row.querySelector('.display-subtotal-row').textContent = formatCurrency(rowSubtotal);
            subtotal += rowSubtotal;
        });

        const discountRate = Math.min(100, Math.max(0, Number(document.getElementById('diskon').value) || 0));
        const discount = subtotal * discountRate / 100;
        totalDue = Math.max(0, subtotal - discount);
        document.getElementById('display-subtotal').textContent = formatCurrency(subtotal);
        document.getElementById('display-diskon').textContent = '- ' + formatCurrency(discount);
        document.getElementById('display-total').textContent = formatCurrency(totalDue);
        totalDisplay.textContent = formatCurrency(totalDue);
        updateBalanceStatus();
    }

    function updateBalanceStatus() {
        const selected = memberSelect.options[memberSelect.selectedIndex];
        const hasMember = Boolean(memberSelect.value);
        const available = hasMember ? Number(selected.dataset.saldo) || 0 : 0;
        balanceDisplay.textContent = hasMember ? formatCurrency(available) : 'Pilih kartu member';
        const insufficient = hasMember && available < totalDue;
        balanceWarning.hidden = !insufficient;
        balanceDisplay.classList.toggle('amount-insufficient', insufficient);
        submitButton.disabled = !hasMember || insufficient || totalDue <= 0;
    }

    document.getElementById('btn-add-item').addEventListener('click', addRow);
    document.getElementById('diskon').addEventListener('input', updateTotal);
    memberSelect.addEventListener('change', updateBalanceStatus);

    cart.addEventListener('change', function (event) {
        if (event.target.matches('.select-produk')) updateRow(event.target.closest('.item-row'));
    });
    cart.addEventListener('input', function (event) {
        if (event.target.matches('.input-qty')) updateTotal();
    });
    cart.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-cart-row');
        if (!button) return;
        button.closest('.item-row').remove();
        updateTotal();
    });

    memberCode.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        const code = memberCode.value.trim().toLowerCase();
        const option = Array.from(memberSelect.options).find(function (item) {
            return item.dataset.kode && item.dataset.kode.toLowerCase() === code;
        });

        if (option) {
            memberSelect.value = option.value;
            memberStatus.textContent = 'Member ditemukan: ' + option.textContent.trim();
            memberStatus.style.color = '#16866b';
        } else {
            memberStatus.textContent = 'Kartu member tidak ditemukan.';
            memberStatus.style.color = '#cf5962';
            memberSelect.value = '';
        }
        updateBalanceStatus();
    });

    form.addEventListener('submit', function (event) {
        const hasSelectedProduct = Array.from(cart.querySelectorAll('.select-produk')).some(function (select) {
            return select.value !== '';
        });
        if (!hasSelectedProduct) {
            event.preventDefault();
            window.alert('Pilih minimal satu produk untuk transaksi.');
            return;
        }

        const selected = memberSelect.options[memberSelect.selectedIndex];
        if (!memberSelect.value || Number(selected.dataset.saldo) < totalDue) {
            event.preventDefault();
            window.alert('Pilih kartu member dengan saldo yang mencukupi.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Memproses pembayaran...';
    });

    addRow();
});
