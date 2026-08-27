(function () {
  const qs = s => document.querySelector(s);
  const qsa = s => Array.from(document.querySelectorAll(s));
  const state = { options: {}, issuedBadges: [], activeBadge: null, idScan: initialScanState(), activeVisitCheck: initialActiveVisitState() };
  const ID_SCAN_CROP = { width: 0.94, height: 0.86 };
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const title = v => String(v || '').toLowerCase().split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
  const fmt = v => { if (!v) return 'Not applicable'; const d = new Date(String(v).replace(' ', 'T')); return Number.isNaN(d.getTime()) ? String(v) : d.toLocaleString(undefined, { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }); };
  const toast = m => window.FAMModal?.showToast?.(m);
  const scanStatusLabel = {
    IDLE: 'Ready',
    CAMERA_STARTING: 'Starting camera',
    LOOKING_FOR_ID: 'Looking for ID',
    HOLD_STEADY: 'Hold steady',
    CAMERA_ACTIVE: 'Camera ready',
    CAPTURING: 'Capturing ID',
    PROCESSING: 'Analyzing ID',
    REVIEW_REQUIRED: 'Review required',
    CONFIRMED: 'ID details confirmed',
    ERROR: 'Needs manual entry'
  };

  function initialScanState() {
    return {
      status: 'IDLE',
      stream: null,
      worker: null,
      timer: null,
      analyzing: false,
      analysisStartedAt: 0,
      frameAttempts: 0,
      stableFrames: 0,
      contentFrames: 0,
      previousFrame: null,
      frameBuffer: [],
      imageDataUrl: '',
      rawText: '',
      detectedType: '',
      detectedName: '',
      last4: '',
      confirmed: false,
      error: '',
      runId: 0
    };
  }

  function initialActiveVisitState() {
    return { token: 0, checking: false, blocked: false, stale: false, visit: null, error: '' };
  }

  function optionRows(items, placeholder, labeler) {
    return `<option value="">${esc(placeholder)}</option>${(items || []).map(item => `<option value="${esc(item.id || item)}">${esc(labeler ? labeler(item) : title(item))}</option>`).join('')}`;
  }

  function fillSelect(select, items, placeholder, labeler) {
    const current = select.value;
    select.innerHTML = optionRows(items, placeholder, labeler);
    if ([...select.options].some(option => option.value === current)) select.value = current;
  }

  function renderOptions() {
    const idTypes = (state.options.identity_document_types || []).filter(type => type !== 'NONE');
    const visitorTypes = (state.options.visitor_types || []).filter(type => !['WALK_IN', 'VENDOR'].includes(type));
    qsa('[data-visitor-type-select]').forEach(select => fillSelect(select, visitorTypes, 'Select visitor type'));
    qsa('[data-id-type-select]').forEach(select => fillSelect(select, idTypes, 'Select ID type'));
    qsa('[data-department-select]').forEach(select => fillSelect(select, state.options.departments, 'Select department', row => row.name || row.code));
    qsa('[data-space-select]').forEach(select => fillSelect(select, state.options.facility_spaces, 'Select facility / room', row => `${row.name}${row.building_name ? ' - ' + row.building_name : ''}`));
    qsa('[data-host-select]').forEach(select => fillSelect(select, state.options.host_employees, 'Optional', row => row.full_name || row.employee_number));
    renderBadges();
    renderIssuedBadges();
  }

  function renderBadges() {
    qsa('[data-badge-select]').forEach(select => {
      fillSelect(select, state.options.available_badges, 'Select available badge', row => row.badge_number);
      syncEntryBlockedState();
    });
  }

  function renderIssuedBadges() {
    const select = qs('[data-issued-badge-select]');
    if (!select) return;
    const current = select.value;
    select.innerHTML = `<option value="">Select issued badge</option>${state.issuedBadges.map(item => `<option value="${esc(item.badge_number)}">${esc(`${item.badge_number} - ${item.visitor_reference_number} - ${item.visitor_name}`)}</option>`).join('')}`;
    const fresh = state.issuedBadges.find(item => item.badge_number === current);
    if (fresh) {
      select.value = current;
      state.activeBadge = fresh;
    } else {
      state.activeBadge = null;
    }
    renderBadgeSummary(state.activeBadge);
  }

  async function refreshOptions() {
    const [payload, issuedPayload] = await Promise.all([
      window.FAMApi.request('../api/visitors/options.php'),
      window.FAMApi.request('../api/visitors/issued-badges.php')
    ]);
    state.options = payload.data || {};
    state.issuedBadges = issuedPayload.data?.items || [];
    renderOptions();
  }

  function destinationValid(data) {
    return Boolean(data.destination_department_reference_id && data.facility_space_id);
  }

  function showError(form, selector, message) {
    const box = form.querySelector(selector);
    if (!box) return;
    box.textContent = message || 'Please review the highlighted fields.';
    box.hidden = false;
  }

  function validationMessage(error) {
    const errors = error?.errors || {};
    return errors.destination
      || errors.destination_department_reference_id
      || errors.facility_space_id
      || errors.visitor_type
      || errors.visit_purpose
      || errors.badge_id
      || errors.identification_type
      || errors.full_name
      || error?.message
      || 'Unable to check in visitor.';
  }

  function clearError(form, selector) {
    const box = form.querySelector(selector);
    if (box) box.hidden = true;
  }

  function activeVisitMessage() {
    const visit = state.activeVisitCheck.visit || {};
    const ref = visit.visitor_reference || 'the existing visit';
    return `This visitor already has an active visit (${ref}). Check out the existing visit before creating another.`;
  }

  function ensureActiveVisitAlert(form) {
    if (!form) return null;
    let alert = form.querySelector('[data-active-visit-alert]');
    if (alert) return alert;
    alert = document.createElement('div');
    alert.className = 'facility-form-error';
    alert.setAttribute('data-active-visit-alert', '');
    alert.hidden = true;
    const entryError = form.querySelector('[data-entry-error]');
    if (entryError?.parentNode) entryError.parentNode.insertBefore(alert, entryError.nextSibling);
    else form.prepend(alert);
    return alert;
  }

  function renderActiveVisitAlert() {
    const form = qs('#visitor-entry-form');
    const alert = ensureActiveVisitAlert(form);
    if (!alert) return;
    if (state.activeVisitCheck.blocked && state.activeVisitCheck.visit) {
      const visit = state.activeVisitCheck.visit;
      const details = [visit.status ? `Status: ${title(visit.status)}` : '', visit.badge_code ? `Badge: ${visit.badge_code}` : ''].filter(Boolean).join(' · ');
      alert.textContent = details ? `${activeVisitMessage()} ${details}` : activeVisitMessage();
      alert.hidden = false;
      return;
    }
    if (state.activeVisitCheck.error) {
      alert.textContent = state.activeVisitCheck.error;
      alert.hidden = false;
      return;
    }
    alert.hidden = true;
    alert.textContent = '';
  }

  function syncEntryBlockedState() {
    const form = qs('#visitor-entry-form');
    if (!form) return;
    const locked = state.activeVisitCheck.checking || state.activeVisitCheck.blocked;
    const submit = form.querySelector('[type="submit"]');
    const badge = form.querySelector('[data-badge-select]');
    if (submit) submit.disabled = locked;
    if (badge) {
      badge.disabled = locked;
      if (state.activeVisitCheck.blocked) badge.value = '';
    }
    renderActiveVisitAlert();
  }

  function clearActiveVisitCheck(options = {}) {
    state.activeVisitCheck = { ...initialActiveVisitState(), token: state.activeVisitCheck.token + 1 };
    if (options.clearBadge !== false) {
      const badge = qs('#visitor-entry-form [data-badge-select]');
      if (badge) badge.value = '';
    }
    syncEntryBlockedState();
  }

  function activeVisitPayload(form) {
    const data = dataFrom(form);
    return {
      full_name: data.full_name || 'Visitor Check',
      visitor_type: data.visitor_type || 'GUEST',
      email_address: data.email_address || '',
      mobile_number: data.mobile_number || '',
      identification_type: data.identification_type || '',
      identification_last4: data.identification_last4 || ''
    };
  }

  function hasStableIdentity(data) {
    return Boolean((data.identification_type && data.identification_last4) || data.email_address || data.mobile_number);
  }

  async function verifyActiveVisitAfterScan(form) {
    const data = activeVisitPayload(form);
    if (!hasStableIdentity(data)) {
      clearActiveVisitCheck({ clearBadge: false });
      return;
    }
    const token = state.activeVisitCheck.token + 1;
    state.activeVisitCheck = { token, checking: true, blocked: false, stale: false, visit: null, error: '' };
    syncEntryBlockedState();
    try {
      const payload = await window.FAMApi.request('../api/visitors/active-visit.php', { method:'POST', body:data });
      if (state.activeVisitCheck.token !== token) return;
      const visit = payload.data?.visit || null;
      state.activeVisitCheck = { token, checking: false, blocked: Boolean(payload.data?.has_active_visit && visit), stale: false, visit, error: '' };
    } catch (error) {
      if (state.activeVisitCheck.token !== token) return;
      state.activeVisitCheck = { token, checking: false, blocked: false, stale: true, visit: null, error: 'Active visit could not be verified. The system will verify again when you check in.' };
    } finally {
      if (state.activeVisitCheck.token === token) syncEntryBlockedState();
    }
  }

  function setScanState(next) {
    state.idScan = { ...state.idScan, ...next };
  }

  function resetScanState() {
    stopLiveScanning();
    clearCaptureBuffer();
    const worker = state.idScan.worker;
    state.idScan = { ...initialScanState(), worker };
    clearActiveVisitCheck();
    updateConfirmedIndicator(false);
  }

  function updateConfirmedIndicator(show) {
    const indicator = qs('[data-id-scan-confirmed]');
    if (indicator) indicator.hidden = !show;
  }

  function dataFrom(form) {
    return Object.fromEntries(new FormData(form).entries());
  }

  function focusFirstEmpty(form) {
    form.querySelector(':invalid')?.focus();
  }

  async function submitEntry(form) {
    clearError(form, '[data-entry-error]');
    if (state.activeVisitCheck.checking) {
      showError(form, '[data-entry-error]', 'Active visit verification is still in progress.');
      return;
    }
    if (state.activeVisitCheck.blocked) {
      showError(form, '[data-entry-error]', activeVisitMessage());
      syncEntryBlockedState();
      return;
    }
    if (!form.reportValidity()) { focusFirstEmpty(form); return; }
    const data = dataFrom(form);
    if (!destinationValid(data)) {
      showError(form, '[data-entry-error]', !data.destination_department_reference_id ? 'Select a department for this visit.' : 'Select a facility/room for this visit.');
      form.querySelector(!data.destination_department_reference_id ? '[name="destination_department_reference_id"]' : '[name="facility_space_id"]')?.focus();
      return;
    }
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
      const payload = await window.FAMApi.request('../api/visitors/reception-check-in.php', { method:'POST', body:data });
      const item = payload.data?.item || {};
      toast(`${item.visitor_reference_number || 'Visitor'} checked in.`);
      form.reset();
      resetScanState();
      form.elements.full_name?.focus();
      await refreshOptions();
    } catch (error) {
      showError(form, '[data-entry-error]', validationMessage(error));
      if (error.status === 409) refreshOptions().catch(() => {});
    } finally {
      button.disabled = state.activeVisitCheck.checking || state.activeVisitCheck.blocked;
    }
  }

  function moveToTopLayer(element) {
    if (element && element.parentElement !== document.body) document.body.appendChild(element);
    return element;
  }

  function scanDialog() {
    return moveToTopLayer(qs('#id-capture-dialog'));
  }

  function closeScanDialog(options = {}) {
    const dialog = scanDialog();
    if (!dialog) return;
    stopLiveScanning();
    const afterClose = () => {
      dialog.innerHTML = '';
      dialog.hidden = true;
      if (!options.preserveConfirmed) clearCaptureBuffer();
    };
    if (window.FAMModal?.closeElement) {
      window.FAMModal.closeElement(dialog, afterClose);
    } else {
      dialog.hidden = true;
      afterClose();
    }
  }

  function stopCamera() {
    const stream = state.idScan.stream;
    if (stream) stream.getTracks().forEach(track => track.stop());
    setScanState({ stream: null });
  }

  function stopLiveScanning() {
    if (state.idScan.timer) window.clearInterval(state.idScan.timer);
    stopCamera();
    setScanState({ timer: null, analyzing: false });
  }

  function clearCaptureBuffer() {
    setScanState({ imageDataUrl: '', rawText: '', detectedType: '', detectedName: '', last4: '', confirmed: false, error: '', frameBuffer: [] });
    const canvas = qs('#id-capture-canvas');
    const ctx = canvas?.getContext?.('2d');
    if (canvas && ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  function clearTemporaryImage() {
    setScanState({ imageDataUrl: '', rawText: '' });
    const canvas = qs('#id-capture-canvas');
    const ctx = canvas?.getContext?.('2d');
    if (canvas && ctx) ctx.clearRect(0, 0, canvas.width, canvas.height);
  }

  function renderScanCapture(message = '') {
    const dialog = scanDialog();
    if (!dialog) return;
    dialog.hidden = false;
    dialog.className = 'facility-dialog id-capture-dialog';
    dialog.innerHTML = `<div class="facility-dialog-panel id-capture-panel">
      <div class="facility-details-modal-header">
        <div>
          <p>Scan Visitor ID</p>
          <h2 id="id-capture-title">Live ID Scan</h2>
        </div>
        <button class="facility-details-modal-close" type="button" data-id-capture-close aria-label="Close ID capture">&times;</button>
      </div>
      <div class="facility-dialog-body id-capture-body">
        <p class="scanner-dialog-copy">Position the front of the ID inside the frame. One captured image is sent securely for AI-assisted extraction, then the officer must verify the result.</p>
        <div class="id-camera-frame" data-id-camera-frame>
          <video id="id-capture-video" autoplay playsinline muted></video>
          <canvas id="id-capture-canvas" hidden></canvas>
          <div class="id-frame-guide" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
        </div>
        <div class="id-capture-status" role="status" aria-live="polite">
          <span class="material-symbols-outlined" aria-hidden="true">document_scanner</span>
          <div><strong>${esc(scanStatusLabel[state.idScan.status] || 'Looking for ID')}</strong><p data-id-ocr-progress>${esc(message || 'Position the ID inside the frame.')}</p></div>
        </div>
      </div>
      <div class="facility-dialog-actions">
        <button class="btn-secondary dashboard-action-button" type="button" data-id-capture-close>Cancel</button>
        <button class="btn-secondary dashboard-action-button" type="button" data-id-capture-close>Enter Manually</button>
        <button class="btn-primary dashboard-action-button" type="button" data-id-capture-trigger>
          <span class="material-symbols-outlined" aria-hidden="true">photo_camera</span>
          Capture ID
        </button>
      </div>
    </div>`;
    dialog.querySelector('[data-id-capture-trigger]')?.focus();
  }

  async function openIdCapture() {
    clearCaptureBuffer();
    updateConfirmedIndicator(false);
    const runId = state.idScan.runId + 1;
    setScanState({ status: 'CAMERA_STARTING', runId, analysisStartedAt: Date.now(), frameAttempts: 0, stableFrames: 0, contentFrames: 0, previousFrame: null, frameBuffer: [] });
    renderScanCapture('Requesting camera permission...');
    if (!navigator.mediaDevices?.getUserMedia) {
      setScanState({ status: 'ERROR', error: 'Camera capture is not supported in this browser.' });
      renderScanError('Camera capture is not supported in this browser. Enter visitor details manually.');
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
      });
      if (state.idScan.runId !== runId || scanDialog()?.hidden) {
        stream.getTracks().forEach(track => track.stop());
        return;
      }
      setScanState({ status: 'LOOKING_FOR_ID', stream });
      const video = qs('#id-capture-video');
      if (video) {
        video.srcObject = stream;
        await video.play().catch(() => {});
      }
      renderScanStatus('Position the ID inside the frame.');
      startAutoCaptureLoop(runId);
    } catch (error) {
      const denied = error?.name === 'NotAllowedError' || error?.name === 'SecurityError';
      const message = denied
        ? 'Camera permission was denied. Enter visitor details manually or allow camera access and try again.'
        : 'Camera is unavailable or already in use. Enter visitor details manually.';
      setScanState({ status: 'ERROR', error: message });
      renderScanError(message);
    }
  }

  function renderScanStatus(message) {
    const status = qs('.id-capture-status');
    if (!status) return;
    status.querySelector('strong').textContent = scanStatusLabel[state.idScan.status] || 'Ready';
    status.querySelector('p').textContent = message;
  }

  function renderScanError(message) {
    const dialog = scanDialog();
    if (!dialog) return;
    stopCamera();
    dialog.hidden = false;
    dialog.innerHTML = `<div class="facility-dialog-panel id-capture-panel">
      <div class="facility-details-modal-header"><div><p>Scan Visitor ID</p><h2 id="id-capture-title">Camera unavailable</h2></div><button class="facility-details-modal-close" type="button" data-id-capture-close aria-label="Close ID capture">&times;</button></div>
      <div class="facility-dialog-body id-capture-body">
        <div class="id-capture-empty" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">no_photography</span>
          <h3>Enter details manually</h3>
          <p>${esc(message)}</p>
        </div>
      </div>
      <div class="facility-dialog-actions">
        <button class="btn-primary dashboard-action-button" type="button" data-id-capture-close>Continue Manually</button>
      </div>
    </div>`;
    dialog.querySelector('[data-id-capture-close]')?.focus();
  }

  function preprocessFrame(video, canvas) {
    const { sx, sy, cropWidth, cropHeight } = idScanCrop(video);
    canvas.width = 1200;
    canvas.height = Math.round((cropHeight / cropWidth) * canvas.width);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, sx, sy, cropWidth, cropHeight, 0, 0, canvas.width, canvas.height);
    const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = image.data;
    for (let i = 0; i < data.length; i += 4) {
      const gray = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
      const contrast = Math.max(0, Math.min(255, (gray - 128) * 1.18 + 128));
      data[i] = data[i + 1] = data[i + 2] = contrast;
    }
    ctx.putImageData(image, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.88);
  }

  function startAutoCaptureLoop(runId) {
    if (state.idScan.timer) window.clearInterval(state.idScan.timer);
    const timer = window.setInterval(() => analyzeFrameForAutoCapture(runId), 250);
    setScanState({ timer, analyzing: true });
    window.setTimeout(() => analyzeFrameForAutoCapture(runId), 250);
  }

  function analyzeFrameForAutoCapture(runId) {
    if (state.idScan.runId !== runId || !state.idScan.analyzing || !['LOOKING_FOR_ID', 'HOLD_STEADY'].includes(state.idScan.status)) return;
    const video = qs('#id-capture-video');
    const canvas = qs('#id-capture-canvas');
    if (!video || !canvas || !video.videoWidth || !video.videoHeight) return;
    const analysis = analyzeVideoFrame(video, canvas);
    const frameAttempts = state.idScan.frameAttempts + 1;
    const elapsed = Date.now() - state.idScan.analysisStartedAt;
    const contentFrames = analysis.present ? state.idScan.contentFrames + 1 : state.idScan.contentFrames;

    if (!analysis.present) {
      setScanState({ frameAttempts, contentFrames, stableFrames: 0, previousFrame: analysis.sample, status: 'LOOKING_FOR_ID' });
      renderScanStatus(elapsed > 8000 ? 'Place the ID inside the frame or use Capture ID manually.' : 'Position the ID inside the frame.');
      return;
    }

    if (!analysis.stable) {
      setScanState({ frameAttempts, contentFrames, stableFrames: 0, previousFrame: analysis.sample, status: 'HOLD_STEADY' });
      renderScanStatus('Hold steady or use Capture ID manually.');
      return;
    }

    const stableFrames = state.idScan.stableFrames + 1;
    const frameBuffer = addFrameCandidate(analysis);
    setScanState({ frameAttempts, contentFrames, stableFrames, frameBuffer, previousFrame: analysis.sample, status: 'HOLD_STEADY' });
    renderScanStatus(stableFrames < 4 ? 'Hold steady...' : 'Capturing ID...');
    if (stableFrames >= 4) captureIdFrame(bestFrameCandidate(frameBuffer));
  }

  function analyzeVideoFrame(video, canvas) {
    const sampleWidth = 120;
    const { sx, sy, cropWidth, cropHeight } = idScanCrop(video);
    canvas.width = sampleWidth;
    canvas.height = Math.round((cropHeight / cropWidth) * sampleWidth);
    const ctx = canvas.getContext('2d', { willReadFrequently: true });
    ctx.drawImage(video, sx, sy, cropWidth, cropHeight, 0, 0, canvas.width, canvas.height);
    const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = image.data;
    const gray = new Uint8ClampedArray(canvas.width * canvas.height);
    let sum = 0;
    let dark = 0;
    let light = 0;
    for (let i = 0, p = 0; i < data.length; i += 4, p += 1) {
      const value = Math.round(0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2]);
      gray[p] = value;
      sum += value;
      if (value < 35) dark += 1;
      if (value > 220) light += 1;
    }
    const pixels = gray.length;
    const brightness = sum / pixels;
    let edge = 0;
    let varianceSum = 0;
    for (let y = 1; y < canvas.height - 1; y += 1) {
      for (let x = 1; x < canvas.width - 1; x += 1) {
        const index = y * canvas.width + x;
        edge += Math.abs(gray[index] - gray[index - 1]) + Math.abs(gray[index] - gray[index - canvas.width]);
      }
    }
    for (let i = 0; i < gray.length; i += 3) {
      const delta = gray[i] - brightness;
      varianceSum += delta * delta;
    }
    const sharpness = edge / pixels;
    const variance = Math.sqrt(varianceSum / Math.ceil(gray.length / 3));
    const darkRatio = dark / pixels;
    const lightRatio = light / pixels;
    const presenceScore = Math.min(sharpness / 14, 1) * 0.45 + Math.min(variance / 42, 1) * 0.45 + Math.min(darkRatio / 0.04, 1) * 0.1;
    const previous = state.idScan.previousFrame;
    let motion = previous?.length === gray.length ? 0 : 999;
    if (previous?.length === gray.length) {
      let diff = 0;
      for (let i = 0; i < gray.length; i += 4) diff += Math.abs(gray[i] - previous[i]);
      motion = diff / Math.ceil(gray.length / 4);
    }
    return {
      sample: gray,
      present: presenceScore >= 0.4 && brightness > 45 && brightness < 220 && darkRatio > 0.004 && lightRatio < 0.62,
      stable: motion < 15,
      sharpness,
      variance,
      presenceScore,
      motion,
    };
  }

  function idScanCrop(video) {
    const sourceWidth = video.videoWidth;
    const sourceHeight = video.videoHeight;
    const cropWidth = Math.floor(sourceWidth * ID_SCAN_CROP.width);
    const cropHeight = Math.floor(sourceHeight * ID_SCAN_CROP.height);
    return {
      sx: Math.floor((sourceWidth - cropWidth) / 2),
      sy: Math.floor((sourceHeight - cropHeight) / 2),
      cropWidth,
      cropHeight,
    };
  }

  function addFrameCandidate(analysis) {
    const video = qs('#id-capture-video');
    const canvas = qs('#id-capture-canvas');
    if (!video || !canvas || !video.videoWidth || !video.videoHeight) return state.idScan.frameBuffer || [];
    const imageDataUrl = preprocessFrame(video, canvas);
    const candidate = {
      imageDataUrl,
      sharpness: analysis.sharpness,
      presenceScore: analysis.presenceScore,
      motion: analysis.motion,
    };
    return [...(state.idScan.frameBuffer || []), candidate].slice(-8);
  }

  function bestFrameCandidate(buffer) {
    return [...(buffer || [])].sort((a, b) => {
      const aScore = a.sharpness + (a.presenceScore * 12) - (a.motion * 0.2);
      const bScore = b.sharpness + (b.presenceScore * 12) - (b.motion * 0.2);
      return bScore - aScore;
    })[0] || null;
  }

  function captureIdFrame(candidate = null) {
    const runId = state.idScan.runId;
    if (!['CAMERA_ACTIVE', 'LOOKING_FOR_ID', 'HOLD_STEADY'].includes(state.idScan.status)) return;
    if (state.idScan.runId !== runId) return;
    const video = qs('#id-capture-video');
    const canvas = qs('#id-capture-canvas');
    if (!video || !canvas || !video.videoWidth || !video.videoHeight) return;
    if (state.idScan.timer) window.clearInterval(state.idScan.timer);
    setScanState({ status: 'CAPTURING', analyzing: false, timer: null });
    renderScanStatus('ID captured. Analyzing ID...');
    const captured = candidate?.imageDataUrl || preprocessFrame(video, canvas);
    setScanState({ imageDataUrl: captured, status: 'PROCESSING' });
    stopCamera();
    renderCapturedProcessing(captured);
    processCapturedImage(runId, captured);
  }

  async function processCapturedImage(runId, imageDataUrl) {
    try {
      const result = await analyzeIdWithBackend(imageDataUrl);
      if (state.idScan.runId !== runId || scanDialog()?.hidden) return;
      applyAnalysisResult(result);
    } catch (error) {
      if (state.idScan.runId !== runId || scanDialog()?.hidden) return;
      try {
        const rawText = await runLocalOcr(imageDataUrl);
        if (state.idScan.runId !== runId || scanDialog()?.hidden) return;
        applyCapturedOcrResult(rawText);
      } catch (_) {
        failCapturedOcr(error.message || 'Could not analyze this ID automatically.');
      }
    }
  }

  async function analyzeIdWithBackend(imageDataUrl) {
    const formData = new FormData();
    formData.append('id_image', dataUrlToBlob(imageDataUrl), 'visitor-id.jpg');
    const headers = new Headers({ Accept: 'application/json' });
    if (window.FAMApi?.csrfToken) headers.set('X-CSRF-Token', window.FAMApi.csrfToken);
    let response;
    try {
      response = await fetch('../api/visitors/analyze-id.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: formData,
      });
    } catch (error) {
      throw new Error('Could not analyze this ID automatically.');
    }
    const text = await response.text();
    let payload = null;
    if (text) {
      try { payload = JSON.parse(text); } catch (_) { payload = null; }
    }
    const token = payload?.csrf_token || payload?.data?.csrf_token;
    if (token && window.FAMApi?.setCsrfToken) window.FAMApi.setCsrfToken(token);
    if (!response.ok || payload?.success === false) {
      throw new Error(payload?.message || 'Could not analyze this ID automatically.');
    }
    return payload?.data?.item || {};
  }

  function dataUrlToBlob(dataUrl) {
    const [meta, value] = String(dataUrl || '').split(',', 2);
    const mime = /^data:([^;]+)/.exec(meta || '')?.[1] || 'image/jpeg';
    const binary = atob(value || '');
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);
    return new Blob([bytes], { type: mime });
  }

  async function runLocalOcr(imageDataUrl) {
    if (!window.Tesseract?.createWorker && !window.Tesseract?.recognize) {
      throw new Error('Local OCR engine is not installed. Enter details manually, or vendor Tesseract.js locally to enable OCR.');
    }
    const options = {
      workerPath: '../assets/vendor/tesseract/worker.min.js',
      corePath: '../assets/vendor/tesseract/tesseract-core.wasm.js',
      langPath: '../assets/vendor/tesseract/tessdata',
      logger: progress => {
        const target = qs('[data-id-ocr-progress]');
        if (!target || !progress?.status || state.idScan.status !== 'PROCESSING') return;
        const percent = Number.isFinite(progress.progress) ? ` ${Math.round(progress.progress * 100)}%` : '';
        target.textContent = `${title(progress.status)}${percent}`;
      }
    };
    if (window.Tesseract?.createWorker) {
      const worker = await getOcrWorker(options);
      const result = await worker.recognize(imageDataUrl);
      return result?.data?.text || '';
    }
    const result = await window.Tesseract.recognize(imageDataUrl, 'eng', options);
    return result?.data?.text || '';
  }

  async function getOcrWorker(options) {
    if (state.idScan.worker) return state.idScan.worker;
    const worker = await window.Tesseract.createWorker('eng', 1, options);
    setScanState({ worker });
    return worker;
  }

  async function releaseOcrWorker() {
    const worker = state.idScan.worker;
    if (!worker?.terminate) return;
    try { await worker.terminate(); } catch (_) {}
    setScanState({ worker: null });
  }

  function applyCapturedOcrResult(rawText) {
    const normalizedText = normalizeOcrText(rawText);
    if (!normalizedText || normalizedText.length < 12) {
      failCapturedOcr('Could not reliably read the captured ID.');
      return;
    }
    const detectedType = detectIdType(normalizedText);
    const detectedName = extractNameCandidate(normalizedText, detectedType);
    const last4 = extractIdLastFour(normalizedText);
    if (!detectedName && !detectedType) {
      failCapturedOcr('Could not identify a visitor name or ID type from the captured ID.');
      return;
    }
    setScanState({ status: 'REVIEW_REQUIRED', rawText: '', detectedType, detectedName, last4, confirmed: false });
    renderReviewResult({ detectedType, detectedName, last4, source: 'Local OCR fallback' });
  }

  function applyAnalysisResult(result) {
    const detectedType = typeof result.id_type === 'string' ? result.id_type : '';
    const detectedName = typeof result.full_name === 'string' ? result.full_name : '';
    const last4 = typeof result.id_last4 === 'string' ? result.id_last4 : '';
    if (!result.document_detected && !detectedName && !detectedType && !last4) {
      failCapturedOcr('Could not analyze this ID automatically.');
      return;
    }
    setScanState({ status: 'REVIEW_REQUIRED', rawText: '', detectedType, detectedName, last4, confirmed: false });
    renderReviewResult({ detectedType, detectedName, last4, needsReview: Boolean(result.needs_review), source: 'AI-assisted extraction', diagnostics: result.diagnostics || null });
  }

  function failCapturedOcr(message) {
    setScanState({ status: 'ERROR', error: message, rawText: '' });
    clearCaptureBuffer();
    renderOcrFailure(message);
  }

  function normalizeOcrText(text) {
    return String(text || '').replace(/[^\S\r\n]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  }

  function detectIdType(text) {
    const value = text.toUpperCase();
    const rules = [
      { type: 'DRIVER_LICENSE', markers: ['DRIVER', "DRIVER'S LICENSE", 'LAND TRANSPORTATION OFFICE', 'LTO'] },
      { type: 'PASSPORT', markers: ['PASSPORT'] },
      { type: 'SCHOOL_ID', markers: ['STUDENT', 'SCHOOL', 'UNIVERSITY', 'COLLEGE'] },
      { type: 'COMPANY_ID', markers: ['EMPLOYEE', 'COMPANY ID', 'IDENTIFICATION CARD'] },
      { type: 'GOVERNMENT_ID', markers: ['REPUBLIC OF THE PHILIPPINES', 'PHILIPPINE IDENTIFICATION', 'PHILSYS', 'PROFESSIONAL REGULATION COMMISSION', 'PRC'] }
    ];
    const available = new Set((state.options.identity_document_types || []).filter(type => type !== 'NONE'));
    const found = rules.find(rule => available.has(rule.type) && rule.markers.some(marker => value.includes(marker)));
    return found?.type || (available.has('OTHER') ? 'OTHER' : '');
  }

  function extractNameCandidate(text, detectedType) {
    const lines = text.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
    const labelPattern = /^(NAME|FULL NAME|SURNAME|GIVEN NAME|MIDDLE NAME|APELYIDO|PANGALAN)\b[:\s-]*/i;
    for (let i = 0; i < lines.length; i += 1) {
      if (!labelPattern.test(lines[i])) continue;
      const sameLine = lines[i].replace(labelPattern, '').trim();
      const candidate = sameLine || lines[i + 1] || '';
      if (isLikelyName(candidate)) return displayName(candidate);
    }
    const blacklist = /(REPUBLIC|PASSPORT|LICENSE|IDENTIFICATION|ADDRESS|BIRTH|DATE|SEX|NATIONALITY|SIGNATURE|VALID|PHILIPPINES|TRANSPORTATION|COMMISSION)/i;
    const candidate = lines.find(line => isLikelyName(line) && !isForbiddenNameLabel(line) && !blacklist.test(line));
    return candidate ? displayName(candidate) : '';
  }

  function isLikelyName(value) {
    const cleaned = String(value || '').replace(/[^A-Za-z .'-]/g, '').trim();
    return cleaned.length >= 5 && cleaned.split(/\s+/).length >= 2 && /^[A-Za-z .'-]+$/.test(cleaned) && !isForbiddenNameLabel(cleaned);
  }

  function isForbiddenNameLabel(value) {
    const normalized = String(value || '').toUpperCase().replace(/[^A-Z]+/g, ' ').replace(/\s+/g, ' ').trim();
    if (!normalized) return false;
    const labels = new Set([
      'LAST NAME',
      'FIRST NAME',
      'MIDDLE NAME',
      'MIDDLE NAMES',
      'LAST NAME FIRST NAME',
      'LAST NAME FIRST NAME MIDDLE NAME',
      'LAST NAME FIRST NAME MIDDLE NAMES',
      'SURNAME',
      'GIVEN NAME',
      'GIVEN NAMES',
      'FULL NAME',
      'NAME',
      'NAME OF HOLDER',
      'CARD HOLDER NAME',
      'HOLDER NAME'
    ]);
    if (labels.has(normalized)) return true;
    const labelTokens = new Set(['LAST', 'FIRST', 'MIDDLE', 'NAMES', 'NAME', 'SURNAME', 'GIVEN', 'FULL', 'HOLDER', 'CARD', 'OF']);
    const tokens = normalized.split(/\s+/).filter(Boolean);
    if (tokens.length < 2) return false;
    const labelCount = tokens.filter(token => labelTokens.has(token)).length;
    return labelCount >= 2 && labelCount / tokens.length >= 0.75;
  }

  function displayName(value) {
    return String(value || '')
      .replace(/[^A-Za-z .'-]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase()
      .replace(/\b[a-z]/g, char => char.toUpperCase());
  }

  function extractIdLastFour(text) {
    const matches = String(text || '').match(/[A-Z0-9-]{6,}/gi) || [];
    const usable = matches.map(item => item.replace(/[^A-Z0-9]/gi, '')).filter(item => /\d/.test(item) && item.length >= 4);
    if (!usable.length) return '';
    return usable[0].slice(-4);
  }

  function renderOcrFailure(message) {
    const dialog = scanDialog();
    if (!dialog) return;
    dialog.innerHTML = `<div class="facility-dialog-panel id-capture-panel">
      <div class="facility-details-modal-header"><div><p>Scan Visitor ID</p><h2 id="id-capture-title">Could not reliably read ID</h2></div><button class="facility-details-modal-close" type="button" data-id-capture-close aria-label="Close ID capture">&times;</button></div>
      <div class="facility-dialog-body id-capture-body">
        <div class="id-capture-empty" role="status">
          <span class="material-symbols-outlined" aria-hidden="true">find_replace</span>
          <h3>Unable to reliably read this ID</h3>
          <p>${esc(message || 'No useful text was found. Retake the ID or enter the details manually.')}</p>
        </div>
      </div>
      <div class="facility-dialog-actions">
        <button class="btn-secondary dashboard-action-button" type="button" data-id-scan-again>Try Again</button>
        <button class="btn-primary dashboard-action-button" type="button" data-id-capture-close>Enter Manually</button>
      </div>
    </div>`;
  }

  function renderCapturedProcessing(imageDataUrl) {
    const dialog = scanDialog();
    if (!dialog) return;
    dialog.innerHTML = `<div class="facility-dialog-panel id-capture-panel">
      <div class="facility-details-modal-header"><div><p>Scan Visitor ID</p><h2 id="id-capture-title">ID captured</h2></div><button class="facility-details-modal-close" type="button" data-id-capture-close aria-label="Close ID capture">&times;</button></div>
      <div class="facility-dialog-body id-capture-body">
        <p class="scanner-dialog-copy">The camera frame is frozen while the system analyzes the captured ID.</p>
        <img class="id-captured-image" alt="Temporary captured visitor ID preview">
        <div class="id-capture-status" role="status" aria-live="polite">
          <span class="material-symbols-outlined id-processing-icon" aria-hidden="true">document_scanner</span>
          <div><strong>${esc(scanStatusLabel.PROCESSING)}</strong><p data-id-ocr-progress>ID captured. Analyzing ID...</p></div>
        </div>
      </div>
      <div class="facility-dialog-actions">
        <button class="btn-secondary dashboard-action-button" type="button" data-id-scan-again>Retake ID</button>
        <button class="btn-secondary dashboard-action-button" type="button" data-id-capture-close>Enter Manually</button>
      </div>
    </div>`;
    dialog.querySelector('.id-captured-image').src = imageDataUrl;
  }

  function renderReviewResult(result) {
    const dialog = scanDialog();
    if (!dialog) return;
    const idTypes = (state.options.identity_document_types || []).filter(type => type !== 'NONE');
    dialog.innerHTML = `<div class="facility-dialog-panel id-capture-panel">
      <div class="facility-details-modal-header"><div><p>Review ID Details</p><h2 id="id-capture-title">Verify extracted details</h2></div><button class="facility-details-modal-close" type="button" data-id-capture-close aria-label="Close ID capture">&times;</button></div>
      <div class="facility-dialog-body id-capture-body">
        <p class="scanner-dialog-copy">Verify the extracted information against the visitor's physical ID before continuing. Every field remains editable.</p>
        ${result.source ? `<p class="scanner-dialog-copy id-review-source">${esc(result.source)}${result.needsReview ? ' - review required' : ''}</p>` : ''}
        <div class="id-review-layout">
          ${state.idScan.imageDataUrl ? `<img class="id-captured-image" src="${esc(state.idScan.imageDataUrl)}" alt="Temporary captured visitor ID preview">` : ''}
          <div class="facility-form-grid">
            <label class="facility-field"><span>Detected ID Type</span><select data-id-review-type>${optionRows(idTypes, 'Select ID type')}</select></label>
            <label class="facility-field"><span>Visitor Name</span><input data-id-review-name value="${esc(result.detectedName || '')}"></label>
            <label class="facility-field"><span>ID Last 4</span><input data-id-review-last4 maxlength="16" value="${esc(result.last4 || '')}" placeholder="Optional"></label>
          </div>
        </div>
        ${renderDiagnosticsPanel(result.diagnostics)}
      </div>
      <div class="facility-dialog-actions">
        <button class="btn-secondary dashboard-action-button" type="button" data-id-scan-again>Retake</button>
        <button class="btn-primary dashboard-action-button" type="button" data-id-use-details>Use Details</button>
      </div>
    </div>`;
    const typeSelect = dialog.querySelector('[data-id-review-type]');
    if (typeSelect && result.detectedType && [...typeSelect.options].some(option => option.value === result.detectedType)) typeSelect.value = result.detectedType;
    dialog.querySelector('[data-id-review-name]')?.focus();
  }

  function renderDiagnosticsPanel(diagnostics) {
    if (!diagnostics || typeof diagnostics !== 'object') return '';
    const quality = diagnostics.quality || {};
    const ai = diagnostics.ai_response || {};
    return `<div class="id-diagnostics-panel" aria-label="ID scan diagnostics">
      <h3>Diagnostics</h3>
      <div class="id-diagnostics-grid">
        <div><span>Capture Quality</span><strong>Sharpness ${esc(quality.sharpness_rating || 'UNKNOWN')} / Brightness ${esc(quality.brightness_rating || 'UNKNOWN')} / Contrast ${esc(quality.contrast_rating || 'UNKNOWN')}</strong></div>
        <div><span>Image</span><strong>${esc(diagnostics.image?.width || 'n/a')} x ${esc(diagnostics.image?.height || 'n/a')} / ${esc(String(diagnostics.image?.sha256 || '').slice(0, 12) || 'no hash')}</strong></div>
        <div><span>Analysis</span><strong>${esc(diagnostics.provider || 'AI')} ${esc(diagnostics.model || '')} / ${esc(diagnostics.failure_stage || diagnostics.stage || 'UNKNOWN')} / ${esc(diagnostics.latency_ms ?? 'n/a')} ms</strong></div>
        <div><span>Name</span><strong>${ai.full_name_present ? 'Detected' : 'Missing'}${ai.full_name_rejected_as_label ? ' / label rejected' : ''}</strong></div>
        <div><span>ID Type</span><strong>${ai.id_type_valid ? 'Detected' : 'Missing'}</strong></div>
        <div><span>Last 4</span><strong>${ai.last4_present ? 'Detected' : 'Missing'}</strong></div>
        <div><span>Needs Review</span><strong>${ai.needs_review ? 'Yes' : 'No'}</strong></div>
      </div>
    </div>`;
  }

  function useReviewedDetails() {
    const dialog = scanDialog();
    const entry = qs('#visitor-entry-form');
    if (!dialog || !entry) return;
    const detectedName = dialog.querySelector('[data-id-review-name]')?.value.trim() || '';
    const detectedType = dialog.querySelector('[data-id-review-type]')?.value || '';
    const last4 = dialog.querySelector('[data-id-review-last4]')?.value.trim() || '';
    if (detectedName) entry.elements.full_name.value = detectedName;
    if (detectedType) entry.elements.identification_type.value = detectedType;
    if (entry.elements.identification_last4) entry.elements.identification_last4.value = last4;
    setScanState({ status: 'CONFIRMED', detectedType, detectedName, last4, confirmed: true, rawText: '' });
    updateConfirmedIndicator(true);
    clearTemporaryImage();
    closeScanDialog({ preserveConfirmed: true });
    verifyActiveVisitAfterScan(entry);
  }

  function selectIssuedBadge(badgeNumber) {
    state.activeBadge = state.issuedBadges.find(item => item.badge_number === badgeNumber) || null;
    renderBadgeSummary(state.activeBadge);
  }

  function renderBadgeSummary(item) {
    const target = qs('#badge-return-summary');
    if (!target) return;
    if (!item) {
      target.hidden = true;
      target.innerHTML = '';
      return;
    }
    target.hidden = false;
    target.innerHTML = `<div class="reception-badge-summary-head"><strong>${esc(item.badge_number)}</strong><span class="facility-badge facility-status-checked-in">Checked In</span></div>
      <h3>${esc(item.visitor_name)}</h3>
      <p>${esc(item.visitor_reference_number)}</p>
      <dl>
        <div><dt>Purpose</dt><dd>${esc(item.purpose || 'Not applicable')}</dd></div>
        <div><dt>Destination</dt><dd>${esc(item.destination || 'Not applicable')}</dd></div>
        <div><dt>Checked In</dt><dd>${esc(fmt(item.checked_in_at))}</dd></div>
      </dl>
      <button class="btn-primary dashboard-action-button" type="button" data-return-badge><span class="material-symbols-outlined" aria-hidden="true">logout</span>Return Badge & Check Out</button>`;
  }

  async function checkoutBadge() {
    if (!state.activeBadge?.badge_number) return;
    const ok = await window.FAMModal.confirm(`Return ${state.activeBadge.badge_number} and check out ${state.activeBadge.visitor_name}?`, { title:'Badge Return Checkout', confirmLabel:'Check Out' });
    if (!ok) return;
    try {
      await window.FAMApi.request('../api/visitors/badge-check-out.php', { method:'POST', body:{ badge_number: state.activeBadge.badge_number, remarks:'Badge returned at Reception Console.' } });
      toast('Badge returned and visitor checked out.');
      state.activeBadge = null;
      const issuedSelect = qs('[data-issued-badge-select]');
      if (issuedSelect) issuedSelect.value = '';
      renderBadgeSummary(null);
      await refreshOptions();
    } catch (error) {
      toast(error.message || 'Unable to check out visitor.');
    }
  }

  function bind() {
    qs('#visitor-entry-form')?.addEventListener('submit', event => { event.preventDefault(); submitEntry(event.target); });
    qs('#visitor-entry-form')?.addEventListener('reset', () => setTimeout(resetScanState, 0));
    qsa('#visitor-entry-form [name="identification_type"], #visitor-entry-form [name="identification_last4"], #visitor-entry-form [name="email_address"], #visitor-entry-form [name="mobile_number"]').forEach(field => {
      field.addEventListener('input', () => clearActiveVisitCheck({ clearBadge: false }));
      field.addEventListener('change', () => clearActiveVisitCheck({ clearBadge: false }));
    });
    qs('[data-scan-id]')?.addEventListener('click', openIdCapture);
    qs('[data-issued-badge-select]')?.addEventListener('change', event => selectIssuedBadge(event.target.value));
    document.addEventListener('click', event => {
      if (event.target.closest('[data-return-badge]')) checkoutBadge();
      if (event.target.closest('[data-id-capture-close]')) closeScanDialog();
      if (event.target.closest('[data-id-capture-trigger]')) captureIdFrame();
      if (event.target.closest('[data-id-scan-again]')) openIdCapture();
      if (event.target.closest('[data-id-use-details]')) useReviewedDetails();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !scanDialog()?.hidden) closeScanDialog();
    });
    const releaseScanResources = () => {
      stopLiveScanning();
      releaseOcrWorker();
    };
    window.addEventListener('beforeunload', releaseScanResources);
    window.addEventListener('pagehide', releaseScanResources);
  }

  document.addEventListener('fam:layout-ready', async () => {
    bind();
    try {
      await window.FAMApi.me();
      await refreshOptions();
    } catch (error) {
      if (error.status === 401) window.location.href = window.FAMApi.pageLoginUrl();
      else toast(error.message || 'Unable to initialize Reception Console.');
    }
  });
})();
