/**
 * SIGAP PPKS - Psikolog Dashboard JavaScript
 * Handles: auth check, data fetching, notes submission, dispute response, navigation
 */

document.addEventListener('DOMContentLoaded', () => {
    // Auth check
    const psikologData = JSON.parse(localStorage.getItem('psikolog_data') || 'null');
    if (!psikologData) {
        window.location.href = '../index.html';
        return;
    }

    // State
    let csrfToken = localStorage.getItem('psikolog_csrf') || '';
    let allCases = [];
    let currentCaseId = null;

    // DOM Elements
    const profileName = document.getElementById('profileName');
    const profileSpec = document.getElementById('profileSpecialization');
    const dateEl = document.getElementById('currentDate');
    const sidebar = document.getElementById('sidebar');

    // Init
    profileName.textContent = psikologData.nama || 'Psikolog';
    profileSpec.textContent = psikologData.spesialisasi || 'Psikolog';
    dateEl.textContent = new Date().toLocaleDateString('id-ID', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    // Sidebar navigation
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const page = link.dataset.page;
            switchPage(page);
            if (window.innerWidth <= 768) sidebar.classList.remove('mobile-open');
        });
    });

    document.getElementById('sidebarToggle')?.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
    document.getElementById('mobileToggle')?.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));

    // Logout
    document.getElementById('logoutBtn')?.addEventListener('click', async () => {
        try {
            await fetch('../../api/psikolog/logout.php');
        } catch (_) { }
        localStorage.removeItem('psikolog_data');
        localStorage.removeItem('psikolog_csrf');
        window.location.href = '../index.html';
    });

    // Page switcher
    function switchPage(page) {
        document.querySelectorAll('.page-content').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        document.getElementById(`page-${page}`)?.classList.add('active');
        document.querySelector(`.nav-link[data-page="${page}"]`)?.classList.add('active');
        document.getElementById('pageTitle').textContent = {
            overview: 'Overview',
            cases: 'Kasus Saya',
            schedule: 'Jadwal Pertemuan'
        }[page] || 'Dashboard';

        if (page === 'schedule') loadSchedules();
    }

    // ===== FETCH CASES =====
    async function loadCases(statusFilter = '') {
        try {
            const url = `../../api/psikolog/get_cases.php${statusFilter ? '?status=' + statusFilter : ''}`;
            const res = await fetch(url);
            const data = await res.json();

            if (data.status === 'success') {
                allCases = data.data || [];
                if (data.csrf_token) csrfToken = data.csrf_token;
                updateStats(data.statistics);
                renderRecentCases(allCases.slice(0, 5));
                renderAllCases(allCases);
            }
        } catch (err) {
            console.error('Load cases error:', err);
            showToast('Gagal memuat data kasus', 'error');
        }
    }

    function updateStats(stats) {
        if (!stats) return;
        document.getElementById('statTotal').textContent = stats.total || 0;
        document.getElementById('statAktif').textContent = stats.aktif || 0;
        document.getElementById('statMenunggu').textContent = stats.menunggu || 0;
        document.getElementById('statDispute').textContent = stats.dispute || 0;
        document.getElementById('statSelesai').textContent = stats.selesai || 0;
    }

    function renderRecentCases(cases) {
        const tbody = document.getElementById('recentCasesBody');
        if (!cases.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="empty-state"><i class="fas fa-inbox"></i><p>Belum ada kasus</p></td></tr>';
            return;
        }
        tbody.innerHTML = cases.map(c => `
            <tr>
                <td><strong>${esc(c.kode_pelaporan)}</strong></td>
                <td>${statusBadge(c.status_laporan)}</td>
                <td>${c.tingkat_risiko ? riskBadge(c.tingkat_risiko) : '<span style="color:var(--psi-text-muted)">-</span>'}</td>
                <td>${c.waktu_mulai ? formatDate(c.waktu_mulai) : '<span style="color:var(--psi-text-muted)">-</span>'}</td>
                <td>
                    <button class="btn-action view" onclick="openCaseModal(${c.id}, 'detail')" title="Lihat Detail Kasus">
                        <i class="fas fa-external-link-alt"></i> Buka Kasus
                    </button>
                </td>
            </tr>
        `).join('');
    }

    function renderAllCases(cases) {
        const tbody = document.getElementById('allCasesBody');
        if (!cases.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="empty-state"><i class="fas fa-inbox"></i><p>Tidak ada kasus</p></td></tr>';
            return;
        }
        tbody.innerHTML = cases.map(c => `
            <tr>
                <td><strong>${esc(c.kode_pelaporan)}</strong></td>
                <td>${statusBadge(c.status_laporan)}</td>
                <td>${c.status_darurat ? `<span class="risk-badge tinggi">${esc(c.status_darurat)}</span>` : '-'}</td>
                <td>${esc(c.korban_sebagai || '-')}</td>
                <td>${formatDate(c.created_at)}</td>
                <td>
                     <button class="btn-action view" onclick="openCaseModal(${c.id}, 'detail')" title="Lihat Detail Kasus">
                        <i class="fas fa-folder-open"></i> Detail
                    </button>
                </td>
            </tr>
        `).join('');
    }

    // Status filter
    document.getElementById('statusFilter')?.addEventListener('change', (e) => {
        loadCases(e.target.value);
    });

    // ===== SCHEDULES =====
    async function loadSchedules() {
        try {
            const res = await fetch(`../../api/schedule/get.php?psikolog_id=${psikologData.id}`);
            const data = await res.json();
            const list = document.getElementById('scheduleList');

            if (data.status === 'success' && data.data.length) {
                list.innerHTML = data.data.map(s => {
                    const d = new Date(s.waktu_mulai);
                    return `
                    <div class="schedule-card">
                        <div class="schedule-date">
                            <span class="day">${d.getDate()}</span>
                            <span class="month">${d.toLocaleString('id-ID', { month: 'short' })}</span>
                        </div>
                        <div class="schedule-info">
                            <div class="schedule-header" style="display:flex; justify-content:space-between; align-items:start;">
                                <h4>${esc(s.kode_pelaporan)}</h4>
                                ${statusBadge(s.status_jadwal)}
                            </div>
                            <p><i class="fas fa-clock"></i> ${formatTime(s.waktu_mulai)} - ${formatTime(s.waktu_selesai)}</p>
                            <p><i class="fas fa-${s.tipe === 'online' ? 'video' : 'map-marker-alt'}"></i> ${esc(s.tempat_atau_link)}</p>
                            
                            <div class="schedule-actions" style="margin-top: 10px;">
                                <button class="btn-action edit" onclick="openCaseModal(${s.laporan_id}, 'notes')" style="width:100%; justify-content:center; background: var(--psi-primary); color: white;">
                                    <i class="fas fa-clipboard-check"></i> Kelola Sesi & Catatan
                                </button>
                            </div>
                        </div>
                    </div>`;
                }).join('');
            } else {
                list.innerHTML = '<div class="empty-state"><i class="fas fa-calendar-times"></i><p>Belum ada jadwal</p></div>';
            }
        } catch (err) {
            console.error('Load schedules error:', err);
        }
    }

    // ===== CASE MODAL =====
    // ===== CASE MODAL =====
    // ===== CASE MODAL =====
    window.openCaseModal = async function (caseId, tab = 'detail') {
        currentCaseId = caseId;

        let caseData = allCases.find(c => c.id == caseId);

        // If not found in cache, fetch from API
        if (!caseData) {
            try {
                showToast('Mengambil data kasus...', 'info');
                const res = await fetch(`../../api/psikolog/get_case_detail.php?id=${caseId}`);
                const result = await res.json();

                if (result.status === 'success' && result.data) {
                    caseData = result.data;
                    // Add to cache so we don't fetch again
                    allCases.push(caseData);
                } else {
                    throw new Error(result.message || 'Gagal mengambil data');
                }
            } catch (err) {
                console.error('Fetch Case Detail Error:', err);
                showToast(err.message || 'Gagal mengambil data kasus', 'error');
                return;
            }
        }

        if (!caseData) {
            showToast('Gagal membuka kasus. Data tidak ditemukan.', 'error');
            return;
        }

        document.getElementById('modalTitle').textContent = `Kasus: ${caseData.kode_pelaporan}`;
        document.getElementById('caseModal').style.display = 'flex';

        // Set active tab
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.querySelector(`.tab-btn[data-tab="${tab}"]`)?.classList.add('active');
        document.getElementById(`tab-${tab}`)?.classList.add('active');

        // Render detail
        renderCaseDetail(caseData);

        // Prepare notes form
        document.getElementById('notesLaporanId').value = caseData.id;
        document.getElementById('notesJadwalId').value = caseData.jadwal_id || '';
        document.getElementById('notesCatatanId').value = caseData.catatan_id || '';

        // Load existing notes if editing
        // Always try to load notes mostly if tab is notes
        if (tab === 'notes' || caseData.catatan_id) {
            await loadExistingNotes(caseData);
        }

        // Load feedback
        loadFeedback(caseData.id);
    };

    function renderCaseDetail(c) {
        const grid = document.getElementById('caseDetailGrid');
        grid.innerHTML = `
            <div class="detail-item">
                <label>Kode Laporan</label>
                <div class="value">${esc(c.kode_pelaporan)}</div>
            </div>
            <div class="detail-item">
                <label>Status</label>
                <div class="value">${statusBadge(c.status_laporan)}</div>
            </div>
            <div class="detail-item">
                <label>Korban Sebagai</label>
                <div class="value">${esc(c.korban_sebagai || '-')}</div>
            </div>
            <div class="detail-item">
                <label>Gender Korban</label>
                <div class="value">${esc(c.gender_korban || '-')}</div>
            </div>
            <div class="detail-item">
                <label>Tingkat Kekhawatiran</label>
                <div class="value">${esc(c.tingkat_kekhawatiran || '-')}</div>
            </div>
            <div class="detail-item">
                <label>Tanggal Kejadian</label>
                <div class="value">${c.waktu_kejadian ? formatDate(c.waktu_kejadian) : '-'}</div>
            </div>
            <div class="detail-item full-width">
                <label>Lokasi Kejadian</label>
                <div class="value">${esc(c.lokasi_kejadian || '-')}</div>
            </div>
            ${c.waktu_mulai ? `
            <div class="detail-item">
                <label>Jadwal Pertemuan</label>
                <div class="value">${formatDate(c.waktu_mulai)} ${formatTime(c.waktu_mulai)}</div>
            </div>
            <div class="detail-item">
                <label>Tipe & Tempat</label>
                <div class="value"><i class="fas fa-${c.tipe_pertemuan === 'online' ? 'video' : 'map-marker-alt'}"></i> ${esc(c.tempat_atau_link || '-')}</div>
            </div>` : ''}
            <div class="detail-item">
                <label>Dispute Count</label>
                <div class="value">${c.dispute_count || 0}</div>
            </div>
            <div class="detail-item">
                <label>Tanggal Laporan</label>
                <div class="value">${formatDate(c.created_at)}</div>
            </div>
        `;
    }

    async function loadExistingNotes(caseData) {
        try {
            const res = await fetch(`../../api/psikolog/get_notes.php?laporan_id=${caseData.id}`);
            const data = await res.json();
            if (data.status === 'success' && data.data.length) {
                const note = data.data[0];
                document.getElementById('notesCatatanId').value = note.id;
                document.getElementById('ringkasanKasus').value = note.ringkasan_kasus || '';
                document.getElementById('detailKonsultasi').value = note.detail_konsultasi || '';
                document.getElementById('rekomendasi').value = note.rekomendasi || '';
                const riskRadio = document.querySelector(`input[name="tingkatRisiko"][value="${note.tingkat_risiko}"]`);
                if (riskRadio) riskRadio.checked = true;
                updateCharCount();
            }
        } catch (err) {
            console.error('Load notes error:', err);
        }
    }

    async function loadFeedback(laporanId) {
        const feedbackList = document.getElementById('feedbackList');
        const disputeForm = document.getElementById('disputeResponseForm');
        disputeForm.style.display = 'none';

        try {
            const res = await fetch(`../../api/feedback/get.php?laporan_id=${laporanId}`);
            const data = await res.json();

            if (data.status === 'success' && data.data.length) {
                feedbackList.innerHTML = data.data.map(f => `
                    <div class="feedback-card ${f.tipe_feedback}">
                        <div class="feedback-header">
                            <span class="feedback-type ${f.tipe_feedback}">
                                <i class="fas fa-${f.tipe_feedback === 'confirm' ? 'check-circle' : 'exclamation-triangle'}"></i>
                                ${f.tipe_feedback === 'confirm' ? 'Dikonfirmasi' : 'Dispute'}
                            </span>
                            <span class="feedback-date">${formatDate(f.created_at)}</span>
                        </div>
                        <div class="feedback-content">
                            ${f.komentar_user ? `<p class="label">Komentar User:</p><p>${esc(f.komentar_user)}</p>` : ''}
                            ${f.detail_dispute ? `<p class="label">Detail Dispute:</p><p>${esc(f.detail_dispute)}</p>` : ''}
                            ${f.respon_psikolog ? `
                            <div class="feedback-response">
                                <p class="label">Respon Anda:</p>
                                <p>${esc(f.respon_psikolog)}</p>
                                <small style="color:var(--psi-text-muted)">${f.responded_at ? formatDate(f.responded_at) : ''}</small>
                            </div>` : ''}
                        </div>
                    </div>
                `).join('');

                // Show dispute response form if latest feedback is unresponded dispute
                const latestDispute = data.data.find(f => f.tipe_feedback === 'dispute' && !f.respon_psikolog);
                if (latestDispute) {
                    disputeForm.style.display = 'block';
                    disputeForm.dataset.feedbackId = latestDispute.id;
                    disputeForm.dataset.catatanId = latestDispute.catatan_id;
                }
            } else {
                feedbackList.innerHTML = '<div class="empty-state"><i class="fas fa-comments"></i><p>Belum ada feedback dari user</p></div>';
            }
        } catch (err) {
            console.error('Load feedback error:', err);
            feedbackList.innerHTML = '<p class="loading-text">Gagal memuat feedback</p>';
        }
    }

    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(`tab-${btn.dataset.tab}`)?.classList.add('active');
        });
    });

    // Close modal
    document.getElementById('modalClose')?.addEventListener('click', closeModal);
    document.getElementById('caseModal')?.addEventListener('click', (e) => {
        if (e.target.id === 'caseModal') closeModal();
    });

    function closeModal() {
        document.getElementById('caseModal').style.display = 'none';
        resetNotesForm();
    }

    function resetNotesForm() {
        document.getElementById('notesForm')?.reset();
        document.getElementById('notesCatatanId').value = '';
        updateCharCount();
    }

    // Character counter
    document.getElementById('ringkasanKasus')?.addEventListener('input', updateCharCount);

    function updateCharCount() {
        const val = document.getElementById('ringkasanKasus')?.value || '';
        const counter = document.getElementById('ringkasanCount');
        if (counter) counter.textContent = val.length;
    }

    // ===== SUBMIT NOTES =====
    document.getElementById('notesForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        submitNotes('submit');
    });

    document.getElementById('saveDraftBtn')?.addEventListener('click', () => submitNotes('draft'));

    async function submitNotes(action) {
        const ringkasan = document.getElementById('ringkasanKasus').value.trim();
        const detail = document.getElementById('detailKonsultasi').value.trim();
        const rekomendasi = document.getElementById('rekomendasi').value.trim();
        const tingkatRisiko = document.querySelector('input[name="tingkatRisiko"]:checked')?.value || 'sedang';

        if (!ringkasan || !detail) {
            showToast('Ringkasan dan detail konsultasi wajib diisi', 'error');
            return;
        }

        if (action === 'submit' && !confirm('Yakin submit catatan ke User? Data akan terlihat oleh User untuk dikonfirmasi.')) {
            return;
        }

        try {
            const res = await fetch('../../api/psikolog/submit_notes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    laporan_id: document.getElementById('notesLaporanId').value,
                    jadwal_id: document.getElementById('notesJadwalId').value || null,
                    catatan_id: document.getElementById('notesCatatanId').value || null,
                    ringkasan_kasus: ringkasan,
                    detail_konsultasi: detail,
                    rekomendasi: rekomendasi,
                    tingkat_risiko: tingkatRisiko,
                    action: action,
                    csrf_token: csrfToken
                })
            });

            const data = await res.json();
            if (data.csrf_token) csrfToken = data.csrf_token;

            if (data.status === 'success') {
                showToast(data.message, 'success');
                if (data.data?.catatan_id) {
                    document.getElementById('notesCatatanId').value = data.data.catatan_id;
                }
                closeModal();
                loadCases();
            } else {
                showToast(data.message || 'Gagal menyimpan', 'error');
            }
        } catch (err) {
            showToast('Gagal terhubung ke server', 'error');
        }
    }

    // ===== DISPUTE RESPONSE =====
    document.getElementById('sendDisputeResponse')?.addEventListener('click', async () => {
        const form = document.getElementById('disputeResponseForm');
        const respon = document.getElementById('responPsikolog').value.trim();

        if (!respon) {
            showToast('Respon wajib diisi', 'error');
            return;
        }

        try {
            const res = await fetch('../../api/psikolog/respond_dispute.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    feedback_id: form.dataset.feedbackId,
                    catatan_id: form.dataset.catatanId,
                    respon_psikolog: respon,
                    ringkasan_kasus: document.getElementById('ringkasanKasus').value.trim(),
                    detail_konsultasi: document.getElementById('detailKonsultasi').value.trim(),
                    rekomendasi: document.getElementById('rekomendasi').value.trim(),
                    tingkat_risiko: document.querySelector('input[name="tingkatRisiko"]:checked')?.value,
                    csrf_token: csrfToken
                })
            });

            const data = await res.json();
            if (data.csrf_token) csrfToken = data.csrf_token;

            if (data.status === 'success') {
                showToast(data.message, 'success');
                closeModal();
                loadCases();
            } else {
                showToast(data.message || 'Gagal mengirim respon', 'error');
            }
        } catch (err) {
            showToast('Gagal terhubung ke server', 'error');
        }
    });

    // ===== HELPERS =====
    function statusBadge(status) {
        const cls = (status || '').toLowerCase().replace(/\s+/g, '_');
        const labels = {
            'investigasi': '🔍 Investigasi',
            'ditolak': '❌ Ditolak',
            'dilanjutkan': '✅ Dilanjutkan',
            'dijadwalkan': '📅 Dijadwalkan',
            'konsultasi': '💬 Konsultasi',
            'menunggu_konfirmasi': '⏳ Menunggu Konfirmasi',
            'dispute': '⚠️ Dispute',
            'closed': '✅ Closed',
            'scheduled': '📅 Scheduled',
            'completed': '✅ Completed',
            'cancelled': '❌ Cancelled',
            'rescheduled': '🔄 Rescheduled'
        };
        return `<span class="status-badge ${cls}">${labels[cls] || status}</span>`;
    }

    function riskBadge(level) {
        const labels = { rendah: '🟢 Rendah', sedang: '🟡 Sedang', tinggi: '🔴 Tinggi', kritis: '🟣 Kritis' };
        return `<span class="risk-badge ${level}">${labels[level] || level}</span>`;
    }

    function formatDate(dt) {
        if (!dt) return '-';
        return new Date(dt).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function formatTime(dt) {
        if (!dt) return '';
        return new Date(dt).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
    }

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'times-circle' : 'info-circle'}"></i> ${esc(message)}`;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 3200);
    }

    // Initial load
    loadCases();
});
