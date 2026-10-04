import React, { useMemo, useState } from "react";
import "./activity-ledger-atlas.css";

type Activity = {
  id: number;
  actor: string;
  action: string;
  type: "company" | "team" | "sales" | "system";
  summary: string;
  company: string;
  domain: string;
  initials: string;
  color: string;
  when: string;
  date: string;
  time: string;
  details: string;
  reference: string;
};

const activities: Activity[] = [
  {
    id: 1, actor: "Magne Kristoffersen", action: "Bedrift oppdatert", type: "company",
    summary: "Oppdaterte bedriftsinformasjon", company: "Nordhavn Studio", domain: "nordhavn.salesman.no",
    initials: "NS", color: "#8e5848", when: "09:42", date: "12. november 2024", time: "09:42:18",
    details: "Bedriftens navn og kontaktinformasjon ble endret fra administratorkontoen.", reference: "EVT-8F21A4",
  },
  {
    id: 2, actor: "System", action: "Innlogging", type: "system",
    summary: "Ny administratorøkt startet", company: "Global hendelse", domain: "Alle virksomheter",
    initials: "SYS", color: "#667b74", when: "09:16", date: "12. november 2024", time: "09:16:03",
    details: "En administrator logget inn på Salesman kontrollpanel.", reference: "EVT-8F2190",
  },
  {
    id: 3, actor: "Magne Kristoffersen", action: "Ansatt opprettet", type: "team",
    summary: "La til en ny bruker i teamet", company: "Aasen & Vik", domain: "aasen-vik.salesman.no",
    initials: "AV", color: "#aa874b", when: "16:28", date: "11. november 2024", time: "16:28:51",
    details: "Ny medarbeider ble invitert med tilgang til salgsmodulen.", reference: "EVT-8F1D77",
  },
  {
    id: 4, actor: "Ingrid Solheim", action: "Salg registrert", type: "sales",
    summary: "Registrerte et nytt salg", company: "Nordhavn Studio", domain: "nordhavn.salesman.no",
    initials: "NS", color: "#8e5848", when: "14:05", date: "11. november 2024", time: "14:05:29",
    details: "Salgshendelsen ble registrert og knyttet til virksomhetens aktivitetslogg.", reference: "EVT-8F1C4E",
  },
  {
    id: 5, actor: "Magne Kristoffersen", action: "Bedrift opprettet", type: "company",
    summary: "Opprettet en ny virksomhet", company: "Skarv Regnskap", domain: "skarv.salesman.no",
    initials: "SR", color: "#718591", when: "10:51", date: "08. november 2024", time: "10:51:42",
    details: "Virksomheten ble lagt til i kundeporteføljen og fikk et nytt subdomene.", reference: "EVT-8F18B2",
  },
  {
    id: 6, actor: "System", action: "Bedrift slettet", type: "company",
    summary: "Historisk sletting registrert", company: "Furu Interiør", domain: "furu.salesman.no",
    initials: "FI", color: "#92716c", when: "08:14", date: "08. november 2024", time: "08:14:10",
    details: "Slettingen er bevart i historikken med virksomhetens identitet fra hendelsestidspunktet.", reference: "EVT-8F179C",
  },
];

const categories = [
  { value: "all", label: "Alle kategorier" },
  { value: "company", label: "Bedrift" },
  { value: "team", label: "Team" },
  { value: "sales", label: "Salg" },
  { value: "system", label: "System" },
];

const dates = [
  { key: "12. november 2024", label: "12 nov", day: "TIR" },
  { key: "11. november 2024", label: "11 nov", day: "MAN" },
  { key: "10. november 2024", label: "10 nov", day: "SØN" },
  { key: "08. november 2024", label: "08 nov", day: "FRE" },
];

const lanes = [
  { name: "Global hendelse", initials: "GL", color: "#667b74", kind: "Plattform" },
  { name: "Nordhavn Studio", initials: "NS", color: "#8e5848", kind: "Virksomhet" },
  { name: "Aasen & Vik", initials: "AV", color: "#aa874b", kind: "Virksomhet" },
  { name: "Skarv Regnskap", initials: "SR", color: "#718591", kind: "Virksomhet" },
  { name: "Furu Interiør", initials: "FI", color: "#92716c", kind: "Virksomhet" },
];

