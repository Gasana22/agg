"use client";

import * as React from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { isAxiosError } from "axios";
import { AuthProvider } from "@/lib/auth-context";
import { FarmProvider } from "@/lib/farm-context";

export function Providers({ children }: { children: React.ReactNode }) {
  const [queryClient] = React.useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // A 4xx (403 lacking permission, 404 not found, ...) won't
            // succeed on retry -- only a network blip or 5xx might. Retrying
            // client errors anyway is what left restricted pages spinning
            // on "Loading..." for ~20s of backoff before ever reaching an
            // error state a page could render something useful for.
            retry: (failureCount, error) => {
              if (isAxiosError(error) && error.response && error.response.status < 500) {
                return false;
              }
              return failureCount < 3;
            },
          },
        },
      })
  );

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <FarmProvider>{children}</FarmProvider>
      </AuthProvider>
    </QueryClientProvider>
  );
}
