"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { Banner, Field } from "@/components/ui";
import { api, ApiError } from "@/lib/api";

export default function ResetPasswordPage() {
  const [link, setLink] = useState<{ token: string; email: string } | null>(null);
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [done, setDone] = useState<string | null>(null);
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the link is only readable in the browser
    setLink({ token: params.get("token") ?? "", email: params.get("email") ?? "" });
  }, []);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!link) return;
    setSubmitting(true);
    setError(null);
    try {
      const result = await api<{ message: string }>("/auth/reset-password", {
        body: { ...link, password, password_confirmation: confirmation },
        noCompany: true,
      });
      setDone(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Something went wrong.", 0));
    } finally {
      setSubmitting(false);
    }
  }

  const invalidLink = link !== null && (!link.token || !link.email);

  return (
    <div className="authwrap">
      <form className="panel authcard" onSubmit={submit} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>MY</div>
          <h2>Choose a new password</h2>
          {link?.email && <div className="tiny">For {link.email}</div>}
        </div>
        {done ? (
          <div className="stack">
            <Banner tone="success">{done}</Banner>
            <Link className="btn primary block" href="/login" style={{ textAlign: "center", textDecoration: "none" }}>Sign in</Link>
          </div>
        ) : invalidLink ? (
          <div className="stack">
            <Banner tone="error">This reset link is incomplete. Request a new one.</Banner>
            <Link className="btn block" href="/forgot-password" style={{ textAlign: "center", textDecoration: "none" }}>Request a new link</Link>
          </div>
        ) : (
          <div className="stack">
            {error && (
              <Banner tone="error">
                {error.message}{" "}
                {error.code === "RESET_LINK_INVALID" && <Link className="link" href="/forgot-password">Request a new link</Link>}
              </Banner>
            )}
            <Field label="New password" error={error?.errors.password?.[0]} hint="At least 10 characters, with letters and numbers.">
              <input className="input" type="password" autoComplete="new-password" value={password} onChange={(event) => setPassword(event.target.value)} autoFocus />
            </Field>
            <Field label="Confirm new password">
              <input className="input" type="password" autoComplete="new-password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
            </Field>
            <button className="btn primary block" type="submit" disabled={submitting || !password || password !== confirmation}>
              {submitting ? "Saving…" : "Change password"}
            </button>
            {confirmation && password !== confirmation && <div className="fielderror">The passwords do not match.</div>}
          </div>
        )}
      </form>
    </div>
  );
}
