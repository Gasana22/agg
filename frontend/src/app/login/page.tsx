"use client";

import * as React from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { motion } from "framer-motion";
import { Mail } from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { AuthBrandMark } from "@/components/auth/auth-brand";
import { IconInput } from "@/components/auth/icon-input";
import { PasswordInput } from "@/components/auth/password-input";
import { AuthShowcasePanel } from "@/components/auth/auth-showcase";

const schema = z.object({
  email: z.string().email("Enter a valid email"),
  password: z.string().min(1, "Password is required"),
});

type FormValues = z.infer<typeof schema>;

export default function LoginPage() {
  const { login } = useAuth();
  const [error, setError] = React.useState<string | null>(null);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setError(null);
    try {
      await login(values.email, values.password);
    } catch (err) {
      const message = isAxiosError(err)
        ? err.response?.data?.message ?? "Invalid credentials."
        : "Something went wrong.";
      setError(message);
    }
  };

  return (
    <div className="flex flex-1 items-center justify-center bg-slate-950 p-4 sm:p-6">
      <motion.div
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25 }}
        className="grid w-full max-w-4xl overflow-hidden rounded-2xl border border-slate-800 bg-slate-900 shadow-2xl lg:grid-cols-2"
      >
        <div className="flex flex-col justify-center p-8 sm:p-10">
          <AuthBrandMark />

          <div className="mt-8">
            <h1 className="text-xl font-semibold text-white">Sign in</h1>
            <p className="mt-1 text-sm text-slate-400">
              Welcome back. Sign in to your Farmsap account.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="mt-6 flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="email" className="text-slate-300">
                Email
              </Label>
              <IconInput
                id="email"
                type="email"
                icon={Mail}
                autoComplete="email"
                placeholder="you@example.com"
                {...register("email")}
              />
              {errors.email && (
                <p className="text-xs text-red-400">{errors.email.message}</p>
              )}
            </div>
            <div className="flex flex-col gap-1.5">
              <div className="flex items-center justify-between">
                <Label htmlFor="password" className="text-slate-300">
                  Password
                </Label>
                <Link
                  href="/forgot-password"
                  className="text-xs text-blue-400 underline-offset-4 hover:underline"
                >
                  Forgot password?
                </Link>
              </div>
              <PasswordInput
                id="password"
                autoComplete="current-password"
                placeholder="••••••••"
                {...register("password")}
              />
              {errors.password && (
                <p className="text-xs text-red-400">{errors.password.message}</p>
              )}
            </div>

            <label className="flex items-center gap-2 text-sm text-slate-400">
              <input
                type="checkbox"
                className="size-4 rounded border-slate-700 bg-slate-900 accent-blue-500"
              />
              Remember me
            </label>

            {error && <p className="text-sm text-red-400">{error}</p>}
            <Button
              type="submit"
              disabled={isSubmitting}
              className="mt-2 bg-blue-600 text-white hover:bg-blue-500"
            >
              {isSubmitting ? "Signing in…" : "Sign in"}
            </Button>
            <p className="text-center text-sm text-slate-400">
              No account?{" "}
              <Link href="/register" className="text-blue-400 underline-offset-4 hover:underline">
                Register
              </Link>
            </p>
          </form>
        </div>

        <div className="hidden lg:block">
          <AuthShowcasePanel ctaHref="/register" ctaLabel="Create an account" />
        </div>
      </motion.div>
    </div>
  );
}
