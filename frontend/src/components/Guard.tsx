"use client";

import Link from "next/link";
import { useAuth } from "@/lib/auth";

/**
 * Renders the page only for people holding one of the permissions. Others see a helpful
 * message and no protected data (the API refuses the data as well).
 */
export function Guard({ permissions, children }: { permissions: string[]; children: React.ReactNode }) {
  const { access, can } = useAuth();

  if (access === null) return <div className="loadingpage">Loading…</div>;

  if (!can(...permissions)) return <NoAccess />;

  return <>{children}</>;
}

export function NoAccess({ message }: { message?: string }) {
  return (
    <div className="panel">
      <div className="empty">
        <div className="emptyicon">⚿</div>
        <h3>You do not have access to this page</h3>
        <p>{message ?? "Your role does not include this area. If you need it for your work, ask your company administrator to change your access."}</p>
        <Link className="btn" href="/dashboard">Back to dashboard</Link>
      </div>
    </div>
  );
}