const categoryNames: Record<Activity["type"], string> = {
  company: "Bedrift",
  team: "Team",
  sales: "Salg",
  system: "System",
};

function Glyph({ name, size = 16 }: { name: "search" | "download" | "arrow" | "close" | "copy" | "clock" | "building" | "user" | "spark"; size?: number }) {
  const paths: Record<typeof name, React.ReactNode> = {
    search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 4 4" /></>,
    download: <><path d="M12 3v11m-4-4 4 4 4-4" /><path d="M5 17v3h14v-3" /></>,
    arrow: <><path d="M7 17 17 7M7 7h10v10" /></>,
    close: <><path d="m6 6 12 12M18 6 6 18" /></>,
    copy: <><rect x="8" y="8" width="12" height="12" rx="2" /><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    building: <><path d="M4 21V6l8-3 8 3v15M2 21h20" /><path d="M8 9h1m6 0h1M8 13h1m6 0h1M11 21v-4h2v4" /></>,
    user: <><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></>,
    spark: <><path d="m12 3 1.6 6.4L20 11l-6.4 1.6L12 19l-1.6-6.4L4 11l6.4-1.6L12 3Z" /><path d="m19 16 .7 2.3L22 19l-2.3.7L19 22l-.7-2.3L16 19l2.3-.7L19 16Z" /></>,
  };
  return <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{paths[name]}</svg>;
}

