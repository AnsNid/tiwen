import React from "react";
import { HashRouter } from "react-router-dom";
import { Toaster } from "react-hot-toast";
import { MotionConfig } from "motion/react";
import { AuthProvider } from "./contexts/AuthContext";
import { ConfigProvider } from "./contexts/ConfigContext";
import { ErrorBoundary } from "./components/ErrorBoundary";
import AppRoutes from "./AppRoutes";

export default function App() {
  return (
    <ErrorBoundary>
      <MotionConfig reducedMotion="user">
        <HashRouter>
          <ConfigProvider>
            <AuthProvider>
              <AppRoutes />
            </AuthProvider>
          </ConfigProvider>
        </HashRouter>
        {/* Toast 全局配置,取色自设计令牌 */}
        <Toaster
          position="top-center"
          toastOptions={{
            duration: 3000,
            style: {
              background: "var(--color-ink)",
              color: "var(--color-ink-inverse)",
              borderRadius: "12px",
              fontSize: "13px",
            },
          }}
        />
      </MotionConfig>
    </ErrorBoundary>
  );
}
