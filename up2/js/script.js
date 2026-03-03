const API_CREATE = "../checkout/api/create-payment.php";
const API_CHECK = "../checkout/api/check-payment.php";
const SESSION_KEY = "up2CheckoutSessionId";
const POLL_INTERVAL_MS = 5000;
const POLL_MAX_MS = 10 * 60 * 1000;
const DEFAULT_NEXT_URL = "../up3/index.html";
const UP2_AMOUNT_CENTS = 3582;

const params = new URLSearchParams(window.location.search);
const nome = (params.get("nome") || "Cliente").trim();
const cpf = (params.get("cpf") || "").replace(/\D/g, "");
const pixKey = (params.get("chave_pix") || params.get("cpf") || cpf).trim();
const nextUrl = (params.get("next_up2") || params.get("next") || DEFAULT_NEXT_URL).trim();
const leadPhone = (params.get("phone") || params.get("telefone") || "").replace(/\D/g, "");

let sessionId = localStorage.getItem(SESSION_KEY) || "";
let pollTimer = null;
let pollStartedAt = 0;
let expiresAtRaw = null;

function setStep(step) {
  document.getElementById("chip1").classList.toggle("active", step === 1);
  document.getElementById("chip2").classList.toggle("active", step === 2);
  document.getElementById("chip3").classList.toggle("active", step === 3);
  document.getElementById("step1").classList.toggle("hidden", step !== 1);
  document.getElementById("step2").classList.toggle("hidden", step !== 2);
  document.getElementById("step3").classList.toggle("hidden", step !== 3);
}

function showStatus(id, text, type = "info") {
  const el = document.getElementById(id);
  el.classList.remove("hidden", "ok", "error");
  if (type === "ok") el.classList.add("ok");
  if (type === "error") el.classList.add("error");
  el.textContent = text;
}

function buildCustomerContact() {
  const normalizedCPF = cpf || "cliente";
  const email = `${normalizedCPF}@email.com`.replace(/[^\w@.\-]/g, "");
  const phone = leadPhone.length >= 10 ? leadPhone : "11999999999";
  return { email, phone };
}

function renderQR(pixCode) {
  const qrEl = document.getElementById("qrcode");
  qrEl.innerHTML = "";
  new QRCode(qrEl, {
    text: pixCode,
    width: 220,
    height: 220,
    correctLevel: QRCode.CorrectLevel.M
  });
}

async function createPixPayment() {
  const button = document.getElementById("paymentButton");
  const loading = document.getElementById("loadingMessage");
  button.disabled = true;
  loading.style.display = "block";

  try {
    const { email, phone } = buildCustomerContact();
    var storedUtms = typeof getStoredUtms === "function" ? getStoredUtms() : {};
    var fbpVal = typeof getFbp === "function" ? getFbp() : null;
    if (fbpVal) storedUtms.fbp = fbpVal;

    const payload = {
      session_id: sessionId || undefined,
      name: nome,
      document: cpf,
      phone,
      email,
      amount_cents: UP2_AMOUNT_CENTS,
      pix_key: pixKey,
      flow: "up2",
      tracking_parameters: storedUtms,
      source_url: window.location.href
    };

    const res = await fetch(API_CREATE, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || "Falha ao gerar PIX.");

    sessionId = data.session_id;
    localStorage.setItem(SESSION_KEY, sessionId);
    expiresAtRaw = data.expires_at || null;

    if (typeof fbTrack === "function") {
      fbTrack("InitiateCheckout", {
        currency: "BRL",
        value: UP2_AMOUNT_CENTS / 100,
        content_name: "PIX up2",
        content_ids: [data.payment_code]
      }, { browserOnly: true });
    }

    renderQR(data.pix_qrcode_text);
    document.getElementById("pix-copy-code").textContent = data.pix_qrcode_text;
    setStep(2);
    showStatus("step2Status", "PIX gerado com sucesso. Realize o pagamento para continuar.", "ok");
    startPolling();
  } catch (err) {
    showStatus("step1Status", err.message || "Erro ao gerar pagamento.", "error");
  } finally {
    button.disabled = false;
    loading.style.display = "none";
  }
}

async function checkPayment() {
  if (!sessionId) return null;
  const res = await fetch(`${API_CHECK}?session_id=${encodeURIComponent(sessionId)}`, {
    method: "GET",
    headers: { "Accept": "application/json" }
  });
  const data = await res.json();
  if (!res.ok || !data.ok) throw new Error(data.error || "Falha ao consultar status");
  return data;
}

async function verifyNow() {
  try {
    showStatus("step2Status", "Verificando pagamento...", "info");
    const status = await checkPayment();
    if (status && status.status === "paid") {
      onPaid();
      return;
    }
    showStatus("step2Status", "Pagamento ainda pendente. Assim que confirmar, avançaremos automaticamente.", "info");
  } catch (err) {
    showStatus("step2Status", err.message || "Erro ao verificar pagamento.", "error");
  }
}

function onPaid() {
  stopPolling();
  setStep(3);
  if (typeof fbTrack === "function") {
    fbTrack("Purchase", {
      currency: "BRL",
      value: UP2_AMOUNT_CENTS / 100,
      content_name: "PIX up2"
    });
  }
  window.location.href = nextUrl + window.location.search;
}

function startPolling() {
  stopPolling();
  pollStartedAt = Date.now();

  pollTimer = setInterval(async () => {
    const elapsed = Date.now() - pollStartedAt;
    if (elapsed > POLL_MAX_MS) {
      stopPolling();
      showStatus("step2Status", "Tempo de verificação encerrado. Clique em 'Já paguei - Verificar'.", "error");
      return;
    }
    try {
      const status = await checkPayment();
      if (status && status.status === "paid") onPaid();
    } catch (err) {}
  }, POLL_INTERVAL_MS);
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
}

function updateExpireLabel() {
  const label = document.getElementById("timer");
  if (!label) return;
  if (!expiresAtRaw) {
    label.textContent = "--:--";
    return;
  }
  const normalized = expiresAtRaw.replace(" ", "T");
  const expiresDate = new Date(normalized);
  if (Number.isNaN(expiresDate.getTime())) {
    label.textContent = "--:--";
    return;
  }
  const diff = Math.max(0, expiresDate.getTime() - Date.now());
  const min = String(Math.floor(diff / 60000)).padStart(2, "0");
  const sec = String(Math.floor((diff % 60000) / 1000)).padStart(2, "0");
  label.textContent = `${min}:${sec}`;
}

document.addEventListener("DOMContentLoaded", () => {
  document.getElementById("paymentButton").addEventListener("click", createPixPayment);
  document.getElementById("verify-pix-btn").addEventListener("click", verifyNow);
  document.getElementById("copy-pix-btn").addEventListener("click", async () => {
    const code = document.getElementById("pix-copy-code").textContent || "";
    if (!code || code.startsWith("Gerando")) return;
    await navigator.clipboard.writeText(code);
    showStatus("step2Status", "Código PIX copiado.", "ok");
  });
  document.getElementById("continueBtn").addEventListener("click", () => {
    window.location.href = nextUrl + window.location.search;
  });

  if (sessionId) {
    setStep(2);
    showStatus("step2Status", "Retomamos sua sessão PIX. Verificando pagamento...", "info");
    startPolling();
  } else {
    setStep(1);
  }

  setInterval(updateExpireLabel, 1000);
  updateExpireLabel();
});

