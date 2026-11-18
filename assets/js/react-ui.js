/* React UI enhancement: backend PHP forms remain the source of truth. */
(function () {
    const React = window.React;
    const ReactDOM = window.ReactDOM;
    if (!React || !ReactDOM) return;

    // ========================================================================
    // Member Scan Status (existing)
    // ========================================================================
    function MemberScanStatus() {
        const [status, setStatus] = React.useState('Siap menerima scan kartu member');
        const input = document.getElementById('kode_member');
        if (!input) return null;

        React.useEffect(function () {
            input.focus();
            const onInput = function () {
                setStatus(input.value ? 'Kode kartu terbaca: ' + input.value : 'Siap menerima scan kartu member');
            };
            input.addEventListener('input', onInput);
            return function () { input.removeEventListener('input', onInput); };
        }, []);

        return React.createElement('div', { className: 'react-member-status', role: 'status' },
            React.createElement('span', { className: 'react-status-dot' }), status
        );
    }

    const memberRoot = document.createElement('div');
    memberRoot.id = 'react-member-status-root';
    const memberInput = document.getElementById('kode_member');
    if (memberInput && memberInput.parentNode) {
        memberInput.parentNode.appendChild(memberRoot);
        ReactDOM.createRoot(memberRoot).render(React.createElement(MemberScanStatus));
    }

    // ========================================================================
    // Product Card React Component (interactive enhancement)
    // ========================================================================
    function ProductCard({ product, onAddToCart }) {
        const [added, setAdded] = React.useState(false);
        const stockClass = product.stok <= 0 ? 'stock-critical' : (product.stok <= 5 ? 'stock-low' : 'stock-ok');
        const stockLabel = product.stok <= 0 ? 'Habis' : (product.stok <= 5 ? 'Rendah' : 'Aman');

        function handleAddToCart(e) {
            e.preventDefault();
            e.stopPropagation();
            if (onAddToCart) { onAddToCart(product.id_produk); }
            setAdded(true);
            setTimeout(function () { setAdded(false); }, 1200);
        }

        return React.createElement('article', {
            className: 'product-card',
            tabIndex: 0,
            role: 'button',
            'aria-label': 'Produk ' + product.nama_produk + ', harga ' + product.harga + ', stok ' + product.stok + ' unit',
            'data-id': product.id_produk,
            onKeyDown: function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    var btn = e.currentTarget.querySelector('.quick-add');
                    if (btn) { btn.click(); }
                }
            }
        },
            React.createElement('button', {
                type: 'button',
                className: 'quick-add',
                title: 'Tambah ke keranjang',
                'aria-label': 'Tambah ' + product.nama_produk + ' ke keranjang',
                'data-id': product.id_produk,
                onClick: handleAddToCart
            }, added ? '✓' : '+'),

            React.createElement('div', { className: 'product-card-top' },
                React.createElement('span', { className: 'product-icon', 'aria-hidden': 'true' }, '📦'),
                React.createElement('span', {
                    className: 'badge ' + (product.status === 'aktif' ? 'badge-success' : 'badge-danger')
                }, product.status === 'aktif' ? 'Aktif' : 'Nonaktif')
            ),

            React.createElement('div', { className: 'product-card-code' }, product.kode_produk),
            React.createElement('h3', null, product.nama_produk),
            React.createElement('p', { className: 'product-category' }, product.nama_kategori || 'Tanpa kategori'),

            React.createElement('div', { className: 'product-card-meta' },
                React.createElement('strong', null, 'Rp ' + product.harga.toLocaleString('id-ID')),
                React.createElement('span', { className: 'stock-pill ' + stockClass }, stockLabel + ' ' + product.stok)
            ),

            React.createElement('div', { className: 'product-card-actions' },
                React.createElement('a', {
                    href: 'index.php?page=produk&action=adjust_stock&id=' + product.id_produk,
                    className: 'btn btn-secondary btn-sm'
                }, 'Stok'),
                React.createElement('a', {
                    href: 'index.php?page=produk&action=edit&id=' + product.id_produk,
                    className: 'btn btn-primary btn-sm'
                }, 'Ubah')
            )
        );
    }

    // Mount React product cards if a container exists
    function mountReactProductCards() {
        var container = document.getElementById('react-product-grid');
        if (!container) { return; }

        try {
            var productsJson = container.getAttribute('data-products');
            if (!productsJson) { return; }

            var products = JSON.parse(productsJson);

            function ProductGrid() {
                return React.createElement('div', { className: 'product-grid' },
                    products.map(function (p) {
                        return React.createElement(ProductCard, {
                            key: p.id_produk,
                            product: p,
                            onAddToCart: function (id) {
                                if (window.ToKo && window.ToKo.addToCart) {
                                    window.ToKo.addToCart(id);
                                }
                            }
                        });
                    })
                );
            }

            ReactDOM.createRoot(container).render(React.createElement(ProductGrid));
        } catch (err) {
            console.warn('ToKo React product grid could not be mounted:', err);
        }
    }

    mountReactProductCards();
})();
