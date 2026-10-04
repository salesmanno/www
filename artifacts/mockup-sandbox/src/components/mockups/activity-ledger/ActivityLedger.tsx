import React, { useMemo, useState } from "react";

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
    id: 1,
    actor: "Magne Kristoffersen",
    action: "Bedrift oppdatert",
    type: "company",
    summary: "Oppdaterte bedriftsinformasjon",
    company: "Nordhavn Studio",
    domain: "nordhavn.salesman.no",
    initials: "NS",
    color: "#98624e",
    when: "09:42",
    date: "12. november 2024",
    time: "09:42:18",
    details: "Bedriftens navn og kontaktinformasjon ble endret fra administratorkontoen.",
    reference: "EVT-8F21A4",
  },
  {
    id: 2,
    actor: "System",
    action: "Innlogging",
    type: "system",
    summary: "Ny administratorøkt startet",
    company: "Global hendelse",
    domain: "Alle virksomheter",
    initials: "S",
    color: "#657869",
    when: "09:16",
    date: "12. november 2024",
    time: "09:16:03",
    details: "En administrator logget inn på Salesman kontrollpanel.",
    reference: "EVT-8F2190",
  },
  {
    id: 3,
    actor: "Magne Kristoffersen",
    action: "Ansatt opprettet",
    type: "team",
    summary: "La til en ny bruker i teamet",
    company: "Aasen & Vik",
    domain: "aasen-vik.salesman.no",
    initials: "AV",
    color: "#b48a47",
    when: "16:28",
    date: "11. november 2024",
    time: "16:28:51",
    details: "Ny medarbeider ble invitert med tilgang til salgsmodulen.",
    reference: "EVT-8F1D77",
  },
  {
    id: 4,
    actor: "Ingrid Solheim",
    action: "Salg registrert",
    type: "sales",
    summary: "Registrerte et nytt salg",
    company: "Nordhavn Studio",
    domain: "nordhavn.salesman.no",
    initials: "NS",
    color: "#98624e",
    when: "14:05",
    date: "11. november 2024",
    time: "14:05:29",
    details: "Salgshendelsen ble registrert og knyttet til virksomhetens aktivitetslogg.",
    reference: "EVT-8F1C4E",
  },
  {
    id: 5,
    actor: "Magne Kristoffersen",
    action: "Bedrift opprettet",
    type: "company",
    summary: "Opprettet en ny virksomhet",
    company: "Skarv Regnskap",
    domain: "skarv.salesman.no",
    initials: "SR",
    color: "#6c7d89",
    when: "10:51",
    date: "08. november 2024",
    time: "10:51:42",
    details: "Virksomheten ble lagt til i kundeporteføljen og fikk et nytt subdomene.",
    reference: "EVT-8F18B2",
  },
  {
    id: 6,
    actor: "System",
    action: "Bedrift slettet",
    type: "company",
    summary: "Historisk sletting registrert",
    company: "Furu Interiør",
    domain: "furu.salesman.no",
    initials: "FI",
    color: "#8c6b68",
    when: "08:14",
    date: "08. november 2024",
    time: "08:14:10",
    details: "Slettingen er bevart i historikken med virksomhetens identitet fra hendelsestidspunktet.",
    reference: "EVT-8F179C",
  },
];

const typeLabels: Record<string, string> = {
  all: "Alle hendelser",
  company: "Bedrift",
  team: "Team",
  sales: "Salg",
  system: "System",
};