export function ActivityLedgerAtlas() {
  const [query, setQuery] = useState("");
  const [category, setCategory] = useState("all");
  const [period, setPeriod] = useState("7 dager");
  const [laneFilter, setLaneFilter] = useState("Alle virksomheter");
  const [selectedId, setSelectedId] = useState<number | null>(1);
  const [copied, setCopied] = useState(false);

  const filtered = useMemo(() => activities.filter((event) => {
    const needle = query.trim().toLocaleLowerCase("no");
    const searchable = `${event.actor} ${event.action} ${event.summary} ${event.company} ${event.domain} ${event.reference}`.toLocaleLowerCase("no");
    const matchesCategory = category === "all" || event.type === category;
    const matchesLane = laneFilter === "Alle virksomheter" || event.company === laneFilter;
    const matchesQuery = !needle || searchable.includes(needle);
    const matchesPeriod = period !== "24 timer" || event.date === "12. november 2024";
    return matchesCategory && matchesLane && matchesQuery && matchesPeriod;
  }), [category, laneFilter, period, query]);

  const selected = filtered.find((event) => event.id === selectedId) ?? filtered[0] ?? null;
  const visibleLanes = lanes.filter((lane) => laneFilter === "Alle virksomheter" || lane.name === laneFilter);
  const laneCount = (name: string) => filtered.filter((event) => event.company === name).length;

  function exportLog() {
    const rows = [
      ["Tidspunkt", "Aktør", "Hendelse", "Virksomhet", "Domene", "Referanse"],
      ...filtered.map((event) => [`${event.date} ${event.time}`, event.actor, event.action, event.company, event.domain, event.reference]),
    ];
    const csv = rows.map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" }));
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "salesman-aktivitetslogg.csv";
    anchor.click();
    URL.revokeObjectURL(url);
  }

  async function copyReference() {
    if (!selected) return;
    try {
      await navigator.clipboard?.writeText(selected.reference);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1600);
    } catch {
      setCopied(false);
    }
  }

  function resetView() {
    setQuery("");
    setCategory("all");
    setPeriod("7 dager");
    setLaneFilter("Alle virksomheter");
    setSelectedId(1);
  }

  return (
    <main className="atlas-shell">
      <header className="atlas-masthead">
        <div className="atlas-brand">
          <span className="atlas-brandmark">S</span>
          <span>salesman<span className="atlas-domain">.no</span></span>
          <span className="atlas-divider" />
          <span className="atlas-product">CONTROL ROOM</span>
        </div>
        <div className="atlas-admin"><span className="atlas-live-dot" />ARKIVET ER OPPDATERT <span className="atlas-admin-avatar">MK</span></div>
      </header>

      <div className="atlas-page">
        <div className="atlas-breadcrumb"><span>ADMINISTRASJON</span><span className="atlas-breadcrumb-slash">/</span><strong>AKTIVITETSARKIV</strong></div>

        <section className="atlas-intro">
          <div>
            <div className="atlas-eyebrow"><Glyph name="spark" size={14} /> AUDIT ATLAS <span>·</span> ENDRENGNINGER I SAMMENHENG</div>
            <h1>Se virksomhetene<br className="atlas-title-break" /> <em>i samme spor.</em></h1>
            <p>Finn ut hva som skjedde, hvor det skjedde — og hva som kom rett før.</p>
          </div>
          <button type="button" className="atlas-export" onClick={exportLog}><Glyph name="download" size={15} /> Eksporter logg</button>
        </section>

        <section className="atlas-summary" aria-label="Oversikt over filtrerte hendelser">
          <div className="atlas-summary-main">
            <span className="atlas-summary-label">HENDELSER I VISNING</span>
            <strong>{String(filtered.length).padStart(2, "0")}</strong>
            <span className="atlas-summary-note">av {activities.length} registrerte spor</span>
          </div>
          <div className="atlas-summary-stat"><span>BERØRTE VIRKSOMHETER</span><strong>{new Set(filtered.filter((event) => event.company !== "Global hendelse").map((event) => event.company)).size}</strong></div>
          <div className="atlas-summary-stat"><span>SISTE REGISTRERING</span><strong>{filtered[0]?.when ?? "—"} <small>{filtered[0] ? "12 NOV" : "INGEN TREFF"}</small></strong></div>
          <div className="atlas-summary-mark"><span>PLATTFORMLOGG</span><strong>01—06</strong></div>
        </section>

        <section className="atlas-explorer">
          <div className="atlas-explorer-head">
            <div>
              <div className="atlas-section-index">01 / SAMMENLIGNING</div>
              <h2>Hendelseskart</h2>
              <p>Hver rad følger en virksomhet. Velg et spor for å lese detaljene.</p>
            </div>
            <div className="atlas-view-key"><span><i className="atlas-key-dot company" /> Virksomhet</span><span><i className="atlas-key-dot system" /> Plattform</span></div>
          </div>

          <div className="atlas-controls">
            <label className="atlas-search"><Glyph name="search" size={16} /><input aria-label="Søk i aktivitetsarkivet" placeholder="Søk etter aktør, hendelse, domene eller referanse" value={query} onChange={(event) => setQuery(event.target.value)} />{query && <button type="button" aria-label="Tøm søk" onClick={() => setQuery("")}><Glyph name="close" size={13} /></button>}</label>
            <label className="atlas-category"><span>KATEGORI</span><select aria-label="Filtrer etter kategori" value={category} onChange={(event) => setCategory(event.target.value)}>{categories.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
            <div className="atlas-period" aria-label="Tidsrom">
              {["24 timer", "7 dager", "30 dager"].map((range) => <button type="button" key={range} onClick={() => setPeriod(range)} className={period === range ? "active" : ""}>{range}</button>)}
            </div>
          </div>

          <div className="atlas-lane-filters" aria-label="Velg virksomhetsfokus">
            <span className="atlas-filter-label">FOKUS</span>
            <button type="button" className={laneFilter === "Alle virksomheter" ? "active" : ""} onClick={() => setLaneFilter("Alle virksomheter")}>Alle enheter <span>{filtered.length}</span></button>
            {lanes.map((lane) => <button type="button" key={lane.name} className={laneFilter === lane.name ? "active" : ""} onClick={() => setLaneFilter(lane.name)}>{lane.name} <span>{laneCount(lane.name)}</span></button>)}
            {(query || category !== "all" || period !== "7 dager" || laneFilter !== "Alle virksomheter") && <button type="button" className="atlas-reset" onClick={resetView}>Nullstill <Glyph name="close" size={12} /></button>}
          </div>

          <div className="atlas-matrix">
            <div className="atlas-date-axis">
              <div className="atlas-axis-title">ENHET <span>↓</span></div>
              {dates.map((date) => <div className="atlas-date-head" key={date.key}><span>{date.day}</span><strong>{date.label}</strong></div>)}
            </div>
            {filtered.length === 0 ? (
              <div className="atlas-empty">
                <span className="atlas-empty-icon"><Glyph name="search" size={20} /></span>
                <strong>Ingen spor i dette utsnittet</strong>
                <span>Prøv et bredere tidsrom eller fjern ett av filtrene.</span>
                <button type="button" onClick={resetView}>Vis hele arkivet</button>
              </div>
            ) : visibleLanes.map((lane) => {
              const laneEvents = filtered.filter((event) => event.company === lane.name);
              return (
                <div className="atlas-lane" key={lane.name}>
                  <div className="atlas-lane-label">
                    <span className="atlas-lane-avatar" style={{ backgroundColor: lane.color }}>{lane.initials}</span>
                    <span className="atlas-lane-copy"><strong>{lane.name}</strong><small>{lane.kind} <i /> {String(laneCount(lane.name)).padStart(2, "0")} spor</small></span>
                    <span className="atlas-lane-arrow"><Glyph name="arrow" size={12} /></span>
                  </div>
                  <div className="atlas-lane-cells">
                    {dates.map((date) => {
                      const cellEvents = laneEvents.filter((event) => event.date === date.key);
                      return (
                        <div className={`atlas-slot ${cellEvents.length ? "occupied" : ""}`} key={date.key} data-date={date.label}>
                          {cellEvents.map((event) => (
                            <button type="button" key={event.id} className={`atlas-event ${selected?.id === event.id ? "selected" : ""} type-${event.type}`} onClick={() => { setSelectedId(event.id); setCopied(false); }} aria-pressed={selected?.id === event.id}>
                              <span className="atlas-event-time">{event.when}</span>
                              <strong>{event.action}</strong>
                              <span className="atlas-event-summary">{event.summary}</span>
                              <span className="atlas-event-corner"><Glyph name="arrow" size={12} /></span>
                            </button>
                          ))}
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })}
            <div className="atlas-matrix-foot"><span><i /> Hendelser sortert etter dato</span><span>{filtered.length} SPOR I UTVALGET</span></div>
          </div>
        </section>

        <section className="atlas-trace" aria-live="polite">
          <div className="atlas-trace-rail">
            <span className="atlas-section-index">02 / VALGT SPOR</span>
            <span className="atlas-trace-count">{selected ? `EVENT ${String(selected.id).padStart(2, "0")}` : "TOMT UTVALG"}</span>
          </div>
          {selected ? (
            <div className="atlas-trace-body">
              <div className={`atlas-trace-symbol type-${selected.type}`}><Glyph name={selected.type === "system" ? "spark" : selected.type === "team" ? "user" : "building"} size={19} /></div>
              <div className="atlas-trace-description">
                <div className="atlas-trace-type">{categoryNames[selected.type]} <span>·</span> {selected.date}</div>
                <h2>{selected.action}</h2>
                <p>{selected.details}</p>
              </div>
              <div className="atlas-trace-meta">
                <div><span>UTFØRT AV</span><strong>{selected.actor}</strong></div>
                <div><span>VIRKSOMHET</span><strong>{selected.company}</strong><small>{selected.domain}</small></div>
                <div><span>TIDSPUNKT</span><strong>{selected.time}</strong></div>
              </div>
              <div className="atlas-trace-reference">
                <span>REFERANSE</span><strong>{selected.reference}</strong>
                <button type="button" onClick={copyReference}><Glyph name="copy" size={13} />{copied ? "Kopiert" : "Kopier ID"}</button>
              </div>
            </div>
          ) : (
            <div className="atlas-trace-empty"><Glyph name="clock" size={17} /> Velg en hendelse i kartet for å åpne revisjonssporet.</div>
          )}
        </section>

        <footer className="atlas-footer"><span>SALESman CONTROL ROOM <i>·</i> AKTIVITETSARKIV</span><span><Glyph name="clock" size={12} /> Historikk bevares også når en bruker eller virksomhet fjernes.</span></footer>
      </div>
    </main>
  );
}

export default ActivityLedgerAtlas;