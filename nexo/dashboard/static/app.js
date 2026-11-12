const fmt = new Intl.NumberFormat("en-US");

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
  const res = await fetch("/api/status", { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  text("generated-at", `Updated ${data.generated_at}`);
  text("total", num(data.summary.total));
  text("fresh-1d", num(data.summary.fresh_1d));
  text("fresh-7d", num(data.summary.fresh_7d));
  text("stale-7d", num(data.summary.stale_7d));
  text("max-updated", data.summary.max_updated);

  const pill = byId("service-pill");
  pill.textContent = data.service.active ? "Running" : data.service.active_label || "Stopped";
  pill.className = `pill ${data.service.active ? "ok" : "bad"}`;
  text("main-pid", data.service.main_pid || "-");
  text("started-at", data.service.started_at || "-");
  text("current-step", currentStep(data.steps));

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

async function loadNexo() {
  const res = await fetch("/api/nexo", { cache: "no-store" });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const data = await res.json();

  const late = data.alerts.filter((a) => a.kind === "overdue").length;
  text("nexo-summary", `${data.sources.length} fuentes · ${late} atrasadas · ${data.alerts.length} alertas`);

  const sources = byId("nexo-sources");
  sources.replaceChildren();
  for (const s of data.sources) {
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
    candidates.appendChild(row([
      { value: c.host },
      { value: c.country.toUpperCase() },
      { value: `${c.score}/7`, className: "num" },
      { value: c.status },
      { value: c.first_seen },
    ]));
  }
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

byId("reload").addEventListener("click", loadStatus);
byId("refresh-now").addEventListener("click", startRefresh);

loadStatus().catch((err) => {
  text("generated-at", `Dashboard error: ${err.message}`);
});
loadNexo().catch((err) => {
  text("nexo-summary", `Error: ${err.message}`);
});
setInterval(() => loadStatus().catch(() => {}), 15000);
setInterval(() => loadNexo().catch(() => {}), 60000);
