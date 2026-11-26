"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Banner, Field } from "@/components/ui";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

function nextPath() {
  const next = new URLSearchParams(window.location.search).get("next");
  // Only same-site paths, never an absolute URL taken from the query string.
  return next && next.startsWith("/") && !next.startsWith("//") ? next : "/dashboard";
}

export default function LoginPage() {
  const { status, company, signIn } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status !== "authenticated") return;
    // People in several companies choose one first; one company opens straight away.
    router.replace(company ? nextPath() : "/select-company");
  }, [status, company, router]);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await signIn(email, password);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : new ApiError("Something went wrong. Please try again.", 0));
      setSubmitting(false);
    }
  }

  return (
    <div className="authwrap">
      <form className="panel authcard" onSubmit={submit} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>MY</div>
          <h2>Welcome back</h2>
          <div className="tiny">Sign in to your M.Y HRMS workspace</div>
        </div>
        {error && (
          <Banner tone="error">
            {error.message}
            {error.code === "ACCOUNT_LOCKED" && (
              <>
                {" "}
                <Link className="link" href="/forgot-password">Reset your password</Link>
              </>
            )}
          </Banner>
        )}
        <div className="stack">
          <Field label="Email address" error={error?.errors.email?.[0]}>
            <input className="input" type="email" autoComplete="username" value={email} onChange={(event) => setEmail(event.target.value)} required autoFocus />
          </Field>
          <Field label="Password" error={error?.errors.password?.[0]}>
            <input className="input" type="password" autoComplete="current-password" value={password} onChange={(event) => setPassword(event.target.value)} required />
          </Field>
          <div className="row">
            <span />
            <Link className="link" href="/forgot-password">Forgot password?</Link>
          </div>
          <button className="btn primary block" type="submit" disabled={submitting || !email || !password}>
            {submitting ? "Signing in…" : "Sign in"}
          </button>
        </div>
      </form>
    </div>
  );
}
