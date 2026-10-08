import React, { Component, ErrorInfo, ReactNode } from "react";
import { AlertTriangle } from "lucide-react";

interface Props {
  children?: ReactNode;
}

interface State {
  hasError: boolean;
  error?: Error;
}

export class ErrorBoundary extends React.Component<Props, State> {
  public state: State = {
    hasError: false,
  };

  public static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error };
  }

  public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    console.error("Uncaught error:", error, errorInfo);
  }

  public render() {
    if (this.state.hasError) {
      return (
        <div className="flex min-h-screen items-center justify-center bg-page p-4">
          <div className="flex w-full max-w-md flex-col items-center gap-4 rounded-2xl border border-line bg-surface p-8 text-center shadow-lifted">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-down-soft text-down">
              <AlertTriangle className="h-6 w-6" />
            </span>
            <h2 className="text-lg font-semibold text-ink">页面发生错误</h2>
            <p className="text-sm leading-relaxed text-ink-faint">
              {this.state.error?.message || "发生未知错误，请刷新重试。"}
            </p>
            <button
              onClick={() => window.location.reload()}
              className="mt-2 rounded-xl bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-accent-strong"
            >
              刷新页面
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
