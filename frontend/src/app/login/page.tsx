"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { Banner, Field } from "@/components/ui";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";

function nextPath() {
  const next = new URLSearchParams(window.location.search).get("next");
  // Only same-site paths, never an absolute URL taken from the query string.
  return next && next.startsWith("/") && !next.startsWith("//") ? next : "/dashboard";
}

function asApiError(caught: unknown) {
  return caught instanceof ApiError ? caught : new ApiError("Something went wrong. Please try again.", 0);
}

export default function LoginPage() {
  const { status, user, company, signIn, verifyMfa } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<ApiError | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [sessionEnded, setSessionEnded] = useState(false);
  const [challenge, setChallenge] = useState<{ challengeId: string; message: string } | null>(null);
  const [code, setCode] = useState("");
  const [ssoNotice, setSsoNotice] = useState<string | null>(null);

  useEffect(() => {
    try {
      if (window.sessionStorage.getItem("myhrms.sessionEnded")) {
        window.sessionStorage.removeItem("myhrms.sessionEnded");
        // eslint-disable-next-line react-hooks/set-state-in-effect -- read once from browser storage
        setSessionEnded(true);
      }
    } catch {
      /* storage unavailable */
    }
  }, []);

  useEffect(() => {
    if (status !== "authenticated") return;
    // People in several companies choose one first; one company opens straight away.
    router.replace(company ? nextPath() : user?.platform_role ? "/platform/companies" : "/select-company");
  }, [status, user, company, router]);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      const result = await signIn(email, password);
      if (result.mfa) {
        setChallenge(result.mfa);
        setSubmitting(false);
      }
    } catch (caught) {
      setError(asApiError(caught));
      setSubmitting(false);
    }
  }

  async function continueWithSso() {
    setError(null);
    setSsoNotice(null);
    if (!email) {
      setSsoNotice("Enter your work email first, then choose Continue with company SSO.");
      return;
    }
    try {
      const result = await api<{ available: boolean; message?: string; display_name?: string; redirect_url?: string }>("/auth/sso/discover", {
        body: { email },
        noCompany: true,
      });
      if (result.available && result.redirect_url) {
        setSsoNotice(`Opening ${result.display_name}…`);
        window.location.assign(result.redirect_url);
      } else {
        setSsoNotice(result.message ?? "Single sign-on is not available for this email.");
      }
    } catch (caught) {
      setError(asApiError(caught));
    }
  }

  async function submitCode(event: React.FormEvent) {
    event.preventDefault();
    if (!challenge) return;
    setSubmitting(true);
    setError(null);
    try {
      await verifyMfa(challenge.challengeId, code);
    } catch (caught) {
      const apiError = asApiError(caught);
      setError(apiError);
      setSubmitting(false);
      if (apiError.code === "MFA_CHALLENGE_EXPIRED") {
        setChallenge(null);
        setCode("");
      }
    }
  }

  if (challenge) {
    return (
      <div className="authwrap">
        <form className="panel authcard" onSubmit={submitCode} noValidate>
          <div className="authhead">
            <div className="logo" style={{ margin: "auto" }}>MY</div>
            <h2>Verify your sign-in</h2>
            <div className="tiny">{challenge.message}</div>
          </div>
          {error && <Banner tone="error">{error.message}</Banner>}
          <div className="stack">
            <Field label="6-digit code" error={error?.errors.code?.[0]}>
              <input
                className="input"
                inputMode="numeric"
                autoComplete="one-time-code"
                maxLength={6}
                value={code}
                onChange={(event) => setCode(event.target.value.replace(/\D/g, ""))}
                style={{ letterSpacing: 6, fontSize: 18, textAlign: "center" }}
                autoFocus
              />
            </Field>
            <button className="btn primary block" type="submit" disabled={submitting || code.length !== 6}>
              {submitting ? "Checking…" : "Verify and sign in"}
            </button>
            <button type="button" className="link" style={{ textAlign: "center" }} onClick={() => { setChallenge(null); setCode(""); setError(null); }}>
              Use a different account
            </button>
          </div>
        </form>
      </div>
    );
  }

  return (
    <div className="authwrap">
      <form className="panel authcard" onSubmit={submit} noValidate>
        <div className="authhead">
          <div className="logo" style={{ margin: "auto" }}>MY</div>
          <h2>Welcome back</h2>
          <div className="tiny">Sign in to your M.Y HRMS workspace</div>
        </div>
        {sessionEnded && !error && <Banner tone="info">Your session ended, either after a period of inactivity or because your password changed. Sign in again to continue.</Banner>}
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
          <button className="btn block" type="button" onClick={() => void continueWithSso()} disabled={submitting}>
            Continue with company SSO
          </button>
          {ssoNotice && <div className="tiny" style={{ textAlign: "center" }}>{ssoNotice}</div>}
          <div className="tiny" style={{ textAlign: "center" }}>
            New to M.Y HRMS? <Link className="link" href="/register">Set up a company workspace</Link>
          </div>
        </div>
      </form>
    </div>
  );
}
