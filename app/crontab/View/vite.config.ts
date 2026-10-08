import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import {defineConfig} from 'vite';

export default defineConfig(() => ({
  // 相对路径:页面挂在 /crontab/admin 下,产物引用 ./assets/* 才能命中后端 crontab/assets/* 静态路由
  base: './',
  plugins: [react(), tailwindcss()],
}));
