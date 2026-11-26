"use client";

import { useEffect, useState } from "react";

/**
 * A tab kept in the `?tab=` query string, so links can open a specific tab.
 */
export function useTab(keys: string[], fallback = keys[0]) {
  const [active, setActive] = useState(fallback);

  useEffect(() => {
    const requested = new URLSearchParams(window.location.search).get("tab");
    // eslint-disable-next-line react-hooks/set-state-in-effect -- the query string is only readable in the browser
    if (requested && keys.includes(requested)) setActive(requested);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- read once on first load
  }, []);

  function choose(key: string) {
    setActive(key);
    const url = new URL(window.location.href);
    url.searchParams.set("tab", key);
    window.history.replaceState(null, "", url.toString());
  }

  return [active, choose] as const;
}
