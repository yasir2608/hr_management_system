"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, Modal, PageHead, Panel, Table, formatDate, formatDateTime, humanize } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

type Detail = {
  user: { id: string; name: string; email: string; status: string; mfa_enabled: boolean; last_login_at: string | null; created_at: string };
  mfa_required_by_company: boolean;
  memberships: { id: string; status: string; joined_at: string | null; company: { id: string; name: string; status: string } | null; roles: string[] }[];
  sign_ins: { id: string; succeeded: boolean; failure_reason: string | null; ip_address: string | null; created_at: string }[];
  staff_activity: { id: string; action: string; description: string; created_at: string; actor: string | null; properties: { reason?: string } | null }[];
};

type Action = "mfa-reset" | "lock" | "unlock";

const ACTIONS: Record<Action, { title: string; button: string; danger: boolean; explain: string }> = {
  "mfa-reset": { title: "Reset two-step sign-in", button: "Reset two-step sign-in", danger: false, explain: "Turns off their own two-step setting and cancels codes already sent. If a company requires two-step sign-in, they will still be asked for a code." },
  lock: { title: "Lock sign-in", button: "Lock sign-in", danger: true, explain: "They are signed out everywhere and cannot sign in to any company until unlocked. Their data is not changed." },
  unlock: { title: "Unlock sign-in", button: "Unlock sign-in", danger: false, explain: "They can sign in again with their existing password." },
};

export default function PlatformUserPage() {
  const { id } = useParams<{ id: string }>();
  const { user: me } = useAuth();
  const isOps = me?.platform_role === "ops";
  const [detail, setDetail] = useState<Detail | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [reason, setReason] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api<Detail>(`/platform/users/${id}`, { noCompany: true }).then(setDetail);
  }, [id]);

  useEffect(load, [load]);

  async function run() {
    if (!action) return;
    setError(null);
    try {
      const result = await api<{ message: string }>(`/platform/users/${id}/${action}`, { method: "POST", body: { reason }, noCompany: true });
      setNotice(result.message);
      setAction(null);
      setReason("");
      load();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Could not complete the action.", 0));
    }
  }

  if (!detail) return <div className="empty">Loading…</div>;
  const { user } = detail;
  const locked = user.status === "disabled";

  return (
    <>
      <PageHead
        eyebrow="Customer user"
        title={user.name}
        subtitle={`${user.email} · account since ${formatDate(user.created_at)}`}
        actions={<>
          <Link className="btn" href="/users">All users</Link>
          {isOps && <button className="btn" onClick={() => setAction("mfa-reset")}>Reset two-step sign-in</button>}
          {isOps && (locked ? <button className="btn primary" onClick={() => setAction("unlock")}>Unlock sign-in</button> : <button className="btn danger" onClick={() => setAction("lock")}>Lock sign-in</button>)}
        </>}
      />
      {notice && <Banner tone="success">{notice}</Banner>}
      {locked && <Banner tone="error">Sign-in is locked. This person cannot open any company.</Banner>}
      <div className="grid two">
        <Panel title="Sign-in">
          <div className="formgrid">
            <Field label="Status"><div><Badge status={locked ? "blocked" : "active"} label={locked ? "Locked" : "Active"} /></div></Field>
            <Field label="Last sign-in"><div>{user.last_login_at ? formatDateTime(user.last_login_at) : "Never"}</div></Field>
            <Field label="Two-step sign-in"><div>{user.mfa_enabled ? "Turned on by them" : "Off"}{detail.mfa_required_by_company && <div className="tiny">Required by a company they belong to</div>}</div></Field>
          </div>
        </Panel>
        <Panel title="Companies" bodyless>
          <Table headers={["Company", "Roles", "Joined", "Access"]}>
            {detail.memberships.map((membership) => (
              <tr key={membership.id}>
                <td>{membership.company ? <Link href={`/companies/${membership.company.id}`}>{membership.company.name}</Link> : "—"}{membership.company?.status === "suspended" && <div className="tiny">Company suspended</div>}</td>
                <td>{membership.roles.join(", ") || "—"}</td>
                <td>{formatDate(membership.joined_at)}</td>
                <td><Badge status={membership.status} /></td>
              </tr>
            ))}
          </Table>
        </Panel>
      </div>
      <div className="grid two">
        <Panel title="Recent sign-in attempts" bodyless>
          {detail.sign_ins.length === 0 ? <EmptyState title="No sign-in attempts" /> : (
            <Table headers={["When", "Result", "IP address"]}>
              {detail.sign_ins.map((attempt) => (
                <tr key={attempt.id}>
                  <td>{formatDateTime(attempt.created_at)}</td>
                  <td>{attempt.succeeded ? <Badge status="active" label="Signed in" /> : <Badge status="failed" label={humanize(attempt.failure_reason ?? "failed")} />}</td>
                  <td>{attempt.ip_address ?? "—"}</td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
        <Panel title="Staff actions" bodyless>
          {detail.staff_activity.length === 0 ? <EmptyState title="No staff actions yet" /> : (
            <Table headers={["When", "Who", "What"]}>
              {detail.staff_activity.map((entry) => (
                <tr key={entry.id}>
                  <td>{formatDateTime(entry.created_at)}</td>
                  <td>{entry.actor ?? "—"}</td>
                  <td>{entry.description}{entry.properties?.reason && <div className="tiny">{entry.properties.reason}</div>}</td>
                </tr>
              ))}
            </Table>
          )}
        </Panel>
      </div>
      {action && (
        <Modal
          title={ACTIONS[action].title}
          onClose={() => { setAction(null); setError(null); }}
          footer={<><button className="btn" onClick={() => setAction(null)}>Cancel</button><button className={`btn ${ACTIONS[action].danger ? "danger" : "primary"}`} onClick={() => void run()}>{ACTIONS[action].button}</button></>}
        >
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="callout">{ACTIONS[action].explain}</div>
          <Field label="Reason" required error={error?.errors.reason?.[0]} hint="E.g. the support ticket. Kept in the platform activity log.">
            <textarea className="input" rows={3} value={reason} onChange={(event) => setReason(event.target.value)} />
          </Field>
        </Modal>
      )}
    </>
  );
}
