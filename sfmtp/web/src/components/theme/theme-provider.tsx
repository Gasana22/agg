"use client";

import * as React from "react";

type Theme = "light" | "dark" | "system";
const STORAGE_KEY = "sfmtp-theme";
const CHANGE_EVENT = "sfmtp-theme-change";

/** Inline script (in <head>) that applies the theme before first paint. */
export const themeScript = `(()=>{try{var t=localStorage.getItem('${STORAGE_KEY}')||'system';var d=t==='dark'||(t==='system'&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.classList.toggle('dark',d)}catch(e){}})()`;

function readTheme(): Theme {
  try {
    const value = localStorage.getItem(STORAGE_KEY);
    return value === "light" || value === "dark" ? value : "system";
  } catch {
    return "system";
  }
}

function apply(theme: Theme) {
  const dark = theme === "dark" || (theme === "system" && window.matchMedia("(prefers-color-scheme: dark)").matches);
  document.documentElement.classList.toggle("dark", dark);
}

function subscribe(onChange: () => void) {
  const media = window.matchMedia("(prefers-color-scheme: dark)");
  const update = () => {
    apply(readTheme());
    onChange();
  };
  media.addEventListener("change", update);
  window.addEventListener("storage", update); // other tabs
  window.addEventListener(CHANGE_EVENT, update);
  return () => {
    media.removeEventListener("change", update);
    window.removeEventListener("storage", update);
    window.removeEventListener(CHANGE_EVENT, update);
  };
}

const ThemeContext = React.createContext<{ theme: Theme; setTheme: (t: Theme) => void }>({
  theme: "system",
  setTheme: () => {},
});

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  // localStorage is the source of truth; the server always renders "system".
  const theme = React.useSyncExternalStore(subscribe, readTheme, () => "system" as Theme);

  const setTheme = React.useCallback((next: Theme) => {
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {}
    apply(next);
    window.dispatchEvent(new Event(CHANGE_EVENT));
  }, []);

  const value = React.useMemo(() => ({ theme, setTheme }), [theme, setTheme]);

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  return React.useContext(ThemeContext);
}
