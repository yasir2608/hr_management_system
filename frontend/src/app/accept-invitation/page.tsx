"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Banner, Field } from "@/components/ui";
import { api, ApiError, session } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import type { SessionUser } from "@/lib/types";

type Invitation = { email: string; company: string; role: string; invited_by: string | null; has_account: boolean };

export default function AcceptInvitationPage() {
  const { refresh } = useAuth();
  const router = useRouter();
  const [token, setToken] = useState<string | null>(null);
  const [invitation, setInvitation] = useState<Invitation | null>(null);
  const [problem, setProblem] = useState<string | null>(null);
  const [form, setForm] = useState({ name: "", password: "", password_confirmation: "" });
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const value = new URLSearchParams(window.location.search).get("token") ?? "";
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the link is only readable in the browser
    setToken(value);
    api<Invitation>("/invitations/accept", { query: { token: value }, noCompany: true })
      .then(setInvitation)
      .catch((caught) => setProblem(caught instanceof ApiError ? caught.message : "This invitation could not be opened."));
  }, []);

  async function accept(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const result = await api<{ token: string; user: SessionUser }>("/invitations/accept", { body: { token, ...form }, noCompany: true });
      session.setToken(result.token);
      const joined = result.user.companies.find((company) => company.name === invitation?.company);
      session.setCompanyId(joined?.id ?? null);
      await refresh();
      router.replace("/dashboard");
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Something went wrong.", 0));
      setSubmitting(false);
    }
  }

  return (
    <div className="authwrap">
      <form className="panel authcard" onSubmit={accept} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>MY</div>
          <h2>Accept your invitation</h2>
          {invitation && (
            <div className="tiny">
              {invitation.invited_by ?? "Your company"} invited you to join <strong>{invitation.company}</strong> as {invitation.role}.
            </div>
          )}
        </div>
        {problem && (
          <div className="stack">
            <Banner tone="error">{problem}</Banner>
            <Link className="btn block" style={{ textAlign: "center" }} href="/login">Go to sign in</Link>
          </div>
        )}
        {!problem && !invitation && <div className="empty">Checking your invitation…</div>}
        {invitation && (
          <div className="stack">
            {error && <Banner tone="error">{error.message}</Banner>}
            <Field label="Email address">
              <input className="input" value={invitation.email} disabled />
            </Field>
            {invitation.has_account ? (
              <Field label="Your M.Y HRMS password" error={error?.errors.password?.[0]} hint="You already have an account. Confirm it to add this company.">
                <input className="input" type="password" autoComplete="current-password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
              </Field>
            ) : (
              <>
                <Field label="Your name" required error={error?.errors.name?.[0]}>
                  <input className="input" value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} autoComplete="name" />
                </Field>
                <Field label="Choose a password" required error={error?.errors.password?.[0]} hint="At least 10 characters, with letters and numbers.">
                  <input className="input" type="password" autoComplete="new-password" value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} />
                </Field>
                <Field label="Confirm password" required>
                  <input className="input" type="password" autoComplete="new-password" value={form.password_confirmation} onChange={(event) => setForm({ ...form, password_confirmation: event.target.value })} />
                </Field>
              </>
            )}
            <button className="btn primary block" type="submit" disabled={submitting}>{submitting ? "Joining…" : "Accept and continue"}</button>
          </div>
        )}
      </form>
    </div>
  );
}
