import axios from "axios";
import toast from "react-hot-toast";

// 生产环境走同源相对路径(页面挂在 /crontab/admin 下);
// 开发环境默认指向本地后端,可用 VITE_API_BASE 覆盖
export const API_BASE_URL: string =
  import.meta.env.VITE_API_BASE ??
  (import.meta.env.PROD ? "/crontab/api" : "http://127.0.0.1:9551/crontab/api");

interface ApiEnvelope<T> {
  code: number;
  message: string;
  data: T;
}

export const TOKEN_KEY = "x-token";

/** 智能解析可用 Token (仅识别并保留 x-token) */
export function resolveToken(): string {
  if (typeof window === "undefined") return "";

  // 1. 从 URL Query / Hash 中提取 x-token
  try {
    const searchParams = new URLSearchParams(window.location.search);
    const sToken = searchParams.get("x-token") || searchParams.get("x_token");
    if (sToken && sToken.trim()) {
      const clean = sToken.trim();
      setSavedToken(clean);
      return clean;
    }

    if (window.location.hash && window.location.hash.includes("?")) {
      const hashParams = new URLSearchParams(window.location.hash.split("?")[1]);
      const hToken = hashParams.get("x-token") || hashParams.get("x_token");
      if (hToken && hToken.trim()) {
        const clean = hToken.trim();
        setSavedToken(clean);
        return clean;
      }
    }
  } catch {}

  // 2. 本地与会话存储提取
  const localToken =
    localStorage.getItem(TOKEN_KEY) ||
    sessionStorage.getItem(TOKEN_KEY);
  if (localToken && localToken.trim()) return localToken.trim();

  // 3. 在 iframe 嵌入环境下直接继承父页面 (window.parent) 的 x-token
  if (window.parent && window.parent !== window) {
    try {
      const p = window.parent as any;
      const parentToken =
        p.localStorage?.getItem("x-token") ||
        p.sessionStorage?.getItem("x-token") ||
        p["x-token"] ||
        p.APP_TOKEN ||
        p.TOKEN;
      if (parentToken && typeof parentToken === "string" && parentToken.trim()) {
        const clean = parentToken.trim();
        setSavedToken(clean);
        return clean;
      }
    } catch {}
  }

  // 4. Cookie 提取
  try {
    const match = document.cookie.match(/(?:^|;\s*)x-token=([^;]+)/);
    if (match && match[1]) return decodeURIComponent(match[1]).trim();
  } catch {}

  return "";
}

/** 统一设置并持久化 x-token */
export function setSavedToken(token: string): void {
  if (typeof window === "undefined") return;
  const clean = String(token || "").replace(/^Bearer\s+/i, "").trim();
  if (!clean) return;

  localStorage.setItem(TOKEN_KEY, clean);
  sessionStorage.setItem(TOKEN_KEY, clean);

  try {
    document.cookie = `${TOKEN_KEY}=${encodeURIComponent(clean)}; path=/; max-age=2592000; SameSite=Lax`;
  } catch {}

  window.dispatchEvent(new CustomEvent("x-token-updated", { detail: clean }));
}

/** 彻底清除 x-token 及登录凭据 */
export function clearSavedToken(): void {
  if (typeof window === "undefined") return;
  localStorage.removeItem(TOKEN_KEY);
  sessionStorage.removeItem(TOKEN_KEY);

  try {
    document.cookie = `${TOKEN_KEY}=; path=/; max-age=0; SameSite=Lax`;
  } catch {}

  window.dispatchEvent(new Event("token-expired"));
}

/** 跨域异步向父级窗口请求 Token (基于 HTML5 postMessage 管道) */
export function requestParentToken(timeoutMs = 600): Promise<string> {
  return new Promise((resolve) => {
    if (typeof window === "undefined" || !window.parent || window.parent === window) {
      return resolve("");
    }

    const timer = setTimeout(() => {
      window.removeEventListener("message", onMsg);
      resolve("");
    }, timeoutMs);

    const onMsg = (event: MessageEvent) => {
      const data = event.data;
      if (
        data?.type === "XPHP_AUTH_TOKEN_RESPONSE" ||
        data?.type === "AUTH_TOKEN_RESPONSE" ||
        data?.action === "AUTH_TOKEN_RESPONSE"
      ) {
        const incoming = data?.["x-token"] || data?.x_token || "";
        if (typeof incoming === "string" && incoming.trim()) {
          clearTimeout(timer);
          window.removeEventListener("message", onMsg);
          const t = incoming.trim();
          setSavedToken(t);
          resolve(t);
        }
      }
    };

    window.addEventListener("message", onMsg);
    try {
      window.parent.postMessage({ type: "GET_AUTH_TOKEN" }, "*");
    } catch {
      clearTimeout(timer);
      window.removeEventListener("message", onMsg);
      resolve("");
    }
  });
}

const api = axios.create({
  baseURL: API_BASE_URL,
  timeout: 15000,
});

api.interceptors.request.use((config) => {
  config.headers["Content-Type"] = "application/json";
  const token = resolveToken();
  if (token) {
    config.headers["x-token"] = token.startsWith("Bearer ") ? token : `Bearer ${token}`;
  }
  return config;
});

// 业务错误与网络错误统一在这里处理并 reject,成功响应原样放行,由 apiGet/apiPost 拆包
api.interceptors.response.use(
  (response) => {
    const res = response.data as ApiEnvelope<unknown> | undefined;
    if (res && typeof res === "object" && res.code !== undefined && res.code !== 200) {
      const message = typeof res.message === "string" && res.message ? res.message : "请求失败";
      if (res.code === 401) {
        toast.error("登录凭据失效，请重新登录");
        clearSavedToken();
      } else {
        toast.error(message);
      }
      return Promise.reject(new Error(message));
    }
    return response;
  },
  (error) => {
    const status = error.response?.status;
    const data = error.response?.data as ApiEnvelope<unknown> | undefined;
    if (status === 401) {
      localStorage.removeItem("token");
      window.dispatchEvent(new Event("token-expired"));
      toast.error("会话已过期，请重新登录");
    } else if (data?.message) {
      toast.error(data.message);
    } else if (status) {
      toast.error(`服务器错误 (${status})`);
    } else {
      toast.error("网络请求失败，请检查后端服务是否可用");
    }
    return Promise.reject(error);
  }
);

// 以下两个封装已保证:拿到返回值即业务成功(code === 200),调用方无需再判断 code
export async function apiGet<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const res = await api.get<ApiEnvelope<T>>(url, { params });
  return res.data.data;
}

export async function apiPost<T = void>(url: string, data?: Record<string, unknown>): Promise<T> {
  const res = await api.post<ApiEnvelope<T>>(url, data);
  return res.data.data;
}
