"use client";

import * as React from "react";
import Link from "next/link";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { motion } from "framer-motion";
import { Mail, User } from "lucide-react";

import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { AuthBrandMark } from "@/components/auth/auth-brand";
import { IconInput } from "@/components/auth/icon-input";
import { PasswordInput } from "@/components/auth/password-input";
import { AuthShowcasePanel } from "@/components/auth/auth-showcase";

const schema = z
  .object({
    name: z.string().min(1, "Name is required"),
    email: z.string().email("Enter a valid email"),
    password: z.string().min(8, "At least 8 characters"),
    password_confirmation: z.string(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

type FormValues = z.infer<typeof schema>;

export default function RegisterPage() {
  const { register: registerUser } = useAuth();
  const [error, setError] = React.useState<string | null>(null);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setError(null);
    try {
      await registerUser(values);
    } catch (err) {
      const message = isAxiosError(err)
        ? err.response?.data?.message ?? "Could not register."
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
        <div className="hidden lg:block">
          <AuthShowcasePanel ctaHref="/login" ctaLabel="Sign in instead" />
        </div>

        <div className="flex flex-col justify-center p-8 sm:p-10">
          <AuthBrandMark />

          <div className="mt-8">
            <h1 className="text-xl font-semibold text-white">Create account</h1>
            <p className="mt-1 text-sm text-slate-400">
              Register a customer account for Farmsap.
            </p>
          </div>

          <form onSubmit={handleSubmit(onSubmit)} className="mt-6 flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="name" className="text-slate-300">
                Name
              </Label>
              <IconInput
                id="name"
                icon={User}
                autoComplete="name"
                placeholder="Jane Doe"
                {...register("name")}
              />
              {errors.name && <p className="text-xs text-red-400">{errors.name.message}</p>}
            </div>
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
              {errors.email && <p className="text-xs text-red-400">{errors.email.message}</p>}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="password" className="text-slate-300">
                Password
              </Label>
              <PasswordInput
                id="password"
                autoComplete="new-password"
                placeholder="••••••••"
                {...register("password")}
              />
              {errors.password && (
                <p className="text-xs text-red-400">{errors.password.message}</p>
              )}
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="password_confirmation" className="text-slate-300">
                Confirm password
              </Label>
              <PasswordInput
                id="password_confirmation"
                autoComplete="new-password"
                placeholder="••••••••"
                {...register("password_confirmation")}
              />
              {errors.password_confirmation && (
                <p className="text-xs text-red-400">{errors.password_confirmation.message}</p>
              )}
            </div>
            {error && <p className="text-sm text-red-400">{error}</p>}
            <Button
              type="submit"
              disabled={isSubmitting}
              className="mt-2 bg-blue-600 text-white hover:bg-blue-500"
            >
              {isSubmitting ? "Creating account…" : "Create account"}
            </Button>
            <p className="text-center text-sm text-slate-400">
              Already have an account?{" "}
              <Link href="/login" className="text-blue-400 underline-offset-4 hover:underline">
                Sign in
              </Link>
            </p>
          </form>
        </div>
      </motion.div>
    </div>
  );
}
