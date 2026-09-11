"use client";

import * as React from "react";
import { useRouter } from "next/navigation";
import { api } from "@/lib/api";

export type SfmtpUser = {
  id: number;
  name: string;
  email: string;
  phone: string | null;
};

type AuthState = {
  user: SfmtpUser | null;
  roles: string[];
  loading: boolean;
};

type AuthContextValue = AuthState & {
  login: (email: string, password: string) => Promise<void>;
  register: (data: {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
};

const AuthContext = React.createContext<AuthContextValue | null>(null);

const TOKEN_KEY = "sfmtp_token";

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [state, setState] = React.useState<AuthState>({
    user: null,
    roles: [],
    loading: true,
  });
  const router = useRouter();

  const loadMe = React.useCallback(async () => {
    const token = window.localStorage.getItem(TOKEN_KEY);
    if (!token) {
      setState({ user: null, roles: [], loading: false });
      return;
    }
    try {
      const { data } = await api.get("/auth/me");
      setState({ user: data.user, roles: data.roles, loading: false });
    } catch {
      window.localStorage.removeItem(TOKEN_KEY);
      setState({ user: null, roles: [], loading: false });
    }
  }, []);

  React.useEffect(() => {
    loadMe();
  }, [loadMe]);

  const login = React.useCallback(
    async (email: string, password: string) => {
      const { data } = await api.post("/auth/login", { email, password });
      window.localStorage.setItem(TOKEN_KEY, data.access_token);
      setState({ user: data.user, roles: data.roles, loading: false });
      router.push("/dashboard");
    },
    [router]
  );

  const register = React.useCallback(
    async (payload: {
      name: string;
      email: string;
      password: string;
      password_confirmation: string;
    }) => {
      const { data } = await api.post("/auth/register", payload);
      window.localStorage.setItem(TOKEN_KEY, data.access_token);
      setState({ user: data.user, roles: data.roles, loading: false });
      router.push("/dashboard");
    },
    [router]
  );

  const logout = React.useCallback(async () => {
    try {
      await api.post("/auth/logout");
    } finally {
      window.localStorage.removeItem(TOKEN_KEY);
      setState({ user: null, roles: [], loading: false });
      router.push("/login");
    }
  }, [router]);

  const value = React.useMemo(
    () => ({ ...state, login, register, logout }),
    [state, login, register, logout]
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = React.useContext(AuthContext);
  if (!ctx) {
    throw new Error("useAuth must be used within AuthProvider");
  }
  return ctx;
}