function Icon({ name, size = 16 }: { name: string; size?: number }) {
  const paths: Record<string, React.ReactNode> = {
    search: <><circle cx="11" cy="11" r="6.5" /><path d="m16 16 4 4" /></>,
    down: <><path d="m7 10 5 5 5-5" /></>,
    left: <><path d="m14 5-7 7 7 7" /></>,
    right: <><path d="m10 5 7 7-7 7" /></>,
    download: <><path d="M12 3v11m-4-4 4 4 4-4" /><path d="M5 17v3h14v-3" /></>,
    pause: <><path d="M8 5v14M16 5v14" /></>,
    play: <><path d="m8 5 11 7-11 7z" /></>,
    building: <><path d="M4 21V6l8-3 8 3v15M2 21h20" /><path d="M8 9h1m6 0h1M8 13h1m6 0h1M11 21v-4h2v4" /></>,
    globe: <><circle cx="12" cy="12" r="9" /><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18" /></>,
    clock: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
    check: <><path d="m5 12 4 4L19 6" /></>,
    more: <><circle cx="5" cy="12" r="1" /><circle cx="12" cy="12" r="1" /><circle cx="19" cy="12" r="1" /></>,
    arrow: <><path d="M7 17 17 7M7 7h10v10" /></>,
    calendar: <><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M16 3v4M8 3v4M3 10h18" /></>,
    filter: <><path d="M4 6h16M7 12h10m-7 6h4" /></>,
  };
  return (
    <svg aria-hidden="true" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
      {paths[name] ?? paths.more}
    </svg>
  );
}

