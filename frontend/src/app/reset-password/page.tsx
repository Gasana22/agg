"use client";

import * as React from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { isAxiosError } from "axios";
import { motion } from "framer-motion";

import { api } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";

const schema = z
  .object({
    password: z.string().min(8, "Password must be at least 8 characters"),
    password_confirmation: z.string().min(1, "Please confirm your password"),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: "Passwords do not match",
    path: ["password_confirmation"],
  });

type FormValues = z.infer<typeof schema>;

export default function ResetPasswordPage() {
  return (
    <React.Suspense fallback={null}>
      <ResetPasswordForm />
    </React.Suspense>
  );
}

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get("token") ?? "";
  const email = searchParams.get("email") ?? "";

  const [error, setError] = React.useState<string | null>(null);
  const [done, setDone] = React.useState(false);
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<FormValues>({ resolver: zodResolver(schema) });

  const onSubmit = async (values: FormValues) => {
    setError(null);
    try {
      await api.post("/auth/reset-password", {
        token,
        email,
        password: values.password,
        password_confirmation: values.password_confirmation,
      });
      setDone(true);
      setTimeout(() => router.push("/login"), 2000);
    } catch (err) {
      const message = isAxiosError(err)
        ? err.response?.data?.message ?? "Could not reset password."
        : "Something went wrong.";
      setError(message);
    }
  };

  return (
    <div className="flex flex-1 items-center justify-center bg-muted/40 p-6">
      <motion.div
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25 }}
        className="w-full max-w-sm"
      >
        <Card>
          <CardHeader>
            <CardTitle className="text-xl">Set a new password</CardTitle>
            <CardDescription>{email ? `Resetting the password for ${email}.` : "Choose a new password."}</CardDescription>
          </CardHeader>
          <CardContent>
            {!token || !email ? (
              <p className="text-sm text-destructive">
                This reset link is missing information. Request a new one from the{" "}
                <Link href="/forgot-password" className="text-primary underline-offset-4 hover:underline">
                  forgot password
                </Link>{" "}
                page.
              </p>
            ) : done ? (
              <p className="text-sm text-muted-foreground">
                Password reset. Taking you to sign in…
              </p>
            ) : (
              <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="password">New password</Label>
                  <Input id="password" type="password" autoComplete="new-password" {...register("password")} />
                  {errors.password && (
                    <p className="text-xs text-destructive">{errors.password.message}</p>
                  )}
                </div>
                <div className="flex flex-col gap-1.5">
                  <Label htmlFor="password_confirmation">Confirm password</Label>
                  <Input
                    id="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    {...register("password_confirmation")}
                  />
                  {errors.password_confirmation && (
                    <p className="text-xs text-destructive">{errors.password_confirmation.message}</p>
                  )}
                </div>
                {error && <p className="text-sm text-destructive">{error}</p>}
                <Button type="submit" disabled={isSubmitting} className="mt-2">
                  {isSubmitting ? "Resetting…" : "Reset password"}
                </Button>
              </form>
            )}
          </CardContent>
        </Card>
      </motion.div>
    </div>
  );
}
