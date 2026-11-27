"use client";

import { useEffect, useState } from "react";
import { EmptyState, Panel } from "@/components/ui";
import { api } from "@/lib/api";

type Entry = { id: string; start_date: string; end_date: string; start_half: boolean; end_half: boolean; status: string; leave_type: { name: string; color: string } | null };
type Calendar = { month: string; days_in_month: number; people: { id: string; full_name: string; is_self: boolean; leave: Entry[] }[] };

function shiftMonth(month: string, by: number) {
  const [year, value] = month.split("-").map(Number);
  const date = new Date(year, value - 1 + by, 1);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

/** Month view: one row per person, a coloured cell for each day of leave. */
export function CalendarTab() {
  const [month, setMonth] = useState(new Date().toISOString().slice(0, 7));
  const [pending, setPending] = useState(false);
  const [calendar, setCalendar] = useState<Calendar | null>(null);

  useEffect(() => {
    api<Calendar>("/leave/calendar", { query: { month, include_pending: pending ? 1 : 0 } }).then(setCalendar);
  }, [month, pending]);

  const days = calendar ? Array.from({ length: calendar.days_in_month }, (_, index) => index + 1) : [];
  const label = new Date(`${month}-01T00:00:00`).toLocaleDateString(undefined, { month: "long", year: "numeric" });

  const entryOn = (entries: Entry[], day: number) => {
    const date = `${month}-${String(day).padStart(2, "0")}`;
    return entries.find((entry) => entry.start_date <= date && entry.end_date >= date);
  };

  return (
    <Panel
      title={`Leave calendar · ${label}`}
      right={
        <div className="row">
          <label className="checkrow"><input type="checkbox" checked={pending} onChange={(event) => setPending(event.target.checked)} /> Show requests waiting for approval</label>
          <button className="btn" onClick={() => setMonth(shiftMonth(month, -1))}>‹ Previous</button>
          <button className="btn" onClick={() => setMonth(new Date().toISOString().slice(0, 7))}>This month</button>
          <button className="btn" onClick={() => setMonth(shiftMonth(month, 1))}>Next ›</button>
        </div>
      }
      bodyless
    >
      {calendar && calendar.people.length === 0 ? (
        <EmptyState icon="☀" title="Nobody to show" />
      ) : (
        <div className="tablewrap">
          <table style={{ tableLayout: "fixed" }}>
            <thead>
              <tr>
                <th style={{ width: 170 }}>Person</th>
                {days.map((day) => {
                  const weekday = new Date(`${month}-${String(day).padStart(2, "0")}T00:00:00`).getDay();
                  return <th key={day} style={{ padding: "8px 2px", textAlign: "center", width: 26, color: weekday === 0 || weekday === 6 ? "#b8c1d1" : undefined }}>{day}</th>;
                })}
              </tr>
            </thead>
            <tbody>
              {calendar?.people.map((person) => (
                <tr key={person.id}>
                  <td style={{ fontWeight: person.is_self ? 700 : undefined }}>{person.full_name}{person.is_self && " (you)"}</td>
                  {days.map((day) => {
                    const entry = entryOn(person.leave, day);
                    return (
                      <td key={day} style={{ padding: 2 }} title={entry ? `${entry.leave_type?.name}${entry.status !== "approved" ? " (waiting)" : ""}` : undefined}>
                        {entry && <div style={{ height: 18, borderRadius: 4, background: entry.leave_type?.color ?? "#315ee8", opacity: entry.status === "approved" ? 1 : 0.4 }} />}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Panel>
  );
}
