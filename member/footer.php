        </div><!-- /.content-area -->
    </div><!-- /.main-content -->

    <!-- jQuery -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- Toastr -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>

    <script>
        if (typeof toastr !== 'undefined') {
            toastr.options = {
                closeButton: true, progressBar: true,
                positionClass: 'toast-top-right',
                timeOut: 4000, extendedTimeOut: 1500
            };
            <?php
            $flash = getFlash();
            if ($flash):
                $type = $flash['type'] === 'error' ? 'error' : ($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'info'));
                $msg  = addslashes($flash['message']);
                echo "toastr.{$type}('{$msg}');";
            endif;
            ?>
        }
    </script>

    <?php if (isset($pageScripts)): ?>
        <?= $pageScripts ?>
    <?php endif; ?>
</body>
</html>
