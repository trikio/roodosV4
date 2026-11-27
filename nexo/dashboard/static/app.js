const fmt = new Intl.NumberFormat("en-US");

// Autos | Casas: las mismas secciones filtradas por vertical; se elige con #autos o #casas.
function vertical() {
  return location.hash === "#casas" ? "casas" : "autos";
}

function api(path) {
  return `${path}${path.includes("?") ? "&" : "?"}vertical=${vertical()}`;
}

function applyVertical() {
  const v = vertical();
  document.body.dataset.vertical = v;
  for (const a of document.querySelectorAll(".tabs a")) a.classList.toggle("active", a.dataset.vertical === v);
  text("total-label", v === "casas" ? "Total casas activas" : "Total autos");
  text("sources-title", v === "casas" ? "Fuentes de casas" : "Fuentes de autos");
}

function byId(id) {
  return document.getElementById(id);
}

function text(id, value) {
  byId(id).textContent = value ?? "-";
}

function num(value) {
  return fmt.format(Number(value || 0));
}

function row(cells) {
  const tr = document.createElement("tr");
  for (const cell of cells) {
    const td = document.createElement("td");
    if (cell.className) td.className = cell.className;
    td.textContent = cell.value ?? "-";
    tr.appendChild(td);
  }
  return tr;
}

function currentStep(steps) {
  if (!steps || !steps.length) return "-";
  const active = [...steps].reverse().find((line) => line.includes(" START "));
  const lastEnd = [...steps].reverse().find((line) => line.includes(" END "));
  if (!active) return lastEnd || steps[steps.length - 1];
  if (!lastEnd) return active;
  return steps.indexOf(active) > steps.indexOf(lastEnd) ? active : lastEnd;
}

