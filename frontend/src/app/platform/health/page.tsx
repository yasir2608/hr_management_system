"use client";

import { useCallback, useEffect, useState } from "react";
import { Badge, Banner, EmptyState, Field, PageHead, Panel, Table, formatDateTime } from "@/components/ui";
import { api } from "@/lib/api";

type Check = { ok: boolean; detail: string; ms?: number; writable?: boolean };

type Health = {
  checks: Record<"database" | "cache" | "queue" | "storage", Check>;
  migrations: { ran: number; pending: string[] };
  failed_jobs: { id: number; queue: string; failed_at: string; error: string }[];
  app: { name: string; environment: string; debug: boolean; version: string | null; laravel: string; php: string; timezone: string; checked_at: string };
};

const LABELS: Record<keyof Health["checks"], string> = { database: "Database", cache: "Cache", queue: "Background jobs", storage: "File storage" };

export default function PlatformHealthPage() {
  const [health, setHealth] = useState<Health | null>(null);

  const load = useCallback(() => {
    api<Health>("/platform/health", { noCompany: true }).then(setHealth);
  }, []);

  useEffect(load, [load]);

  if (!health) return <div className="empty">Checking…</div>;
  const problems = Object.values(health.checks).filter((check) => !check.ok).length + (health.migrations.pending.length > 0 ? 1 : 0);

  return (
    <>
      <PageHead
        eyebrow="Staff console"
        title="System health"
        subtitle={`Checked ${formatDateTime(health.app.checked_at)}. These checks only read; nothing is changed.`}
        actions={<button className="btn" onClick={load}>Check again</button>}
      />
      {problems === 0 ? <Banner tone="success">Everything looks healthy.</Banner> : <Banner tone="error">{problems} item{problems === 1 ? " needs" : "s need"} attention.</Banner>}
      <div className="grid two">
        <Panel title="Services" bodyless>
          <Table headers={["Service", "Status", "Detail"]}>
            {(Object.keys(LABELS) as (keyof Health["checks"])[]).map((key) => {
              const check = health.checks[key];
              return (
                <tr key={key}>
                  <td><strong>{LABELS[key]}</strong></td>
                  <td><Badge status={check.ok ? "active" : "failed"} label={check.ok ? "Healthy" : "Problem"} /></td>
                  <td>{check.detail}{check.ms !== undefined && <span className="tiny"> · {check.ms} ms</span>}{check.writable === false && <div className="tiny">Storage folder is not writable</div>}</td>
                </tr>
              );
            })}
          </Table>
        </Panel>
        <Panel title="Application">
          <div className="formgrid">
            <Field label="Version"><div>{health.app.version ?? "—"}</div></Field>
            <Field label="Environment"><div>{health.app.environment}{health.app.debug && <Badge tone="amber" label="Debug on" />}</div></Field>
            <Field label="Laravel"><div>{health.app.laravel}</div></Field>
            <Field label="PHP"><div>{health.app.php}</div></Field>
            <Field label="Server time zone"><div>{health.app.timezone}</div></Field>
            <Field label="Migrations run"><div>{health.migrations.ran}</div></Field>
          </div>
        </Panel>
      </div>
      <Panel title="Database migrations" subtitle="Migrations are never run from here. A developer runs them on the server." bodyless={health.migrations.pending.length > 0}>
        {health.migrations.pending.length === 0 ? <span className="muted">All {health.migrations.ran} migrations have been run.</span> : (
          <Table headers={[`${health.migrations.pending.length} not yet run`]}>
            {health.migrations.pending.map((name) => <tr key={name}><td><code>{name}</code></td></tr>)}
          </Table>
        )}
      </Panel>
      <Panel title="Failed background jobs" subtitle="The 10 most recent." bodyless>
        {health.failed_jobs.length === 0 ? <EmptyState title="No failed jobs" /> : (
          <Table headers={["Failed", "Queue", "Error"]}>
            {health.failed_jobs.map((job) => (
              <tr key={job.id}>
                <td style={{ whiteSpace: "nowrap" }}>{formatDateTime(job.failed_at)}</td>
                <td>{job.queue}</td>
                <td><code style={{ whiteSpace: "pre-wrap" }}>{job.error}</code></td>
              </tr>
            ))}
          </Table>
        )}
      </Panel>
    </>
  );
}
