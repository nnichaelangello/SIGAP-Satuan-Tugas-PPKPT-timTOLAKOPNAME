/**
 * Sistem Monitoring Laporan v5.0
 * Mendukung pencarian dual: Kode Laporan & Email
 */

(function () {
  'use strict';

  // Mode debug
  const IS_DEBUG = window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1';

  const logger = {
    log: (...args) => IS_DEBUG && console.log(...args),
    warn: (...args) => IS_DEBUG && console.warn(...args),
    error: (...args) => console.error(...args)
  };

  // State
  const State = {
    currentReport: null,
    isSearching: false,
    searchType: null
  };

  // Elemen DOM
  const DOM = {
    reportIdInput: document.getElementById('reportIdInput'),
    searchBtn: document.getElementById('searchBtn'),
    searchLoader: document.getElementById('searchLoader'),
    errorMessage: document.getElementById('errorMessage'),
    errorText: document.getElementById('errorText'),
    timelineContainer: document.getElementById('timelineContainer'),
    timelineHeader: document.getElementById('timelineHeader'),
    timelineTitle: document.getElementById('timelineTitle'),
    timelineId: document.getElementById('timelineId'),
    timelineDate: document.getElementById('timelineDate'),
    statusBadge: document.getElementById('statusBadge'),
    statusText: document.getElementById('statusText'),
    timeline: document.getElementById('timeline'),
    centeredLoadingOverlay: document.getElementById('centeredLoadingOverlay')
  };

  // Konfigurasi
  const CONFIG = {
    searchDelay: 1200,
    centeredCubeDelay: 1500,
    apiEndpoint: '../api/monitoring/get_laporan.php'
  };

  // Inisialisasi
  function init() {
    logger.log('🚀 Monitoring System v5.0 dimulai');
    checkURLParameter();
    setupEventListeners();
    setupInputHints();
    logger.log('✅ Monitoring System siap');
  }

  // Setup hint input
  function setupInputHints() {
    if (!DOM.reportIdInput) return;

    DOM.reportIdInput.addEventListener('input', function (e) {
      const value = e.target.value.trim();

      if (!value) {
        DOM.reportIdInput.placeholder = 'Masukkan Kode Laporan atau Email';
        return;
      }

      if (value.includes('@')) {
        DOM.reportIdInput.placeholder = 'Contoh: user@student.itb.ac.id';
        State.searchType = 'email';
      } else {
        DOM.reportIdInput.placeholder = 'Contoh: PPKPT228236148';
        DOM.reportIdInput.value = value.toUpperCase();
        State.searchType = 'kode';
      }
    });
  }

  // Setup event listeners
  function setupEventListeners() {
    if (DOM.searchBtn) {
      DOM.searchBtn.addEventListener('click', handleSearch);
    }

    if (DOM.reportIdInput) {
      DOM.reportIdInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          handleSearch();
        }
      });

      DOM.reportIdInput.addEventListener('focus', hideError);
    }
  }

  // Cek parameter URL
  function checkURLParameter() {
    const urlParams = new URLSearchParams(window.location.search);
    const query = urlParams.get('kode') || urlParams.get('email');

    if (query) {
      logger.log('🔍 Auto-search:', query);
      DOM.reportIdInput.value = query;
      setTimeout(handleSearch, 1000);
    }
  }

  // Handle pencarian
  async function handleSearch() {
    if (State.isSearching) return;

    const query = DOM.reportIdInput?.value?.trim();
    if (!query) {
      showError('Silakan masukkan Kode Laporan atau Email.');
      return;
    }

    const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(query);
    const searchType = isEmail ? 'email' : 'kode';
    const displayQuery = isEmail ? query : query.toUpperCase();

    logger.log(`Mencari dengan ${searchType}:`, displayQuery);

    State.isSearching = true;
    State.searchType = searchType;
    disableInput();
    hideError();
    showSearchLoader();

    try {
      const url = `${CONFIG.apiEndpoint}?query=${encodeURIComponent(displayQuery)}`;
      const response = await fetch(url, { method: 'GET', headers: { 'Accept': 'application/json' } });
      const result = await response.json();

      hideSearchLoader();

      if (result.success && result.data) {
        State.currentReport = result.data;
        State.auditLogs = result.data.history || []; // Store separate copy
        updateTimelineHeader();
        clearTimeline();
        showCenteredLoading();

        setTimeout(() => {
          hideCenteredLoading();
          displayAllSteps();
          updatePhaseCards();
          enableInput();
          State.isSearching = false;
          checkCompletionConfetti();
        }, CONFIG.centeredCubeDelay);
      } else {
        const errorMsg = searchType === 'email'
          ? `Email "${query}" tidak ditemukan. Pastikan email yang digunakan sama dengan saat melapor.`
          : `Kode Laporan "${displayQuery}" tidak ditemukan. Silakan periksa kembali.`;

        showError(errorMsg);
        enableInput();
        State.isSearching = false;
      }
    } catch (error) {
      logger.error('Error mengambil laporan:', error);
      hideSearchLoader();
      showError('Terjadi kesalahan saat mengambil data. Silakan coba lagi.');
      enableInput();
      State.isSearching = false;
    }
  }

  // Update header timeline
  function updateTimelineHeader() {
    if (!State.currentReport) return;

    DOM.timelineHeader.style.display = 'flex';
    DOM.timelineHeader.style.opacity = '0';
    DOM.timelineHeader.style.transform = 'translateY(-12px)';

    DOM.timelineTitle.textContent = 'Progress Laporan';
    DOM.timelineId.textContent = State.currentReport.id;

    const date = new Date(State.currentReport.createdAt);
    DOM.timelineDate.textContent = date.toLocaleDateString('id-ID', {
      day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit'
    });

    const isCompleted = State.currentReport.status === 'completed';
    const isRejected = State.currentReport.status === 'rejected';
    const isDispute = State.currentReport.status === 'dispute';

    let badgeClass = '';
    let badgeIcon = 'fa-clock';
    let badgeText = 'Dalam Proses';

    if (isCompleted) {
      badgeClass = 'status-completed';
      badgeIcon = 'fa-check-circle';
      badgeText = 'Selesai';
    } else if (isRejected) {
      badgeClass = 'status-rejected';
      badgeIcon = 'fa-times-circle';
      badgeText = 'Ditolak';
    } else if (isDispute) {
      badgeClass = 'status-dispute';
      badgeIcon = 'fa-exclamation-triangle';
      badgeText = 'Dispute';
    }

    DOM.statusBadge.className = `timeline-status-badge ${badgeClass}`;
    DOM.statusBadge.innerHTML = `
      <i class="fas ${badgeIcon}"></i>
      <span>${badgeText}</span>
    `;

    requestAnimationFrame(() => {
      DOM.timelineHeader.style.transition = 'all 0.6s cubic-bezier(0.22, 0.61, 0.36, 1)';
      DOM.timelineHeader.style.opacity = '1';
      DOM.timelineHeader.style.transform = 'translateY(0)';
    });
  }

  // Bersihkan timeline
  function clearTimeline() {
    DOM.timeline.style.opacity = '0';
    setTimeout(() => {
      DOM.timeline.innerHTML = '';
      DOM.timeline.style.transition = 'opacity 0.4s ease';
      DOM.timeline.style.opacity = '1';
    }, 300);
  }

  // Loading overlay
  function showCenteredLoading() {
    if (DOM.centeredLoadingOverlay) {
      DOM.centeredLoadingOverlay.style.display = 'flex';
      DOM.centeredLoadingOverlay.style.opacity = '0';
      requestAnimationFrame(() => {
        DOM.centeredLoadingOverlay.style.transition = 'opacity 0.4s ease';
        DOM.centeredLoadingOverlay.style.opacity = '1';
      });
    }
  }

  function hideCenteredLoading() {
    if (DOM.centeredLoadingOverlay) {
      DOM.centeredLoadingOverlay.style.opacity = '0';
      setTimeout(() => { DOM.centeredLoadingOverlay.style.display = 'none'; }, 400);
    }
  }

  // Tampilkan SEMUA step timeline
  function displayAllSteps() {
    if (!State.currentReport?.steps?.length) {
      logger.error('Tidak ada step untuk ditampilkan');
      return;
    }

    DOM.timeline.innerHTML = '';
    State.currentReport.steps.forEach((step, index) => {
      const stepElement = createStepElement(step);
      DOM.timeline.appendChild(stepElement);

      setTimeout(() => {
        requestAnimationFrame(() => {
          stepElement.style.opacity = '1';
          stepElement.style.transform = 'translateY(0) scale(1)';
        });
      }, index * 200);
    });
  }

  // Buat elemen step
  function createStepElement(step) {
    const stepElement = document.createElement('div');
    stepElement.className = `timeline-item status-${step.status}`;
    if (step.action_required) stepElement.classList.add('action-required');
    stepElement.style.cssText = 'opacity: 0; transform: translateY(24px) scale(0.95); transition: all 0.6s cubic-bezier(0.22, 0.61, 0.36, 1);';

    const marker = document.createElement('div');

    const markerConfig = {
      loading: { class: 'marker-loading', content: createSmallCubeHTML() },
      success: { class: 'marker-success', content: step.icon || '✓' },
      failed: { class: 'marker-failed', content: step.icon || '✗' },
      pending: { class: 'marker-pending', content: step.icon || '⏸' }
    };

    const config = markerConfig[step.status] || markerConfig.pending;
    marker.className = `timeline-marker ${config.class}`;
    marker.innerHTML = config.content;

    const content = document.createElement('div');
    content.className = 'timeline-content';
    let dateHTML = '';
    if (step.date) {
      const d = new Date(step.date);
      dateHTML = `<small class="timeline-date">${d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })}</small>`;
    }
    content.innerHTML = `
      <div class="timeline-content-title">${step.title}</div>
      <p class="timeline-content-desc">${step.description}</p>
      ${dateHTML}
    `;

    stepElement.appendChild(marker);
    stepElement.appendChild(content);
    return stepElement;
  }

  // ===== Phase Cards =====
  function updatePhaseCards() {
    const data = State.currentReport;
    if (!data) return;

    // Schedule Card
    const scheduleCard = document.getElementById('scheduleCard');
    if (data.schedule) {
      const s = data.schedule;
      const startDate = new Date(s.waktu_mulai);
      const endDate = s.waktu_selesai ? new Date(s.waktu_selesai) : null;
      document.getElementById('scheduleInfo').innerHTML = `
        <div class="phase-detail-grid">
          <div class="phase-detail-item">
            <i class="fas fa-calendar"></i>
            <div>
              <strong>Tanggal</strong>
              <p>${startDate.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
            </div>
          </div>
          <div class="phase-detail-item">
            <i class="fas fa-clock"></i>
            <div>
              <strong>Waktu</strong>
              <p>${startDate.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}${endDate ? ' - ' + endDate.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) : ''}</p>
            </div>
          </div>
          <div class="phase-detail-item">
            <i class="fas fa-${s.tipe === 'online' ? 'video' : 'map-marker-alt'}"></i>
            <div>
              <strong>${s.tipe === 'online' ? 'Link Meeting' : 'Lokasi'}</strong>
              <p>${escHTML(s.tempat || '-')}</p>
            </div>
          </div>
          <div class="phase-detail-item">
            <i class="fas fa-user-md"></i>
            <div>
              <strong>Psikolog</strong>
              <p>${escHTML(s.psikolog || '-')} (${escHTML(s.spesialisasi || '-')})</p>
            </div>
          </div>
        </div>
      `;
      scheduleCard.style.display = 'block';
    } else {
      scheduleCard.style.display = 'none';
    }

    // Consultation Card (+ Feedback Form Integration)
    const consultCard = document.getElementById('consultationCard');
    if (data.consultation) {
      const c = data.consultation;
      const riskColors = { rendah: '#10b981', sedang: '#f59e0b', tinggi: '#ef4444', kritis: '#a855f7' };
      const riskColor = riskColors[c.tingkat_risiko] || '#8899aa';

      let consultHtml = `
        <div class="phase-detail-grid">
          <div class="phase-detail-item full-width">
            <i class="fas fa-file-alt"></i>
            <div>
              <strong>Ringkasan Kasus</strong>
              <p>${escHTML(c.ringkasan_kasus || '-')}</p>
            </div>
          </div>
          <div class="phase-detail-item full-width">
            <i class="fas fa-align-left"></i>
            <div>
              <strong>Detail Konsultasi</strong>
              <p>${escHTML(c.detail_konsultasi || '-')}</p>
            </div>
          </div>
          <div class="phase-detail-item full-width">
             <i class="fas fa-lightbulb"></i>
             <div>
              <strong>Rekomendasi</strong>
              <p>${escHTML(c.rekomendasi || '-')}</p>
            </div>
          </div>
          <div class="phase-detail-item">
            <i class="fas fa-exclamation-circle" style="color:${riskColor}"></i>
            <div>
              <strong>Tingkat Risiko</strong>
              <p style="color:${riskColor}; font-weight:600;">${(c.tingkat_risiko || '-').toUpperCase()}</p>
            </div>
          </div>
        </div>
      `;

      // Integrate Feedback Form if Status is Waiting Confirmation or Dispute
      const statusRaw = data.status_raw;
      if (statusRaw === 'Menunggu_Konfirmasi' || statusRaw === 'Dispute') {
        consultHtml += `
            <div class="feedback-section" style="border-top:1px solid #eee; margin-top:20px; padding-top:20px;">
               <h5 style="margin-bottom:10px; color:#1f2937;">Konfirmasi Hasil Konsultasi</h5>
               <p style="color:#6b7280; font-size:0.9rem; margin-bottom:15px;">
                 Mohon konfirmasi jika catatan konsultasi di atas sudah sesuai. 
                 Jika ada ketidaksesuaian, Anda dapat mengajukan keberatan.
               </p>
               
               <div style="margin-bottom:15px;">
                   <label style="display:block; margin-bottom:5px; font-weight:500; font-size:0.9rem;">Email Pelapor (Verifikasi) <span style="color:red">*</span></label>
                   <input type="email" id="userEmail" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px; font-family:inherit;" placeholder="Masukkan email yang digunakan saat melapor..." />
               </div>
               
               <input type="hidden" id="feedbackLaporanId" value="${data.id}" />
               <input type="hidden" id="feedbackCatatanId" value="${c.id}" />
               <input type="hidden" id="feedbackKode" value="${data.id}" />
               
               <div style="display:flex; gap:10px; flex-wrap:wrap;">
                   <button id="btnConfirm" style="flex:1; background:#10b981; color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fas fa-check-circle"></i> Sesuai & Tutup Kasus
                   </button>
                   <button id="btnDisputeToggle" style="flex:1; background:#ef4444; color:white; border:none; padding:12px; border-radius:8px; cursor:pointer; font-weight:600; display:flex; align-items:center; justify-content:center; gap:8px;">
                        <i class="fas fa-exclamation-triangle"></i> Ada Kesalahan
                   </button>
               </div>

               <!-- Dispute Form (Hidden by default) -->
               <div id="disputeFormContainer" style="display:none; margin-top:15px; background:#fef2f2; padding:15px; border-radius:8px; border:1px solid #fee2e2;">
                    <label style="display:block; margin-bottom:8px; color:#b91c1c; font-weight:600;">Detail Bagian yang Salah:</label>
                    <textarea id="disputeDetail" rows="3" style="width:100%; border:1px solid #fca5a5; padding:10px; border-radius:6px; margin-bottom:10px;" placeholder="Jelaskan bagian mana yang tidak sesuai..."></textarea>
                    
                    <div style="text-align:right;">
                        <button id="btnSubmitDispute" style="background:#dc2626; color:white; border:none; padding:8px 20px; border-radius:6px; cursor:pointer; font-weight:600;">
                            Kirim Keberatan
                        </button>
                    </div>
               </div>
            </div>
          `;
      }

      document.getElementById('consultationInfo').innerHTML = consultHtml;
      consultCard.style.display = 'block';

      // Attach Event Listeners Dynamically
      if (document.getElementById('btnConfirm')) {
        document.getElementById('btnConfirm').addEventListener('click', () => submitFeedback('confirm'));
      }
      if (document.getElementById('btnDisputeToggle')) {
        document.getElementById('btnDisputeToggle').addEventListener('click', () => {
          const form = document.getElementById('disputeFormContainer');
          form.style.display = form.style.display === 'none' ? 'block' : 'none';
        });
      }
      if (document.getElementById('btnSubmitDispute')) {
        document.getElementById('btnSubmitDispute').addEventListener('click', () => submitFeedback('dispute'));
      }

    } else {
      consultCard.style.display = 'none';
    }

    // Hide separate feedback card (legacy)
    const feedbackCard = document.getElementById('feedbackCard');
    if (feedbackCard) feedbackCard.style.display = 'none';

    // Feedback History
    if (data.feedback && data.feedback.length) {
      // Create separate container for history if needed, or append to consult card?
      // Better to append to timeline or a dedicated history section.
      // For now, let's append it to the Consultation Info as well, or keep it separate in timeline
      // But user asked for BUTTONS in consultation field. History can be anywhere.
      // Let's create a history div inside consultationInfo bottom

      const historyHtml = '<div style="margin-top:20px; border-top:1px solid #eee; padding-top:15px;"><h5 style="margin-bottom:10px;">Riwayat Feedback</h5>' +
        data.feedback.map(f => {
          const isDispute = f.tipe_feedback === 'dispute';
          return `
            <div class="phase-feedback-item ${isDispute ? 'dispute' : 'confirm'}" style="background:${isDispute ? '#fef2f2' : '#f0fdf4'}; padding:12px; border-radius:8px; margin-bottom:10px; border-left:4px solid ${isDispute ? '#ef4444' : '#10b981'};">
              <div class="phase-feedback-header" style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span style="font-weight:600; color:${isDispute ? '#ef4444' : '#10b981'};">
                  <i class="fas fa-${isDispute ? 'exclamation-triangle' : 'check-circle'}"></i>
                  ${isDispute ? 'Keberatan User' : 'Dikonfirmasi User'}
                </span>
                <small style="color:#6b7280;">${new Date(f.created_at || f.feedback_date).toLocaleDateString('id-ID')}</small>
              </div>
              ${f.komentar_user ? `<p style="margin:0 0 4px; font-size:0.9rem;">"${escHTML(f.komentar_user)}"</p>` : ''}
              ${f.detail_dispute ? `<p style="margin:0; font-size:0.9rem; color:#b91c1c;"><strong>Detail:</strong> ${escHTML(f.detail_dispute)}</p>` : ''}
              ${f.respon_psikolog ? `<div style="margin-top:8px; padding-top:8px; border-top:1px dashed #ccc;"><small style="color:#0c969c; font-weight:bold;">Respon Psikolog:</small><p style="margin:0; font-size:0.9rem; color:#374151;">${escHTML(f.respon_psikolog)}</p></div>` : ''}
            </div>
          `;
        }).join('') + '</div>';

      // Append history to consult info if exists
      document.getElementById('consultationInfo').insertAdjacentHTML('beforeend', historyHtml);
    }

    // Render Audit Trail (History)
    renderAuditTrail(data.history);
  }

  function escHTML(str) {
    const div = document.createElement('div');
    div.textContent = str || '';
    return div.innerHTML;
  }

  // ===== Feedback Submit =====
  // Removed setupFeedbackHandlers as it's now dynamic inside updatePhaseCards
  function setupFeedbackHandlers() { }


  async function submitFeedback(type) {
    const email = document.getElementById('userEmail')?.value?.trim();
    const komentar = document.getElementById('userKomentar')?.value?.trim();
    const disputeDetail = document.getElementById('disputeDetail')?.value?.trim();
    const kode = document.getElementById('feedbackKode')?.value;
    const catatanId = document.getElementById('feedbackCatatanId')?.value;

    if (!email) {
      showError('Email wajib diisi untuk verifikasi identitas.');
      return;
    }

    if (type === 'dispute' && !disputeDetail) {
      showError('Detail keberatan wajib diisi.');
      return;
    }

    if (type === 'confirm' && !confirm('Apakah Anda yakin data konsultasi sudah benar? Kasus akan ditutup.')) return;

    try {
      const res = await fetch('../api/feedback/submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          kode_pelaporan: kode,
          catatan_id: catatanId,
          email: email,
          tipe_feedback: type,
          komentar_user: komentar || null,
          detail_dispute: type === 'dispute' ? disputeDetail : null
        })
      });
      const data = await res.json();
      if (data.status === 'success') {
        showFeedbackToast(data.message || (type === 'confirm' ? 'Kasus berhasil dikonfirmasi!' : 'Keberatan berhasil dikirim.'), 'success');
        // Refresh 
        setTimeout(() => handleSearch(), 1500);
      } else {
        showError(data.message || 'Gagal mengirim feedback.');
      }
    } catch (err) {
      showError('Gagal terhubung ke server.');
    }
  }

  function showFeedbackToast(msg, type) {
    const toast = document.createElement('div');
    toast.style.cssText = `position:fixed;bottom:24px;right:24px;padding:14px 20px;border-radius:8px;color:white;font-size:0.88rem;font-weight:500;z-index:999;display:flex;align-items:center;gap:8px;box-shadow:0 8px 30px rgba(0,0,0,0.4);animation:slideInRight 0.3s ease;background:${type === 'success' ? '#10b981' : '#ef4444'};`;
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'times-circle'}"></i> ${msg}`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
  }

  // Buat HTML cube kecil
  function createSmallCubeHTML() {
    return `
      <div class="cube-wrapper small">
        <div class="cube">
          <div class="cube-faces">
            <div class="cube-face shadow"></div>
            <div class="cube-face bottom"></div>
            <div class="cube-face top"></div>
            <div class="cube-face left"></div>
            <div class="cube-face right"></div>
            <div class="cube-face back"></div>
            <div class="cube-face front"></div>
          </div>
        </div>
      </div>
    `;
  }

  // Cek konfeti jika selesai
  function checkCompletionConfetti() {
    if (!State.currentReport) return;

    const allSuccess = State.currentReport.steps.every(s => s.status === 'success');
    if (allSuccess && State.currentReport.status === 'completed') {
      setTimeout(() => {
        logger.log('🎉 Semua step selesai! Memulai konfeti...');
        window.Confetti?.start();
      }, 800);
    }
  }

  // Helper UI
  function showSearchLoader() { DOM.searchLoader?.classList.add('show'); }
  function hideSearchLoader() { DOM.searchLoader?.classList.remove('show'); }
  // ===== Audit Trail Logic =====

  // Expose to window for onclick handlers
  window.filterAudit = function (role) {
    if (!State.auditLogs) return;

    // Update buttons
    document.querySelectorAll('.filter-btn').forEach(btn => {
      btn.classList.remove('active');
      btn.style.background = 'white';
      btn.style.color = '#475569';
      if (btn.textContent.toLowerCase().includes(role === 'all' ? 'semua' : role)) {
        btn.classList.add('active');
        btn.style.background = '#4b8a7b';
        btn.style.color = 'white';
        btn.style.borderColor = '#4b8a7b';
      }
    });

    renderAuditTrail(State.auditLogs, role);
  };

  window.showAuditDetail = function (index) {
    const logs = State.currentFilteredLogs || State.auditLogs;
    const item = logs[index];
    if (!item || !item.perubahan_data) return;

    let changes = [];
    try {
      changes = JSON.parse(item.perubahan_data);
    } catch (e) {
      console.error("Invalid JSON diff", e);
      return;
    }

    const content = document.getElementById('auditDiffContent');
    let html = '<table style="width:100%; border-collapse:collapse; font-size:0.9rem;"><thead><tr style="background:#f1f5f9; text-align:left;"><th style="padding:12px; border-bottom:2px solid #e2e8f0;">Field</th><th style="padding:12px; border-bottom:2px solid #e2e8f0; color:#ef4444;">Sebelumnya</th><th style="padding:12px; border-bottom:2px solid #e2e8f0; color:#10b981;">Menjadi</th></tr></thead><tbody>';

    changes.forEach(change => {
      html += `
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px; font-weight:600; color:#334155; vertical-align:top;">${change.field}</td>
                <td style="padding:12px; color:#64748b; background:#fff1f2; vertical-align:top; white-space:pre-wrap;">${change.old || '-'}</td>
                <td style="padding:12px; color:#0f172a; background:#f0fdf4; vertical-align:top; white-space:pre-wrap;">${change.new || '-'}</td>
            </tr>
          `;
    });
    html += '</tbody></table>';

    content.innerHTML = html;

    const modal = document.getElementById('auditDetailModal');
    modal.style.display = 'flex';
  };

  window.closeAuditModal = function () {
    document.getElementById('auditDetailModal').style.display = 'none';
  };

  function renderAuditTrail(history, filterRole = 'all') {
    const auditCard = document.getElementById('auditTrailCard');
    const listContainer = document.getElementById('auditTrailList');

    // Store globally for filtering access
    if (!State.auditLogs) State.auditLogs = history;

    // Filter
    let filtered = history || [];
    if (filterRole !== 'all') {
      filtered = history.filter(item => item.diubah_oleh_role === filterRole);
    }
    State.currentFilteredLogs = filtered;

    if (filtered.length === 0) {
      if (filterRole === 'all') {
        if (auditCard) auditCard.style.display = 'none';
      } else {
        if (auditCard) auditCard.style.display = 'block';
        listContainer.innerHTML = '<div style="text-align:center; padding:32px; color:#94a3b8; font-style:italic;">Tidak ada riwayat aktivitas untuk filter ini.</div>';
      }
      return;
    }

    if (auditCard) auditCard.style.display = 'block';

    let html = '<div class="audit-timeline" style="position:relative; padding-left:20px; border-left:2px solid #e2e8f0; margin-left:10px; margin-top:10px;">';

    filtered.forEach((item, index) => {
      const date = new Date(item.created_at).toLocaleString('id-ID', {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });

      let roleColor = '#64748b'; // default slate
      let roleIcon = 'user';
      let roleLabel = item.diubah_oleh_role;

      switch (item.diubah_oleh_role) {
        case 'admin':
          roleColor = '#3b82f6'; // blue
          roleIcon = 'user-shield';
          roleLabel = 'Admin';
          break;
        case 'psikolog':
          roleColor = '#8b5cf6'; // purple
          roleIcon = 'user-md';
          roleLabel = 'Psychologist';
          break;
        case 'user':
          roleColor = '#f59e0b'; // amber
          roleIcon = 'user';
          roleLabel = 'Anda';
          break;
        case 'system':
          roleColor = '#10b981'; // emerald
          roleIcon = 'robot';
          roleLabel = 'Sistem';
          break;
      }

      // Status Change Visualization
      let statusHtml = '';
      if (item.status_lama && item.status_baru && item.status_lama !== item.status_baru) {
        statusHtml = `<div style="font-weight:600; color:#334155; margin-bottom:4px; font-size:0.95rem;">
                         <span style="color:#94a3b8">${item.status_lama}</span> 
                         <i class="fas fa-long-arrow-alt-right" style="margin:0 6px; color:#cbd5e1;"></i> 
                         <span style="color:#0f172a">${item.status_baru}</span>
                       </div>`;
      } else {
        statusHtml = `<div style="font-weight:600; color:#0f172a; margin-bottom:4px; font-size:0.95rem;">Status: ${item.status_baru}</div>`;
      }

      // Detail Button
      let detailBtn = '';
      if (item.perubahan_data) {
        detailBtn = `<button onclick="showAuditDetail(${index})" style="margin-top:12px; display:inline-flex; align-items:center; gap:6px; background:white; border:1px solid #cbd5e1; padding:6px 14px; border-radius:30px; font-size:0.8rem; font-weight:600; color:#475569; cursor:pointer; transition:all 0.2s; box-shadow:0 1px 2px rgba(0,0,0,0.05);">
                        <i class="fas fa-file-alt" style="color:#4b8a7b;"></i> Lihat Detail Perubahan
                       </button>`;
      }

      html += `
        <div class="audit-item" style="margin-bottom:28px; position:relative;">
            <!-- Dot -->
            <div style="position:absolute; left:-29px; top:2px; width:16px; height:16px; background:${roleColor}; border-radius:50%; border:3px solid white; box-shadow:0 0 0 2px #e2e8f0; z-index:2;"></div>
            
            <!-- Header -->
            <div style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:0.8rem; color:#64748b;">
                <span style="font-weight:700; color:${roleColor}; display:flex; align-items:center; gap:4px; background:${roleColor}10; padding:2px 8px; border-radius:4px;">
                    <i class="fas fa-${roleIcon}"></i> ${roleLabel.toUpperCase()}
                </span>
                <span style="width:3px; height:3px; background:#cbd5e1; border-radius:50%;"></span>
                <span>${date}</span>
            </div>
            
            <!-- Body -->
            <div style="background:#f8fafc; padding:16px; border-radius:12px; border:1px solid #f1f5f9; box-shadow:0 1px 2px rgba(0,0,0,0.02);">
                ${statusHtml}
                ${item.keterangan ? `<div style="margin-top:6px; font-size:0.9rem; color:#475569;">${item.keterangan}</div>` : ''}
                ${detailBtn}
            </div>
        </div>
      `;
    });

    html += '</div>';
    listContainer.innerHTML = html;

    // Set default active tab style if first run
    if (filterRole === 'all' && !document.querySelector('.filter-btn.active')) {
      const allBtn = document.querySelector('.filter-btn');
      if (allBtn) {
        allBtn.classList.add('active');
        allBtn.style.background = '#4b8a7b';
        allBtn.style.color = 'white';
        allBtn.style.borderColor = '#4b8a7b';
      }
    }
  }

  function showError(message) { DOM.errorText.textContent = message; DOM.errorMessage.classList.add('show'); }
  function hideError() { DOM.errorMessage.classList.remove('show'); }
  function disableInput() { DOM.reportIdInput.disabled = true; DOM.searchBtn.disabled = true; DOM.searchBtn.classList.add('loading'); }
  function enableInput() { DOM.reportIdInput.disabled = false; DOM.searchBtn.disabled = false; DOM.searchBtn.classList.remove('loading'); }

  // API Publik
  window.MonitoringSystem = {
    search: handleSearch,
    getState: () => ({ ...State }),
    version: '6.0.0'
  };

  // Inisialisasi
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => { init(); setupFeedbackHandlers(); });
  } else {
    init();
    setupFeedbackHandlers();
  }
})();