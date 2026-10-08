import { useState } from "react";
import { useNavigate } from "react-router-dom";
import toast from "react-hot-toast";
import { motion } from "motion/react";
import {
  Activity,
  ArrowRight,
  CheckCircle2,
  Clock,
  Eye,
  EyeOff,
  FileCode2,
  Layers,
  Lock,
  Play,
  Shield,
  ShieldCheck,
  Sparkles,
  Terminal,
  User,
  Zap,
} from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { useConfig } from "../contexts/ConfigContext";
import { apiPost } from "../lib/api";
import type { LoginResult } from "../types";

export default function Login() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const { config } = useConfig();
  const [loading, setLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [formData, setFormData] = useState({ username: "", password: "" });

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.username.trim() || !formData.password) {
      toast.error("请输入账号和密码");
      return;
    }
    setLoading(true);
    try {
      const result = await apiPost<LoginResult>("/login", formData);
      toast.success("欢迎回来");
      login(result.token, result.user);
      navigate("/");
    } catch {
      // apiPost 已提示错误
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="flex min-h-screen w-full flex-col lg:flex-row bg-[var(--color-page)]">
      {/* ── Left Column: Brand Showcase (Desktop only, >= lg) ── */}
      <div className="relative hidden lg:flex lg:w-1/2 flex-col justify-between overflow-hidden bg-[#0c1817] p-10 xl:p-16 text-white min-h-screen">
        {/* Ambient Light & Grid */}
        <div className="pointer-events-none absolute -left-20 -top-20 h-96 w-96 rounded-full bg-[var(--color-accent)] opacity-20 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-24 -right-20 h-96 w-96 rounded-full bg-[#0a3534] opacity-25 blur-3xl" />
        <div className="pointer-events-none absolute inset-0 bg-grid-pattern opacity-40" />

        {/* Brand Header */}
        <div className="relative z-10">
          <div className="inline-flex items-center gap-2.5 rounded-full border border-white/12 bg-white/5 px-3.5 py-1.5 backdrop-blur-md">
            <span className="flex h-5 w-5 items-center justify-center rounded-full bg-[var(--color-accent)] text-white">
              <Clock className="h-3 w-3" />
            </span>
            <span className="text-xs font-semibold tracking-wide text-teal-200">
              Crontab Task Scheduler
            </span>
          </div>

          <div className="mt-8 max-w-lg">
            <h1 className="text-3xl font-black tracking-tight text-white xl:text-[40px] xl:leading-[1.15]">
              企业级定时任务与 <br />
              <span className="text-teal-400">分布式任务调度</span> 中心
            </h1>
            <p className="mt-4 text-[14px] leading-relaxed text-teal-100/75">
              基于 Swoole 常驻协程进程，提供毫秒与分钟级精准调度、类回调与 Shell 指令执行、并发控制与全量执行日志流。
            </p>
          </div>
        </div>

        {/* Feature Highlights Grid */}
        <div className="relative z-10 my-8 grid grid-cols-2 gap-3.5">
          <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur-xs transition-all hover:border-white/20 hover:bg-white/[0.06]">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-teal-500/20 text-teal-300">
                <Zap className="h-4 w-4" />
              </div>
              <div>
                <div className="text-[12.5px] font-bold text-white">高精度秒级调度</div>
                <div className="text-[10.5px] text-teal-200/60">常驻内存无外部依赖</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur-xs transition-all hover:border-white/20 hover:bg-white/[0.06]">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-300">
                <FileCode2 className="h-4 w-4" />
              </div>
              <div>
                <div className="text-[12.5px] font-bold text-white">类回调与命令行</div>
                <div className="text-[10.5px] text-teal-200/60">灵活支持多语言与脚本</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur-xs transition-all hover:border-white/20 hover:bg-white/[0.06]">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/20 text-amber-300">
                <Layers className="h-4 w-4" />
              </div>
              <div>
                <div className="text-[12.5px] font-bold text-white">单任务多进程并发</div>
                <div className="text-[10.5px] text-teal-200/60">重叠执行隔离保护</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/[0.03] p-4 backdrop-blur-xs transition-all hover:border-white/20 hover:bg-white/[0.06]">
            <div className="flex items-center gap-2.5">
              <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-cyan-500/20 text-cyan-300">
                <ShieldCheck className="h-4 w-4" />
              </div>
              <div>
                <div className="text-[12.5px] font-bold text-white">全链路运行日志</div>
                <div className="text-[10.5px] text-teal-200/60">执行时长与耗时监控</div>
              </div>
            </div>
          </div>
        </div>

        {/* Bottom System Status */}
        <div className="relative z-10 flex items-center justify-between border-t border-white/10 pt-5 text-[11.5px] text-teal-200/60">
          <div className="flex items-center gap-2">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-teal-400 opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-teal-500" />
            </span>
            <span>Swoole 协程常驻调度引擎 · 运行中</span>
          </div>
          <div className="font-mono text-[10.5px]">X-PHP Crontab Module</div>
        </div>
      </div>

      {/* ── Right Column: Authentication Card (Full-width on Mobile) ── */}
      <div className="flex flex-1 flex-col items-center justify-center min-h-screen px-4 py-8 sm:px-8 sm:py-12 md:px-12">
        {/* Mobile Header Branding (Only visible on < lg) */}
        <div className="mb-6 flex flex-col items-center text-center lg:hidden">
          <div className="flex h-12 w-12 items-center justify-center overflow-hidden rounded-2xl bg-[var(--color-accent)] text-white shadow-glow">
            {config?.site_logo ? (
              <img src={config.site_logo} alt="Logo" className="h-full w-full object-contain" />
            ) : (
              <Activity className="h-6 w-6" />
            )}
          </div>
          <h1 className="mt-3.5 text-xl font-extrabold tracking-tight text-ink sm:text-2xl">
            {config?.site_name || "Crontab 调度中心"}
          </h1>
          <p className="mt-1 text-[12px] text-ink-faint">
            定时任务可视化配置与调度引擎管控
          </p>
        </div>

        {/* Login Card */}
        <motion.div
          initial={{ opacity: 0, y: 12 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.25, ease: "easeOut" }}
          className="w-full max-w-md"
        >
          <div className="rounded-3xl border border-line bg-surface p-6 sm:p-8 md:p-9 shadow-lifted">
            {/* Desktop Card Header */}
            <div className="mb-6 hidden lg:block">
              <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-accent-soft text-accent">
                <Sparkles className="h-5 w-5" />
              </div>
              <h2 className="text-2xl font-black tracking-tight text-ink">
                管理员登录
              </h2>
              <p className="mt-1 text-[12.5px] leading-normal text-ink-faint">
                输入您的调度系统凭据，进入管理后台
              </p>
            </div>

            {/* Mobile Card Header */}
            <div className="mb-5 lg:hidden">
              <h2 className="text-lg font-bold text-ink">账号密码登录</h2>
              <p className="mt-0.5 text-[11.5px] text-ink-faint">
                请输入管理员账号与密码
              </p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label
                  htmlFor="username"
                  className="mb-1.5 block text-xs font-bold text-ink-soft"
                >
                  账号 / 用户名
                </label>
                <div className="relative flex items-center">
                  <User className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
                  <input
                    id="username"
                    type="text"
                    name="username"
                    autoComplete="username"
                    placeholder="请输入管理员账号"
                    value={formData.username}
                    onChange={handleChange}
                    style={{ paddingLeft: "40px", paddingRight: "14px" }}
                    className="h-11 w-full rounded-xl border border-line bg-inset text-sm text-ink placeholder:text-ink-faint/60 transition-colors focus:border-accent focus:bg-surface focus:outline-none focus:ring-4 focus:ring-accent-soft"
                    required
                  />
                </div>
              </div>

              <div>
                <label
                  htmlFor="password"
                  className="mb-1.5 block text-xs font-bold text-ink-soft"
                >
                  登录密码
                </label>
                <div className="relative flex items-center">
                  <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
                  <input
                    id="password"
                    type={showPassword ? "text" : "password"}
                    name="password"
                    autoComplete="current-password"
                    placeholder="请输入登录密码"
                    value={formData.password}
                    onChange={handleChange}
                    style={{ paddingLeft: "40px", paddingRight: "42px" }}
                    className="h-11 w-full rounded-xl border border-line bg-inset text-sm text-ink placeholder:text-ink-faint/60 transition-colors focus:border-accent focus:bg-surface focus:outline-none focus:ring-4 focus:ring-accent-soft"
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-ink-faint transition-colors hover:text-ink active:scale-95"
                    tabIndex={-1}
                    aria-label={showPassword ? "隐藏密码" : "显示密码"}
                  >
                    {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                  </button>
                </div>
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={loading}
                  className="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-accent text-sm font-bold text-white shadow-glow transition-all hover:bg-accent-strong active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {loading ? (
                    <>
                      <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                      <span>正在安全验证…</span>
                    </>
                  ) : (
                    <>
                      <span>进入调度控制台</span>
                      <ArrowRight className="h-4 w-4" />
                    </>
                  )}
                </button>
              </div>
            </form>

            <div className="mt-6 border-t border-line pt-4 text-center">
              <p className="text-[11px] text-ink-faint flex items-center justify-center gap-1.5">
                <Shield className="h-3 w-3 text-accent" />
                <span>企业级高可靠调度服务 · 仅限授权访问</span>
              </p>
            </div>
          </div>

          {config?.site_url && (
            <p className="mt-4 text-center">
              <a
                href={config.site_url}
                className="text-xs text-ink-faint transition-colors hover:text-accent hover:underline"
              >
                ← 返回 {config.site_name || "站点首页"}
              </a>
            </p>
          )}
        </motion.div>
      </div>
    </div>
  );
}
