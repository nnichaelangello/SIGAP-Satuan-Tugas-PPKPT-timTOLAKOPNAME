/**
 * ============================================================
 * SIGAP PPKPT - Case Detail Page JavaScript
 * File: assets/js/case-detail.js
 * Description: Fetches and displays case details from API
 * ============================================================
 */

(function () {
    'use strict';

    // ========================================
    // CONFIGURATION
    // ========================================
    const API_BASE = '../../../api/cases/';
    const DEBUG_MODE = false;

    // ========================================
    // STATE
    // ========================================
    let caseData = null;
    let csrfToken = '';
    let caseId = null;

    // ========================================
    // DOM ELEMENTS
    // ========================================
    const elements = {
        // States
        loadingState: document.getElementById('loadingState'),
        errorState: document.getElementById('errorState'),
        errorMessage: document.getElementById('errorMessage'),
        caseContent: document.getElementById('caseContent'),

        // Header
        breadcrumbCode: document.getElementById('breadcrumbCode'),
        caseCode: document.getElementById('caseCode'),
        caseDate: document.getElementById('caseDate'),
        statusBadge: document.getElementById('statusBadge'),

        // Status Darurat
        statusDarurat: document.getElementById('statusDarurat'),
        tingkatKekhawatiran: document.getElementById('tingkatKekhawatiran'),

        // Korban Info
        korbanSebagai: document.getElementById('korbanSebagai'),
        genderKorban: document.getElementById('genderKorban'),
        usiaKorban: document.getElementById('usiaKorban'),
        statusDisabilitas: document.getElementById('statusDisabilitas'),
        jenisDisabilitasWrapper: document.getElementById('jenisDisabilitasWrapper'),
        jenisDisabilitas: document.getElementById('jenisDisabilitas'),

        // Kontak
        emailKorban: document.getElementById('emailKorban'),
        whatsappKorban: document.getElementById('whatsappKorban'),

        // Kejadian
        pelakuKekerasan: document.getElementById('pelakuKekerasan'),
        waktuKejadian: document.getElementById('waktuKejadian'),
        lokasiKejadian: document.getElementById('lokasiKejadian'),
        detailKejadian: document.getElementById('detailKejadian'),

        // Evidence
        evidenceList: document.getElementById('evidenceList'),
        noEvidence: document.getElementById('noEvidence'),

        // Actions
        btnDelete: document.getElementById('btnDelete'),
        statusMenu: document.getElementById('statusMenu'),

        // Modal
        deleteModal: document.getElementById('deleteModal'),
        modalCaseCode: document.getElementById('modalCaseCode'),
        btnCancelDelete: document.getElementById('btnCancelDelete'),
        btnConfirmDelete: document.getElementById('btnConfirmDelete'),

        // Schedule
        btnSchedule: document.getElementById('btnSchedule'),
        scheduleModal: document.getElementById('scheduleModal'),
        btnCloseSchedule: document.getElementById('btnCloseSchedule'),
        scheduleForm: document.getElementById('scheduleForm'),
        psikologSelect: document.getElementById('psikologSelect'),
        scheduleDate: document.getElementById('scheduleDate'),
        scheduleTime: document.getElementById('scheduleTime'),
        scheduleDuration: document.getElementById('scheduleDuration'),
        meetingLocation: document.getElementById('meetingLocation'),
        btnSubmitSchedule: document.getElementById('btnSubmitSchedule'),

        // Schedule Display
        scheduleInfoSection: document.getElementById('scheduleInfoSection'),
        schedulePsikolog: document.getElementById('schedulePsikolog'),
        scheduleTimeAuth: document.getElementById('scheduleTimeAuth'),
        scheduleType: document.getElementById('scheduleType'),
        scheduleLocation: document.getElementById('scheduleLocation'),

        // Toast
        toastContainer: document.getElementById('toastContainer')
    };

    // ========================================
    // INITIALIZATION
    // ========================================
    document.addEventListener('DOMContentLoaded', function () {
        // Get case ID from URL
        const urlParams = new URLSearchParams(window.location.search);
        caseId = urlParams.get('id');

        if (!caseId) {
            showError('ID kasus tidak ditemukan dalam URL');
            return;
        }

        // Load case data
        loadCaseDetail();

        // Setup event listeners
        setupEventListeners();
    });

    // ========================================
    // API FUNCTIONS
    // ========================================

    /**
     * Load case detail from API
     */
    async function loadCaseDetail() {
        try {
            showLoading();

            const response = await fetch(`${API_BASE}get_case_detail.php?id=${caseId}`, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store'
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (DEBUG_MODE) {
                console.log('Case Detail API Response:', data);
            }

            if (data.status === 'success') {
                caseData = data.data;
                csrfToken = data.csrf_token || '';
                renderCaseDetail();
            } else {
                throw new Error(data.message || 'Gagal memuat data kasus');
            }

        } catch (error) {
            console.error('Error loading case detail:', error);
            showError(error.message);
        }
    }

    /**
     * Update case status
     */
    async function updateCaseStatus(newStatus) {
        try {
            const response = await fetch(`${API_BASE}update_case.php`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: caseId,
                    status_laporan: newStatus,
                    csrf_token: csrfToken
                })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }

            const data = await response.json();

            if (data.status === 'success') {
                // Update local data
                caseData.status_laporan = newStatus;
                csrfToken = data.csrf_token || csrfToken;

                // Update UI
                updateStatusBadge(newStatus);
                showToast('Berhasil', `Status berhasil diubah menjadi ${newStatus}`, 'success');
            } else {
                throw new Error(data.message || 'Gagal mengubah status');
            }

        } catch (error) {
            console.error('Error updating status:', error);
            showToast('Gagal', error.message, 'error');
        }
    }

    /**
     * Delete case
     */
    /**
     * Delete case
     */
    async function deleteCase() {
        // ... (existing code)
    }

    // ========================================
    // SCHEDULING FUNCTIONS
    // ========================================

    async function openScheduleModal() {
        elements.scheduleModal.style.display = 'flex';
        elements.scheduleModal.classList.add('show');

        // Disable submit until psikologs loaded
        elements.btnSubmitSchedule.disabled = true;

        // Load psikologs if not loaded
        if (elements.psikologSelect.options.length <= 1) {
            await loadPsikologs();
        } else {
            elements.btnSubmitSchedule.disabled = false;
        }
    }

    function closeScheduleModal() {
        elements.scheduleModal.style.display = 'none';
        elements.scheduleModal.classList.remove('show');
    }

    async function loadPsikologs() {
        try {
            const response = await fetch(`${API_BASE}../admin/get_psikologs.php`);
            const data = await response.json();

            if (data.status === 'success') {
                const options = data.data.map(p =>
                    `<option value="${p.id}">${p.nama_lengkap} - ${p.spesialisasi || 'Umum'}</option>`
                ).join('');

                elements.psikologSelect.innerHTML = '<option value="" selected disabled>Pilih Psikolog</option>' + options;
                elements.btnSubmitSchedule.disabled = false;
            } else {
                showToast('Gagal', 'Gagal memuat daftar psikolog', 'error');
            }
        } catch (error) {
            console.error('Error loading psikologs:', error);
            showToast('Gagal', 'Terjadi kesalahan jaringan', 'error');
        }
    }

    async function submitSchedule(e) {
        e.preventDefault();

        const psikologId = elements.psikologSelect.value;
        const date = elements.scheduleDate.value;
        const time = elements.scheduleTime.value;
        const duration = elements.scheduleDuration.value;
        const type = document.querySelector('input[name="meetingType"]:checked').value;
        const location = elements.meetingLocation.value;

        if (!psikologId || !date || !time) {
            showToast('Error', 'Mohon lengkapi formulir', 'error');
            return;
        }

        // Calculate end time
        const startDateTime = new Date(`${date}T${time}`);
        const endDateTime = new Date(startDateTime.getTime() + duration * 60000);

        // Format YYYY-MM-DD HH:MM:SS
        const formatDB = (d) => {
            return d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0') + ' ' +
                String(d.getHours()).padStart(2, '0') + ':' +
                String(d.getMinutes()).padStart(2, '0') + ':00';
        };

        const payload = {
            laporan_id: caseId,
            psikolog_id: psikologId,
            waktu_mulai: formatDB(startDateTime),
            waktu_selesai: formatDB(endDateTime),
            tipe: type,
            tempat: location
        };

        try {
            elements.btnSubmitSchedule.disabled = true;
            elements.btnSubmitSchedule.textContent = 'Menyimpan...';

            const response = await fetch(`${API_BASE}schedule_meeting.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.status === 'success') {
                showToast('Berhasil', 'Jadwal konsultasi berhasil dibuat!', 'success');
                closeScheduleModal();

                // Reload Details
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                throw new Error(data.message || 'Gagal membuat jadwal');
            }

        } catch (error) {
            console.error(error);
            showToast('Gagal', error.message, 'error');
            elements.btnSubmitSchedule.disabled = false;
            elements.btnSubmitSchedule.textContent = 'Simpan Jadwal';
        }
    }

    // ========================================
    // RENDER FUNCTIONS
    // ========================================

    /**
     * Render case detail to UI
     */
    function renderCaseDetail() {
        if (!caseData) return;

        // Header
        const kode = caseData.kode_pelaporan || `#CASE-${caseData.id}`;
        elements.breadcrumbCode.textContent = kode;
        elements.caseCode.textContent = kode;
        elements.caseDate.textContent = `Dilaporkan: ${formatDate(caseData.created_at)}`;
        elements.modalCaseCode.textContent = kode;

        // Status Badge
        updateStatusBadge(caseData.status_laporan);

        // Status Darurat
        renderStatusDarurat(caseData.status_darurat);
        renderTingkatKekhawatiran(caseData.tingkat_kekhawatiran);

        // Korban Info
        elements.korbanSebagai.textContent = caseData.korban_sebagai || '-';
        elements.genderKorban.textContent = caseData.gender_korban || '-';
        elements.usiaKorban.textContent = caseData.usia_korban || '-';
        elements.statusDisabilitas.textContent = caseData.status_disabilitas || 'Tidak';

        // Show jenis disabilitas if applicable
        if (caseData.jenis_disabilitas && caseData.status_disabilitas &&
            caseData.status_disabilitas.toLowerCase() !== 'tidak') {
            elements.jenisDisabilitasWrapper.style.display = 'flex';
            elements.jenisDisabilitas.textContent = caseData.jenis_disabilitas;
        }

        // Kontak
        elements.emailKorban.textContent = caseData.email_korban || '-';
        elements.whatsappKorban.textContent = caseData.whatsapp_korban || '-';

        // Kejadian
        elements.pelakuKekerasan.textContent = caseData.pelaku_kekerasan || '-';
        elements.waktuKejadian.textContent = caseData.waktu_kejadian || '-';
        elements.lokasiKejadian.textContent = caseData.lokasi_kejadian || '-';
        elements.detailKejadian.textContent = caseData.detail_kejadian || 'Tidak ada detail kejadian.';

        // Evidence
        renderEvidence(caseData.bukti || []);

        // Schedule
        if (caseData.jadwal) {
            elements.scheduleInfoSection.style.display = 'block';
            elements.schedulePsikolog.textContent = caseData.jadwal.psikolog_nama;

            const start = new Date(caseData.jadwal.waktu_mulai);
            const end = new Date(caseData.jadwal.waktu_selesai);

            const timeStr = start.toLocaleDateString('id-ID', {
                weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
            }) + ', ' + start.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) +
                ' - ' + end.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });

            elements.scheduleTimeAuth.textContent = timeStr;
            elements.scheduleType.textContent = (caseData.jadwal.tipe || 'Online').toUpperCase();

            const loc = caseData.jadwal.tempat_atau_link;
            elements.scheduleLocation.innerHTML = loc.startsWith('http') ?
                `<a href="${loc}" target="_blank" style="color: #0d6efd; text-decoration: underline;">${loc}</a>` : loc;

            // Hide schedule button if already scheduled
            if (elements.btnSchedule) elements.btnSchedule.style.display = 'none';
        } else {
            elements.scheduleInfoSection.style.display = 'none';
            if (elements.btnSchedule) elements.btnSchedule.style.display = 'inline-block';
        }

        // Show schedule content
        hideLoading();
        elements.caseContent.style.display = 'block';

        // Render Consultation & Feedback
        const consultSection = document.getElementById('consultationStatusSection');
        const consultBadge = document.getElementById('consultationStatusBadge');
        const riskBadge = document.getElementById('consultationRiskBadge');
        const feedbackList = document.getElementById('feedbackHistoryList');

        if (caseData.konsultasi || (caseData.feedback_history && caseData.feedback_history.length > 0)) {
            consultSection.style.display = 'block';

            // Consult Status
            if (caseData.konsultasi) {
                const status = caseData.konsultasi.status || 'Draft';
                const risk = caseData.konsultasi.tingkat_risiko || '-';
                consultBadge.innerHTML = `<span class="badge ${getStatusBadgeColor(status)}">${status.toUpperCase()}</span>`;
                riskBadge.textContent = risk.toUpperCase();
            } else {
                consultBadge.textContent = 'Belum Mengisi';
            }

            // Feedback History
            if (caseData.feedback_history && caseData.feedback_history.length > 0) {
                feedbackList.innerHTML = caseData.feedback_history.map(f => {
                    const isDispute = f.tipe_feedback === 'dispute';
                    return `
                    <div class="feedback-item mb-3 p-3 border rounded ${isDispute ? 'bg-danger bg-opacity-10' : 'bg-success bg-opacity-10'}">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="badge ${isDispute ? 'bg-danger' : 'bg-success'}">
                                ${isDispute ? '<i class="bi bi-exclamation-triangle me-1"></i>DISPUTE' : '<i class="bi bi-check-circle me-1"></i>CONFIRMED'}
                            </span>
                            <small class="text-muted">${formatDate(f.created_at)}</small>
                        </div>
                        ${f.komentar_user ? `<p class="mb-1"><strong>Komentar User:</strong> ${f.komentar_user}</p>` : ''}
                        ${f.detail_dispute ? `<p class="mb-1 text-danger"><strong>Detail Masalah:</strong> ${f.detail_dispute}</p>` : ''}
                        ${f.respon_psikolog ? `
                            <div class="mt-2 pt-2 border-top border-secondary border-opacity-25">
                                <small class="text-primary fw-bold">Respon Psikolog:</small>
                                <p class="mb-0 small">${f.respon_psikolog}</p>
                            </div>
                        ` : ''}
                    </div>`;
                }).join('');
            } else {
                feedbackList.innerHTML = '<p class="text-muted fst-italic">Belum ada feedback dari user.</p>';
            }
        } else {
            consultSection.style.display = 'none';
        }
    }

    function getStatusBadgeColor(status) {
        status = (status || '').toLowerCase();
        if (status === 'submitted') return 'bg-info text-white';
        if (status === 'confirmed') return 'bg-success';
        if (status === 'disputed') return 'bg-danger';
        return 'bg-secondary';
    }

    /**
     * Update status badge
     */
    function updateStatusBadge(status) {
        const statusLower = (status || 'process').toLowerCase().replace(' ', '-');
        const statusMap = {
            'process': { icon: 'bi-clock-history', text: 'Process', class: 'process' },
            'in-progress': { icon: 'bi-hourglass-split', text: 'In Progress', class: 'in-progress' },
            'completed': { icon: 'bi-check-circle', text: 'Completed', class: 'completed' }
        };

        const config = statusMap[statusLower] || statusMap['process'];

        elements.statusBadge.className = `status-badge-large ${config.class}`;
        elements.statusBadge.innerHTML = `<i class="bi ${config.icon}"></i><span>${config.text}</span>`;
    }

    /**
     * Render status darurat
     */
    function renderStatusDarurat(status) {
        const isDarurat = status && status.toLowerCase() === 'ya';

        if (isDarurat) {
            elements.statusDarurat.innerHTML = `
                <span class="urgency-indicator darurat">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    DARURAT
                </span>
            `;
        } else {
            elements.statusDarurat.innerHTML = `
                <span class="urgency-indicator tidak-darurat">
                    <i class="bi bi-check-circle-fill"></i>
                    Tidak Darurat
                </span>
            `;
        }
    }

    /**
     * Render tingkat kekhawatiran
     */
    function renderTingkatKekhawatiran(level) {
        const levelLower = (level || '').toLowerCase();
        let badgeClass = 'khawatir';
        let displayText = level || 'Tidak diketahui';

        if (levelLower.includes('sedikit')) {
            badgeClass = 'sedikit';
        } else if (levelLower.includes('sangat') || levelLower.includes('darurat')) {
            badgeClass = 'sangat';
        }

        elements.tingkatKekhawatiran.innerHTML = `
            <span class="worry-badge ${badgeClass}">${displayText}</span>
        `;
    }

    /**
     * Render evidence list
     */
    function renderEvidence(buktiList) {
        if (!buktiList || buktiList.length === 0) {
            elements.evidenceList.style.display = 'none';
            elements.noEvidence.style.display = 'block';
            return;
        }

        elements.noEvidence.style.display = 'none';
        elements.evidenceList.style.display = 'flex';

        elements.evidenceList.innerHTML = buktiList.map(bukti => {
            const fileType = getFileType(bukti.file_type || bukti.file_url);
            const fileName = bukti.file_url ? bukti.file_url.split('/').pop() : 'Bukti';
            const iconConfig = getFileIconConfig(fileType);

            // Adjust path based on whether it's absolute or relative
            let fileUrl = bukti.file_url;
            if (fileUrl && !fileUrl.startsWith('http') && !fileUrl.startsWith('/')) {
                // Relative path from database - add base path from current location
                fileUrl = '../../../' + fileUrl;
            }

            return `
                <div class="evidence-item">
                    <div class="evidence-icon ${iconConfig.class}">
                        <i class="bi ${iconConfig.icon}"></i>
                    </div>
                    <div class="evidence-info">
                        <div class="evidence-name">${fileName}</div>
                        <div class="evidence-meta">${fileType.toUpperCase()} - ${formatDate(bukti.created_at)}</div>
                    </div>
                    <div class="evidence-actions">
                        <button class="evidence-btn" onclick="window.open('${fileUrl}', '_blank')" title="Lihat">
                            <i class="bi bi-eye"></i>
                        </button>
                        <a href="${fileUrl}" download class="evidence-btn" title="Download">
                            <i class="bi bi-download"></i>
                        </a>
                    </div>
                </div>
            `;
        }).join('');
    }

    /**
     * Get file type from mime type or URL
     */
    function getFileType(input) {
        if (!input) return 'document';

        const lower = input.toLowerCase();

        if (lower.includes('image') || lower.match(/\.(jpg|jpeg|png|gif|webp)$/)) {
            return 'image';
        } else if (lower.includes('video') || lower.match(/\.(mp4|avi|mov|webm)$/)) {
            return 'video';
        } else if (lower.includes('pdf') || lower.match(/\.pdf$/)) {
            return 'document';
        }

        return 'document';
    }

    /**
     * Get icon config for file type
     */
    function getFileIconConfig(type) {
        const configs = {
            'image': { icon: 'bi-image', class: 'image' },
            'video': { icon: 'bi-play-circle', class: 'video' },
            'document': { icon: 'bi-file-earmark-text', class: 'document' }
        };

        return configs[type] || configs['document'];
    }

    // ========================================
    // EVENT LISTENERS
    // ========================================

    function setupEventListeners() {
        // Delete button
        if (elements.btnDelete) {
            elements.btnDelete.addEventListener('click', showDeleteModal);
        }

        // Modal cancel
        if (elements.btnCancelDelete) {
            elements.btnCancelDelete.addEventListener('click', hideDeleteModal);
        }

        // Modal confirm delete
        if (elements.btnConfirmDelete) {
            elements.btnConfirmDelete.addEventListener('click', deleteCase);
        }

        // Close modal on overlay click
        if (elements.deleteModal) {
            elements.deleteModal.addEventListener('click', function (e) {
                if (e.target === elements.deleteModal) {
                    hideDeleteModal();
                }
            });
        }

        // Status menu items
        if (elements.statusMenu) {
            elements.statusMenu.querySelectorAll('.dropdown-item').forEach(item => {
                item.addEventListener('click', function (e) {
                    e.preventDefault();
                    const newStatus = this.getAttribute('data-status');

                    if (newStatus === 'Dijadwalkan') {
                        openScheduleModal();
                    } else {
                        updateCaseStatus(newStatus);
                    }
                });
            });
        }

        // Schedule Events
        if (elements.btnSchedule) elements.btnSchedule.addEventListener('click', openScheduleModal);
        if (elements.btnCloseSchedule) elements.btnCloseSchedule.addEventListener('click', closeScheduleModal);
        if (elements.scheduleForm) elements.scheduleForm.addEventListener('submit', submitSchedule);
    }

    // ========================================
    // UI HELPERS
    // ========================================

    function showLoading() {
        elements.loadingState.style.display = 'block';
        elements.errorState.style.display = 'none';
        elements.caseContent.style.display = 'none';
    }

    function hideLoading() {
        elements.loadingState.style.display = 'none';
    }

    function showError(message) {
        elements.loadingState.style.display = 'none';
        elements.caseContent.style.display = 'none';
        elements.errorState.style.display = 'block';
        elements.errorMessage.textContent = message;
    }

    function showDeleteModal() {
        elements.deleteModal.classList.add('show');
    }

    function hideDeleteModal() {
        elements.deleteModal.classList.remove('show');
    }

    function showToast(title, message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        const icon = type === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';

        toast.innerHTML = `
            <i class="bi ${icon}"></i>
            <div class="toast-content">
                <div class="toast-title">${title}</div>
                <div class="toast-message">${message}</div>
            </div>
        `;

        elements.toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('toast-hide');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function formatDate(dateString) {
        if (!dateString) return '-';

        try {
            const date = new Date(dateString);
            const options = {
                day: 'numeric',
                month: 'long',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            };
            return date.toLocaleDateString('id-ID', options);
        } catch (e) {
            return dateString;
        }
    }

    // ========================================
    // EXPORT
    // ========================================
    window.CaseDetailManager = {
        loadCaseDetail: loadCaseDetail,
        updateCaseStatus: updateCaseStatus,
        deleteCase: deleteCase
    };

})();
