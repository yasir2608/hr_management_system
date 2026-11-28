"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { api } from "@/lib/api";

type Result = { type: "employee" | "user"; id: string; title: string; subtitle: string | null; href: string };

/**
 * Workspace search. The API only returns records the user may see, so nothing
 * protected can appear here, not even as a count.
 */
export function SearchBox() {
  const [term, setTerm] = useState("");
  const [results, setResults] = useState<Result[] | null>(null);
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (term.trim().length < 2) return;
    const timer = window.setTimeout(() => {
      api<{ data: Result[] }>("/search", { query: { q: term.trim() } })
        .then((result) => setResults(result.data))
        .catch(() => setResults([]));
    }, 250);
    return () => window.clearTimeout(timer);
  }, [term]);

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (box.current && !box.current.contains(event.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  const showing = open && term.trim().length >= 2 && results !== null;

  return (
    <div ref={box} className="topsearch">
      <input
        className="input"
        type="search"
        placeholder="Search people…"
        aria-label="Search people"
        value={term}
        onFocus={() => setOpen(true)}
        onChange={(event) => {
          setTerm(event.target.value);
          setOpen(true);
        }}
      />
      {showing && (
        <div className="menu" role="listbox" style={{ left: 0, right: "auto" }}>
          {results.length === 0 && <div className="tiny" style={{ padding: "8px 10px" }}>No matches you have access to.</div>}
          {results.map((result) => (
            <Link key={`${result.type}-${result.id}`} href={result.href} onClick={() => setOpen(false)}>
              <strong>{result.title}</strong>
              <div className="tiny">
                {result.type === "user" ? "User" : "Employee"}
                {result.subtitle ? ` · ${result.subtitle}` : ""}
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
