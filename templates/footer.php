<?php
/**
 * Simple, Clean, Professional Footer Template
 */
if (Auth::check()):
?>
        </div> <!-- End page-body -->

        <!-- Simple Clean Professional Footer -->
        <footer class="app-footer-simple no-print">
            <div>
                &copy; <?= date('Y') ?> <strong><?= e(APP_NAME) ?></strong> — Sistem Informasi Penjualan Toko
            </div>
            <div class="footer-simple-meta">
                <span class="status-indicator-dot" title="Sistem Aktif & Terhubung"></span>
                <span>Database: <code>db_tokokita</code></span>
                <span>·</span>
                <span>PHP <?= phpversion() ?></span>
                <span>·</span>
                <span>User: <strong><?= e($currentUser['nama_lengkap']) ?></strong> (<?= e($currentUser['role']) ?>)</span>
            </div>
        </footer>

    </div> <!-- End main-content -->
</div> <!-- End app-shell -->
<?php endif; ?>

<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
