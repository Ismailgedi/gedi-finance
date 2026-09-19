
Gedi Finance - Super Admin UI Polish

This package fixes the visual layout of the Super Admin User Management page,
especially the dark-theme contrast and the password-reset modal.

The package contains:
- super-admin-ui-polish.css
- apply-super-admin-ui-polish.ps1

Recommended use:
1. Extract this folder into:
   C:\Users\igedi\gedi-finance\frontend\
2. Open PowerShell in that frontend folder.
3. Run:
   powershell -ExecutionPolicy Bypass -File .\gedi-super-admin-ui-polish\apply-super-admin-ui-polish.ps1
4. Run:
   npm.cmd run build
5. Refresh the browser.

The script is idempotent. It will not append the CSS twice.