export function ActivityLedger() {
  const [company, setCompany] = useState("Alle virksomheter");
  const [kind, setKind] = useState("all");
  const [query, setQuery] = useState("");
  const [selectedId, setSelectedId] = useState(1);
  const [paused, setPaused] = useState(false);
  const [period, setPeriod] = useState("7 dager");
  const [copied, setCopied] = useState(false);

  const periodActivities = useMemo(
    () => period === "24 timer" ? activities.filter((event) => event.date === "12. november 2024") : activities,
    [period],
  );
  const filtered = useMemo(() => periodActivities.filter((event) => {
    const matchesCompany = company === "Alle virksomheter" || event.company === company;
    const matchesKind = kind === "all" || event.type === kind;
    const haystack = `${event.actor} ${event.action} ${event.summary} ${event.company} ${event.domain}`.toLocaleLowerCase("no");
    return matchesCompany && matchesKind && haystack.includes(query.toLocaleLowerCase("no"));
  }), [company, kind, query, periodActivities]);

  const selected = filtered.find((event) => event.id === selectedId) ?? filtered[0] ?? activities[0];
  const grouped = filtered.reduce<Record<string, Activity[]>>((groups, event) => {
    (groups[event.date] ??= []).push(event);
    return groups;
  }, {});
  const companies = [
    { name: "Alle virksomheter", count: 6, mark: "◈", color: "#a24839" },
    { name: "Nordhavn Studio", count: 2, mark: "NS", color: "#98624e" },
    { name: "Aasen & Vik", count: 1, mark: "AV", color: "#b48a47" },
    { name: "Skarv Regnskap", count: 1, mark: "SR", color: "#6c7d89" },
    { name: "Furu Interiør", count: 1, mark: "FI", color: "#8c6b68" },
  ];

  function exportLog() {
    const csv = [
      ["Tidspunkt", "Aktør", "Hendelse", "Virksomhet", "Domene"],
      ...filtered.map((event) => [`${event.date} ${event.time}`, event.actor, event.action, event.company, event.domain]),
    ].map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(",")).join("\n");
    const url = URL.createObjectURL(new Blob(["\uFEFF" + csv], { type: "text/csv;charset=utf-8" }));
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = "salesman-aktivitetslogg.csv";
    anchor.click();
    URL.revokeObjectURL(url);
  }

  async function copyReference() {
    try {
      await navigator.clipboard?.writeText(selected.reference);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1800);
    } catch {
      setCopied(false);
    }
  }

  return (
    <div className="ledger">
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600&display=swap');
        .ledger{--paper:#f3efe8;--sheet:#fcfaf6;--ink:#282a25;--muted:#85837b;--line:#e6e0d5;--accent:#a24839;--sage:#6f8374;min-height:100dvh;background:var(--paper);color:var(--ink);font-family:'DM Sans',sans-serif;font-size:13px;line-height:1.45;overflow:hidden}
        .ledger *{box-sizing:border-box}
        .ledger button,.ledger input{font:inherit}
        .ledger button{color:inherit}
        .ledger-top{height:66px;background:#302f2a;color:#f8f4eb;display:flex;align-items:center;padding:0 34px;gap:34px;border-bottom:1px solid rgba(255,255,255,.08)}
        .ledger-brand{display:flex;align-items:center;gap:11px;min-width:225px}
        .ledger-mark{width:30px;height:30px;border:1px solid #ba8c78;color:#e5baa1;border-radius:50%;display:grid;place-items:center;font-family:'Playfair Display',serif;font-size:19px;line-height:1}
        .ledger-brandname{font-size:14px;font-weight:700;letter-spacing:.03em}
        .ledger-brandname span{color:#bdb5a7;font-weight:400}
        .ledger-nav{height:100%;display:flex;align-items:center;gap:27px;color:#bcb8af}
        .ledger-nav span{height:100%;position:relative;display:flex;align-items:center;color:inherit}
        .ledger-nav span.active{color:#f9f2e8}
        .ledger-nav span.active:after{position:absolute;content:'';height:2px;bottom:0;left:0;right:0;background:#bd7964}
        .ledger-topright{margin-left:auto;display:flex;align-items:center;gap:20px;color:#c8c3b9}
        .ledger-presence{display:flex;gap:8px;align-items:center;font-size:11px}
        .ledger-presence i{width:7px;height:7px;border-radius:50%;background:#9fb19b;box-shadow:0 0 0 3px rgba(159,177,155,.13)}
        .ledger-user{width:29px;height:29px;border-radius:50%;display:grid;place-items:center;background:#64584b;color:#f5e8d7;font-size:10px;font-weight:700}
        .ledger-main{max-width:1600px;margin:auto;padding:27px 34px 38px}
        .ledger-breadcrumb{display:flex;align-items:center;gap:9px;color:#99948a;font-size:11px;letter-spacing:.045em}
        .ledger-breadcrumb strong{font-weight:500;color:#655e53}
        .ledger-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:19px 0 22px}
        .ledger-kicker{color:var(--accent);font-family:'DM Mono',monospace;font-size:10px;letter-spacing:.14em;text-transform:uppercase;margin-bottom:5px}
        .ledger-title{font-family:'Playfair Display',Georgia,serif;font-size:35px;line-height:1.08;letter-spacing:-.035em;font-weight:500;margin:0}
        .ledger-subtitle{color:#817e75;font-size:12px;margin-top:8px}
        .ledger-actions{display:flex;gap:8px}
        .ledger-button{height:36px;padding:0 13px;border:1px solid #d9d3c9;background:var(--sheet);border-radius:4px;display:inline-flex;align-items:center;gap:8px;font-size:11px;font-weight:600;cursor:pointer;transition:transform .16s,background .16s}
        .ledger-button:hover{transform:translateY(-1px);background:#fff}
        .ledger-button.export{background:#36362f;border-color:#36362f;color:#f9f4eb}
        .ledger-workspace{display:grid;grid-template-columns:218px minmax(330px,1fr) 246px;gap:14px;align-items:stretch}
        .ledger-panel{background:var(--sheet);border:1px solid #e7e1d7;border-radius:5px;min-width:0}
        .ledger-sidebar{padding:19px 14px 15px}
        .ledger-label{font-family:'DM Mono',monospace;font-size:9px;color:#9c978d;letter-spacing:.13em;text-transform:uppercase}
        .ledger-companylist{display:grid;gap:4px;margin:13px -6px 18px}
        .ledger-company{width:100%;min-height:44px;display:flex;align-items:center;gap:10px;padding:6px 8px;border:0;border-radius:3px;background:none;text-align:left;cursor:pointer;color:#5d5b54}
        .ledger-company:hover{background:#f5f1e9}
        .ledger-company.selected{background:#f2e9e1;color:#432f29}
        .ledger-company-mark{width:27px;height:27px;flex:0 0 27px;display:grid;place-items:center;border-radius:4px;color:#fff;font-size:8px;font-weight:700;letter-spacing:.03em}
        .ledger-company-mark.all{background:#a24839;font-family:'Playfair Display',serif;font-size:16px}
        .ledger-company-copy{min-width:0;flex:1}
        .ledger-company-name{display:block;font-size:10.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .ledger-company-meta{display:block;margin-top:1px;color:#9c978d;font-family:'DM Mono',monospace;font-size:9px}
        .ledger-count{font-family:'DM Mono',monospace;font-size:9px;color:#9a958c}
        .ledger-side-divider{height:1px;background:var(--line);margin:16px 0}
        .ledger-range{margin-top:10px;display:flex;flex-wrap:wrap;gap:5px}
        .ledger-range button{border:1px solid transparent;background:transparent;padding:5px 7px;border-radius:3px;color:#817d73;font-size:10px;cursor:pointer}
        .ledger-range button.selected{color:#6f372e;background:#f3e9e2;border-color:#ecdbd1}
        .ledger-retention{background:#f5f1e9;padding:11px;border-radius:4px;margin-top:16px;color:#777268;font-size:10px;line-height:1.55}
        .ledger-retention b{display:block;color:#4e5048;font-size:10px;margin-bottom:3px}
        .ledger-center{overflow:hidden}
        .ledger-toolbar{min-height:57px;border-bottom:1px solid var(--line);padding:10px 15px;display:flex;align-items:center;gap:8px}
        .ledger-search{height:34px;display:flex;align-items:center;gap:8px;padding:0 9px;border:1px solid #e4ded4;background:#fffdf9;border-radius:3px;flex:1;min-width:100px;color:#99948a}
        .ledger-search input{width:100%;border:0;outline:0;background:transparent;color:#393933;font-size:11px}
        .ledger-search input::placeholder{color:#aaa59c}
        .ledger-kind{height:34px;padding:0 8px;border:1px solid #e4ded4;background:#fffdf9;border-radius:3px;display:flex;align-items:center;gap:6px}
        .ledger-kind select{border:0;outline:0;appearance:none;max-width:97px;background:transparent;color:#646158;font-size:10px;cursor:pointer}
        .ledger-live{margin-left:auto;display:flex;align-items:center;gap:6px;color:#77776d;font-size:9px;white-space:nowrap}
        .ledger-live-dot{width:6px;height:6px;border-radius:50%;background:#83a082}
        .ledger-live.paused .ledger-live-dot{background:#c29a62}
        .ledger-timeline{padding:6px 19px 20px;max-height:618px;overflow:auto}
        .ledger-dategroup{padding-top:17px}
        .ledger-datehead{display:flex;align-items:center;gap:10px;color:#8d887e;font-family:'DM Mono',monospace;font-size:9px;letter-spacing:.09em;text-transform:uppercase;margin-bottom:7px}
        .ledger-datehead:after{content:'';height:1px;background:#e8e2d9;flex:1}
        .ledger-event{width:100%;position:relative;display:grid;grid-template-columns:44px 1fr 39px;gap:0;align-items:start;text-align:left;padding:10px 8px 11px 0;background:none;border:0;border-radius:3px;cursor:pointer}
        .ledger-event:before{content:'';position:absolute;left:15px;top:0;bottom:-8px;width:1px;background:#e7e0d6}
        .ledger-event:last-child:before{bottom:15px}
        .ledger-event.selected{background:#f5f0e8}
        .ledger-eventmark{z-index:1;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;color:#9e5946;background:#f2e6dd;border:1px solid #ead8cd}
        .ledger-eventmark.sales{color:#637966;background:#e8eee8;border-color:#dce6dc}
        .ledger-eventmark.system{color:#817958;background:#f1eedf;border-color:#e9e2c8}
        .ledger-eventmark.team{color:#787395;background:#eceaf3;border-color:#e0deeb}
        .ledger-eventbody{min-width:0;padding-top:1px}
        .ledger-eventtop{display:flex;align-items:center;justify-content:space-between;gap:8px}
        .ledger-eventtitle{font-size:11px;font-weight:700;color:#3d3d37}
        .ledger-eventtime{font-family:'DM Mono',monospace;font-size:9px;color:#99948b;white-space:nowrap}
        .ledger-eventsummary{margin-top:2px;color:#817d74;font-size:10px;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}
        .ledger-eventcompany{display:flex;align-items:center;gap:5px;margin-top:6px;color:#77756d;font-size:9px}
        .ledger-company-avatar{display:grid;place-items:center;width:17px;height:17px;border-radius:3px;color:white;font-size:6px;font-weight:700}
        .ledger-eventchevron{align-self:center;color:#aaa49a;display:flex;justify-content:flex-end}
        .ledger-centerfoot{border-top:1px solid var(--line);padding:11px 15px;display:flex;align-items:center;justify-content:space-between;color:#99948a;font-family:'DM Mono',monospace;font-size:9px}
        .ledger-inspector{padding:18px 16px;display:flex;flex-direction:column}
        .ledger-inspectorhead{display:flex;align-items:center;justify-content:space-between;margin-bottom:21px}
        .ledger-inspectorhead span{font-family:'DM Mono',monospace;font-size:9px;color:#98938a;letter-spacing:.11em;text-transform:uppercase}
        .ledger-inspectorhead button{width:25px;height:25px;border:1px solid #e5dfd5;background:none;border-radius:50%;display:grid;place-items:center;color:#88837a;cursor:pointer}
        .ledger-detailmark{width:40px;height:40px;display:grid;place-items:center;border-radius:5px;background:#f2e6dd;color:#a24839;margin-bottom:13px}
        .ledger-detailtitle{font-family:'Playfair Display',Georgia,serif;font-size:20px;line-height:1.18;font-weight:500;margin:0;color:#302f2a}
        .ledger-detaildate{display:flex;align-items:center;gap:6px;margin-top:9px;color:#8d887e;font-family:'DM Mono',monospace;font-size:9px}
        .ledger-detailcopy{margin:15px 0 18px;padding-bottom:17px;border-bottom:1px solid var(--line);font-size:11px;color:#716e65;line-height:1.65}
        .ledger-companycard{padding:10px;border:1px solid #e8e2d9;border-radius:4px;display:flex;align-items:center;gap:9px}
        .ledger-companycard .ledger-company-mark{width:31px;height:31px;flex-basis:31px}
        .ledger-detailcompany{font-size:10px;font-weight:700;color:#46463f}
        .ledger-detaildomain{font-size:9px;color:#99948b;margin-top:2px}
        .ledger-detailsection{margin-top:19px}
        .ledger-detailrow{display:flex;justify-content:space-between;gap:8px;margin-top:10px;font-size:10px}
        .ledger-detailrow span:first-child{color:#9a958c}
        .ledger-detailrow span:last-child{color:#5f5e56;text-align:right}
        .ledger-reference{font-family:'DM Mono',monospace;font-size:9px!important}
        .ledger-copy{margin-top:8px;border:0;background:none;color:#a24839;font-size:9px;cursor:pointer;padding:0}
        .ledger-inspector-spacer{flex:1;min-height:20px}
        .ledger-auditnote{padding:10px;background:#f5f1e9;border-radius:3px;color:#827c71;font-size:9px;line-height:1.55}
        .ledger-empty{padding:54px 16px;text-align:center;color:#8b867b;font-size:11px}
        .ledger-empty strong{display:block;font-family:'Playfair Display',Georgia,serif;font-size:18px;font-weight:500;color:#46443d;margin-bottom:5px}
        @media(max-width:1040px){.ledger-workspace{grid-template-columns:190px minmax(300px,1fr)}.ledger-inspector{grid-column:2;min-height:300px}.ledger-timeline{max-height:500px}}
        @media(max-width:720px){.ledger{overflow:visible}.ledger-top{padding:0 16px;height:58px;gap:12px}.ledger-brand{min-width:0}.ledger-brandname{font-size:12px}.ledger-nav{display:none}.ledger-presence{display:none}.ledger-topright{gap:10px}.ledger-main{padding:20px 14px 28px}.ledger-heading{align-items:flex-start;flex-direction:column;margin:15px 0 17px}.ledger-title{font-size:31px}.ledger-actions{width:100%}.ledger-actions .ledger-button{flex:1;justify-content:center}.ledger-workspace{grid-template-columns:1fr;gap:10px}.ledger-sidebar{padding:13px}.ledger-companylist{display:flex;overflow-x:auto;margin:10px -2px 0;padding-bottom:3px}.ledger-company{flex:0 0 auto;width:auto;min-width:125px;border:1px solid #ece5da}.ledger-company:first-child{min-width:130px}.ledger-company-copy{max-width:115px}.ledger-side-divider,.ledger-range,.ledger-retention,.ledger-sidebar>.ledger-label:nth-of-type(2){display:none}.ledger-center{grid-row:2}.ledger-toolbar{flex-wrap:wrap}.ledger-search{flex-basis:100%}.ledger-live{margin-left:auto}.ledger-kind{margin-left:auto}.ledger-kind select{max-width:110px}.ledger-timeline{max-height:none;padding:4px 12px 15px}.ledger-inspector{grid-column:auto;grid-row:3;min-height:0}.ledger-centerfoot{font-size:8px}.ledger-event{grid-template-columns:40px 1fr 20px}.ledger-eventsummary{white-space:normal}.ledger-inspector-spacer{display:none}}
        @media(prefers-reduced-motion:reduce){.ledger *{scroll-behavior:auto!important;transition:none!important}}
      `}</style>

      <header className="ledger-top">
        <div className="ledger-brand">
          <div className="ledger-mark">S</div>
          <div className="ledger-brandname">salesman<span>.no</span></div>
        </div>
        <nav className="ledger-nav" aria-label="Hovedmeny">
          <span>Oversikt</span>
          <span>Virksomheter</span>
          <span className="active">Aktivitet</span>
          <span>Innstillinger</span>
        </nav>
        <div className="ledger-topright">
          <div className={`ledger-presence ${paused ? "paused" : ""}`}><i />{paused ? "Oppdatering satt på pause" : "Logg oppdatert nå"}</div>
          <div className="ledger-user" title="Magne Kristoffersen">MK</div>
        </div>
      </header>

      <main className="ledger-main">
        <div className="ledger-breadcrumb"><span>ADMIN</span><Icon name="right" size={11} /><strong>AKTIVITETSARKIV</strong></div>
        <div className="ledger-heading">
          <div>
            <div className="ledger-kicker">Drift · hendelsesarkiv</div>
            <h1 className="ledger-title">Aktivitetshistorikk</h1>
            <div className="ledger-subtitle">Et samlet spor av endringer på tvers av virksomhetene dine.</div>
          </div>
          <div className="ledger-actions">
            <button className="ledger-button" type="button" onClick={() => setPaused((value) => !value)}>
              <Icon name={paused ? "play" : "pause"} size={14} />{paused ? "Gjenoppta" : "Sett på pause"}
            </button>
            <button className="ledger-button export" type="button" onClick={exportLog}>
              <Icon name="download" size={14} />Eksporter CSV
            </button>
          </div>
        </div>

        <section className="ledger-workspace" aria-label="Aktivitetsarkiv">
          <aside className="ledger-panel ledger-sidebar">
            <div className="ledger-label">Utforsk etter virksomhet</div>
            <div className="ledger-companylist">
              {companies.map((item) => (
                <button key={item.name} type="button" onClick={() => setCompany(item.name)} className={`ledger-company ${company === item.name ? "selected" : ""}`}>
                  <span className={`ledger-company-mark ${item.name === "Alle virksomheter" ? "all" : ""}`} style={{ backgroundColor: item.name === "Alle virksomheter" ? undefined : item.color }}>{item.mark}</span>
                  <span className="ledger-company-copy">
                    <span className="ledger-company-name">{item.name}</span>
                    <span className="ledger-company-meta">{item.name === "Alle virksomheter" ? "Samlet logg" : "Virksomhetslogg"}</span>
                  </span>
                  <span className="ledger-count">{item.count}</span>
                </button>
              ))}
            </div>
            <div className="ledger-side-divider" />
            <div className="ledger-label">Tidsrom</div>
            <div className="ledger-range">
              {["24 timer", "7 dager", "30 dager"].map((range) => <button type="button" key={range} onClick={() => setPeriod(range)} className={period === range ? "selected" : ""}>{range}</button>)}
            </div>
            <div className="ledger-retention">
              <b>Arkivet er komplett</b>
              Endringshistorikk bevares også når en virksomhet eller bruker fjernes.
            </div>
          </aside>

          <section className="ledger-panel ledger-center">
            <div className="ledger-toolbar">
              <label className="ledger-search">
                <Icon name="search" size={15} />
                <input aria-label="Søk i historikken" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Søk på aktør, virksomhet eller hendelse" />
              </label>
              <label className="ledger-kind">
                <Icon name="filter" size={14} />
                <select aria-label="Filtrer hendelsestype" value={kind} onChange={(event) => setKind(event.target.value)}>
                  {Object.entries(typeLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
                <Icon name="down" size={12} />
              </label>
              <div className={`ledger-live ${paused ? "paused" : ""}`}><i className="ledger-live-dot" />{paused ? "PAUSET" : "LIVE"}</div>
            </div>

            <div className="ledger-timeline">
              {Object.keys(grouped).length === 0 ? (
                <div className="ledger-empty"><strong>Ingen treff i arkivet</strong>Prøv et annet søk eller vis alle virksomheter.</div>
              ) : Object.entries(grouped).map(([date, events]) => (
                <div className="ledger-dategroup" key={date}>
                  <div className="ledger-datehead"><Icon name="calendar" size={12} />{date}</div>
                  {events.map((event) => (
                    <button type="button" key={event.id} onClick={() => setSelectedId(event.id)} className={`ledger-event ${selected.id === event.id ? "selected" : ""}`}>
                      <span className={`ledger-eventmark ${event.type}`}><Icon name={event.type === "system" ? "globe" : event.type === "sales" ? "check" : event.type === "team" ? "more" : "building"} size={14} /></span>
                      <span className="ledger-eventbody">
                        <span className="ledger-eventtop"><span className="ledger-eventtitle">{event.action}</span><span className="ledger-eventtime">{event.when}</span></span>
                        <span className="ledger-eventsummary">{event.actor} · {event.summary}</span>
                        <span className="ledger-eventcompany"><i className="ledger-company-avatar" style={{ backgroundColor: event.color }}>{event.initials}</i>{event.company}</span>
                      </span>
                      <span className="ledger-eventchevron"><Icon name="right" size={14} /></span>
                    </button>
                  ))}
                </div>
              ))}
            </div>
            <div className="ledger-centerfoot"><span>VISER {filtered.length} AV {activities.length} HENDELSER</span><span>{period.toUpperCase()} · SISTE AKTIVITET FØRST</span></div>
          </section>

          <aside className="ledger-panel ledger-inspector" aria-label="Hendelsesdetaljer">
            {filtered.length === 0 ? (
              <div className="ledger-empty"><strong>Ingen hendelse valgt</strong>Endre søket for å utforske arkivet.</div>
            ) : (
              <>
                <div className="ledger-inspectorhead"><span>Hendelsesdetaljer</span><button type="button" title="Kopier referanse" onClick={copyReference}><Icon name="more" size={15} /></button></div>
                <div className={`ledger-detailmark ${selected.type}`}><Icon name={selected.type === "system" ? "globe" : selected.type === "sales" ? "check" : selected.type === "team" ? "more" : "building"} size={19} /></div>
                <h2 className="ledger-detailtitle">{selected.action}</h2>
                <div className="ledger-detaildate"><Icon name="clock" size={12} />{selected.date} · {selected.time}</div>
                <p className="ledger-detailcopy">{selected.details}</p>
                <div className="ledger-label">Tilknyttet virksomhet</div>
                <div className="ledger-companycard" style={{ marginTop: 8 }}>
                  <span className="ledger-company-mark" style={{ backgroundColor: selected.color }}>{selected.initials}</span>
                  <span><span className="ledger-detailcompany">{selected.company}</span><span className="ledger-detaildomain">{selected.domain}</span></span>
                </div>
                <div className="ledger-detailsection">
                  <div className="ledger-label">Hendelsesdata</div>
                  <div className="ledger-detailrow"><span>Utført av</span><span>{selected.actor}</span></div>
                  <div className="ledger-detailrow"><span>Kategori</span><span>{typeLabels[selected.type]}</span></div>
                  <div className="ledger-detailrow"><span>Referanse</span><span className="ledger-reference">{selected.reference}</span></div>
                  <button className="ledger-copy" type="button" onClick={copyReference}>{copied ? "Referanse kopiert" : "Kopier referanse"}</button>
                </div>
                <div className="ledger-inspector-spacer" />
                <div className="ledger-auditnote">Denne oppføringen er en del av virksomhetens uforanderlige aktivitetslogg.</div>
              </>
            )}
          </aside>
        </section>
      </main>
    </div>
  );
}

export default ActivityLedger;