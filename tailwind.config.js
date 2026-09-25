/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './views/**/*.php',
    './views/**/js/*.js',
    './public/js/pagejscript.js',
    './public/js/flood-map.js',
    './public/js/flood-upload.js',
    './libs/helpers.php',
  ],
  theme: {
    extend: {
      colors: {
        // โทนน้ำ (ฟ้า) — ระบบ COC ใช้เขียว ระบบน้ำท่วมใช้ฟ้าให้แยกออกจากกันได้ทันที
        brand: {
          50: '#f0f7fd',
          100: '#e0eefa',
          200: '#bddbf4',
          300: '#8cc0ea',
          400: '#4f9bd9',
          500: '#1f78c1',
          600: '#155e9c',
          700: '#124c7e',
          800: '#103f68',
          900: '#0c2f4f',
        },
        ink: {
          100: '#eef3f8',
          200: '#dfe6ee',
          300: '#e9eef4',
          400: '#8a94a3',
          500: '#6b7482',
          600: '#4d5663',
          700: '#243142',
          800: '#18212d',
        },
        lv: {
          evacuated: '#7c3aed',
          blocked: '#dc2626',
          watch: '#f59e0b',
        },
      },
      fontFamily: {
        sans: ['"Segoe UI"', 'Tahoma', '"Noto Sans Thai"', 'sans-serif'],
      },
      boxShadow: {
        card: '0 1px 2px rgba(16,24,40,.05), 0 1px 6px rgba(16,24,40,.04)',
        pop: '0 10px 30px rgba(16,24,40,.18)',
      },
    },
  },
  plugins: [],
  safelist: [
    'label-default', 'label-primary', 'label-success', 'label-danger', 'label-warning', 'label-info',
    'btn-success', 'btn-danger', 'btn-info', 'btn-warning', 'btn-primary', 'btn-default',
    'alert-danger', 'alert-warning', 'alert-info', 'alert-success',
    'in', 'show', 'open', 'modal-backdrop', 'fade', 'modal-open',
    'lv-evacuated', 'lv-blocked', 'lv-watch',
    'pri-urgent', 'pri-high', 'pri-normal',
  ],
};