async function loadStatus() {
  const res = await fetch(api("/api/status"), { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  text("generated-at", `Updated ${data.generated_at}`);
  text("total", num(data.summary.total));
  text("fresh-1d", num(data.summary.fresh_1d));
  text("fresh-7d", num(data.summary.fresh_7d));
  text("stale-7d", num(data.summary.stale_7d));
  text("max-updated", data.summary.max_updated);

  if (data.vertical !== "autos") {
    text("current-step", "-");
  } else {
  const pill = byId("service-pill");
  pill.textContent = data.service.active ? "Running" : data.service.active_label || "Stopped";
  pill.className = `pill ${data.service.active ? "ok" : "bad"}`;
  text("main-pid", data.service.main_pid || "-");
  text("started-at", data.service.started_at || "-");
  text("current-step", currentStep(data.steps));
  }

  const countries = byId("countries");
  countries.replaceChildren();
  for (const item of data.countries) {
    countries.appendChild(row([
      { value: item.country },
      { value: num(item.total), className: "num" },
      { value: num(item.fresh_1d), className: "num" },
      { value: num(item.fresh_3d), className: "num" },
      { value: num(item.fresh_7d), className: "num" },
      { value: item.max_updated },
    ]));
  }

  const sources = byId("sources");
  sources.replaceChildren();
  for (const item of data.sources) {
    sources.appendChild(row([
      { value: item.source },
      { value: item.country },
      { value: num(item.total), className: "num" },
      { value: num(item.fresh_1d), className: "num" },
      { value: num(item.fresh_7d), className: "num" },
      { value: item.max_updated },
    ]));
  }

  const steps = byId("steps");
  steps.replaceChildren();
  for (const line of data.steps) {
    const li = document.createElement("li");
    li.textContent = line;
    steps.appendChild(li);
  }

  text("log-tail", data.log_tail.join("\n"));
}

const OUTCOME_LABELS = { ok: "ok", empty: "sin avisos", drop: "caída", failed: "falló" };
const COUNTRY_NAMES = { cl: "Chile", mx: "México", pe: "Perú", ec: "Ecuador" };

async function loadCountries() {
  const res = await fetch(api("/api/countries"), { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  const total = data.countries.reduce((sum, c) => sum + Number(c.active || 0), 0);
  text("countries-total", `${num(total)} avisos activos en total`);

  const body = byId("countries-active");
  body.replaceChildren();
  for (const c of data.countries) {
    body.appendChild(row([
      { value: COUNTRY_NAMES[c.country] || c.country.toUpperCase() },
      { value: num(c.active), className: "num" },
      { value: num(c.new_24h), className: "num" },
      { value: num(c.seen_24h), className: "num" },
      { value: num(c.seen_7d), className: "num" },
      { value: num(c.landings), className: "num casas-only" },
      { value: num(c.generated), className: "num casas-only" },
    ]));
  }
}

async function loadNexo() {
  const res = await fetch(api("/api/nexo"), { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  const late = data.alerts.filter((a) => a.kind === "overdue").length;
  text("nexo-summary", `${data.sources.length} fuentes · ${late} atrasadas · ${data.alerts.length} alertas`);

  const sources = byId("nexo-sources");
  sources.replaceChildren();
  const activeByCountry = {};
  for (const s of data.sources) activeByCountry[s.country] = (activeByCountry[s.country] || 0) + Number(s.active || 0);
  let currentCountry = null;
  for (const s of data.sources) {
    if (s.country !== currentCountry) {
      currentCountry = s.country;
      const header = row([
        { value: COUNTRY_NAMES[s.country] || s.country.toUpperCase() },
        { value: "" },
        { value: num(activeByCountry[s.country]), className: "num" },
      ]);
      header.className = "group-row";
      sources.appendChild(header);
    }
    const run = s.last_run ? `${s.last_run} · ${OUTCOME_LABELS[s.outcome] || s.outcome} (${num(s.last_run_seen)})` : "-";
    const images = s.images_checked ? `${s.images_broken} de ${s.images_checked}` : "-";
    const tr = row([
      { value: s.name },
      { value: s.country.toUpperCase() },
      { value: num(s.active), className: "num" },
      { value: s.last_seen },
      { value: run },
      { value: num(s.seen_24h), className: "num" },
      { value: num(s.new_24h), className: "num" },
      { value: `${num(s.stale)}${s.full_crawl ? "" : " *"}`, className: "num" },
      { value: images, className: "num" },
    ]);
    if (s.outcome && s.outcome !== "ok") tr.className = "bad-row";
    if (s.error_excerpt) tr.title = s.error_excerpt;
    sources.appendChild(tr);
  }

  const alerts = byId("nexo-alerts");
  alerts.replaceChildren();
  for (const a of data.alerts) {
    const li = document.createElement("li");
    li.textContent = `${a.opened_at} · ${a.message}`;
    alerts.appendChild(li);
  }
  if (!data.alerts.length) alerts.textContent = "Sin alertas.";

  const candidates = byId("nexo-candidates");
  candidates.replaceChildren();
  for (const c of data.candidates) {
    const tr = row([
      { value: c.host },
      { value: c.country.toUpperCase() },
      { value: `${c.score}/7`, className: "num" },
      { value: CANDIDATE_STATUS[c.status] || c.status },
      { value: c.first_seen },
    ]);
    tr.title = c.url;
    const actions = document.createElement("td");
    actions.className = "casas-only";
    if (c.status === "review") {
      actions.append(candidateButton("Adaptar", c.host, "adapt"), " ", candidateButton("Descartar", c.host, "reject"));
    }
    tr.appendChild(actions);
    candidates.appendChild(tr);
  }
  if (!data.candidates.length) candidates.textContent = "Sin candidatas todavía (la búsqueda corre a las 9:30).";
}

const CANDIDATE_STATUS = {
  new: "por probar", review: "por revisar", adapting: "adaptando", manual: "spider propio",
  dealer: "automotora", blocked: "robots.txt lo prohíbe",
};

function candidateButton(label, host, action) {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.textContent = label;
  btn.addEventListener("click", async () => {
    btn.disabled = true;
    await fetch(`/api/candidates/${encodeURIComponent(host)}/${action}`, { method: "POST" });
    await loadNexo();
  });
  return btn;
}

const DEALER_STATUS = { review: "por aprobar", active: "activa", paused: "en pausa" };

function actionButton(label, host, action) {
  const btn = document.createElement("button");
  btn.type = "button";
  btn.textContent = label;
  btn.addEventListener("click", async () => {
    btn.disabled = true;
    await fetch(`/api/dealers/${encodeURIComponent(host)}/${action}`, { method: "POST" });
    await loadDealers();
  });
  return btn;
}

async function loadDealers() {
  const res = await fetch("/api/dealers", { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  const pending = data.sites.filter((s) => s.status === "review").length;
  const active = data.sites.filter((s) => s.status === "active").length;
  text("dealers-summary", `${active} activas · ${pending} por aprobar`);

  const body = byId("dealers");
  body.replaceChildren();
  for (const s of data.sites) {
    let sample = "-";
    try {
      const first = JSON.parse(s.sample || "[]")[0];
      if (first) sample = `${first.title} · ${num(first.price)}`;
    } catch (err) {}
    const tr = row([
      { value: s.name && s.name !== s.host ? `${s.name} (${s.host})` : s.host },
      { value: s.country.toUpperCase() },
      { value: DEALER_STATUS[s.status] || s.status },
      { value: num(s.test_items), className: "num" },
      { value: num(s.active), className: "num" },
      { value: s.last_seen },
      { value: sample },
    ]);
    const actions = document.createElement("td");
    if (s.status === "review") {
      actions.append(actionButton("Aprobar", s.host, "approve"), " ", actionButton("Descartar", s.host, "reject"));
    } else if (s.status === "active") {
      actions.append(actionButton("Pausar", s.host, "pause"));
    } else {
      actions.append(actionButton("Activar", s.host, "approve"));
    }
    tr.appendChild(actions);
    body.appendChild(tr);
  }
  if (!data.sites.length) body.textContent = "Todavía no hay automotoras probadas.";
}

async function startRefresh() {
  const btn = byId("refresh-now");
  btn.disabled = true;
  btn.textContent = "Running";
  try {
    await fetch("/api/refresh", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({}),
    });
    await loadStatus();
  } finally {
    btn.disabled = false;
    btn.textContent = "Run";
  }
}

function loadAll() {
  applyVertical();
  loadStatus().catch((err) => text("generated-at", `Dashboard error: ${err.message}`));
  loadCountries().catch((err) => text("countries-total", `Error: ${err.message}`));
  loadNexo().catch((err) => text("nexo-summary", `Error: ${err.message}`));
  if (vertical() === "autos") loadDealers().catch((err) => text("dealers-summary", `Error: ${err.message}`));
}

window.addEventListener("hashchange", loadAll);
byId("reload").addEventListener("click", loadAll);
byId("refresh-now").addEventListener("click", startRefresh);

loadAll();
setInterval(() => loadStatus().catch(() => {}), 15000);
setInterval(() => loadNexo().catch(() => {}), 60000);
setInterval(() => loadCountries().catch(() => {}), 60000);
