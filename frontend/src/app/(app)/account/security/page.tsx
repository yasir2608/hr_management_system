"use client";

import { useEffect, useState } from "react";
import { Badge, Banner, EmptyState, formatDateTime, PageHead, Panel, Table } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

type Attempt = {
  id: string;
  succeeded: boolean;
  failure_reason: string | null;
  ip_address: string | null;
  user_agent: string | null;
  created_at: string;
};

const reasons: Record<string, string> = {
  wrong_password: "Wrong password",
  unknown_email: "Unknown email",
  account_disabled: "Account disabled",
  too_many_attempts: "Blocked: too many attempts",
};

function browser(userAgent: string | null) {
  if (!userAgent) return "Unknown device";
  const match = /(Edg|Chrome|Firefox|Safari)\/[\d.]+/.exec(userAgent);
  const os = /Windows|Mac OS X|Android|iPhone|Linux/.exec(userAgent)?.[0] ?? "";
  return [match?.[1] === "Edg" ? "Edge" : match?.[1], os.replace("Mac OS X", "macOS")].filter(Boolean).join(" on ") || userAgent.slice(0, 40);
}

export default function SecurityPage() {
  const [attempts, setAttempts] = useState<Attempt[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Attempt[] }>("/auth/sign-ins", { noCompany: true })
      .then((result) => setAttempts(result.data))
      .catch((caught) => setError(caught instanceof ApiError ? caught.message : "Could not load sign-in activity."));
  }, []);

  const [mfa, setMfa] = useState<{ mfa_enabled: boolean; required_by_company: boolean } | null>(null);

  useEffect(() => {
    api<{ mfa_enabled: boolean; required_by_company: boolean }>("/auth/mfa", { noCompany: true }).then(setMfa).catch(() => undefined);
  }, []);

  async function toggleMfa(enabled: boolean) {
    setMfa(await api("/auth/mfa", { method: "PUT", body: { mfa_enabled: enabled }, noCompany: true }));
    setNotice(enabled ? "Two-step verification is on. You will be asked for a code next time you sign in." : "Two-step verification is off.");
  }

  async function signOutOthers() {
    const result = await api<{ message: string }>("/auth/logout-others", { method: "POST", noCompany: true });
    setNotice(result.message);
  }

  return (
    <>
      <PageHead
        eyebrow="Account"
        title="Sign-in & security"
        subtitle="Review recent sign-ins to your account. If you do not recognise one, reset your password and sign out other devices."
        actions={<button className="btn" onClick={() => void signOutOthers()}>Sign out other devices</button>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {error && <Banner tone="error">{error}</Banner>}
      <div style={{ marginBottom: 15 }}>
        <Panel title="Two-step verification" subtitle="Ask for a 6-digit code from your email each time you sign in.">
          {mfa === null ? (
            <span className="muted">Loading…</span>
          ) : mfa.required_by_company ? (
            <div className="callout blue">One of your companies requires two-step verification, so it is always on for your account.</div>
          ) : (
            <label className="checkrow">
              <input type="checkbox" checked={mfa.mfa_enabled} onChange={(event) => void toggleMfa(event.target.checked)} />
              Require a sign-in code sent to my email
            </label>
          )}
        </Panel>
      </div>
      <Panel title="Recent sign-in activity" subtitle="The last 50 attempts, newest first." bodyless>
        {attempts === null && !error ? (
          <div className="empty">Loading…</div>
        ) : attempts && attempts.length === 0 ? (
          <EmptyState title="No sign-in activity yet" />
        ) : (
          <Table headers={["When", "Result", "Device", "IP address"]}>
            {attempts?.map((attempt) => (
              <tr key={attempt.id}>
                <td>{formatDateTime(attempt.created_at)}</td>
                <td>
                  {attempt.succeeded ? <Badge tone="green" label="Signed in" /> : <Badge tone="red" label={reasons[attempt.failure_reason ?? ""] ?? "Failed"} />}
                </td>
                <td>{browser(attempt.user_agent)}</td>
                <td className="muted">{attempt.ip_address ?? "—"}</td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}
