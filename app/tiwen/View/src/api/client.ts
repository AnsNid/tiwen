/**
 * tiwen 后端 API 客户端
 * 统一 {code, message, data} 解包、X-Token Bearer 鉴权、SSE 流式读取
 */

const TOKEN_KEY = 'tiwen_token';

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) || '';
}

export function setToken(token: string) {
  localStorage.setItem(TOKEN_KEY, token);
}

export function clearToken() {
  localStorage.removeItem(TOKEN_KEY);
}

export interface ApiResult<T> {
  ok: boolean;
  code: number;
  message: string;
  data: T | null;
}

/**
 * 通用请求:自动带 token,解包 {code,message,data}
 * 可传 token 覆盖(后台管理用独立 token,避免与前台登录态互相污染)
 */
export async function api<T = any>(
  path: string,
  options: RequestInit & { json?: any; token?: string } = {}
): Promise<ApiResult<T>> {
  const { json, token: tokenOverride, ...rest } = options;
  // FormData(文件上传)交由浏览器自动设置 multipart 边界,不能手动指定 Content-Type
  const isFormData = typeof FormData !== 'undefined' && rest.body instanceof FormData;
  const headers: Record<string, string> = {
    ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
    ...(rest.headers as Record<string, string> || {}),
  };
  const token = tokenOverride !== undefined ? tokenOverride : getToken();
  if (token) {
    headers['X-Token'] = `Bearer ${token}`;
  }

  try {
    const resp = await fetch(path, {
      ...rest,
      headers,
      body: json !== undefined ? JSON.stringify(json) : rest.body,
    });

    if (resp.status === 401) {
      if (tokenOverride === undefined) {
        clearToken();
      }
      return { ok: false, code: 401, message: '登录态已失效,请重新登录', data: null };
    }

    const payload = await resp.json().catch(() => null);
    if (!payload || typeof payload.code !== 'number') {
      return { ok: false, code: resp.status, message: `服务异常 (HTTP ${resp.status})`, data: null };
    }
    return {
      ok: payload.code === 200,
      code: payload.code,
      message: payload.message || '',
      data: payload.data ?? null,
    };
  } catch (e: any) {
    return { ok: false, code: 0, message: '网络连接失败,请检查网络后重试', data: null };
  }
}

export interface StreamHandlers {
  onEvent: (event: string, data: any) => void;
}

/**
 * SSE 流式请求(POST + ReadableStream 解析,兼容 EventSource 无法 POST 的场景)
 */
export async function streamSSE(
  path: string,
  body: Record<string, any>,
  handlers: StreamHandlers,
  signal?: AbortSignal
): Promise<void> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const token = getToken();
  if (token) {
    headers['X-Token'] = `Bearer ${token}`;
  }

  const resp = await fetch(path, {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
    signal,
  });

  if (!resp.ok || !resp.body) {
    let message = `连接失败 (HTTP ${resp.status})`;
    try {
      const payload = await resp.json();
      if (payload && payload.message) message = payload.message;
    } catch { /* keep default */ }
    handlers.onEvent('fail', { message });
    return;
  }

  const reader = resp.body.getReader();
  const decoder = new TextDecoder('utf-8');
  let buffer = '';

  const processBlock = (block: string) => {
    let event = 'message';
    const dataLines: string[] = [];
    for (const rawLine of block.split('\n')) {
      const line = rawLine.replace(/\r$/, '');
      if (line.startsWith('event:')) {
        event = line.slice(6).trim();
      } else if (line.startsWith('data:')) {
        dataLines.push(line.slice(5).trim());
      }
    }
    if (dataLines.length === 0) return;
    const raw = dataLines.join('\n');
    let data: any = raw;
    try {
      data = JSON.parse(raw);
    } catch { /* keep as string */ }
    handlers.onEvent(event, data);
  };

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let idx;
      while ((idx = buffer.indexOf('\n\n')) !== -1) {
        const block = buffer.slice(0, idx);
        buffer = buffer.slice(idx + 2);
        if (block.trim() !== '') processBlock(block);
      }
    }
    if (buffer.trim() !== '') processBlock(buffer);
  } catch (e: any) {
    if (e && e.name !== 'AbortError') {
      handlers.onEvent('fail', { message: '流式连接中断,请重试' });
    }
  }
}
