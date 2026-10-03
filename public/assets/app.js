// Reservation page client. The server (api.php) is the source of truth; this file
// only renders its state and forwards user actions. Texts shown to users are Czech.
'use strict';

(() => {
  const POLL_MS = 3000;

  const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
  const el = {
    emailForm: document.getElementById('email-form'),
    email: document.getElementById('email'),
    message: document.getElementById('message'),
    picker: document.getElementById('picker'),
    seats: Array.from(document.querySelectorAll('.seat')),
    summaryEmail: document.getElementById('summary-email'),
    summarySeats: document.getElementById('summary-seats'),
    summaryCount: document.getElementById('summary-count'),
    summaryPrice: document.getElementById('summary-price'),
    standingCount: document.getElementById('standing-count'),
    stepperButtons: Array.from(document.querySelectorAll('.stepper-button')),
    countdown: document.getElementById('countdown'),
    confirmForm: document.getElementById('confirm-form'),
    confirmButton: document.getElementById('confirm-button'),
    cancel: document.getElementById('cancel'),
  };

  let state = null;
  let busy = false;
  let expiresAt = null;
  let countdownTimer = null;

  const priceFormat = new Intl.NumberFormat('cs-CZ', { style: 'currency', currency: 'CZK', maximumFractionDigits: 0 });

  // --- API -------------------------------------------------------------------

  async function api(action, body) {
    const options = body === undefined
      ? { method: 'GET' }
      : {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
          body: JSON.stringify(body),
        };
    let data;
    try {
      const response = await fetch(`api.php?action=${action}`, { credentials: 'same-origin', ...options });
      data = await response.json();
    } catch (err) {
      return { ok: false, error: 'Nepodařilo se spojit se serverem. Zkontrolujte připojení.' };
    }
    if (data.state) {
      render(data.state);
    }
    return data;
  }

  async function perform(action, body, successMessage) {
    if (busy) {
      return null;
    }
    busy = true;
    document.body.classList.add('is-busy');
    try {
      const result = await api(action, body);
      showMessage(result.ok ? (successMessage || '') : result.error, !result.ok);
      return result;
    } finally {
      busy = false;
      document.body.classList.remove('is-busy');
    }
  }

  async function refresh() {
    if (!busy && document.visibilityState === 'visible') {
      await api('state');
    }
  }

  // --- Rendering ----------------------------------------------------------------

  function showMessage(text, isError) {
    el.message.textContent = text;
    el.message.classList.toggle('message--error', Boolean(isError));
  }

  function render(newState) {
    state = newState;
    if (!state.sale_open) {
      window.location.reload();
      return;
    }

    const reservation = state.reservation;
    const taken = new Set(state.taken);
    const mine = new Set(state.mine);

    el.picker.hidden = !reservation;
    el.emailForm.hidden = Boolean(reservation);

    for (const seat of el.seats) {
      const id = Number(seat.dataset.seat);
      const isMine = mine.has(id);
      const isTaken = taken.has(id);
      seat.classList.toggle('seat--mine', isMine);
      seat.classList.toggle('seat--taken', isTaken);
      seat.setAttribute('aria-pressed', String(isMine));
      seat.disabled = !reservation || isTaken;
    }

    if (!reservation) {
      stopCountdown();
      return;
    }

    const seatCount = reservation.seats.length;
    const total = seatCount + reservation.standing;
    el.summaryEmail.textContent = reservation.email;
    el.summarySeats.textContent = seatCount
      ? reservation.seats.map((s) => s.label.replace('/', '–')).join(', ')
      : '–';
    el.standingCount.textContent = String(reservation.standing);
    el.summaryCount.textContent = String(total);
    el.summaryPrice.textContent = priceFormat.format(
      seatCount * state.prices.seat + reservation.standing * state.prices.standing
    );
    el.confirmButton.disabled = total === 0;

    const atLimit = total >= state.limits.max_tickets;
    el.stepperButtons.forEach((button) => {
      const step = Number(button.dataset.step);
      button.disabled = step < 0
        ? reservation.standing === 0
        : atLimit || state.limits.standing_left <= reservation.standing;
    });

    if (reservation.expires_in === null) {
      stopCountdown();
    } else {
      startCountdown(reservation.expires_in);
    }
  }

  function startCountdown(seconds) {
    expiresAt = Date.now() + seconds * 1000;
    el.countdown.hidden = false;
    tick();
    if (!countdownTimer) {
      countdownTimer = setInterval(tick, 1000);
    }
  }

  function stopCountdown() {
    clearInterval(countdownTimer);
    countdownTimer = null;
    expiresAt = null;
    el.countdown.hidden = true;
  }

  function tick() {
    const left = Math.max(0, Math.round((expiresAt - Date.now()) / 1000));
    const minutes = Math.floor(left / 60);
    const seconds = String(left % 60).padStart(2, '0');
    el.countdown.textContent = `Vybraná místa držíme ještě ${minutes}:${seconds}. Každá změna čas obnoví.`;
    el.countdown.classList.toggle('countdown--urgent', left <= 30);
    if (left === 0) {
      stopCountdown();
      showMessage('Čas na rezervaci vypršel, vybraná místa byla uvolněna.', true);
      refresh();
    }
  }

  // --- Events ---------------------------------------------------------------------

  el.emailForm.addEventListener('submit', (event) => {
    event.preventDefault();
    const email = el.email.value.trim();
    if (!el.email.checkValidity() || email === '') {
      showMessage('Zadejte platný e-mail.', true);
      el.email.focus();
      return;
    }
    perform('start', { email }, 'E-mail ověřen. Vyberte místa nebo lístky bez místenky.');
  });

  el.seats.forEach((seat) => {
    seat.addEventListener('click', () => {
      const seatId = Number(seat.dataset.seat);
      const isMine = seat.classList.contains('seat--mine');
      perform(isMine ? 'release' : 'hold', { seat_id: seatId });
    });
  });

  el.stepperButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const count = state.reservation.standing + Number(button.dataset.step);
      perform('standing', { count });
    });
  });

  el.cancel.addEventListener('click', async () => {
    const result = await perform('cancel', {}, 'Rezervace byla zrušena, můžete zadat jiný e-mail.');
    if (result && result.ok) {
      el.email.focus();
    }
  });

  el.confirmForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = new FormData(el.confirmForm);
    const result = await perform('confirm', {
      name: String(form.get('name') || ''),
      phone: String(form.get('phone') || ''),
      consent: form.get('consent') === 'on',
    });
    if (result && result.ok && result.redirect) {
      window.location.href = result.redirect;
    }
  });

  document.addEventListener('visibilitychange', refresh);

  refresh();
  setInterval(refresh, POLL_MS);
})();
