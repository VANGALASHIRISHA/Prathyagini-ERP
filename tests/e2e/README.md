# ERP end-to-end tests (Playwright)

1. Start XAMPP (Apache + MySQL) and the app: `C:\xampp\php\php.exe -S localhost:8000 -t public` from the project root.
2. `cd tests/e2e && npm install && npx playwright install chromium`
3. Set a LOCAL Super Admin login: `set ERP_USER=...` / `set ERP_PASS=...` (defaults to the local `bctest` user).
4. `npx playwright test` — screenshots land in `results/shots/`. Run `npx playwright test --headed` to watch.

Never point these tests at the live server: they create products and stock.
