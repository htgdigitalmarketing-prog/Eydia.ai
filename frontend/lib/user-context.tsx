"use client";

import { createContext, useContext } from "react";
import { User } from "@/lib/api";

export const UserContext = createContext<User | null>(null);

export function useUser(): User {
  const user = useContext(UserContext);
  if (!user) throw new Error("useUser must be used inside the app layout");
  return user;
}
