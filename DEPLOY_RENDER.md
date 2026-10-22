# Deploy to Render (test link)

You need: a GitHub account, a Render account, and one MySQL database (Render has no MySQL — use Aiven,
TiDB Cloud, Railway MySQL or similar and copy host/port/name/user/password).

Do the License Control Center first, then the ERP.

## A. License Control Center (folder `app (4)`)
1. Create an empty MySQL database for it. 2. Push the folder to a new **private** GitHub repo
   (`keys/private.pem` is excluded from the image; do not commit real secrets).
3. Render > New > Blueprint > pick the repo. Fill: DB_HOST, DB_NAME, DB_USER, DB_PASS, APP_BASE_URL (the URL Render
   gives, e.g. https://pratyangi-license.onrender.com), LICENSE_PRIVATE_KEY (full text of keys/private.pem).
4. Open `<url>/setup_admin.php`, create the admin, then log in. Create a license and copy the key.

## B. ERP (folder `app (3)`)
1. Another empty MySQL database. 2. Push to a new private GitHub repo.
3. Render > New > Blueprint. Fill DB_*, LICENSE_SERVER_URL (URL from step A), APP_BASE_URL.
   The tables are created automatically on first start.
4. Open `<erp-url>/setup_admin.php`, create the Super Admin, log in, open License and activate with the key from A.
   `license/public_key.pem` in the ERP must be the public half of the private key used in A.

## Know before testing
- Free Render services sleep when idle (first request is slow) and have no persistent disk: uploaded product
  images disappear on redeploy and PHP sessions end if the service restarts.
- The local license bypass is ignored off localhost, so the ERP will ask for a real activation.
- Delete or rename `public/setup_admin.php` after the first admin exists.
