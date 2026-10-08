"use client";

import { ThemeProvider } from "next-themes";
import { useEffect, useState } from "react";

export function Providers({ children }: { children: React.ReactNode }) {
  // Removing the mounted check allows next-themes to inject its inline script instantly
  // which completely eliminates the Flash of Unstyled Content (FOUC).
  return (
    <ThemeProvider attribute="class" defaultTheme="dark" enableSystem={false}>
      {children}
    </ThemeProvider>
  );
}
