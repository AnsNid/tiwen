import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import { UserProfile, PlanId } from '../types';
import { api, getToken, setToken, clearToken } from '../api/client';

interface AuthContextType {
  user: UserProfile | null;
  isAuthenticated: boolean;
  isAuthModalOpen: boolean;
  authMode: 'login' | 'register' | 'reset';
  authReason: string;
  openAuthModal: (reason?: string, mode?: 'login' | 'register' | 'reset') => void;
  closeAuthModal: () => void;
  setAuthMode: (mode: 'login' | 'register' | 'reset') => void;
  loginWithPassword: (email: string, password: string) => Promise<{ success: boolean; message?: string }>;
  registerWithCode: (email: string, code: string, password?: string) => Promise<{ success: boolean; message?: string }>;
  sendVerificationCode: (email: string) => Promise<{ success: boolean; message?: string }>;
  sendResetCode: (email: string) => Promise<{ success: boolean; message?: string }>;
  resetPassword: (email: string, code: string, password: string) => Promise<{ success: boolean; message?: string }>;
  logout: () => void;
  refreshUser: () => Promise<void>;
  upgradePlan: (plan: PlanId) => void;
  consumeTokens: (amount: number) => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<UserProfile | null>(null);
  const [restoring, setRestoring] = useState<boolean>(() => !!getToken());

  const [isAuthModalOpen, setIsAuthModalOpen] = useState(false);
  const [authMode, setAuthMode] = useState<'login' | 'register' | 'reset'>('login');
  const [authReason, setAuthReason] = useState('登录以享受完整 AI 多模型协同功能');

  // 启动时用 token 恢复登录态
  useEffect(() => {
    let cancelled = false;
    if (!getToken()) {
      setRestoring(false);
      return;
    }
    (async () => {
      const res = await api<UserProfile>('/tiwen/me');
      if (cancelled) return;
      if (res.ok && res.data) {
        setUser(res.data);
      } else {
        clearToken();
      }
      setRestoring(false);
    })();
    return () => { cancelled = true; };
  }, []);

  const openAuthModal = (reason = '登录以享受完整 AI 多模型协同功能', mode: 'login' | 'register' | 'reset' = 'login') => {
    setAuthReason(reason);
    setAuthMode(mode);
    setIsAuthModalOpen(true);
  };

  const closeAuthModal = () => {
    setIsAuthModalOpen(false);
  };

  const sendVerificationCode = async (email: string): Promise<{ success: boolean; message?: string }> => {
    const res = await api('/tiwen/sendCode', { method: 'POST', json: { email } });
    return { success: res.ok, message: res.message };
  };

  const sendResetCode = async (email: string): Promise<{ success: boolean; message?: string }> => {
    const res = await api('/tiwen/resetCode', { method: 'POST', json: { email } });
    return { success: res.ok, message: res.message };
  };

  const resetPassword = async (email: string, code: string, password: string): Promise<{ success: boolean; message?: string }> => {
    if (!password || password.length < 6) {
      return { success: false, message: '请设置 6 位以上新密码' };
    }
    if (!code || code.length !== 6) {
      return { success: false, message: '请输入 6 位邮箱验证码' };
    }
    const res = await api('/tiwen/resetPassword', { method: 'POST', json: { email, code, password } });
    if (!res.ok) {
      return { success: false, message: res.message || '密码重置失败' };
    }
    return { success: true, message: res.message };
  };

  const loginWithPassword = async (email: string, password: string): Promise<{ success: boolean; message?: string }> => {
    if (!password || password.length < 6) {
      return { success: false, message: '密码不能少于 6 位' };
    }
    const res = await api<{ token: string; user: UserProfile }>('/tiwen/login', {
      method: 'POST',
      json: { email, password },
    });
    if (!res.ok || !res.data) {
      return { success: false, message: res.message || '登录失败' };
    }
    setToken(res.data.token);
    setUser(res.data.user);
    setIsAuthModalOpen(false);
    return { success: true };
  };

  const registerWithCode = async (email: string, code: string, password?: string): Promise<{ success: boolean; message?: string }> => {
    if (!password || password.length < 6) {
      return { success: false, message: '请设置 6 位以上登录密码' };
    }
    if (!code || code.length !== 6) {
      return { success: false, message: '请输入 6 位邮箱验证码' };
    }
    const res = await api<{ token: string; user: UserProfile }>('/tiwen/register', {
      method: 'POST',
      json: { email, code, password },
    });
    if (!res.ok) {
      return { success: false, message: res.message || '注册失败' };
    }
    if (res.data?.token) {
      setToken(res.data.token);
      setUser(res.data.user);
      setIsAuthModalOpen(false);
      return { success: true };
    }
    // 注册成功但自动登录失败(少见),引导去登录页
    setAuthMode('login');
    return { success: true, message: '注册成功,请使用密码登录' };
  };

  const logout = () => {
    clearToken();
    setUser(null);
  };

  const refreshUser = useCallback(async () => {
    if (!getToken()) return;
    const res = await api<UserProfile>('/tiwen/me');
    if (res.ok && res.data) {
      setUser(res.data);
    } else if (res.code === 401) {
      clearToken();
      setUser(null);
    }
  }, []);

  const upgradePlan = (plan: PlanId) => {
    if (!user) return;
    setUser({ ...user, plan });
    refreshUser();
  };

  const consumeTokens = (amount: number) => {
    if (!user || !user.tokenQuota || user.tokenQuota.total <= 0) return;
    setUser({
      ...user,
      tokenQuota: {
        ...user.tokenQuota,
        used: Math.min(user.tokenQuota.total, user.tokenQuota.used + amount),
      },
    });
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        isAuthenticated: !!user,
        isAuthModalOpen,
        authMode,
        authReason,
        openAuthModal,
        closeAuthModal,
        setAuthMode,
        loginWithPassword,
        registerWithCode,
        sendVerificationCode,
        sendResetCode,
        resetPassword,
        logout,
        refreshUser,
        upgradePlan,
        consumeTokens
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) throw new Error('useAuth must be used within an AuthProvider');
  return context;
};
