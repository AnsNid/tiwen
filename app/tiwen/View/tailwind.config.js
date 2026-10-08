/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: '#2f6bff',
          hover: '#1e4fd6',
          subtle: '#eef4ff',
          muted: '#dce8ff'
        },
        gpt: '#0fa815',
        claude: '#7b52d7',
        gemini: '#f58720',
        deepseek: '#0066ff'
      },
      fontFamily: {
        sans: ['"Noto Sans SC"', '-apple-system', 'BlinkMacSystemFont', '"PingFang SC"', '"Segoe UI"', 'Roboto', 'sans-serif'],
      },
      boxShadow: {
        'card': '0 1px 2px rgba(20,30,50,.05), 0 8px 24px rgba(20,30,50,.06)',
        'subtle': '0 1px 3px rgba(20,30,50,.07)',
        'focus': '0 0 0 3px rgba(47,107,255,0.18)'
      }
    },
  },
  plugins: [
    // @tailwindcss/typography
  ],
}
