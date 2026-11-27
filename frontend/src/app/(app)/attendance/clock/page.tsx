"use client";

import { useCallback, useEffect, useState } from "react";
import { Guard } from "@/components/Guard";
import { Badge, Banner, EmptyState, Field, PageHead, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Shift = { id: string; clock_in_local: string; clock_out_local: string | null; minutes: number; note: string | null };
type Status = { enabled: boolean; timezone: string; local_time: string; clocked_in: boolean; open_shift: Shift | null; minutes_today: number };

function hours(minutes: number) {
  return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, "0")}m`;
}

function ClockPage() {
  const [status, setStatus] = useState<Status | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [now, setNow] = useState<Date>(new Date());
  const [note, setNote] = useState("");
  const [confirmation, setConfirmation] = useState<{ tone: "success" | "error"; text: string } | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api<Status>("/me/clock").then(setStatus).catch((caught) => setProblem(caught instanceof ApiError ? caught.message : "Could not load."));
  }, []);

  useEffect(load, [load]);

  useEffect(() => {
    const timer = window.setInterval(() => setNow(new Date()), 15000);
    return () => window.clearInterval(timer);
  }, []);

  async function press(action: "in" | "out") {
    setBusy(true);
    try {
      const result = await api<Status & { message: string }>(`/me/clock/${action}`, { body: { note: note || null } });
      setStatus(result);
      setConfirmation({ tone: "success", text: result.message });
      setNote("");
    } catch (caught) {
      setConfirmation({ tone: "error", text: caught instanceof ApiError ? caught.message : "That did not work." });
      load();
    } finally {
      setBusy(false);
    }
  }

  if (problem) return <><PageHead eyebrow="Time & attendance" title="Clock in and out" /><EmptyState icon="◷" title={problem} /></>;
  if (!status) return <div className="loadingpage">Loading…</div>;

  const localTime = now.toLocaleTimeString([], { timeZone: status.timezone, hour: "2-digit", minute: "2-digit" });
  const localDate = now.toLocaleDateString([], { timeZone: status.timezone, weekday: "long", day: "numeric", month: "long" });

  return (
    <>
      <PageHead eyebrow="Time & attendance" title="Clock in and out" subtitle="Your working time is recorded in your location's time zone." />
      {confirmation && <Banner tone={confirmation.tone}>{confirmation.text}</Banner>}
      {!status.enabled ? (
        <Panel><EmptyState icon="◷" title="Your company does not record attendance in M.Y HRMS" /></Panel>
      ) : (
        <div className="grid two">
          <Panel title={localDate} subtitle={status.timezone} right={status.clocked_in ? <Badge tone="green" label="Clocked in" /> : <Badge tone="gray" label="Not clocked in" />}>
            <div style={{ textAlign: "center", padding: "10px 0 18px" }}>
              <div style={{ fontSize: 46, fontWeight: 750, letterSpacing: -1 }}>{localTime}</div>
              {status.open_shift && <div className="tiny">Since {status.open_shift.clock_in_local.slice(11)} · {hours(status.open_shift.minutes)} so far</div>}
            </div>
            <div className="stack">
              <Field label="Note" optional><input className="input" value={note} onChange={(event) => setNote(event.target.value)} placeholder="e.g. Working from the client site" /></Field>
              {status.clocked_in ? (
                <button className="btn danger block" style={{ padding: 14 }} disabled={busy} onClick={() => void press("out")}>Clock out</button>
              ) : (
                <button className="btn primary block" style={{ padding: 14 }} disabled={busy} onClick={() => void press("in")}>Clock in</button>
              )}
            </div>
          </Panel>
          <Panel title="Today">
            <div className="statnum">{hours(status.minutes_today)}</div>
            <div className="tiny">Worked today, including the current shift.</div>
          </Panel>
        </div>
      )}
    </>
  );
}

export default function Page() {
  return (
    <Guard permissions={["self.attendance.clock"]}>
      <ClockPage />
    </Guard>
  );
}
