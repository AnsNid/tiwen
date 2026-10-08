import { Navigate, Outlet, Route, Routes } from "react-router-dom";
import { useAuth } from "./contexts/AuthContext";
import Dashboard from "./pages/Dashboard";
import Login from "./pages/Login";

const ProtectedRoute = () => {
  const { token, user, isLoading } = useAuth();
  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-page">
        <div className="flex items-center gap-3 text-sm text-ink-faint">
          <span className="h-5 w-5 animate-spin rounded-full border-2 border-accent/25 border-t-accent" />
          正在恢复会话…
        </div>
      </div>
    );
  }
  // token 有效但用户信息拉取失败时视为未登录,避免进入"有 token 无身份"的脏状态
  if (!token || !user) {
    return <Navigate to="/login" replace />;
  }
  return <Outlet />;
};

export default function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route element={<ProtectedRoute />}>
        <Route path="/" element={<Dashboard />} />
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  );
}
