"use client";

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from "react";
import { api, ApiError, onApiError, session } from "./api";
import type { CompanyChoice, SessionUser } from "./types";

type Status = "loading" | "guest" | "authenticated";

type SignInResponse = { token: string; user: SessionUser } | { mfa_required: true; challenge_id: string; message: string };

/** Either signed in, or a code is needed to finish signing in. */
export type SignInResult = { user: SessionUser; mfa?: undefined } | { mfa: { challengeId: string; message: string }; user?: undefined };

type AuthState = {
  status: Status;
  user: SessionUser | null;
  company: CompanyChoice | null;
  signIn: (email: string, password: string) => Promise<SignInResult>;
  verifyMfa: (challengeId: string, code: string) => Promise<void>;
  signOut: () => Promise<void>;
  enterCompany: (companyId: string) => void;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

const COMPANY_ACCESS_CODES = ["COMPANY_ACCESS_DENIED", "MEMBERSHIP_SUSPENDED", "COMPANY_SUSPENDED", "COMPANY_REQUIRED"];

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<Status>("loading");
  const [user, setUser] = useState<SessionUser | null>(null);
  const [companyId, setCompanyId] = useState<string | null>(null);

  const resetToGuest = useCallback(() => {
    session.clear();
    setUser(null);
    setCompanyId(null);
    setStatus("guest");
  }, []);

  const adopt = useCallback((next: SessionUser) => {
    setUser(next);
    const stored = session.companyId();
    const enterable = next.companies.filter((company) => company.can_enter);
    const keep = enterable.find((company) => company.id === stored);
    const chosen = keep?.id ?? (enterable.length === 1 ? enterable[0].id : null);
    session.setCompanyId(chosen);
    setCompanyId(chosen);
    setStatus("authenticated");
  }, []);

  const refresh = useCallback(async () => {
    if (!session.token()) return resetToGuest();
    try {
      adopt(await api<SessionUser>("/auth/me", { noCompany: true }));
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) resetToGuest();
      else setStatus("guest");
    }
  }, [adopt, resetToGuest]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- restoring the stored session on first load
    void refresh();
  }, [refresh]);

  useEffect(
    () =>
      onApiError((error) => {
        if (error.status === 401) {
          // Tell the sign-in page why the person was sent back to it.
          if (session.token()) {
            try {
              window.sessionStorage.setItem("myhrms.sessionEnded", "1");
            } catch {
              /* ignore */
            }
          }
          resetToGuest();
          return;
        }
        // Access to the open company was refused (removed, suspended, or a tampered id):
        // leave the company and send the person back to the picker with the reason.
        if (error.code && COMPANY_ACCESS_CODES.includes(error.code)) {
          try {
            window.sessionStorage.setItem("myhrms.companyNotice", error.message);
          } catch {
            /* ignore */
          }
          session.setCompanyId(null);
          setCompanyId(null);
          void refresh();
        }
      }),
    [resetToGuest, refresh],
  );

  const signIn = useCallback(
    async (email: string, password: string): Promise<SignInResult> => {
      const result = await api<SignInResponse>("/auth/login", { body: { email, password }, noCompany: true });
      if ("mfa_required" in result) return { mfa: { challengeId: result.challenge_id, message: result.message } };
      session.setToken(result.token);
      adopt(result.user);
      return { user: result.user };
    },
    [adopt],
  );

  const verifyMfa = useCallback(
    async (challengeId: string, code: string) => {
      const result = await api<{ token: string; user: SessionUser }>("/auth/mfa/verify", {
        body: { challenge_id: challengeId, code },
        noCompany: true,
      });
      session.setToken(result.token);
      adopt(result.user);
    },
    [adopt],
  );

  const signOut = useCallback(async () => {
    await api("/auth/logout", { method: "POST", noCompany: true }).catch(() => undefined);
    resetToGuest();
  }, [resetToGuest]);

  const enterCompany = useCallback((id: string) => {
    session.setCompanyId(id);
    setCompanyId(id);
  }, []);

  const value = useMemo<AuthState>(
    () => ({
      status,
      user,
      company: user?.companies.find((company) => company.id === companyId) ?? null,
      signIn,
      verifyMfa,
      signOut,
      enterCompany,
      refresh,
    }),
    [status, user, companyId, signIn, verifyMfa, signOut, enterCompany, refresh],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const value = useContext(AuthContext);
  if (!value) throw new Error("useAuth must be used inside AuthProvider");
  return value;
}
