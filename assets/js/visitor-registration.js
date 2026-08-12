(function () {
  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => Array.from(document.querySelectorAll(selector));
  const form = $('#visitorForm');
  const status = $('#formStatus');
  const stepDots = $$('.stepper li');
  const steps = $$('.wizard-step');
  let currentStep = 1;
  let challengeId = null;
  let verificationToken = null;
  let verifiedEmail = null;
  let resendAt = 0;
  let timer = null;
  let optionsCache = {};

  const stepTitles = ['Personal Information', 'Visit Information', 'Email Verification', 'Review and Consent', 'Confirmation'];
  const typeLabels = { APPLICANT: 'Applicant', GUEST: 'Guest', VENDOR: 'Vendor', CONTRACTOR: 'Contractor', DELIVERY: 'Delivery', OTHER: 'Other' };

  function appBasePath() {
    const marker = '/pages/';
    const index = window.location.pathname.indexOf(marker);
    return index >= 0 ? window.location.pathname.slice(0, index + 1) : '/FacilitiesAdministrativeManagement/';
  }

  async function api(path, body) {
    const response = await fetch(`${appBasePath()}api/public/visitors/${path}`, {
      method: body ? 'POST' : 'GET',
      credentials: 'same-origin',
      headers: body ? { 'Content-Type': 'application/json', 'Accept': 'application/json' } : { 'Accept': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || payload?.success === false) {
      const error = new Error(payload?.message || 'Request failed.');
      error.errors = payload?.data?.errors || {};
      throw error;
    }
    return payload.data || {};
  }

  function message(text, type) {
    status.textContent = text || '';
    status.className = `status-message ${type || ''}`;
  }

  function setStep(step) {
    currentStep = step;
    steps.forEach((panel) => panel.classList.toggle('hidden', Number(panel.dataset.step) !== step));
    form.classList.toggle('hidden', step === 5);
    $('#confirmStep').classList.toggle('hidden', step !== 5);
    document.querySelector('.stepper').classList.toggle('final-complete', step === 5);
    stepDots.forEach((dot, index) => {
      dot.classList.toggle('active', index === step - 1);
      dot.classList.toggle('complete', step === 5 || index < step - 1);
    });
    $('#mobileStepCount').textContent = `Step ${step} of 5`;
    $('#mobileStepTitle').textContent = stepTitles[step - 1];
    $('#progressBar').style.width = `${(step / 5) * 100}%`;
    message('');
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (step === 5) requestAnimationFrame(() => $('#confirmTitle').focus({ preventScroll: true }));
  }

  function values() {
    syncSchedule();
    $('#companyOrSchool').value = $('#organizationName').value;
    return Object.fromEntries(new FormData(form).entries());
  }

  function fieldValue(selector) {
    const field = $(selector);
    if (!field) return '';
    if (field.tagName === 'SELECT') return field.selectedOptions[0]?.textContent || '';
    return field.value.trim();
  }

  function validateStep(step) {
    const checks = step === 1
      ? [['#fullName', 'Enter your full name.'], ['#emailAddress', 'Enter a valid email address.'], ['#visitorType', 'Select a visitor type.']]
      : [['#destinationDepartment', 'Select a destination.'], ['#visitPurpose', 'Enter the purpose of your visit.']];
    for (const [selector, text] of checks) {
      const field = $(selector);
      field.setCustomValidity('');
      const valid = selector === '#emailAddress' ? field.checkValidity() : field.value.trim() !== '';
      if (!valid) {
        field.setCustomValidity(text);
        field.reportValidity();
        field.addEventListener('input', () => field.setCustomValidity(''), { once: true });
        field.addEventListener('change', () => field.setCustomValidity(''), { once: true });
        field.focus();
        return false;
      }
    }
    if (step === 2 && !$('#visitingNow').checked && $('#visitDate').value && $('#arrivalTime').value && $('#endTime').value && $('#endTime').value <= $('#arrivalTime').value) {
      $('#endTime').setCustomValidity('Expected end time must be after arrival time.');
      $('#endTime').reportValidity();
      $('#endTime').addEventListener('input', () => $('#endTime').setCustomValidity(''), { once: true });
      $('#endTime').focus();
      return false;
    }
    return true;
  }

  function syncSchedule() {
    if ($('#visitingNow').checked || !$('#visitDate').value || !$('#arrivalTime').value) {
      $('#scheduledStartAt').value = '';
      $('#scheduledEndAt').value = '';
      return;
    }
    $('#scheduledStartAt').value = `${$('#visitDate').value}T${$('#arrivalTime').value}`;
    $('#scheduledEndAt').value = $('#endTime').value ? `${$('#visitDate').value}T${$('#endTime').value}` : '';
  }

  function setResend(seconds) {
    resendAt = Date.now() + seconds * 1000;
    clearInterval(timer);
    timer = setInterval(updateResend, 250);
    updateResend();
  }

  function updateResend() {
    const left = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
    $('#resendOtp').classList.toggle('is-disabled', left > 0);
    $('#resendOtp').setAttribute('aria-disabled', left > 0 ? 'true' : 'false');
    $('#resendHint').textContent = left > 0 ? `You can resend a code in ${left} seconds.` : 'You can request a new code if it has not arrived.';
    if (left === 0) clearInterval(timer);
  }

  function updateOtpBoxes() {
    $('#otpCode').value = $$('.otp-digit').map((input) => input.value.replace(/\D/g, '')).join('').slice(0, 6);
  }

  function setOtpDigits(value) {
    const digits = value.replace(/\D/g, '').slice(0, 6);
    $$('.otp-digit').forEach((input, index) => { input.value = digits[index] || ''; });
    updateOtpBoxes();
  }

  function fillSelect(select, rows, label) {
    rows.forEach((row) => {
      const option = document.createElement('option');
      option.value = row.id;
      option.textContent = label(row);
      select.appendChild(option);
    });
  }

  function toggleApplicant() {
    const isApplicant = $('#visitorType').value === 'APPLICANT';
    $('.applicant-field').classList.toggle('hidden', !isApplicant);
    $('#organizationLabel').textContent = isApplicant ? 'School / Organization' : 'Organization / Company / School';
  }

  function invalidateEmailIfChanged() {
    const email = $('#emailAddress').value.trim().toLowerCase();
    if (verifiedEmail && email !== verifiedEmail) {
      challengeId = null;
      verificationToken = null;
      verifiedEmail = null;
      setOtpDigits('');
    }
  }

  function reviewRow(term, value) {
    const shown = value || 'Not provided';
    const emptyClass = value ? '' : ' class="is-empty"';
    return `<div><dt>${term}</dt><dd${emptyClass}>${shown}</dd></div>`;
  }

  function buildReview() {
    const type = typeLabels[$('#visitorType').value] || $('#visitorType').value;
    $('#personalReview').innerHTML = [
      reviewRow('Full Name', fieldValue('#fullName')),
      reviewRow('Email', fieldValue('#emailAddress')),
      reviewRow('Mobile', fieldValue('#mobileNumber')),
      reviewRow('Visitor Type', type),
      reviewRow('Organization / School', fieldValue('#organizationName'))
    ].join('');
    const schedule = $('#visitingNow').checked ? 'Visiting now' : [$('#visitDate').value, $('#arrivalTime').value, $('#endTime').value ? `to ${$('#endTime').value}` : ''].filter(Boolean).join(' ');
    $('#visitReview').innerHTML = [
      reviewRow('Destination', fieldValue('#destinationDepartment')),
      reviewRow('Host', fieldValue('#hostContact')),
      reviewRow('Purpose', fieldValue('#visitPurpose')),
      reviewRow('Visit Date / Time', schedule),
      $('#visitorType').value === 'APPLICANT' ? reviewRow('Applicant Reference', fieldValue('#applicantReference')) : '',
      reviewRow('Facility Space', fieldValue('#facilitySpace')),
      reviewRow('Additional Details', fieldValue('#visitDescription'))
    ].join('');
  }

  function renderQr(submitted) {
    const image = $('#visitorQrImage');
    const fallback = $('#qrFallback');
    image.hidden = true;
    fallback.hidden = true;
    image.removeAttribute('src');

    if (!submitted.qr_token) {
      fallback.hidden = false;
      return;
    }

    const reference = submitted.visitor_reference_number || 'this registration';
    image.alt = `Visitor pass QR code for ${reference}`;
    image.onload = () => {
      image.hidden = false;
      fallback.hidden = true;
    };
    image.onerror = () => {
      image.hidden = true;
      fallback.hidden = false;
    };
    image.src = `${appBasePath()}api/public/visitors/qr-svg.php?token=${encodeURIComponent(submitted.qr_token)}`;
  }

  async function requestOtp() {
    if (!validateStep(2)) return;
    invalidateEmailIfChanged();
    if (verificationToken && verifiedEmail === $('#emailAddress').value.trim().toLowerCase()) {
      buildReview();
      setStep(4);
      return;
    }
    message('Sending verification code...', '');
    try {
      const data = values();
      data.privacy_consent = true;
      const result = await api('start.php', data);
      challengeId = result.challenge_id;
      verifiedEmail = null;
      verificationToken = null;
      $('#maskedEmail').textContent = result.masked_email || 'your email address';
      setResend(result.resend_available_in_seconds || 60);
      setStep(3);
      setTimeout(() => $$('.otp-digit')[0].focus(), 100);
    } catch (error) {
      message(error.message, 'error');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (currentStep !== 4) return;
    const consented = form.elements.privacy_consent.checked;
    $('#consentError').hidden = consented;
    if (!consented) {
      form.elements.privacy_consent.focus();
      return;
    }
    message('Submitting registration...', '');
    try {
      const submitted = await api('submit.php', { challenge_id: challengeId, verification_token: verificationToken });
      $('#refNumber').textContent = submitted.visitor_reference_number;
      $('#refName').textContent = submitted.visitor_name;
      $('#refDestination').textContent = submitted.destination || 'Reception / Security Desk';
      $('#refSchedule').textContent = submitted.scheduled_start_at ? `${submitted.scheduled_start_at}${submitted.scheduled_end_at ? ' to ' + submitted.scheduled_end_at : ''}` : 'Visiting now';
      renderQr(submitted);
      setStep(5);
    } catch (error) {
      message(error.message, 'error');
    }
  });

  $$('[data-next]').forEach((button) => button.addEventListener('click', () => {
    invalidateEmailIfChanged();
    if (validateStep(1)) setStep(2);
  }));
  $$('[data-back]').forEach((button) => button.addEventListener('click', () => setStep(Number(button.dataset.back) - 1)));
  $$('[data-edit]').forEach((link) => link.addEventListener('click', (event) => {
    event.preventDefault();
    setStep(Number(link.dataset.edit));
  }));
  $('#requestOtp').addEventListener('click', requestOtp);
  $('#verifyOtp').addEventListener('click', async () => {
    message('Verifying code...', '');
    try {
      const result = await api('verify-otp.php', { challenge_id: challengeId, otp: $('#otpCode').value.trim() });
      verificationToken = result.verification_token;
      verifiedEmail = $('#emailAddress').value.trim().toLowerCase();
      buildReview();
      setStep(4);
    } catch (error) {
      message(error.message, 'error');
      $$('.otp-digit')[0].focus();
    }
  });
  $('#resendOtp').addEventListener('click', async (event) => {
    event.preventDefault();
    if ($('#resendOtp').classList.contains('is-disabled')) return;
    message('Requesting another code...', '');
    try {
      const result = await api('resend-otp.php', { challenge_id: challengeId });
      setResend(result.resend_available_in_seconds || 60);
      message('A new verification code was sent.', 'success');
    } catch (error) {
      message(error.message, 'error');
    }
  });
  $('#changeEmail').addEventListener('click', (event) => {
    event.preventDefault();
    setStep(1);
  });
  $$('.otp-digit').forEach((input, index, inputs) => {
    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D/g, '').slice(-1);
      updateOtpBoxes();
      if (input.value && inputs[index + 1]) inputs[index + 1].focus();
    });
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Backspace' && !input.value && inputs[index - 1]) inputs[index - 1].focus();
    });
    input.addEventListener('paste', (event) => {
      event.preventDefault();
      setOtpDigits(event.clipboardData.getData('text'));
      const nextEmpty = inputs.find((item) => item.value === '');
      (nextEmpty || inputs[inputs.length - 1]).focus();
    });
  });
  $('#visitorType').addEventListener('change', toggleApplicant);
  $('#visitingNow').addEventListener('change', () => $('#scheduleFields').classList.toggle('hidden', $('#visitingNow').checked));
  $('#emailAddress').addEventListener('input', invalidateEmailIfChanged);
  form.elements.privacy_consent.addEventListener('change', () => { $('#consentError').hidden = form.elements.privacy_consent.checked; });
  $('#copyReference').addEventListener('click', async () => {
    const reference = $('#refNumber').textContent.trim();
    try {
      await navigator.clipboard.writeText(reference);
      $('#copyStatus').textContent = 'Copied';
    } catch {
      $('#copyStatus').textContent = 'Copy unavailable';
    }
    setTimeout(() => { $('#copyStatus').textContent = ''; }, 1800);
  });
  $('#printConfirmation').addEventListener('click', () => window.print());
  $('#startAnother').addEventListener('click', (event) => {
    event.preventDefault();
    window.location.reload();
  });
  $('#finishRegistration').addEventListener('click', () => window.location.href = 'visitor-registration.html');
  window.addEventListener('beforeunload', (event) => {
    if (currentStep > 1 && currentStep < 5) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  async function init() {
    $('#formStartedAt').value = Math.floor(Date.now() / 1000);
    $('#currentYear').textContent = new Date().getFullYear();
    optionsCache = await api('options.php');
    optionsCache.visitor_types.forEach((type) => {
      const option = document.createElement('option');
      option.value = type;
      option.textContent = typeLabels[type] || type;
      $('#visitorType').appendChild(option);
    });
    fillSelect($('#destinationDepartment'), optionsCache.departments || [], (row) => row.name);
    if ((optionsCache.facility_spaces || []).length > 0) {
      fillSelect($('#facilitySpace'), optionsCache.facility_spaces, (row) => row.name);
    } else {
      $('#facilitySpaceWrap').classList.add('hidden');
    }
    $('#scheduleFields').classList.add('hidden');
    toggleApplicant();
    setStep(1);
  }

  init().catch((error) => message(error.message, 'error'));
})();
