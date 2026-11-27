import type { ReactNode } from "react";

/**
 * Page title row: title, muted subtitle, actions on the right. The small label above the title (eyebrow) is part of
 * the staff console's navy design; the company design hides it (its breadcrumb already names the area).
 */
export function PageHead({ eyebrow, title, subtitle, actions }: { eyebrow: string; title: string; subtitle?: string; actions?: ReactNode }) {
  return (
    <div className="pagehead">
      <div>
        <div className="eyebrow">{eyebrow}</div>
        <h1>{title}</h1>
        {subtitle && <p className="subtitle">{subtitle}</p>}
      </div>
      {actions && <div className="actions">{actions}</div>}
    </div>
  );
}

export function Panel({ title, subtitle, right, children, bodyless }: { title?: ReactNode; subtitle?: ReactNode; right?: ReactNode; children?: ReactNode; bodyless?: boolean }) {
  return (
    <section className="panel">
      {title !== undefined && (
        <div className="panelhd">
          <div>
            <h2>{title}</h2>
            {subtitle && <p>{subtitle}</p>}
          </div>
          {right}
        </div>
      )}
      {bodyless ? children : <div className="panelbody">{children}</div>}
    </section>
  );
}

const badgeTones: Record<string, string> = {
  active: "green", approved: "green", ready: "green", paid: "green", complete: "green", completed: "green", accepted: "green", locked: "green",
  onboarding: "blue", on_leave: "blue", invited: "blue", submitted: "blue", calculated: "blue",
  pending: "amber", pending_review: "amber", needs_attention: "amber", needs_information: "amber", awaiting_approval: "amber", due_soon: "amber", warning: "amber", suspended: "amber", received: "amber", in_review: "amber",
  overdue: "red", blocked: "red", declined: "red", rejected: "red", disabled: "red", failed: "red", expired: "red", ended: "gray", removed: "gray", revoked: "gray",
  draft: "gray", cancelled: "gray", withdrawn: "gray",
};

export function humanize(value: string) {
  const text = value.replace(/[_.]/g, " ");
  return text.charAt(0).toUpperCase() + text.slice(1);
}

export function Badge({ status, label, tone }: { status?: string; label?: string; tone?: string }) {
  const key = (status ?? "").toLowerCase();
  return <span className={`badge ${tone ?? badgeTones[key] ?? "gray"}`}>{label ?? humanize(key)}</span>;
}

/** A metric card: label with an optional icon badge, a large value and a note, as in the design's dashboard. */
export function Stat({ label, value, note, good, icon }: { label: string; value: ReactNode; note?: ReactNode; good?: boolean; icon?: string }) {
  return (
    <div className="panel stat">
      <div className="statlabel"><span>{label}</span>{icon && <span className="staticon">{icon}</span>}</div>
      <div className="statnum">{value}</div>
      {note && <div className={`statnote${good ? " good" : ""}`}>{note}</div>}
    </div>
  );
}

export function EmptyState({ icon = "○", title, children, action }: { icon?: string; title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="empty">
      <div className="emptyicon">{icon}</div>
      <h3>{title}</h3>
      {children && <p>{children}</p>}
      {action}
    </div>
  );
}

export function Banner({ tone, children }: { tone: "error" | "success" | "info"; children: ReactNode }) {
  return <div className={`banner ${tone}`} role={tone === "error" ? "alert" : "status"}>{children}</div>;
}

export function Field({ label, error, required, optional, hint, children, full }: { label: string; error?: string; required?: boolean; optional?: boolean; hint?: ReactNode; children: ReactNode; full?: boolean }) {
  return (
    <div className={`field${full ? " full" : ""}`}>
      <label>
        {label} {required && <span className="req" title="Required">*</span>}
        {optional && <span className="tiny">(optional)</span>}
      </label>
      {children}
      {hint && !error && <div className="tiny">{hint}</div>}
      {error && <div className="fielderror">{error}</div>}
    </div>
  );
}

export function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join("");
}

export function Person({ name, detail }: { name: string; detail?: ReactNode }) {
  return (
    <div className="person">
      <div className="mini">{initials(name)}</div>
      <div>
        <strong>{name}</strong>
        {detail && <div className="tiny">{detail}</div>}
      </div>
    </div>
  );
}

export function Modal({ title, children, footer, onClose }: { title: string; children: ReactNode; footer?: ReactNode; onClose: () => void }) {
  return (
    <div className="modalback show" role="dialog" aria-modal="true" aria-label={title} onClick={(event) => event.target === event.currentTarget && onClose()}>
      <div className="modal">
        <div className="modalhead">
          <h2>{title}</h2>
          <button className="close" onClick={onClose} aria-label="Close">×</button>
        </div>
        <div className="modalbody">{children}</div>
        {footer && <div className="modalfoot">{footer}</div>}
      </div>
    </div>
  );
}

export function Table({ headers, children }: { headers: ReactNode[]; children: ReactNode }) {
  return (
    <div className="tablewrap">
      <table>
        <thead>
          <tr>{headers.map((header, index) => <th key={index}>{header}</th>)}</tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
    </div>
  );
}

export function formatDate(value?: string | null) {
  if (!value) return "—";
  const date = new Date(value.length === 10 ? `${value}T00:00:00` : value);
  return date.toLocaleDateString(undefined, { day: "numeric", month: "short", year: "numeric" });
}

export function formatDateTime(value?: string | null) {
  if (!value) return "—";
  return new Date(value).toLocaleString(undefined, { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });
}

export function money(amount: number | string | null | undefined, currency = "USD") {
  if (amount === null || amount === undefined || amount === "") return "—";
  return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(Number(amount));
}

/**
 * Shows a sensitive value, or a "Restricted" marker when the API withheld it
 * because the viewer lacks the payroll-level permission.
 */
export function Sensitive({ field, restricted, children }: { field: string; restricted?: string[]; children: ReactNode }) {
  if (restricted?.includes(field)) {
    return (
      <span className="badge gray" title="Needs payroll-level permission">
        Restricted
      </span>
    );
  }
  return <>{children}</>;
}
