import React, { lazy, Suspense } from 'react';
import { BrowserRouter, Routes, Route, Navigate, useLocation } from 'react-router-dom';
import { LanguageProvider } from './context/LanguageContext';
import { AuthProvider } from './context/AuthContext';
import { SessionProvider } from './context/SessionContext';
import { Navbar } from './components/layout/Navbar';
import { Footer } from './components/layout/Footer';
import { Toast } from './components/layout/Toast';
import { AuthModal } from './components/auth/AuthModal';
import { SubscribeModal } from './components/common/SubscribeModal';
import { Loader2 } from 'lucide-react';

// 页面级代码分割:每个页面独立 chunk,按路由按需加载
const LandingPage = lazy(() => import('./pages/LandingPage').then((m) => ({ default: m.LandingPage })));
const WorkbenchPage = lazy(() => import('./pages/WorkbenchPage').then((m) => ({ default: m.WorkbenchPage })));
const PricingPage = lazy(() => import('./pages/PricingPage').then((m) => ({ default: m.PricingPage })));
const ProfileOverviewPage = lazy(() => import('./pages/ProfileOverviewPage').then((m) => ({ default: m.ProfileOverviewPage })));
const ProfileHistoryPage = lazy(() => import('./pages/ProfileHistoryPage').then((m) => ({ default: m.ProfileHistoryPage })));
const PaymentCallbackPage = lazy(() => import('./pages/PaymentCallbackPage').then((m) => ({ default: m.PaymentCallbackPage })));
const LegalPage = lazy(() => import('./pages/LegalPage').then((m) => ({ default: m.LegalPage })));
const SysAdminPage = lazy(() => import('./pages/SysAdminPage').then((m) => ({ default: m.SysAdminPage })));

/** 路由 chunk 加载中的兜底视图 */
const RouteFallback: React.FC = () => (
  <div className="min-h-[60vh] flex items-center justify-center">
    <div className="flex items-center gap-2 text-sm font-semibold text-[#65718a]">
      <Loader2 className="w-4 h-4 animate-spin text-brand" />
      <span>加载中…</span>
    </div>
  </div>
);

/** 站点外壳:/sysAdmin 为独立后台布局,不渲染前台导航与页脚 */
const AppShell: React.FC = () => {
  const { pathname } = useLocation();
  const isAdmin = pathname.startsWith('/sysAdmin');

  return (
    <div className="min-h-screen flex flex-col bg-[#f5f7fb] text-[#1b2230]">
      {!isAdmin && <Navbar />}
      <div className="flex-1">
        <Suspense fallback={<RouteFallback />}>
          <Routes>
            <Route path="/" element={<LandingPage />} />
            <Route path="/chat" element={<WorkbenchPage />} />
            <Route path="/chat/:sessionId" element={<WorkbenchPage />} />
            <Route path="/pricing" element={<PricingPage />} />
            <Route path="/profile" element={<ProfileOverviewPage />} />
            <Route path="/profile/history" element={<ProfileHistoryPage />} />
            <Route path="/payment/callback" element={<PaymentCallbackPage />} />
            <Route path="/legal" element={<Navigate to="/legal/register" replace />} />
            <Route path="/legal/:doc" element={<LegalPage />} />
            <Route path="/sysAdmin" element={<SysAdminPage />} />
            <Route path="/sysAdmin/:section" element={<SysAdminPage />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Routes>
        </Suspense>
      </div>

      {/* Global Footer */}
      {!isAdmin && <Footer />}

      {/* Global Overlays */}
      <AuthModal />
      <SubscribeModal />
      <Toast />
    </div>
  );
};

export const App: React.FC = () => {
  return (
    <BrowserRouter>
      <LanguageProvider>
        <AuthProvider>
          <SessionProvider>
            <AppShell />
          </SessionProvider>
        </AuthProvider>
      </LanguageProvider>
    </BrowserRouter>
  );
};
