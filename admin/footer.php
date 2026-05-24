        </div><!-- End content-area -->
    </div><!-- End main-content -->

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Toastr -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        // CSRF Token for AJAX requests
        const csrfToken = '<?= $csrfToken ?>';

        // Toggle Sidebar (Mobile) — defined first so CDN failures below can't hide it
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        // Close sidebar when clicking outside
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggleBtn = document.querySelector('[onclick="toggleSidebar()"]');
            if (sidebar && toggleBtn && window.innerWidth < 992 && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });

        // Configure Toastr
        if (typeof toastr !== 'undefined') {
            toastr.options = {
                closeButton: true,
                progressBar: true,
                positionClass: "toast-top-right",
                timeOut: 5000,
                extendedTimeOut: 2000,
                showEasing: "swing",
                hideEasing: "linear",
                showMethod: "fadeIn",
                hideMethod: "fadeOut",
                newestOnTop: true,
                preventDuplicates: true
            };
        }

        // Initialize DataTables with Thai language (inline to avoid CORS issues)
        if ($.fn.DataTable) {
            $.extend(true, $.fn.dataTable.defaults, {
                language: {
                    "emptyTable": "ไม่มีข้อมูลในตาราง",
                    "info": "แสดง _START_ ถึง _END_ จาก _TOTAL_ รายการ",
                    "infoEmpty": "แสดง 0 ถึง 0 จาก 0 รายการ",
                    "infoFiltered": "(กรองจากทั้งหมด _MAX_ รายการ)",
                    "infoThousands": ",",
                    "lengthMenu": "แสดง _MENU_ รายการ",
                    "loadingRecords": "กำลังโหลด...",
                    "processing": "กำลังประมวลผล...",
                    "search": "ค้นหา:",
                    "zeroRecords": "ไม่พบข้อมูลที่ตรงกัน",
                    "paginate": {
                        "first": "หน้าแรก",
                        "last": "หน้าสุดท้าย",
                        "next": "ถัดไป",
                        "previous": "ก่อนหน้า"
                    },
                    "aria": {
                        "sortAscending": ": เรียงจากน้อยไปมาก",
                        "sortDescending": ": เรียงจากมากไปน้อย"
                    }
                },
                pageLength: 25,
                responsive: true
            });
        }

        // Initialize Select2
        if ($.fn.select2) {
            $('.select2').select2({
                theme: 'bootstrap-5'
            });
        }

        // AJAX Setup with CSRF
        if (typeof $ !== 'undefined') {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-Token': csrfToken
                }
            });
        }

        // Show flash messages if any
        <?php if ($flash = getFlash()): ?>
        if (typeof toastr !== 'undefined') toastr.<?= $flash['type'] ?>('<?= addslashes($flash['message']) ?>');
        <?php endif; ?>

        // Confirm delete
        function confirmDelete(message, callback) {
            if (confirm(message || 'คุณแน่ใจหรือไม่ที่จะลบรายการนี้?')) {
                callback();
            }
        }

        // Format number
        function formatNumber(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }

        // Loading overlay
        function showLoading() {
            $('body').append('<div class="loading-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;"><div class="spinner-border text-light" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        }

        function hideLoading() {
            $('.loading-overlay').remove();
        }
    </script>

    <?php if (isset($pageScripts)): ?>
        <?= $pageScripts ?>
    <?php endif; ?>
</body>
</html>
