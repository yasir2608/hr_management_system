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
