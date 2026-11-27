"use client";

import Link from "next/link";
import { useState } from "react";
import { Banner, Field } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState("");
  const [sent, setSent] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const result = await api<{ message: string }>("/auth/forgot-password", { body: { email }, noCompany: true });
      setSent(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Something went wrong.", 0));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="authwrap">
      <form className="panel authcard" onSubmit={submit} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>✦</div>
          <h2>Reset password</h2>
          <div className="tiny">Enter the email address you use to sign in.</div>
        </div>
        {sent ? (
          <div className="stack">
            <Banner tone="success">{sent}</Banner>
            <Link className="btn block" href="/login" style={{ textAlign: "center", textDecoration: "none" }}>Back to sign in</Link>
          </div>
        ) : (
          <div className="stack">
            {error && <Banner tone="error">{error.message}</Banner>}
            <Field label="Email address" error={error?.errors.email?.[0]}>
              <input className="input" type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} autoFocus />
            </Field>
            <button className="btn primary block" type="submit" disabled={submitting || !email}>
              {submitting ? "Sending…" : "Send reset link"}
            </button>
            <Link className="link" href="/login" style={{ textAlign: "center" }}>Back to sign in</Link>
          </div>
        )}
      </form>
    </div>
  );
}
