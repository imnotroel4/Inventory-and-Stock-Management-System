// Barcodes: EAN-13, EAN-8, UPC-A, UPC-E, Code 128, Code 39.  QR codes: QR.
document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('prodModal');
  const panel = document.getElementById('qrPanel');
  if (!modalEl || !panel) return;

  const form = modalEl.querySelector('form');
  const reader = document.getElementById('qrReader');
  const msg = document.getElementById('qrMsg');
  const bar = document.getElementById('scanBar');
  const label = document.getElementById('scanLabel');
  const toggleBtn = document.getElementById('scanToggle');
  const closeBtn = document.getElementById('scanClose');
  const fileIn = document.getElementById('qrFile');
  const modeBtns = panel.querySelectorAll('[data-scan-mode]');
  const READER_ID = 'qrReader';
  const MODES = {
    barcode: { name: 'Barcode scanner', hint: 'Hold the barcode flat inside the frame, with good light.' },
    qr:      { name: 'QR code scanner', hint: 'Point the camera at the product’s QR code.' },
  };
  const NEEDS_TWO_READS = ['CODE_128', 'CODE_39'];   // these have no check digit, so the same text must be read twice in a row

  let scanner = null, scannerMode = null;            
  let mode = 'barcode';                              
  let state = 'closed';                              
  let busy = false, token = 0;                       
  let seen = { text: '', at: 0 }, lastBad = { text: '', at: 0 };

  // ----- messages (always textContent: nothing from a scanned code is ever written as HTML) -----
  function say(type, text, link) {
    msg.className = 'alert alert-' + type + ' py-2 px-3 small mb-0 mt-2';
    msg.textContent = text;
    if (link) { msg.append(' '); const a = document.createElement('a'); a.href = link.href; a.textContent = link.label; a.className = 'alert-link'; msg.append(a); }
    msg.classList.remove('d-none');
  }
  function clearMsg() { msg.classList.add('d-none'); msg.textContent = ''; }

  // ----- what is visible: mode buttons, the Stop/Start + Close row, and the camera view -----
  function render() {
    const open = state !== 'closed';
    bar.classList.toggle('d-none', !open);
    reader.classList.toggle('d-none', state !== 'scanning');
    label.textContent = open ? MODES[mode].name : '';
    toggleBtn.innerHTML = state === 'scanning' ? '<i class="bi bi-stop-circle"></i> Stop scanning' : '<i class="bi bi-camera-video"></i> Start scanning';
    toggleBtn.classList.toggle('text-danger', state === 'scanning');
    modeBtns.forEach(b => {
      const on = open && b.dataset.scanMode === mode;
      b.classList.toggle('btn-primary', on); b.classList.toggle('btn-light', !on);
    });
  }

  // ----- the library instance: formats are chosen when it is created, so switching mode creates a new one -----
  async function dropScanner() {                     // forget the instance (and anything it left in the view)
    const old = scanner; scanner = null; scannerMode = null;
    if (old) { try { await old.clear(); } catch (e) { /* nothing to clear */ } }
    reader.innerHTML = '';
  }
  async function getScanner(m, fresh = false) {
    if (scanner && (fresh || scannerMode !== m)) await dropScanner();
    if (!scanner) {
      const F = Html5QrcodeSupportedFormats;
      const formats = m === 'barcode' ? [F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_128, F.CODE_39] : [F.QR_CODE];
      scanner = new Html5Qrcode(READER_ID, { formatsToSupport: formats, verbose: false, experimentalFeatures: { useBarCodeDetectorIfSupported: true } });
      scannerMode = m;
    }
    return scanner;
  }

  // ----- camera control -----
  async function halt() {                            // turns the camera off (state is set by the caller)
    token++;
    const st = scanner && scanner.getState ? scanner.getState() : 0;   // 2 = scanning, 3 = paused
    if (st === 2 || st === 3) { try { await scanner.stop(); } catch (e) { await dropScanner(); } }   // a failed stop leaves a dirty instance: start over next time
  }
  async function stop(keepOpen = true) {
    await halt();
    state = keepOpen && state !== 'closed' ? 'paused' : 'closed';
    render();
  }
  async function closeScanner() { await stop(false); clearMsg(); }

  function cameraError(err) {
    const n = (err && (err.name || '')) + ' ' + (err && err.message ? err.message : err);
    if (/NotAllowed|Permission|denied/i.test(n)) return 'Camera permission was denied. Allow camera access for this site in your browser settings, then try again, or enter the product manually.';
    if (/NotFound|Devices|no camera/i.test(n)) return 'No camera was found on this device. You can scan from a picture or enter the product manually.';
    if (/NotReadable|TrackStart|in use/i.test(n)) return 'The camera is busy in another app or tab. Close it and try again.';
    return 'The camera could not be started. You can enter the product manually.';
  }

  async function start() {
    if (state === 'scanning' || busy) return;
    if (typeof Html5Qrcode === 'undefined') { say('warning', 'The scanner could not load. Reload the page, or enter the product manually.'); return; }
    if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      say('warning', 'Camera access needs a secure connection (HTTPS or localhost). Open this site over HTTPS, scan from a picture, or enter the product manually.');
      state = 'paused'; render(); return;
    }
    clearMsg(); seen = { text: '', at: 0 }; lastBad = { text: '', at: 0 };
    state = 'scanning'; render();                   // the view must be visible before the camera starts
    const my = ++token;
    try {
      const s = await getScanner(mode);
      const box = mode === 'barcode'
        ? (w, h) => ({ width: Math.max(60, Math.floor(w * 0.9)), height: Math.floor(Math.max(90, Math.min(h * 0.55, w * 0.4))) })   // wide and flat, like a barcode
        : (w, h) => { const q = Math.max(60, Math.floor(Math.min(w, h) * 0.75)); return { width: q, height: q }; };   // the library rejects frames under 50px (e.g. while the view is hidden)
      await s.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: box, videoConstraints: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } } },
        (text, result) => onDecoded(text, result, true),
        () => { /* no code in this frame: keep looking */ });
      if (my !== token) { try { await s.stop(); } catch (e) { /* closed while starting */ } return; }
      say('info', MODES[mode].hint);
    } catch (err) {
      if (my !== token) return;
      state = 'paused'; render(); say('danger', cameraError(err));
    }
  }

  // ----- handling a decoded code -----
  async function onDecoded(text, result, live) {
    if (busy) return;
    const fmt = (result && result.result && result.result.format && result.result.format.formatName) || '';
    const now = Date.now();
    if (live && mode === 'barcode' && NEEDS_TWO_READS.includes(fmt)) {   // guards against half-read Code 128 / Code 39
      const again = seen.text === text && now - seen.at < 2500;
      seen = { text, at: now };
      if (!again) return;
    }
    if (live && lastBad.text === text && now - lastBad.at < 2500) return; // the same unreadable code in view: do not nag
    busy = true; const my = token;
    say('info', 'Looking up the product…');
    try {
      const body = new FormData();
      body.append('csrf', form.elements['csrf'].value);
      body.append('code', text);
      if (mode === 'barcode') { body.append('type', 'barcode'); body.append('format', fmt); }
      const res = await fetch('product_scan.php', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!(res.headers.get('content-type') || '').includes('application/json')) throw new Error('session');
      const r = await res.json();
      if (my !== token) return;                                          // closed or stopped meanwhile: drop the answer
      if (r.status === 'invalid' && live) { lastBad = { text, at: Date.now() }; say('warning', r.message); return; }   // keep the camera on
      await stop();
      handle(r);
    } catch (e) {
      if (my !== token) return;
      await stop();
      say('danger', e.message === 'session' ? 'Your session may have expired. Reload the page and sign in again.' : 'The lookup failed. You can still enter the product manually.');
    } finally { busy = false; }
  }

  function handle(r) {
    if (r.status === 'duplicate') {
      const sku = r.product ? r.product.sku : '';
      say('warning', r.message, sku ? { href: 'products.php?q=' + encodeURIComponent(sku), label: 'View it in the list' } : null);
      return;
    }
    if (['found', 'parsed', 'online', 'code_only'].includes(r.status)) {
      fill(r.fields || {});
      setScanFormat(r.barcode && r.barcode.format ? r.barcode.format : '');
      say(r.status === 'code_only' || r.similar ? 'warning' : 'success', r.message,
          r.similar ? { href: 'products.php?q=' + encodeURIComponent(r.similar.sku), label: 'View it' } : null);
      return;
    }
    say('danger', r.message || 'This code could not be used.');
  }

  function fill(fields) {
    form.querySelectorAll('.qr-filled').forEach(el => el.classList.remove('qr-filled'));
    Object.entries(fields).forEach(([name, val]) => {
      const el = form.elements[name];
      if (!el || el.type === 'file' || el.type === 'hidden') return;
      if (el.type === 'checkbox') el.checked = !!val; else el.value = val;
      el.classList.add('qr-filled');
      el.addEventListener('input', () => el.classList.remove('qr-filled'), { once: true });
      el.addEventListener('change', () => el.classList.remove('qr-filled'), { once: true });
    });
    if (form.elements['name'] && !form.elements['name'].value) form.elements['name'].focus();
    else form.elements['sku'] && form.elements['sku'].focus();
  }

  // The barcode format travels with the form so the server can re-check the SKU when the product is saved.
  // If the user types a different SKU, it is no longer "the scanned barcode", so the flag is dropped.
  function setScanFormat(v) { if (form.elements['scan_format']) form.elements['scan_format'].value = v; }
  form.elements['sku'] && form.elements['sku'].addEventListener('input', () => setScanFormat(''));

  // ----- scan from a picture (desktop without a webcam, or camera blocked) -----
  fileIn.addEventListener('change', async () => {
    const file = fileIn.files[0]; fileIn.value = '';
    if (!file || busy) return;
    if (typeof Html5Qrcode === 'undefined') { say('warning', 'The scanner could not load. Reload the page, or enter the product manually.'); return; }
    await stop(false);
    const first = mode;                                                  // try the scanner that was last used, then the other one
    for (const m of [first, first === 'barcode' ? 'qr' : 'barcode']) {
      try {
        mode = m;
        const s = await getScanner(m, true);                             // a fresh instance: nothing left over from the camera
        const found = s.scanFileV2 ? await s.scanFileV2(file, false) : { decodedText: await s.scanFile(file, false), result: {} };
        await onDecoded(found.decodedText, found, false);
        return;
      } catch (e) { /* nothing readable with this scanner: try the next */ }
    }
    mode = first;
    say('danger', 'No barcode or QR code could be read from that picture. Try a clearer, closer photo or enter the product manually.');
  });

  // Closing the scanner a moment after the camera started makes the browser reject the video's pending play() call.
  // That is harmless (the camera is stopped either way), so this one message is kept out of the error log.
  addEventListener('unhandledrejection', ev => { if (/play\(\) request was interrupted/i.test(String(ev.reason && ev.reason.message || ev.reason))) ev.preventDefault(); });

  // ----- wiring -----
  async function openMode(m) {
    if (state === 'scanning' && mode === m) return;
    if (state === 'scanning') { await halt(); state = 'paused'; }     // switching between barcode and QR
    mode = m; await start();
  }
  modeBtns.forEach(b => b.addEventListener('click', () => openMode(b.dataset.scanMode)));
  toggleBtn.addEventListener('click', async () => {
    if (state === 'scanning') { await stop(); say('secondary', 'Scanner stopped. Press Start scanning to continue, or enter the product manually.'); }
    else start();
  });
  closeBtn.addEventListener('click', closeScanner);

  // Opening or closing the form resets the "scanned" marker; closing it also turns the camera off.
  modalEl.addEventListener('show.bs.modal', () => { setScanFormat(''); form.querySelectorAll('.qr-filled').forEach(el => el.classList.remove('qr-filled')); });
  modalEl.addEventListener('hidden.bs.modal', () => { closeScanner(); setScanFormat(''); form.querySelectorAll('.qr-filled').forEach(el => el.classList.remove('qr-filled')); });
  // The camera must never stay on when the tab is hidden or the page is left.
  document.addEventListener('visibilitychange', () => { if (document.hidden && state === 'scanning') { stop(); say('secondary', 'Scanner paused because the page was hidden. Press Start scanning to continue.'); } });
  addEventListener('pagehide', () => { halt(); });

  render();
});
