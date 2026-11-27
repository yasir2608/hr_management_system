"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, Panel } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Activation = {
  status: string;
  can_activate: boolean;
  reason: string | null;
  required_open: { id: string; title: string; status: string }[];
  payroll_ready: boolean;
  payroll_missing: { key: string; label: string }[];
};

/** Activation is gated by required onboarding tasks; payroll readiness is shown next to it, separately. */
export function ActivationPanel({ employeeId, refreshKey, onActivated }: { employeeId: string; refreshKey: number; onActivated: () => void }) {
  const [activation, setActivation] = useState<Activation | null>(null);
  const [message, setMessage] = useState<{ tone: "success" | "error"; text: string } | null>(null);

  const load = useCallback(async () => {
    setActivation(await api<Activation>(`/onboarding/employees/${employeeId}/activation`));
  }, [employeeId]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- refresh when tasks change
    void load();
  }, [load, refreshKey]);

  async function activate() {
    try {
      const result = await api<{ message: string }>(`/onboarding/employees/${employeeId}/activate`, { method: "POST" });
      setMessage({ tone: "success", text: result.message });
      await load();
      onActivated();
    } catch (caught) {
      setMessage({ tone: "error", text: caught instanceof ApiError ? caught.message : "Could not activate." });
    }
  }

  if (!activation || !["invited", "onboarding"].includes(activation.status)) {
    return message ? <Banner tone={message.tone}>{message.text}</Banner> : null;
  }

  return (
    <div className="grid split">
      <Panel title="Activation" right={activation.can_activate ? <Badge tone="green" label="Ready" /> : <Badge tone="amber" label="Not yet" />}>
        {message && <Banner tone={message.tone}>{message.text}</Banner>}
        {activation.can_activate ? (
          <div className="stack">
            <span className="muted">All required onboarding tasks are done.</span>
            <div><button className="btn primary" onClick={() => void activate()}>Mark as active</button></div>
          </div>
        ) : (
          <>
            <div className="tiny" style={{ marginBottom: 6 }}>{activation.reason}</div>
            <ul style={{ margin: 0, paddingLeft: 18 }}>{activation.required_open.map((task) => <li key={task.id} className="tiny">{task.title}</li>)}</ul>
          </>
        )}
      </Panel>
      <Panel title="Payroll readiness" subtitle="Separate from activation." right={activation.payroll_ready ? <Badge tone="green" label="Ready" /> : <Badge tone="amber" label="Missing information" />}>
        {activation.payroll_ready ? <span className="muted">Everything payroll needs is in place.</span> : (
          <ul style={{ margin: 0, paddingLeft: 18 }}>{activation.payroll_missing.map((item) => <li key={item.key} className="tiny">{item.label}</li>)}</ul>
        )}
      </Panel>
    </div>
  );
}
