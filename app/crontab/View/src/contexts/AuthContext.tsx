import React, { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { apiGet, resolveToken, setSavedToken, clearSavedToken, requestParentToken } from "../lib/api";
import type { User } from "../types";

interface AuthContextType {
  token: string | null;
  user: User | null;
  isLoading: boolean;
  login: (token: string, user: User) => void;
  logout: () => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

// 模块级 Promise,防止 StrictMode 双挂载时重复请求用户信息
let fetchUserPromise: Promise<User> | null = null;

export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [token, setToken] = useState<string | null>(() => resolveToken() || null);
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    let alive = true;

    async function initAuth() {
      // 1. 同步解析本地/同源/URL Token
      let currentToken = resolveToken();

      // 2. 若无 Token 且处于跨域 iframe 干净 URL 下，通过 postMessage 向父节点索取
      if (!currentToken && typeof window !== "undefined" && window.parent && window.parent !== window) {
        currentToken = await requestParentToken(600);
      }

      if (!alive) return;

      if (currentToken) {
        setSavedToken(currentToken);
        setToken(currentToken);
        fetchUserPromise ??= apiGet<User>("/getUserInfo");
        fetchUserPromise
          .then((info) => {
            if (alive) setUser(info);
          })
          .catch(() => {
            if (alive) {
              clearSavedToken();
              setToken(null);
              setUser(null);
            }
          })
          .finally(() => {
            fetchUserPromise = null;
            if (alive) setIsLoading(false);
          });
      } else {
        setIsLoading(false);
      }
    }

    initAuth();

    const handleExpired = () => {
      clearSavedToken();
      setToken(null);
      setUser(null);
    };
    window.addEventListener("token-expired", handleExpired);
    return () => {
      alive = false;
      window.removeEventListener("token-expired", handleExpired);
    };
  }, []);

  const login = (newToken: string, newUser: User) => {
    setSavedToken(newToken);
    setToken(newToken);
    setUser(newUser);
  };

  const logout = () => {
    clearSavedToken();
    setToken(null);
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ token, user, isLoading, login, logout }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
};
